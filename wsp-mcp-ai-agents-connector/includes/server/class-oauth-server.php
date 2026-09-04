<?php
/**
 * Native OAuth 2.1 authorization server for "paste a URL only" Claude
 * Connectors (no config file, no manually-copied Bearer header).
 *
 * Implements just enough of RFC 8414 (AS metadata), RFC 9728 (protected
 * resource metadata), RFC 7591 (Dynamic Client Registration) and RFC 6749 +
 * PKCE (RFC 7636, S256 only) for Claude's documented `oauth_dcr` flow:
 * https://claude.com/docs/connectors/building/authentication
 *
 * Every endpoint here is matched against the raw request path on the `init`
 * hook rather than through the REST API or WordPress rewrite rules, for two
 * reasons: the two `.well-known/*` discovery documents MUST live at the site
 * *root* regardless of any REST namespace, and the token endpoint must accept
 * `application/x-www-form-urlencoded` bodies (PHP already populates $_POST
 * for that content type) rather than the REST API's JSON-only body parsing.
 * This mirrors the "match once, exit early" pattern already used by plugins
 * that serve custom root-level documents (e.g. sitemap.xml) without touching
 * rewrite rules or flush_rewrite_rules().
 *
 * Security model: public client only (no client_secret is ever issued —
 * Claude registers as a public client and authenticates with PKCE S256
 * instead). Authorization codes and refresh tokens are single-use and
 * rotated; all tokens are stored as SHA-256 hashes (see class-oauth-store.php)
 * so a database read alone is not a usable credential. An OAuth-issued
 * access token is bound to the specific WordPress user who clicked "Allow" on
 * the consent screen — tool calls then go through the *same* capability
 * checks (WSP_MCP_Auth::require_cap()) as any other logged-in user, which is
 * a stricter model than the static API key's "assume the first administrator"
 * shortcut.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_OAuth_Server {

	/** Register the early request-path dispatcher. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_dispatch' ), 0 );
	}

	/** @return string Scheme + host (+ non-default port) — no path. The OAuth issuer identity. */
	public static function issuer() {
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$port   = wp_parse_url( home_url(), PHP_URL_PORT );
		$base   = ( $scheme ? $scheme : 'https' ) . '://' . $host;
		if ( $port && ! ( 'https' === $scheme && 443 === (int) $port ) && ! ( 'http' === $scheme && 80 === (int) $port ) ) {
			$base .= ':' . $port;
		}
		return $base;
	}

	/** @return string The protected-resource metadata URL (used in the 401 WWW-Authenticate header). */
	public static function protected_resource_metadata_url() {
		return self::issuer() . '/.well-known/oauth-protected-resource';
	}

	/**
	 * @return string home_url()'s own path component, no trailing slash ('' for a
	 * root install, e.g. '/mcp' for a site whose WordPress Address has a base path).
	 * Only the two .well-known/* documents are spec-mandated to sit at the bare
	 * site root; authorize/token/register deliberately live *under* this base path
	 * instead, because WordPress's auth cookies are scoped to it (SITECOOKIEPATH) —
	 * putting the login-dependent /authorize endpoint outside that scope would make
	 * is_user_logged_in() invisible to it after login and loop forever.
	 */
	private static function base_path() {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/** Current request path, with no query string and no trailing slash (root stays "/"). */
	private static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		return $path;
	}

	/** Route the current request to a handler if its path matches one of ours. Otherwise: no-op. */
	public static function maybe_dispatch() {
		$path   = self::current_path();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$base = self::base_path();

		switch ( $path ) {
			case '/.well-known/oauth-protected-resource':
				self::send_json( self::protected_resource_metadata() );
				break; // send_json() exits.

			case '/.well-known/oauth-authorization-server':
				self::send_json( self::authorization_server_metadata() );
				break;

			case $base . '/wsp-mcp-oauth/register':
				if ( 'POST' !== $method ) {
					self::send_json( array( 'error' => 'invalid_request' ), 405 );
				}
				self::handle_register();
				break;

			case $base . '/wsp-mcp-oauth/authorize':
				self::handle_authorize( $method );
				break;

			case $base . '/wsp-mcp-oauth/token':
				if ( 'POST' !== $method ) {
					self::send_json( array( 'error' => 'invalid_request' ), 405 );
				}
				self::handle_token();
				break;
		}
	}

	/* ---------- Discovery documents ---------- */

	private static function protected_resource_metadata() {
		return array(
			'resource'              => esc_url_raw( rest_url( 'wsp-mcp/v1/mcp' ) ),
			'authorization_servers' => array( self::issuer() ),
			'scopes_supported'      => array( 'mcp' ),
		);
	}

	private static function authorization_server_metadata() {
		$issuer = self::issuer();
		return array(
			'issuer'                                => $issuer,
			// Deliberately home_url()-relative, not issuer()-relative — see base_path().
			'authorization_endpoint'                => home_url( '/wsp-mcp-oauth/authorize' ),
			'token_endpoint'                         => home_url( '/wsp-mcp-oauth/token' ),
			'registration_endpoint'                  => home_url( '/wsp-mcp-oauth/register' ),
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'        => array( 'S256' ),
			'token_endpoint_auth_methods_supported'   => array( 'none' ),
			'scopes_supported'                        => array( 'mcp', 'offline_access' ),
		);
	}

	/* ---------- Dynamic Client Registration (RFC 7591) ---------- */

	private static function handle_register() {
		$raw  = file_get_contents( 'php://input' );
		$body = json_decode( (string) $raw, true );
		if ( ! is_array( $body ) ) {
			self::send_json( array( 'error' => 'invalid_client_metadata', 'error_description' => 'Body must be JSON.' ), 400 );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$redirect_uris = array_values( array_filter( $redirect_uris, 'is_string' ) );
		$redirect_uris = array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) );

		if ( empty( $redirect_uris ) || count( $redirect_uris ) > 10 ) {
			self::send_json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'At least one, at most ten, valid redirect_uris are required.' ), 400 );
		}
		foreach ( $redirect_uris as $uri ) {
			$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
			$host   = wp_parse_url( $uri, PHP_URL_HOST );
			$is_https = 'https' === $scheme;
			$is_loopback = in_array( $scheme, array( 'http' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1' ), true );
			if ( ! $is_https && ! $is_loopback ) {
				self::send_json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must be HTTPS (loopback http://localhost is also accepted).' ), 400 );
			}
		}

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( wp_unslash( $body['client_name'] ) ) : 'MCP client';
		$client_name = mb_substr( $client_name, 0, 255 );

		$client_id = WSP_MCP_OAuth_Store::register_client( $client_name, $redirect_uris );

		self::send_json( array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'client_id_issued_at'        => time(),
		), 201 );
	}

	/* ---------- Authorization endpoint (login + consent) ---------- */

	private static function handle_authorize( $method ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is the OAuth authorize request itself; params are validated below, and the consent POST is nonce-protected separately.
		$params = ( 'POST' === $method ) ? wp_unslash( $_POST ) : wp_unslash( $_GET );

		$client_id     = isset( $params['client_id'] ) ? sanitize_text_field( $params['client_id'] ) : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? esc_url_raw( $params['redirect_uri'] ) : '';
		$state         = isset( $params['state'] ) ? sanitize_text_field( $params['state'] ) : '';
		$response_type = isset( $params['response_type'] ) ? sanitize_text_field( $params['response_type'] ) : '';
		$challenge     = isset( $params['code_challenge'] ) ? sanitize_text_field( $params['code_challenge'] ) : '';
		$challenge_m   = isset( $params['code_challenge_method'] ) ? sanitize_text_field( $params['code_challenge_method'] ) : '';
		$scope         = isset( $params['scope'] ) ? sanitize_text_field( $params['scope'] ) : 'mcp';

		$client = $client_id ? WSP_MCP_OAuth_Store::get_client( $client_id ) : null;

		// Everything up to and including the redirect_uri match must be verified
		// BEFORE we ever redirect anywhere — an unvalidated redirect_uri is a
		// classic OAuth open-redirect vulnerability, so failures here render a
		// plain error page instead of bouncing the browser anywhere.
		if ( ! $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			self::render_error_page( 'This connector is not registered, or its redirect address does not match what was registered.' );
		}
		if ( 'code' !== $response_type ) {
			self::redirect_with_error( $redirect_uri, $state, 'unsupported_response_type' );
		}
		if ( '' === $challenge || 'S256' !== $challenge_m ) {
			self::redirect_with_error( $redirect_uri, $state, 'invalid_request', 'PKCE (code_challenge with S256) is required.' );
		}

		// Not logged in yet: send to WordPress's own login screen and come right
		// back here with the exact same query string once authenticated. Reuses
		// the literal request URI the browser is already on (issuer() + raw
		// REQUEST_URI) rather than rebuilding it from home_url(), so this is
		// correct with or without a base path and can't accidentally double up
		// a prefix like /mcp.
		if ( ! is_user_logged_in() ) {
			$current_request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/wsp-mcp-oauth/authorize'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$return_to = self::issuer() . $current_request;
			wp_safe_redirect( wp_login_url( $return_to ) );
			exit;
		}

		$action = isset( $params['wsp_mcp_oauth_action'] ) ? sanitize_text_field( $params['wsp_mcp_oauth_action'] ) : '';

		if ( 'POST' === $method && '' !== $action ) {
			check_admin_referer( 'wsp_mcp_oauth_consent_' . $client_id );

			if ( 'deny' === $action ) {
				self::redirect_with_error( $redirect_uri, $state, 'access_denied' );
			}

			$code = WSP_MCP_OAuth_Store::create_code(
				$client_id,
				get_current_user_id(),
				$redirect_uri,
				$challenge,
				$scope
			);
			$target = add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri );
			// wp_redirect(), not wp_safe_redirect(): the target is the client's own
			// callback (e.g. https://claude.ai/api/mcp/auth_callback), a different
			// host by design — wp_safe_redirect() would block it as "unsafe". The
			// security control here is the exact-match check against $client's
			// registered redirect_uris above, which is stricter than the generic
			// same-host allowlist wp_safe_redirect() enforces.
			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		self::render_consent_page( $client, $client_id, $redirect_uri, $state, $challenge, $challenge_m, $scope );
	}

	/** Minimal, escaped consent screen. No theme dependency — this runs before template_redirect. */
	private static function render_consent_page( $client, $client_id, $redirect_uri, $state, $challenge, $challenge_m, $scope ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$site_name   = esc_html( get_bloginfo( 'name' ) );
		$client_name = esc_html( $client['client_name'] );
		$user        = wp_get_current_user();
		$user_label  = esc_html( $user->user_login );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Authorize access', 'wsp-mcp-ai-agents-connector' ); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:0;padding:40px 20px;color:#1d2327}
				.box{max-width:420px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
				h1{font-size:18px;margin:0 0 6px}
				p{font-size:13.5px;line-height:1.6;color:#3c434a}
				.who{font-size:12.5px;color:#646970;margin-bottom:20px}
				.actions{display:flex;gap:10px;margin-top:24px}
				button{flex:1;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid #dcdcde}
				.allow{background:#0073aa;color:#fff;border-color:#0073aa}
				.deny{background:#fff;color:#3c434a}
			</style>
		</head>
		<body>
			<div class="box">
				<h1><?php echo esc_html( sprintf( /* translators: %s: connecting client name */ __( '%s wants to connect', 'wsp-mcp-ai-agents-connector' ), $client_name ) ); ?></h1>
				<p class="who"><?php echo esc_html( sprintf( /* translators: %s: WordPress username */ __( 'Signed in as %s', 'wsp-mcp-ai-agents-connector' ), $user_label ) ); ?></p>
				<p><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'This will let it read and act on %s through the MCP tools your account is allowed to use.', 'wsp-mcp-ai-agents-connector' ), $site_name ) ); ?></p>
				<form method="post" action="<?php echo esc_url( home_url( '/wsp-mcp-oauth/authorize' ) ); ?>">
					<?php wp_nonce_field( 'wsp_mcp_oauth_consent_' . $client_id ); ?>
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
					<input type="hidden" name="response_type" value="code">
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $challenge ); ?>">
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $challenge_m ); ?>">
					<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
					<div class="actions">
						<button type="submit" name="wsp_mcp_oauth_action" value="deny" class="deny"><?php esc_html_e( 'Deny', 'wsp-mcp-ai-agents-connector' ); ?></button>
						<button type="submit" name="wsp_mcp_oauth_action" value="allow" class="allow"><?php esc_html_e( 'Allow', 'wsp-mcp-ai-agents-connector' ); ?></button>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private static function render_error_page( $message ) {
		nocache_headers();
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'wsp-mcp-ai-agents-connector' ) . '</title></head><body style="font-family:sans-serif;padding:40px;"><h1>' . esc_html__( 'Authorization error', 'wsp-mcp-ai-agents-connector' ) . '</h1><p>' . esc_html( $message ) . '</p></body></html>';
		exit;
	}

	/**
	 * Every call site reaches here only after $redirect_uri has already been
	 * matched exactly against the registered client's redirect_uris (see
	 * handle_authorize()), so — like the success path above — wp_redirect() is
	 * used deliberately instead of wp_safe_redirect(), which would otherwise
	 * block a redirect back to the client's own (different-host) callback.
	 */
	private static function redirect_with_error( $redirect_uri, $state, $error, $description = '' ) {
		if ( '' === $redirect_uri ) {
			self::render_error_page( $description ? $description : $error );
		}
		$args = array( 'error' => $error, 'state' => $state );
		if ( $description ) {
			$args['error_description'] = $description;
		}
		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/* ---------- Token endpoint (RFC 6749 + PKCE) ---------- */

	private static function handle_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token endpoint is a machine-to-machine OAuth grant exchange, authenticated by the authorization code / refresh token itself, not a WP nonce.
		$grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';

		if ( 'authorization_code' === $grant_type ) {
			self::token_exchange_code();
		} elseif ( 'refresh_token' === $grant_type ) {
			self::token_refresh();
		} else {
			self::send_json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
	}

	private static function token_exchange_code() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle_token().
		$code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$code_verifier = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';

		if ( '' === $code || '' === $code_verifier ) {
			self::send_json( array( 'error' => 'invalid_request' ), 400 );
		}

		$row = WSP_MCP_OAuth_Store::consume_code( $code );
		if ( ! $row || $row['client_id'] !== $client_id || $row['redirect_uri'] !== $redirect_uri ) {
			self::send_json( array( 'error' => 'invalid_grant' ), 400 );
		}
		if ( ! self::pkce_matches( $code_verifier, $row['code_challenge'] ) ) {
			self::send_json( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		$tokens = WSP_MCP_OAuth_Store::issue_tokens( $client_id, (int) $row['user_id'], $row['scope'] );
		self::send_json( array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => $row['scope'],
		) );
	}

	private static function token_refresh() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle_token().
		$refresh_token = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( '' === $refresh_token || '' === $client_id ) {
			self::send_json( array( 'error' => 'invalid_request' ), 400 );
		}

		$fresh = WSP_MCP_OAuth_Store::rotate_refresh_token( $refresh_token, $client_id );
		if ( ! $fresh ) {
			// RFC 6749-compliant code for an unusable refresh token, per Claude's
			// documented refresh-failure requirement (invalid_grant, not a custom code).
			self::send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		self::send_json( array(
			'access_token'  => $fresh['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $fresh['expires_in'],
			'refresh_token' => $fresh['refresh_token'],
			'scope'         => $fresh['scope'],
		) );
	}

	/** RFC 7636 S256 PKCE verification. */
	private static function pkce_matches( $code_verifier, $code_challenge ) {
		if ( '' === $code_verifier || '' === $code_challenge ) {
			return false;
		}
		$hash   = hash( 'sha256', $code_verifier, true );
		$b64url = rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
		return hash_equals( $code_challenge, $b64url );
	}

	/* ---------- Response helper ---------- */

	private static function send_json( array $data, $status = 200 ) {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data );
		exit;
	}
}
