<?php
/**
 * Full Audit Log for MCP tool executions.
 *
 * Every `tools/call` handled by WSP_MCP_Server is recorded here: tool name,
 * timestamp, acting user (or "unauthenticated"), request IP, and the
 * outcome (success / denied / error). Entries are stored in a dedicated,
 * self-hosted database table — no external service, API, or paid
 * dependency is involved. Old entries are pruned automatically so the
 * table cannot grow without bound.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Audit_Log {

	/** Default retention window in days. Filterable via wsp_mcp_audit_log_retention_days. */
	const DEFAULT_RETENTION_DAYS = 90;

	/** Recognized status values, for display and filtering. */
	const STATUS_SUCCESS = 'success';
	const STATUS_DENIED  = 'denied';
	const STATUS_ERROR   = 'error';

	/** @return string Fully-qualified table name. */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_audit_log';
	}

	/** Create the audit log table. Idempotent (dbDelta). */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tool_name varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY tool_name (tool_name),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record one MCP tool execution. Never throws — a logging failure must
	 * not break the tool call itself.
	 *
	 * @param string $tool_name Requested tool name (untrusted; sanitized/truncated here).
	 * @param string $status    One of STATUS_SUCCESS, STATUS_DENIED, STATUS_ERROR.
	 * @param string $message   Optional short human-readable detail (e.g. error text).
	 */
	public static function log( $tool_name, $status, $message = '' ) {
		global $wpdb;

		$user_id    = get_current_user_id();
		$user_login = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_login = $user->user_login;
			}
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				self::table(),
				array(
					'tool_name'  => mb_substr( sanitize_text_field( (string) $tool_name ), 0, 191 ),
					'status'     => sanitize_key( $status ),
					'user_id'    => $user_id,
					'user_login' => mb_substr( $user_login, 0, 60 ),
					'ip_address' => self::get_client_ip(),
					'message'    => mb_substr( wp_strip_all_tags( (string) $message ), 0, 500 ),
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			// Swallow — auditing must never take down the MCP request itself.
			return;
		}
	}

	/**
	 * Best-effort client IP for the audit trail. Only the direct connection
	 * (REMOTE_ADDR) is trusted — proxy headers (X-Forwarded-For, etc.) are
	 * attacker-controlled and are not used, so this cannot be spoofed to
	 * frame another address.
	 *
	 * @return string A validated IPv4/IPv6 address, or '' if unavailable.
	 */
	private static function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Fetch a page of log entries, most recent first.
	 *
	 * @param array $args {
	 *     @type int    $per_page Rows per page. Default 20.
	 *     @type int    $paged    1-indexed page number. Default 1.
	 *     @type string $status   Filter by status ('' = all).
	 *     @type string $tool     Filter by exact tool name ('' = all).
	 * }
	 * @return array<int,object> Rows.
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 20;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$status   = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$tool     = isset( $args['tool'] ) ? sanitize_text_field( $args['tool'] ) : '';

		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( '' !== $tool ) {
			$where[]  = 'tool_name = %s';
			$values[] = $tool;
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * $per_page;
		$values[]  = $per_page;
		$values[]  = $offset;

		// Table name is built from $wpdb->prefix (no user input); all values are bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			$values
		) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count log entries matching the same filters as get_entries().
	 *
	 * @param array $args See get_entries().
	 * @return int
	 */
	public static function count_entries( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$tool   = isset( $args['tool'] ) ? sanitize_text_field( $args['tool'] ) : '';

		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( '' !== $tool ) {
			$where[]  = 'tool_name = %s';
			$values[] = $tool;
		}
		$where_sql = implode( ' AND ', $where );

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
			$values
		) );
	}

	/** Distinct tool names seen in the log, for the filter dropdown. */
	public static function get_distinct_tools() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( "SELECT DISTINCT tool_name FROM {$table} ORDER BY tool_name ASC" );
		return is_array( $names ) ? $names : array();
	}

	/** Simple counts for the summary bar: total, success, denied, error. */
	public static function get_summary() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$summary = array(
			'total'                => 0,
			self::STATUS_SUCCESS   => 0,
			self::STATUS_DENIED    => 0,
			self::STATUS_ERROR     => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n = (int) $row['n'];
			$summary['total'] += $n;
			if ( isset( $summary[ $row['status'] ] ) ) {
				$summary[ $row['status'] ] = $n;
			}
		}
		return $summary;
	}

	/** Delete every log entry. Used by the admin "Clear log" action. */
	public static function clear_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/** Prune entries older than the retention window (daily cron). */
	public static function cleanup_old_entries() {
		global $wpdb;
		$table = self::table();
		$days  = (int) apply_filters( 'wsp_mcp_audit_log_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $days <= 0 ) {
			return; // Retention disabled.
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// Table name is built from $wpdb->prefix (no user input); value is bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
