<?php
/**
 * Data-access layer for NSPVault Redirects.
 *
 * @package NSPVault_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the custom redirects table and all read/write operations,
 * including the chain-flattening algorithm that keeps every historical
 * URL pointing directly at the current canonical URL (single 301 hop).
 */
class NSPVault_Redirects_DB {

	/**
	 * Full table name including the WP prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nspvault_redirects';
	}

	/**
	 * Expose the table name (used by the admin list view).
	 *
	 * @return string
	 */
	public function table() {
		return $this->table;
	}

	/**
	 * Create (or upgrade) the redirects table.
	 */
	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $this->table;

		// Note: dbDelta is whitespace-sensitive (two spaces after PRIMARY KEY, etc.).
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path VARCHAR(255) NOT NULL,
			target_path VARCHAR(255) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(20) NOT NULL DEFAULT 'auto',
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hit_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_path (source_path),
			KEY target_path (target_path),
			KEY post_id (post_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Normalize a URL or path into a root-relative path used as the storage key.
	 *
	 * - Strips scheme/host and query/fragment.
	 * - Ensures a single leading slash.
	 * - URL-decodes so stored values match decoded request paths.
	 *
	 * The trailing slash is preserved exactly as supplied so the value matches
	 * the site's permalink structure.
	 *
	 * @param string $url_or_path URL or path.
	 * @return string Normalized path, or empty string when it cannot be parsed.
	 */
	public function normalize_path( $url_or_path ) {
		$url_or_path = trim( (string) $url_or_path );
		if ( '' === $url_or_path ) {
			return '';
		}

		$path = wp_parse_url( $url_or_path, PHP_URL_PATH );
		if ( null === $path || false === $path || '' === $path ) {
			// Value had no path component (e.g. a bare domain).
			return '';
		}

		$path = rawurldecode( $path );
		$path = '/' . ltrim( $path, '/' );

		return $path;
	}

	/**
	 * Return the path variant that toggles the trailing slash, for lenient matching.
	 *
	 * @param string $path Normalized path.
	 * @return string Alternate variant.
	 */
	private function alt_slash( $path ) {
		if ( '/' === $path ) {
			return $path;
		}

		if ( '/' === substr( $path, -1 ) ) {
			return rtrim( $path, '/' );
		}

		return $path . '/';
	}

	/**
	 * Look up a redirect by its source path (tolerant of trailing-slash differences).
	 *
	 * @param string $path Requested path.
	 * @return object|null Row object or null.
	 */
	public function get_by_source( $path ) {
		global $wpdb;

		$path = $this->normalize_path( $path );
		if ( '' === $path ) {
			return null;
		}

		$candidates = array( $path, $this->alt_slash( $path ) );
		$candidates = array_values( array_unique( $candidates ) );

		$placeholders = implode( ', ', array_fill( 0, count( $candidates ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a fixed count.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE source_path IN ( $placeholders ) LIMIT 1",
			$candidates
		);

		$row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Record a URL move and flatten any resulting redirect chains.
	 *
	 * After this runs the following invariants hold:
	 *  - `$old` redirects to `$new`.
	 *  - Every source that previously pointed at `$old` now points at `$new`.
	 *  - `$new` is never itself a source (it is the live/canonical URL).
	 *  - No row is self-referential.
	 *
	 * This is what guarantees a single 301 hop no matter how many times a URL
	 * has been renamed over time.
	 *
	 * @param string $old     Old URL or path (the URL being retired).
	 * @param string $new     New URL or path (the new canonical).
	 * @param int    $post_id Associated post ID (0 for none).
	 * @param string $type    'auto' or 'manual'.
	 * @return bool True on success, false if the move was a no-op or invalid.
	 */
	public function record_move( $old, $new, $post_id = 0, $type = 'auto' ) {
		global $wpdb;

		$old = $this->normalize_path( $old );
		$new = $this->normalize_path( $new );

		if ( '' === $old || '' === $new || $old === $new ) {
			return false;
		}

		$now         = current_time( 'mysql' );
		$post_id     = absint( $post_id );
		$type        = ( 'manual' === $type ) ? 'manual' : 'auto';
		$status_code = $this->default_status_code();

		// 1) Repoint every redirect that currently targets $old so it targets $new.
		//    This is the flattening step: chains collapse into direct hops.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$this->table} SET target_path = %s, updated_at = %s WHERE target_path = %s",
				$new,
				$now,
				$old
			)
		);

		// 2) $new is now the live canonical URL, so it must not redirect anywhere.
		$wpdb->delete( $this->table, array( 'source_path' => $new ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// 3) Upsert the old -> new redirect.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$this->table}
					( source_path, target_path, post_id, type, status_code, hits, created_at, updated_at )
				VALUES ( %s, %s, %d, %s, %d, 0, %s, %s )
				ON DUPLICATE KEY UPDATE
					target_path = VALUES(target_path),
					post_id     = VALUES(post_id),
					type        = VALUES(type),
					status_code = VALUES(status_code),
					updated_at  = VALUES(updated_at)",
				$old,
				$new,
				$post_id,
				$type,
				$status_code,
				$now,
				$now
			)
		);

		// 4) Keep the post association consistent across all rows pointing at $new.
		if ( $post_id ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$this->table} SET post_id = %d WHERE target_path = %s AND post_id = 0",
					$post_id,
					$new
				)
			);
		}

		// 5) Safety net: never keep a self-referential row.
		$wpdb->query( "DELETE FROM {$this->table} WHERE source_path = target_path" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return true;
	}

	/**
	 * Insert or update a manual redirect from the admin UI.
	 *
	 * Runs through the same flattening logic so a hand-entered redirect can
	 * never create a chain either.
	 *
	 * @param string $source      Source URL/path.
	 * @param string $target      Target URL/path.
	 * @param int    $status_code 301 or 302.
	 * @return true|WP_Error
	 */
	public function add_manual( $source, $target, $status_code = 301 ) {
		$source = $this->normalize_path( $source );
		$target = $this->normalize_path( $target );

		if ( '' === $source ) {
			return new WP_Error( 'invalid_source', __( 'The source URL is not valid.', 'nspvault-redirects' ) );
		}
		if ( '' === $target ) {
			return new WP_Error( 'invalid_target', __( 'The target URL is not valid.', 'nspvault-redirects' ) );
		}
		if ( $source === $target ) {
			return new WP_Error( 'same_url', __( 'The source and target URLs are identical.', 'nspvault-redirects' ) );
		}

		$this->record_move( $source, $target, 0, 'manual' );

		// Apply the requested status code to the row we just wrote.
		if ( in_array( (int) $status_code, array( 301, 302, 307, 308 ), true ) ) {
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array( 'status_code' => (int) $status_code ),
				array( 'source_path' => $source ),
				array( '%d' ),
				array( '%s' )
			);
		}

		return true;
	}

	/**
	 * Delete a redirect by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( $this->table, array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Increment the hit counter for a redirect.
	 *
	 * @param int $id Row ID.
	 */
	public function increment_hit( $id ) {
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$this->table} SET hits = hits + 1, last_hit_at = %s WHERE id = %d",
				$now,
				absint( $id )
			)
		);
	}

	/**
	 * Fetch a page of redirects for the admin list.
	 *
	 * @param array $args {
	 *     @type string $search   Search term matched against source/target.
	 *     @type string $orderby  Column to order by.
	 *     @type string $order    ASC|DESC.
	 *     @type int    $per_page Rows per page.
	 *     @type int    $paged    1-based page number.
	 * }
	 * @return object[] Row objects.
	 */
	public function get_rows( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'orderby'  => 'updated_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'paged'    => 1,
			)
		);

		$allowed_orderby = array( 'id', 'source_path', 'target_path', 'hits', 'updated_at', 'created_at', 'last_hit_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = '';
		$params = array();
		if ( '' !== $args['search'] ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where  = 'WHERE source_path LIKE %s OR target_path LIKE %s';
			$params = array( $like, $like );
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where/$orderby/$order are whitelisted above.
		$sql = "SELECT * FROM {$this->table} $where ORDER BY $orderby $order LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count redirects matching an optional search term.
	 *
	 * @param string $search Search term.
	 * @return int
	 */
	public function count_rows( $search = '' ) {
		global $wpdb;

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE source_path LIKE %s OR target_path LIKE %s",
					$like,
					$like
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Total number of redirect hits served.
	 *
	 * @return int
	 */
	public function total_hits() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COALESCE( SUM( hits ), 0 ) FROM {$this->table}" );
	}

	/**
	 * Resolve the configured default status code.
	 *
	 * @return int
	 */
	private function default_status_code() {
		$settings = NSPVault_Redirects::get_settings();
		$code     = isset( $settings['status_code'] ) ? (int) $settings['status_code'] : 301;

		return in_array( $code, array( 301, 302, 307, 308 ), true ) ? $code : 301;
	}
}
