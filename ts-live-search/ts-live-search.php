<?php
/**
 * Plugin Name: TS Live Search
 * Description: A live (instant) search bar for your posts, plus a keyword log in the admin. Every search is
 *              recorded and aggregated by keyword; each keyword can be marked as "Article written", and the
 *              admin list can be filtered by status and searched. Place the bar with the [live_search]
 *              shortcode, or let it auto-insert at the top of the homepage. Searches that return no results
 *              are flagged and the admin is notified with a count on the menu.
 * Version: 1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_LS_VERSION', '1.0' );
define( 'TS_LS_DB_VERSION', '1.1' );

/* ==========================================================
 * 0. DATABASE (keyword log table)
 * ========================================================== */

function ts_ls_table() {
	global $wpdb;
	return $wpdb->prefix . 'ts_search_logs';
}

/**
 * Create/upgrade the log table.
 */
function ts_ls_install() {
	global $wpdb;
	$table           = ts_ls_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		keyword VARCHAR(191) NOT NULL,
		hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
		written TINYINT(1) NOT NULL DEFAULT 0,
		results BIGINT UNSIGNED NOT NULL DEFAULT 0,
		last_searched DATETIME NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY keyword (keyword),
		KEY written (written),
		KEY hits (hits),
		KEY results (results)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'ts_ls_db_version', TS_LS_DB_VERSION );
}
register_activation_hook( __FILE__, 'ts_ls_install' );

// Safety net: create the table if the plugin was updated without re-activation.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'ts_ls_db_version' ) !== TS_LS_DB_VERSION ) {
		ts_ls_install();
	}
} );

/* ==========================================================
 * 1. SETTINGS
 * ========================================================== */

function ts_ls_defaults() {
	return array(
		'placeholder' => 'Search Nintendo Switch ROMs...',
		'auto_home'   => 1, // auto-insert at the top of the homepage
		'min_chars'   => 2,
	);
}

function ts_ls_get_settings() {
	$saved = get_option( 'ts_ls_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( ts_ls_defaults(), $saved );
}

/**
 * Public post types that should be searchable.
 *
 * @return array
 */
function ts_ls_post_types() {
	// Include every public/queryable type (games are usually a custom post type),
	// so the count matches what the site's search page actually shows.
	$types  = get_post_types( array( 'public' => true ), 'names' );
	$types += get_post_types( array( 'publicly_queryable' => true ), 'names' );
	unset( $types['attachment'] );
	/**
	 * Filter the searchable post types.
	 *
	 * @param array $types Post type names.
	 */
	return apply_filters( 'ts_ls_post_types', array_values( $types ) );
}

/**
 * Run a search query, routing through Relevanssi when that plugin is active so
 * the result count matches the site's real search behaviour.
 *
 * @param array $args WP_Query args (must include 's').
 * @return WP_Query
 */
function ts_ls_search_query( $args ) {
	$query = new WP_Query( $args );
	if ( function_exists( 'relevanssi_do_query' ) ) {
		relevanssi_do_query( $query );
	}
	return $query;
}

/* ==========================================================
 * 2. FRONT-END — search bar + live results
 * ========================================================== */

/**
 * Render the search bar markup.
 *
 * @return string
 */
function ts_ls_render_bar() {
	static $printed = false;
	if ( $printed ) {
		return ''; // never output the bar twice on one page
	}
	$printed = true;

	$s        = ts_ls_get_settings();
	$action   = esc_url( home_url( '/' ) );
	$ph        = esc_attr( $s['placeholder'] );
	$min_chars = (int) $s['min_chars'];
	$query_url = esc_url( rest_url( 'ts-search/v1/query' ) );
	$log_url   = esc_url( rest_url( 'ts-search/v1/log' ) );
	$nonce     = wp_create_nonce( 'wp_rest' );

	ts_ls_styles();

	ob_start();
	?>
	<div class="ts-search" data-query="<?php echo $query_url; ?>" data-log="<?php echo $log_url; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-min="<?php echo esc_attr( $min_chars ); ?>">
		<form class="ts-search-form" role="search" method="get" action="<?php echo $action; ?>" autocomplete="off">
			<input type="search" name="s" class="ts-search-input" placeholder="<?php echo $ph; ?>"
				aria-label="<?php echo $ph; ?>" value="<?php echo esc_attr( get_search_query() ); ?>">
			<button type="submit" class="ts-search-btn" aria-label="Search">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
					<circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
				</svg>
			</button>
		</form>
		<div class="ts-search-results" role="listbox" aria-label="Search results"></div>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'live_search', 'ts_ls_render_bar' );

/**
 * Auto-insert at the very top of the homepage (before the post loop).
 */
add_action( 'loop_start', function ( $query ) {
	$s = ts_ls_get_settings();
	if ( empty( $s['auto_home'] ) ) {
		return;
	}
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! ( is_home() && is_front_page() ) ) {
		return;
	}
	if ( is_paged() ) {
		return; // only the first page
	}
	echo ts_ls_render_bar(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder
}, 5 ); // priority 5 so the bar prints above the Homepage Content top block (which hooks loop_start at 10)

/**
 * Front-end behaviour: debounce, fetch live results, log the keyword.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="ts-ls-js">
	(function(){
		var box = document.querySelector('.ts-search');
		if (!box) return;
		var form    = box.querySelector('.ts-search-form');
		var input   = box.querySelector('.ts-search-input');
		var results = box.querySelector('.ts-search-results');
		var queryUrl = box.getAttribute('data-query');
		var logUrl   = box.getAttribute('data-log');
		var nonce    = box.getAttribute('data-nonce');
		var minChars = parseInt(box.getAttribute('data-min'), 10) || 2;
		var timer = null, logTimer = null, lastLogged = '';

		function esc(s){ return (s||'').replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

		function hide(){ results.classList.remove('is-open'); results.innerHTML=''; }

		function render(items){
			if (!items || !items.length){
				results.innerHTML = '<div class="ts-search-empty">No results found.</div>';
				results.classList.add('is-open');
				return;
			}
			var html = items.map(function(it){
				var thumb = it.thumb
					? '<img class="ts-search-thumb" src="'+esc(it.thumb)+'" alt="" loading="lazy">'
					: '<span class="ts-search-thumb ts-search-noimg"></span>';
				var type = it.type ? '<span class="ts-search-type">'+esc(it.type)+'</span>' : '';
				return '<a class="ts-search-item" href="'+esc(it.url)+'">'+thumb+
					'<span class="ts-search-t">'+esc(it.title)+'</span>'+type+'</a>';
			}).join('');
			results.innerHTML = html;
			results.classList.add('is-open');
		}

		function fetchResults(q){
			fetch(queryUrl + '?q=' + encodeURIComponent(q), { headers:{ 'Accept':'application/json' } })
				.then(function(r){ return r.json(); })
				.then(function(d){ render(d && d.items ? d.items : []); })
				.catch(hide);
		}

		function logKeyword(q){
			if (!q || q === lastLogged) return;
			lastLogged = q;
			try {
				fetch(logUrl, {
					method:'POST',
					headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify({ q: q }), keepalive:true
				});
			} catch(e){}
		}

		input.addEventListener('input', function(){
			var q = input.value.trim();
			clearTimeout(timer); clearTimeout(logTimer);
			if (q.length < minChars){ hide(); return; }
			timer = setTimeout(function(){ fetchResults(q); }, 220);
			// Log only the settled keyword, once it stops changing.
			logTimer = setTimeout(function(){ logKeyword(q); }, 900);
		});

		// Submitting (Enter / button) logs immediately and lets the native search run.
		form.addEventListener('submit', function(){
			var q = input.value.trim();
			if (q.length >= minChars) logKeyword(q);
		});

		document.addEventListener('click', function(e){
			if (!box.contains(e.target)) hide();
		});
		input.addEventListener('focus', function(){
			if (results.innerHTML.trim()) results.classList.add('is-open');
		});
	})();
	</script>
	<?php
} );

/* ==========================================================
 * 3. REST — live query + keyword logging
 * ========================================================== */
add_action( 'rest_api_init', function () {
	register_rest_route( 'ts-search/v1', '/query', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ts_ls_rest_query',
		'args'                => array( 'q' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
	) );
	register_rest_route( 'ts-search/v1', '/log', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ts_ls_rest_log',
	) );
} );

/**
 * Live search results.
 */
function ts_ls_rest_query( $request ) {
	$q = trim( (string) $request->get_param( 'q' ) );
	if ( '' === $q ) {
		return array( 'items' => array() );
	}

	$query = ts_ls_search_query( array(
		'post_type'           => ts_ls_post_types(),
		'post_status'         => 'publish',
		's'                   => $q,
		'posts_per_page'      => 8,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	) );

	$items = array();
	foreach ( $query->posts as $p ) {
		$type_obj = get_post_type_object( $p->post_type );
		$items[]  = array(
			'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $p ),
			'thumb' => has_post_thumbnail( $p ) ? get_the_post_thumbnail_url( $p, 'thumbnail' ) : '',
			'type'  => $type_obj ? $type_obj->labels->singular_name : '',
		);
	}
	wp_reset_postdata();

	return array( 'items' => $items );
}

/**
 * Count how many published posts a keyword matches (what the search page shows).
 *
 * @param string $q Keyword.
 * @return int
 */
function ts_ls_count_results( $q ) {
	$query = ts_ls_search_query( array(
		'post_type'           => ts_ls_post_types(),
		'post_status'         => 'publish',
		's'                   => $q,
		'posts_per_page'      => 1,
		'fields'              => 'ids',
		'no_found_rows'       => false,
		'ignore_sticky_posts' => true,
	) );
	$n = (int) $query->found_posts;
	// Relevanssi reports its total on found_posts, but fall back to the row count.
	if ( 0 === $n && ! empty( $query->posts ) ) {
		$n = count( $query->posts );
	}
	wp_reset_postdata();
	return $n;
}

/**
 * Log (upsert) a searched keyword.
 */
function ts_ls_rest_log( $request ) {
	global $wpdb;

	$raw = $request->get_param( 'q' );
	if ( null === $raw ) {
		$body = json_decode( $request->get_body(), true );
		$raw  = is_array( $body ) && isset( $body['q'] ) ? $body['q'] : '';
	}
	$kw = sanitize_text_field( (string) $raw );
	$kw = trim( preg_replace( '/\s+/', ' ', $kw ) );

	$s = ts_ls_get_settings();
	if ( mb_strlen( $kw ) < (int) $s['min_chars'] ) {
		return array( 'ok' => false );
	}
	if ( mb_strlen( $kw ) > 191 ) {
		$kw = mb_substr( $kw, 0, 191 );
	}

	// Rate-limit: one count per keyword per visitor per 10 minutes.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'ts_ls_' . md5( strtolower( $kw ) . '|' . $ip );
	if ( get_transient( $key ) ) {
		return array( 'ok' => true, 'deduped' => true );
	}
	set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

	$table   = ts_ls_table();
	$now     = current_time( 'mysql' );
	$results = ts_ls_count_results( $kw );

	// Atomic upsert keyed on the UNIQUE keyword column. The live result count is
	// refreshed each time so an old "no results" keyword clears once you publish.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (keyword, hits, written, results, last_searched, created_at)
			 VALUES (%s, 1, 0, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, results = VALUES(results), last_searched = VALUES(last_searched)",
			$kw,
			$results,
			$now,
			$now
		)
	);

	return array( 'ok' => true, 'results' => $results );
}

/**
 * Number of searched keywords that returned no results and are not yet handled.
 * Used for the admin menu notification bubble.
 *
 * @return int
 */
function ts_ls_unresolved_count() {
	global $wpdb;
	$table = ts_ls_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE results = 0 AND written = 0" );
}

/* ==========================================================
 * 4. ADMIN — keyword log with filters + "written" flag
 * ========================================================== */
add_action( 'admin_menu', function () {
	// Notification bubble: number of no-result keywords still needing an article.
	$unresolved = ts_ls_unresolved_count();
	$bubble     = $unresolved
		? ' <span class="update-plugins count-' . (int) $unresolved . '"><span class="plugin-count">' . number_format_i18n( $unresolved ) . '</span></span>'
		: '';

	add_menu_page(
		'Search Keywords',
		'Search Keywords' . $bubble,
		'manage_options',
		'ts-search-keywords',
		'ts_ls_admin_page',
		'dashicons-search',
		58
	);
	add_submenu_page(
		'ts-search-keywords',
		'Search Settings',
		'Settings',
		'manage_options',
		'ts-search-settings',
		'ts_ls_settings_page'
	);
} );

/**
 * Handle admin actions (mark written / delete).
 */
add_action( 'admin_init', function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Save "written" marks for the current page.
	if ( isset( $_POST['ts_ls_save_marks'] ) && check_admin_referer( 'ts_ls_marks' ) ) {
		global $wpdb;
		$table    = ts_ls_table();
		$page_ids = isset( $_POST['page_ids'] ) ? array_map( 'absint', (array) $_POST['page_ids'] ) : array();
		$written  = isset( $_POST['written_ids'] ) ? array_map( 'absint', (array) $_POST['written_ids'] ) : array();
		foreach ( $page_ids as $id ) {
			$val = in_array( $id, $written, true ) ? 1 : 0;
			$wpdb->update( $table, array( 'written' => $val ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		}
		set_transient( 'ts_ls_admin_notice', 'Saved.', 30 );
		wp_safe_redirect( ts_ls_admin_redirect() );
		exit;
	}

	// Recompute the result count for every stored keyword (fixes rows logged
	// before the counter existed, or after adding new content).
	if ( isset( $_POST['ts_ls_recheck'] ) && check_admin_referer( 'ts_ls_recheck' ) ) {
		global $wpdb;
		$table = ts_ls_table();
		$rows  = $wpdb->get_results( "SELECT id, keyword FROM {$table}" );
		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'results' => ts_ls_count_results( $row->keyword ) ),
				array( 'id' => (int) $row->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
		set_transient( 'ts_ls_admin_notice', 'Result counts rechecked for all keywords.', 30 );
		wp_safe_redirect( ts_ls_admin_redirect() );
		exit;
	}

	// Delete a keyword.
	if ( isset( $_GET['ts_ls_delete'] ) && check_admin_referer( 'ts_ls_delete_' . (int) $_GET['ts_ls_delete'] ) ) {
		global $wpdb;
		$wpdb->delete( ts_ls_table(), array( 'id' => (int) $_GET['ts_ls_delete'] ), array( '%d' ) );
		set_transient( 'ts_ls_admin_notice', 'Keyword deleted.', 30 );
		wp_safe_redirect( ts_ls_admin_redirect() );
		exit;
	}
} );

/**
 * Rebuild the admin URL preserving the current filters.
 *
 * @return string
 */
function ts_ls_admin_redirect() {
	$args = array( 'page' => 'ts-search-keywords' );
	foreach ( array( 'status', 's', 'orderby', 'paged' ) as $k ) {
		if ( isset( $_REQUEST[ $k ] ) && '' !== $_REQUEST[ $k ] ) {
			$args[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
		}
	}
	return admin_url( 'admin.php?' . http_build_query( $args ) );
}

/**
 * The keyword log screen.
 */
function ts_ls_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = ts_ls_table();

	if ( $notice = get_transient( 'ts_ls_admin_notice' ) ) {
		delete_transient( 'ts_ls_admin_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	// --- Filters ---
	$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'hits';
	$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$per     = 50;
	$offset  = ( $paged - 1 ) * $per;

	$where  = '1=1';
	$params = array();
	if ( 'written' === $status ) {
		$where .= ' AND written = 1';
	} elseif ( 'pending' === $status ) {
		$where .= ' AND written = 0';
	} elseif ( 'noresults' === $status ) {
		$where .= ' AND results = 0';
	}
	if ( '' !== $search ) {
		$where   .= ' AND keyword LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	$order_sql = 'hits DESC';
	if ( 'recent' === $orderby ) {
		$order_sql = 'last_searched DESC';
	} elseif ( 'keyword' === $orderby ) {
		$order_sql = 'keyword ASC';
	}

	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
	$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

	$list_sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
	$list_params = array_merge( $params, array( $per, $offset ) );
	$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

	// Totals for the status tabs.
	$n_all       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$n_written   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE written = 1" );
	$n_pending   = $n_all - $n_written;
	$n_noresults = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE results = 0" );
	$n_unresolved = ts_ls_unresolved_count();

	$base = admin_url( 'admin.php?page=ts-search-keywords' );
	?>
	<div class="wrap">
		<h1>Search Keywords</h1>
		<p>Every search visitors run is recorded here and grouped by keyword, along with how many posts it matched. Tick <strong>Article written</strong> once you have published a post targeting that keyword, then Save.</p>

		<?php if ( $n_unresolved > 0 ) : ?>
			<div class="notice notice-warning" style="border-left-color:#e8394c;">
				<p>
					<strong><?php echo esc_html( number_format_i18n( $n_unresolved ) ); ?></strong>
					searched <?php echo 1 === $n_unresolved ? 'keyword' : 'keywords'; ?> returned
					<strong>no results</strong> and <?php echo 1 === $n_unresolved ? 'has' : 'have'; ?> no article yet &mdash;
					a content opportunity.
					<a href="<?php echo esc_url( add_query_arg( 'status', 'noresults', $base ) ); ?>">Review them &rarr;</a>
				</p>
			</div>
		<?php endif; ?>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base ) ); ?>" class="<?php echo 'all' === $status ? 'current' : ''; ?>">All <span class="count">(<?php echo esc_html( $n_all ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'noresults', $base ) ); ?>" class="<?php echo 'noresults' === $status ? 'current' : ''; ?>" style="color:#b42318;">No results <span class="count">(<?php echo esc_html( $n_noresults ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'pending', $base ) ); ?>" class="<?php echo 'pending' === $status ? 'current' : ''; ?>">Not written <span class="count">(<?php echo esc_html( $n_pending ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'written', $base ) ); ?>" class="<?php echo 'written' === $status ? 'current' : ''; ?>">Written <span class="count">(<?php echo esc_html( $n_written ); ?>)</span></a></li>
		</ul>

		<form method="get" style="margin:8px 0 14px;">
			<input type="hidden" name="page" value="ts-search-keywords">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Filter keywords…" style="min-width:240px;">
			<select name="orderby">
				<option value="hits"    <?php selected( 'hits', $orderby ); ?>>Most searched</option>
				<option value="recent"  <?php selected( 'recent', $orderby ); ?>>Most recent</option>
				<option value="keyword" <?php selected( 'keyword', $orderby ); ?>>A → Z</option>
			</select>
			<button class="button">Filter</button>
		</form>

		<form method="post" action="<?php echo esc_url( ts_ls_admin_redirect() ); ?>" style="display:inline-block;margin:0 0 14px;">
			<?php wp_nonce_field( 'ts_ls_recheck' ); ?>
			<input type="hidden" name="ts_ls_recheck" value="1">
			<button class="button" title="Recompute how many posts each keyword matches (use after adding content or updating this plugin)">↻ Recheck result counts</button>
		</form>

		<form method="post" action="<?php echo esc_url( ts_ls_admin_redirect() ); ?>">
			<?php wp_nonce_field( 'ts_ls_marks' ); ?>
			<input type="hidden" name="ts_ls_save_marks" value="1">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:120px;">Article written</th>
						<th>Keyword</th>
						<th style="width:130px;">Results</th>
						<th style="width:110px;">Searches</th>
						<th style="width:180px;">Last searched</th>
						<th style="width:120px;">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6">No keywords yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td>
									<input type="hidden" name="page_ids[]" value="<?php echo (int) $r->id; ?>">
									<label class="ts-ls-mark">
										<input type="checkbox" name="written_ids[]" value="<?php echo (int) $r->id; ?>" <?php checked( 1, (int) $r->written ); ?>>
										<?php if ( (int) $r->written ) : ?>
											<span class="ts-ls-badge is-yes">Written</span>
										<?php else : ?>
											<span class="ts-ls-badge is-no">Pending</span>
										<?php endif; ?>
									</label>
								</td>
								<td>
									<strong><?php echo esc_html( $r->keyword ); ?></strong>
									<a href="<?php echo esc_url( home_url( '/?s=' . rawurlencode( $r->keyword ) ) ); ?>" target="_blank" class="dashicons dashicons-external" style="text-decoration:none;" title="See results on site"></a>
								</td>
								<td>
									<?php if ( 0 === (int) $r->results ) : ?>
										<span class="ts-ls-badge is-no">No results</span>
									<?php else : ?>
										<?php echo esc_html( number_format_i18n( (int) $r->results ) ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $r->hits ) ); ?></td>
								<td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $r->last_searched ) ); ?></td>
								<td>
									<a class="submitdelete" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ts_ls_delete', (int) $r->id, ts_ls_admin_redirect() ), 'ts_ls_delete_' . (int) $r->id ) ); ?>" onclick="return confirm('Delete this keyword?');">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<button class="button button-primary">Save marks</button>
				<span class="description" style="margin-left:8px;">Ticks on this page are saved; unticked rows on this page are marked pending.</span>
			</p>
		</form>

		<?php
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			$links = paginate_links( array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			) );
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
		?>
	</div>
	<style>
		.ts-ls-mark{display:inline-flex;align-items:center;gap:8px;cursor:pointer;}
		.ts-ls-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;}
		.ts-ls-badge.is-yes{background:#ecfdf3;color:#15803d;border:1px solid #d1fadf;}
		.ts-ls-badge.is-no{background:#fef3f2;color:#b42318;border:1px solid #fecdca;}
	</style>
	<?php
}

/**
 * Settings sub-page (placeholder text, auto-home toggle).
 */
function ts_ls_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ts_ls_save_settings'] ) && check_admin_referer( 'ts_ls_settings' ) ) {
		$s                = ts_ls_get_settings();
		$s['placeholder'] = isset( $_POST['placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['placeholder'] ) ) : $s['placeholder'];
		$s['auto_home']   = isset( $_POST['auto_home'] ) ? 1 : 0;
		$s['min_chars']   = max( 1, min( 5, (int) ( $_POST['min_chars'] ?? 2 ) ) );
		update_option( 'ts_ls_settings', $s );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}
	$s = ts_ls_get_settings();
	?>
	<div class="wrap">
		<h1>Search Settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'ts_ls_settings' ); ?>
			<input type="hidden" name="ts_ls_save_settings" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="placeholder">Placeholder text</label></th>
					<td><input name="placeholder" id="placeholder" type="text" class="regular-text" value="<?php echo esc_attr( $s['placeholder'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Homepage</th>
					<td><label><input type="checkbox" name="auto_home" value="1" <?php checked( 1, (int) $s['auto_home'] ); ?>> Automatically show the search bar at the top of the homepage</label>
						<p class="description">You can also place it anywhere with the shortcode <code>[live_search]</code> (e.g. inside your Homepage Content top block).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="min_chars">Minimum characters</label></th>
					<td><input name="min_chars" id="min_chars" type="number" min="1" max="5" value="<?php echo esc_attr( $s['min_chars'] ); ?>" class="small-text">
						<p class="description">How many characters before live search starts and a keyword is logged.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
	</div>
	<?php
}

/* ==========================================================
 * 5. STYLES (front-end bar)
 * ========================================================== */
function ts_ls_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-ls-styles">
	.ts-search{position:relative;max-width:860px;margin:0 auto 22px;width:100%;
		grid-column:1 / -1;flex-basis:100%;box-sizing:border-box;}
	.ts-search-form{display:flex;align-items:center;gap:8px;background:#fff;
		border:1px solid #e5e7eb;border-radius:12px;padding:6px 6px 6px 22px;
		box-shadow:0 1px 3px rgba(16,24,40,.05);}
	/* Neutralise any theme styling on the inner field so the whole bar is one clean white pill. */
	.ts-search .ts-search-input{flex:1 1 auto;min-width:0;border:0 !important;background:transparent !important;
		box-shadow:none !important;font-size:17px;line-height:1.4;padding:16px 8px;color:#333;outline:none;margin:0;}
	.ts-search .ts-search-input:focus{border:0 !important;box-shadow:none !important;outline:none;}
	.ts-search-input::placeholder{color:#9aa0a6;}
	.ts-search-btn{flex:0 0 auto;width:52px;height:52px;border:0;border-radius:50%;
		background:#111;color:#fff;cursor:pointer;display:inline-flex;align-items:center;
		justify-content:center;transition:background .18s ease;}
	.ts-search-btn:hover{background:#e8394c;}
	.ts-search-btn svg{width:20px;height:20px;}
	.ts-search-results{position:absolute;left:0;right:0;top:calc(100% + 8px);
		background:#fff;border:1px solid #e8e8ea;border-radius:12px;overflow:hidden;
		box-shadow:0 12px 34px rgba(16,24,40,.14);z-index:60;display:none;
		max-height:70vh;overflow-y:auto;}
	.ts-search-results.is-open{display:block;}
	.ts-search-item{display:flex;align-items:center;gap:12px;padding:10px 16px;
		text-decoration:none;color:#101828;border-bottom:1px solid #f0f0f2;}
	.ts-search-item:last-child{border-bottom:0;}
	.ts-search-item:hover{background:#fdf2f4;}
	.ts-search-thumb{width:42px;height:42px;flex:0 0 auto;border-radius:8px;object-fit:cover;background:#f1f1f3;display:block;}
	.ts-search-noimg{background:#eceef1;}
	.ts-search-t{flex:1 1 auto;min-width:0;font-weight:600;font-size:14px;
		white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
	.ts-search-type{flex:0 0 auto;font-size:11px;font-weight:700;color:#e8394c;
		background:#fdecef;border-radius:999px;padding:3px 9px;text-transform:uppercase;letter-spacing:.3px;}
	.ts-search-empty{padding:16px;color:#667085;text-align:center;font-size:14px;}
	@media (max-width:560px){
		.ts-search-form{padding:5px 5px 5px 16px;}
		.ts-search-input{font-size:16px;padding:13px 6px;}
		.ts-search-btn{width:46px;height:46px;}
	}
	</style>
	<?php
}
