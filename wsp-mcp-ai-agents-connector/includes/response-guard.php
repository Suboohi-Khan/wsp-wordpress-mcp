<?php
/**
 * Output-buffer guard for the native MCP/OAuth transport (v2.7.3).
 *
 * A client site can have any number of *other* plugins active — this plugin
 * has no control over how many, or how well-behaved they are. Some print a
 * PHP notice/warning/deprecation string directly to output during perfectly
 * ordinary WordPress hooks (init, wp_loaded, rest_api_init, a template part,
 * …). When that happens on a request this plugin is about to answer with
 * strict JSON, the stray text lands in front of the JSON body and every MCP
 * client's JSON parser fails — which surfaces in Claude as "connected" but
 * "This connector has no tools available", indistinguishable from an actual
 * bug in this plugin. It also causes the classic "headers already sent"
 * warning if the stray output happened before this plugin's own header()
 * calls (the 401 challenge, the OAuth discovery documents, …).
 *
 * The fix is an output buffer opened as early as this plugin's own bootstrap
 * allows — the very top of the main plugin file, before any other include —
 * whenever the current request is aimed at one of this plugin's own
 * endpoints (the native MCP REST route, or the OAuth discovery/registration/
 * authorize/token endpoints). wsp_mcp_output_guard_flush() is called right
 * before the real response is emitted, discarding only the single buffer
 * level this guard itself opened — nothing else on the site (e.g. a
 * compression buffer opened by the server) is touched — so header() calls
 * downstream of a noisy plugin keep working and the client only ever sees
 * clean JSON.
 *
 * This cannot catch output printed before this plugin's own file is
 * `include`d (e.g. a stray echo at the very top of some other plugin's main
 * file that happens to load first) — there is no earlier hook a regular
 * plugin can use for that. Everything from `plugins_loaded` onward, which is
 * where the vast majority of real-world stray output actually happens, is
 * covered.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$GLOBALS['wsp_mcp_output_guard_active'] = false;

/**
 * Whether the current request is this plugin's own MCP/OAuth surface, based
 * on the raw request URI. Deliberately a handful of strpos() calls — no
 * rewrite-rule or REST-route resolution, which isn't available this early in
 * the request lifecycle — since this runs on every single request to the
 * site.
 *
 * @return bool
 */
function wsp_mcp_is_own_endpoint_request() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}
	$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- substring match only, never echoed or stored.

	// Matches both pretty-permalink REST URLs and the ?rest_route= fallback.
	if ( false !== strpos( $uri, 'wsp-mcp/v1/mcp' ) ) {
		return true;
	}
	foreach ( array( 'oauth-protected-resource', 'oauth-authorization-server', 'openid-configuration', 'wsp-mcp-oauth/' ) as $marker ) {
		if ( false !== strpos( $uri, $marker ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Open the guard buffer. No-op for any request that isn't this plugin's own
 * endpoint, so normal page loads and admin screens are entirely unaffected.
 */
function wsp_mcp_output_guard_start() {
	if ( $GLOBALS['wsp_mcp_output_guard_active'] || ! wsp_mcp_is_own_endpoint_request() ) {
		return;
	}
	ob_start();
	$GLOBALS['wsp_mcp_output_guard_active'] = true;
}

/**
 * Discard exactly the one buffer level this guard opened (if any), right
 * before the real response is echoed. Safe to call more than once, or when
 * no buffer was ever opened for this request — both are cheap no-ops, which
 * is what lets this be wired unconditionally into a site-wide filter like
 * `rest_pre_echo_response` without touching unrelated REST responses.
 */
function wsp_mcp_output_guard_flush() {
	if ( empty( $GLOBALS['wsp_mcp_output_guard_active'] ) ) {
		return;
	}
	$stray = ob_get_clean();
	$GLOBALS['wsp_mcp_output_guard_active'] = false;
	if ( is_string( $stray ) && '' !== trim( $stray ) ) {
		// Never fed back to the client — this is a JSON transport, not HTML —
		// but kept in the PHP error log so the admin can trace which other
		// plugin/theme is printing on this request.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'WSP MCP: discarded stray output ahead of a JSON response (likely another active plugin/theme): ' . substr( $stray, 0, 500 ) );
	}
}
