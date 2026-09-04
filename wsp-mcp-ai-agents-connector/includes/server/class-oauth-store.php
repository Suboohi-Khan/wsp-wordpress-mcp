<?php
/**
 * DB-backed store for the native OAuth 2.1 authorization server (Claude
 * Connectors "paste a URL only" flow).
 *
 * Three tables, all created idempotently via dbDelta like the existing
 * sessions/audit-log tables:
 *   - oauth_clients: clients registered via Dynamic Client Registration
 *     (RFC 7591). Public clients only — no client_secret is ever issued or
 *     required, matching how Claude registers itself.
 *   - oauth_codes: short-lived, single-use authorization codes bound to a
 *     PKCE code_challenge, a specific redirect_uri, and the WordPress user
 *     who approved the consent screen.
 *   - oauth_tokens: issued access/refresh token pairs. Tokens are stored as
 *     SHA-256 hashes, never in plaintext, the same principle WordPress core
 *     uses for Application Passwords — a DB read alone cannot be replayed as
 *     a credential.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_OAuth_Store {

	/** Authorization code lifetime in seconds (short — exchanged immediately). */
	const CODE_TTL = 120;

	/** Access token lifetime in seconds (1 hour). */
	const ACCESS_TTL = 3600;

	/** Refresh token lifetime in seconds (30 days), rotated on every use. */
	const REFRESH_TTL = 2592000;

	/** @return string Clients table name. */
	private static function clients_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_oauth_clients';
	}

	/** @return string Authorization codes table name. */
	private static function codes_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_oauth_codes';
	}

	/** @return string Tokens table name. */
	private static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_oauth_tokens';
	}

	/** Create all three tables. Idempotent (dbDelta). */
	public static function create_tables() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$clients = self::clients_table();
		dbDelta( "CREATE TABLE {$clients} (
			client_id varchar(64) NOT NULL,
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (client_id)
		) {$collate};" );

		$codes = self::codes_table();
		dbDelta( "CREATE TABLE {$codes} (
			code varchar(64) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			redirect_uri text NOT NULL,
			code_challenge varchar(128) NOT NULL,
			scope varchar(255) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			PRIMARY KEY  (code),
			KEY expires_at (expires_at)
		) {$collate};" );

		$tokens = self::tokens_table();
		dbDelta( "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			access_token_hash varchar(64) NOT NULL,
			refresh_token_hash varchar(64) NOT NULL DEFAULT '',
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(255) NOT NULL DEFAULT '',
			access_expires_at datetime NOT NULL,
			refresh_expires_at datetime NULL DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY access_token_hash (access_token_hash),
			KEY refresh_token_hash (refresh_token_hash),
			KEY access_expires_at (access_expires_at)
		) {$collate};" );
	}

	/* ---------- Clients (Dynamic Client Registration) ---------- */

	/**
	 * Register a new public OAuth client.
	 *
	 * @param string   $client_name  Human-readable name supplied by the client.
	 * @param string[] $redirect_uris Allowed redirect URIs (validated by caller).
	 * @return string The generated client_id.
	 */
	public static function register_client( $client_name, array $redirect_uris ) {
		global $wpdb;
		$client_id = bin2hex( random_bytes( 24 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::clients_table(),
			array(
				'client_id'     => $client_id,
				'client_name'   => $client_name,
				'redirect_uris' => wp_json_encode( array_values( $redirect_uris ) ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return $client_id;
	}

	/**
	 * Fetch a registered client.
	 *
	 * @param string $client_id Client identifier.
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|null
	 */
	public static function get_client( $client_id ) {
		global $wpdb;
		$table = self::clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"SELECT client_id, client_name, redirect_uris FROM {$table} WHERE client_id = %s",
			$client_id
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$uris = json_decode( $row['redirect_uris'], true );
		return array(
			'client_id'     => $row['client_id'],
			'client_name'   => $row['client_name'],
			'redirect_uris' => is_array( $uris ) ? $uris : array(),
		);
	}

	/* ---------- Authorization codes ---------- */

	/**
	 * Store a freshly issued authorization code.
	 *
	 * @param string $client_id      Client identifier.
	 * @param int    $user_id        WordPress user who approved consent.
	 * @param string $redirect_uri   Exact redirect_uri this code is bound to.
	 * @param string $code_challenge PKCE S256 challenge from the authorize request.
	 * @param string $scope          Requested scope string.
	 * @return string The generated code.
	 */
	public static function create_code( $client_id, $user_id, $redirect_uri, $code_challenge, $scope ) {
		global $wpdb;
		$code = bin2hex( random_bytes( 32 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::codes_table(),
			array(
				'code'           => $code,
				'client_id'      => $client_id,
				'user_id'        => $user_id,
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $code_challenge,
				'scope'          => $scope,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $code;
	}

	/**
	 * Consume (fetch and delete — single use) an authorization code.
	 *
	 * @param string $code Authorization code.
	 * @return array|null Row data, or null if missing/expired.
	 */
	public static function consume_code( $code ) {
		global $wpdb;
		$table = self::codes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"SELECT * FROM {$table} WHERE code = %s AND expires_at > %s",
			$code,
			current_time( 'mysql', true )
		), ARRAY_A );
		// Always delete on first sight — a code must never be exchangeable twice,
		// even if the row was already expired.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'code' => $code ), array( '%s' ) );
		return $row ?: null;
	}

	/* ---------- Tokens ---------- */

	/**
	 * Issue a fresh access/refresh token pair.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $user_id   WordPress user the token acts as.
	 * @param string $scope     Scope string.
	 * @return array{access_token:string,refresh_token:string,expires_in:int}
	 */
	public static function issue_tokens( $client_id, $user_id, $scope ) {
		global $wpdb;
		$access_token  = bin2hex( random_bytes( 32 ) );
		$refresh_token = bin2hex( random_bytes( 32 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::tokens_table(),
			array(
				'access_token_hash'  => hash( 'sha256', $access_token ),
				'refresh_token_hash' => hash( 'sha256', $refresh_token ),
				'client_id'          => $client_id,
				'user_id'            => $user_id,
				'scope'              => $scope,
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => self::ACCESS_TTL,
		);
	}

	/**
	 * Validate an access token presented at the MCP endpoint.
	 *
	 * @param string $access_token Bearer token as received.
	 * @return array{user_id:int,client_id:string}|null
	 */
	public static function validate_access_token( $access_token ) {
		global $wpdb;
		$table = self::tokens_table();
		$hash  = hash( 'sha256', $access_token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"SELECT user_id, client_id FROM {$table} WHERE access_token_hash = %s AND revoked = 0 AND access_expires_at > %s",
			$hash,
			current_time( 'mysql', true )
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array( 'user_id' => (int) $row['user_id'], 'client_id' => $row['client_id'] );
	}

	/**
	 * Rotate a refresh token: validate it, revoke the old pair, issue a new pair.
	 *
	 * @param string $refresh_token Refresh token as received.
	 * @param string $client_id     Client identifier presented alongside it.
	 * @return array{access_token:string,refresh_token:string,expires_in:int,user_id:int,scope:string}|null
	 */
	public static function rotate_refresh_token( $refresh_token, $client_id ) {
		global $wpdb;
		$table = self::tokens_table();
		$hash  = hash( 'sha256', $refresh_token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"SELECT * FROM {$table} WHERE refresh_token_hash = %s AND client_id = %s AND revoked = 0 AND refresh_expires_at > %s",
			$hash,
			$client_id,
			current_time( 'mysql', true )
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		// Revoke the old pair (rotation — refresh tokens are single-use).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'id' => $row['id'] ), array( '%d' ), array( '%d' ) );

		$fresh = self::issue_tokens( $client_id, (int) $row['user_id'], $row['scope'] );
		$fresh['user_id'] = (int) $row['user_id'];
		$fresh['scope']   = $row['scope'];
		return $fresh;
	}

	/** Remove expired codes and long-expired revoked/refresh-expired tokens (daily cron). */
	public static function cleanup_expired() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$codes_table  = self::codes_table();
		$tokens_table = self::tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"DELETE FROM {$codes_table} WHERE expires_at <= %s",
			$now
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (no user input); value bound via prepare().
			"DELETE FROM {$tokens_table} WHERE (refresh_expires_at IS NOT NULL AND refresh_expires_at <= %s) OR (revoked = 1 AND access_expires_at <= %s)",
			$now,
			$now
		) );
	}
}
