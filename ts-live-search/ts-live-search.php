<?php
/**
 * Plugin Name: TS Live Search
 * Description: Adds a live search bar to the homepage so visitors can instantly find posts as they type.
 *              Every search term is logged in the admin panel where you can mark which keywords already
 *              have an article written, and filter by written / not-yet-written.
 *              Also available anywhere via the [ts_live_search] shortcode.
 * Version: 1.0.0
 * Author: NSPVault
 * Text Domain: ts-live-search
 *
 * @package TS_Live_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'TS_LS_VERSION', '1.0.0' );
define( 'TS_LS_FILE', __FILE__ );
define( 'TS_LS_OPTION', 'ts_live_search' );
define( 'TS_LS_REST_NS', 'tsls/v1' );

/* ==========================================================
 * 1. SETTINGS
 * ========================================================== */

/**
 * Default settings.
 *
 * @return array
 */
function ts_ls_defaults() {
	return array(
		'placeholder'   => 'Search Nintendo Switch ROMs...',
		'post_types'    => array( 'post' ), // Which post types the live search looks in.
		'min_chars'     => 2,               // Start searching after this many characters.
		'max_results'   => 8,               // Results shown in the dropdown.
		'auto_homepage' => 1,               // Auto-insert on the homepage top.
		'log_searches'  => 1,               // Record search terms for the admin panel.
	);
}

/**
 * Merged settings.
 *
 * @return array
 */
function ts_ls_get() {
	$saved = get_option( TS_LS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( ts_ls_defaults(), $saved );
}

/**
 * Post types the live search is allowed to look in (validated against real types).
 *
 * @return string[]
 */
function ts_ls_search_post_types() {
	$s     = ts_ls_get();
	$valid = get_post_types( array( 'public' => true ), 'names' );
	unset( $valid['attachment'] );

	$chosen = array_values( array_intersect( (array) $s['post_types'], array_values( $valid ) ) );
	if ( empty( $chosen ) ) {
		$chosen = array( 'post' );
	}
	return $chosen;
}

/* ==========================================================
 * 2. DATABASE — searched-keyword store
 * ========================================================== */

/**
 * Full keyword table name (with prefix).
 *
 * @return string
 */
function ts_ls_table() {
	global $wpdb;
	return $wpdb->prefix . 'ts_search_keywords';
}

/**
 * Create the keyword table on activation.
 */
function ts_ls_create_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$table           = ts_ls_table();

	// dbDelta is whitespace-sensitive (two spaces after PRIMARY KEY).
	$sql = "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		keyword VARCHAR(191) NOT NULL,
		searches BIGINT UNSIGNED NOT NULL DEFAULT 0,
		last_results INT UNSIGNED NOT NULL DEFAULT 0,
		is_written TINYINT(1) NOT NULL DEFAULT 0,
		written_at DATETIME NULL DEFAULT NULL,
		created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY keyword (keyword),
		KEY is_written (is_written),
		KEY searches (searches)
	) $charset_collate;";

	dbDelta( $sql );
}

register_activation_hook( __FILE__, 'ts_ls_create_table' );

/**
 * Make sure the table exists (covers installs where the plugin folder was just
 * copied in without a fresh activation).
 */
function ts_ls_maybe_upgrade() {
	if ( get_option( 'ts_ls_db_version' ) === TS_LS_VERSION ) {
		return;
	}
	ts_ls_create_table();
	update_option( 'ts_ls_db_version', TS_LS_VERSION );
}
add_action( 'admin_init', 'ts_ls_maybe_upgrade' );

/**
 * Normalize a raw search string into the stored keyword.
 *
 * Trims, collapses inner whitespace, lower-cases and clamps the length so the
 * same phrase is always counted under one row regardless of casing/spacing.
 *
 * @param string $raw Raw query.
 * @return string Normalized keyword ('' when nothing usable remains).
 */
function ts_ls_normalize_keyword( $raw ) {
	$raw = wp_strip_all_tags( (string) $raw );
	$raw = preg_replace( '/\s+/u', ' ', $raw );
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}
	// Lower-case in a multibyte-safe way when possible.
	$raw = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );

	// Clamp to the column width (191 chars for utf8mb4 unique keys).
	if ( function_exists( 'mb_substr' ) ) {
		$raw = mb_substr( $raw, 0, 191, 'UTF-8' );
	} else {
		$raw = substr( $raw, 0, 191 );
	}
	return $raw;
}

/**
 * Record one search: upsert the keyword row and bump its counter.
 *
 * @param string $raw     Raw query from the visitor.
 * @param int    $results Number of results the query returned.
 * @return bool True when something was stored.
 */
function ts_ls_log_keyword( $raw, $results = 0 ) {
	$s = ts_ls_get();
	if ( empty( $s['log_searches'] ) ) {
		return false;
	}

	$keyword = ts_ls_normalize_keyword( $raw );
	if ( '' === $keyword || strlen( $keyword ) < 2 ) {
		return false;
	}

	global $wpdb;
	$table   = ts_ls_table();
	$now     = current_time( 'mysql' );
	$results = max( 0, (int) $results );

	// Upsert: first search creates the row, later searches increment it.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"INSERT INTO {$table} ( keyword, searches, last_results, created_at, updated_at )
			VALUES ( %s, 1, %d, %s, %s )
			ON DUPLICATE KEY UPDATE
				searches     = searches + 1,
				last_results = VALUES(last_results),
				updated_at   = VALUES(updated_at)",
			$keyword,
			$results,
			$now,
			$now
		)
	);

	return true;
}

/**
 * Set / clear the "article written" flag for a keyword row.
 *
 * @param int  $id      Row ID.
 * @param bool $written Whether the article exists.
 */
function ts_ls_set_written( $id, $written ) {
	global $wpdb;
	$written = $written ? 1 : 0;

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		ts_ls_table(),
		array(
			'is_written' => $written,
			'written_at' => $written ? current_time( 'mysql' ) : null,
		),
		array( 'id' => absint( $id ) ),
		array( '%d', '%s' ), // A null value is rendered as SQL NULL regardless of format.
		array( '%d' )
	);
}

/**
 * Delete a keyword row.
 *
 * @param int $id Row ID.
 * @return bool
 */
function ts_ls_delete_keyword( $id ) {
	global $wpdb;
	return (bool) $wpdb->delete( ts_ls_table(), array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/* ==========================================================
 * 3. REST ENDPOINTS — live results + keyword logging
 * ========================================================== */

add_action( 'rest_api_init', function () {
	// Live search results as the visitor types.
	register_rest_route(
		TS_LS_REST_NS,
		'/search',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'ts_ls_rest_search',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// Record a search term (fired when the visitor settles on a query).
	register_rest_route(
		TS_LS_REST_NS,
		'/log',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'ts_ls_rest_log',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
} );

/**
 * Run the live search and return a compact list of matching posts.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ts_ls_rest_search( $request ) {
	$s = ts_ls_get();
	$q = trim( (string) $request->get_param( 'q' ) );

	$min = max( 1, (int) $s['min_chars'] );
	if ( strlen( $q ) < $min ) {
		return rest_ensure_response(
			array(
				'query'   => $q,
				'count'   => 0,
				'results' => array(),
			)
		);
	}

	$query = new WP_Query(
		array(
			's'                   => $q,
			'post_type'           => ts_ls_search_post_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 20, (int) $s['max_results'] ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'relevance',
		)
	);

	$results = array();
	foreach ( $query->posts as $post ) {
		$results[] = array(
			'id'    => $post->ID,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post ),
			'thumb' => get_the_post_thumbnail_url( $post, 'thumbnail' ) ?: '',
		);
	}

	return rest_ensure_response(
		array(
			'query'   => $q,
			'count'   => count( $results ),
			'results' => $results,
		)
	);
}

/**
 * Record a search term. Called by the front-end once a query settles.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ts_ls_rest_log( $request ) {
	$q       = (string) $request->get_param( 'q' );
	$results = (int) $request->get_param( 'results' );

	$stored = ts_ls_log_keyword( $q, $results );

	return rest_ensure_response( array( 'stored' => (bool) $stored ) );
}

/* ==========================================================
 * 4. FRONT-END — the search bar
 * ========================================================== */

/**
 * Build the search-bar HTML.
 *
 * @return string
 */
function ts_ls_bar_html() {
	$s           = ts_ls_get();
	$placeholder = $s['placeholder'];

	ob_start();
	?>
	<div class="ts-ls" data-min="<?php echo esc_attr( (int) $s['min_chars'] ); ?>">
		<form class="ts-ls__form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
			<input
				type="search"
				class="ts-ls__input"
				name="s"
				value=""
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				autocomplete="off"
				aria-label="<?php echo esc_attr( $placeholder ); ?>">
			<button type="submit" class="ts-ls__btn" aria-label="<?php esc_attr_e( 'Search', 'ts-live-search' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
					<circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"></circle>
					<line x1="16.5" y1="16.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
				</svg>
			</button>
		</form>
		<div class="ts-ls__results" role="listbox" hidden></div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Print the shared CSS + JS once per request.
 */
function ts_ls_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$rest = esc_url_raw( rest_url( TS_LS_REST_NS ) );
	?>
	<style id="ts-ls-styles">
	.ts-ls{position:relative;max-width:860px;margin:22px auto;width:100%;box-sizing:border-box;}
	.ts-ls *,.ts-ls *::before,.ts-ls *::after{box-sizing:border-box;}
	.ts-ls__form{display:flex;align-items:center;gap:10px;background:#f1f1f1;border:1px solid #e6e6e8;border-radius:14px;padding:10px 12px;transition:box-shadow .15s,border-color .15s;}
	.ts-ls__form:focus-within{border-color:#d0d0d4;box-shadow:0 2px 10px rgba(16,24,40,.08);}
	.ts-ls__input{flex:1 1 auto;min-width:0;border:0;background:transparent;font-size:17px;color:#1a1a1a;padding:8px 6px 8px 10px;outline:none;}
	.ts-ls__input::placeholder{color:#8b8b93;}
	.ts-ls__input::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none;}
	.ts-ls__btn{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:0;border-radius:50%;background:#111;color:#fff;cursor:pointer;transition:background .15s,transform .05s;}
	.ts-ls__btn:hover{background:#000;}
	.ts-ls__btn:active{transform:scale(.96);}
	.ts-ls__results{position:absolute;left:0;right:0;top:calc(100% + 8px);z-index:60;background:#fff;border:1px solid #e6e6e8;border-radius:12px;box-shadow:0 12px 30px rgba(16,24,40,.14);overflow:hidden;max-height:70vh;overflow-y:auto;}
	.ts-ls__results[hidden]{display:none;}
	.ts-ls__item{display:flex;align-items:center;gap:12px;padding:10px 14px;text-decoration:none;color:#1a1a1a;border-bottom:1px solid #f0f0f2;}
	.ts-ls__item:last-child{border-bottom:0;}
	.ts-ls__item:hover,.ts-ls__item.is-active{background:#f7f7f8;}
	.ts-ls__thumb{flex:0 0 auto;width:42px;height:42px;border-radius:8px;object-fit:cover;background:#ececed;}
	.ts-ls__title{flex:1 1 auto;font-size:15px;font-weight:600;line-height:1.35;}
	.ts-ls__msg{padding:14px;color:#6b7280;font-size:14px;text-align:center;}
	@media (max-width:600px){
		.ts-ls__input{font-size:16px;}
		.ts-ls__btn{width:40px;height:40px;}
	}
	</style>
	<script id="ts-ls-script">
	(function(){
		var REST = <?php echo wp_json_encode( $rest ); ?>;
		function initBar(root){
			if(root.dataset.tsLsReady){return;} root.dataset.tsLsReady='1';
			var form=root.querySelector('.ts-ls__form');
			var input=root.querySelector('.ts-ls__input');
			var box=root.querySelector('.ts-ls__results');
			var min=parseInt(root.getAttribute('data-min'),10)||2;
			var timer=null, logTimer=null, lastLogged='', lastCount=0, active=-1, items=[];

			function hide(){ box.hidden=true; box.innerHTML=''; active=-1; items=[]; }
			function esc(s){ return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

			function render(data){
				var res=(data&&data.results)||[];
				lastCount=(data&&typeof data.count==='number')?data.count:res.length;
				if(!res.length){
					box.innerHTML='<div class="ts-ls__msg">No results found.</div>';
					box.hidden=false; items=[]; active=-1; return;
				}
				var html='';
				for(var i=0;i<res.length;i++){
					var r=res[i];
					var thumb=r.thumb?'<img class="ts-ls__thumb" src="'+esc(r.thumb)+'" alt="" loading="lazy">':'<span class="ts-ls__thumb"></span>';
					html+='<a class="ts-ls__item" role="option" href="'+esc(r.url)+'">'+thumb+'<span class="ts-ls__title">'+esc(r.title)+'</span></a>';
				}
				box.innerHTML=html;
				box.hidden=false;
				items=box.querySelectorAll('.ts-ls__item');
				active=-1;
			}

			function search(q){
				fetch(REST+'/search?q='+encodeURIComponent(q),{headers:{'Accept':'application/json'}})
					.then(function(r){return r.json();})
					.then(function(d){ if(input.value.trim()===q){ render(d); scheduleLog(q); } })
					.catch(function(){});
			}

			function scheduleLog(q){
				// Log the settled query ~1.1s after typing stops, so we capture
				// intent without recording every keystroke prefix.
				clearTimeout(logTimer);
				logTimer=setTimeout(function(){
					var term=q.trim();
					if(term.length<min||term===lastLogged){return;}
					lastLogged=term;
					try{
						fetch(REST+'/log',{
							method:'POST',
							headers:{'Content-Type':'application/json'},
							body:JSON.stringify({q:term,results:lastCount}),
							keepalive:true
						});
					}catch(e){}
				},1100);
			}

			input.addEventListener('input',function(){
				var q=input.value.trim();
				clearTimeout(timer);
				if(q.length<min){ hide(); return; }
				timer=setTimeout(function(){ search(q); },220);
			});

			input.addEventListener('keydown',function(e){
				if(box.hidden||!items.length){return;}
				if(e.key==='ArrowDown'){ e.preventDefault(); active=(active+1)%items.length; paint(); }
				else if(e.key==='ArrowUp'){ e.preventDefault(); active=(active-1+items.length)%items.length; paint(); }
				else if(e.key==='Enter'&&active>-1){ e.preventDefault(); window.location.href=items[active].getAttribute('href'); }
				else if(e.key==='Escape'){ hide(); }
			});

			function paint(){
				for(var i=0;i<items.length;i++){ items[i].classList.toggle('is-active',i===active); }
				if(active>-1){ items[active].scrollIntoView({block:'nearest'}); }
			}

			form.addEventListener('submit',function(){
				// A full-search submit is a strong intent — log it immediately.
				var term=input.value.trim();
				if(term.length<min||term===lastLogged){return;}
				lastLogged=term;
				clearTimeout(logTimer);
				try{
					fetch(REST+'/log',{
						method:'POST',
						headers:{'Content-Type':'application/json'},
						body:JSON.stringify({q:term,results:lastCount}),
						keepalive:true
					});
				}catch(e){}
			});

			document.addEventListener('click',function(e){ if(!root.contains(e.target)){ hide(); } });
			input.addEventListener('focus',function(){ if(input.value.trim().length>=min&&box.innerHTML){ box.hidden=false; } });
		}
		function boot(){ var bars=document.querySelectorAll('.ts-ls'); for(var i=0;i<bars.length;i++){ initBar(bars[i]); } }
		if(document.readyState!=='loading'){ boot(); } else { document.addEventListener('DOMContentLoaded',boot); }
	})();
	</script>
	<?php
}

/**
 * Shortcode: [ts_live_search]
 *
 * @return string
 */
function ts_ls_shortcode() {
	ts_ls_assets();
	return ts_ls_bar_html();
}
add_shortcode( 'ts_live_search', 'ts_ls_shortcode' );

/**
 * Should the bar auto-insert on this request (homepage only)?
 *
 * @return bool
 */
function ts_ls_should_auto_show() {
	if ( is_admin() || ! is_main_query() ) {
		return false;
	}
	$s = ts_ls_get();
	if ( empty( $s['auto_homepage'] ) ) {
		return false;
	}
	// Front page (whether it shows latest posts or a static page), first page only.
	if ( ! is_front_page() || is_paged() ) {
		return false;
	}
	return true;
}

/**
 * Echo the bar once per request (front-end auto-insert).
 */
function ts_ls_print_bar() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	ts_ls_assets();
	echo '<div class="ts-ls-wrap">' . ts_ls_bar_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- markup is built from escaped values.
}

/* --- Astra hooks (preferred when the theme provides them) --- */
add_action( 'astra_primary_content_top', function () {
	if ( ts_ls_should_auto_show() ) {
		ts_ls_print_bar();
	}
}, 5 );

/* --- Generic loop fallback (works on most themes) --- */
add_action( 'loop_start', function ( $query ) {
	if ( ! $query->is_main_query() ) {
		return;
	}
	// Only fall back to the loop hook if Astra's hook is not in play.
	if ( did_action( 'astra_primary_content_top' ) ) {
		return;
	}
	if ( ts_ls_should_auto_show() ) {
		ts_ls_print_bar();
	}
}, 5 );

/* ==========================================================
 * 5. ADMIN — searched-keyword panel
 * ========================================================== */

/**
 * Register the admin menu page.
 */
add_action( 'admin_menu', function () {
	add_menu_page(
		__( 'Search Keywords', 'ts-live-search' ),
		__( 'Search Keywords', 'ts-live-search' ),
		'manage_options',
		'ts-live-search',
		'ts_ls_admin_page',
		'dashicons-search',
		58
	);
} );

/**
 * Add a settings-style link on the Plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( TS_LS_FILE ), function ( $links ) {
	$url  = admin_url( 'admin.php?page=ts-live-search' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Keywords', 'ts-live-search' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
} );

/**
 * Current admin page URL with extra query args.
 *
 * @param array $args Extra args.
 * @return string
 */
function ts_ls_admin_url( $args = array() ) {
	$args = wp_parse_args( $args, array( 'page' => 'ts-live-search' ) );
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Handle admin actions (mark written, delete, save settings).
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_REQUEST['page'] ) || 'ts-live-search' !== $_REQUEST['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Toggle "article written".
	if ( isset( $_GET['ts_ls_action'], $_GET['id'] ) && in_array( $_GET['ts_ls_action'], array( 'written', 'unwritten' ), true ) ) {
		$id = absint( $_GET['id'] );
		check_admin_referer( 'ts_ls_written_' . $id );
		ts_ls_set_written( $id, 'written' === $_GET['ts_ls_action'] );
		ts_ls_admin_redirect( __( 'Keyword updated.', 'ts-live-search' ) );
	}

	// Delete one keyword.
	if ( isset( $_GET['ts_ls_action'], $_GET['id'] ) && 'delete' === $_GET['ts_ls_action'] ) {
		$id = absint( $_GET['id'] );
		check_admin_referer( 'ts_ls_delete_' . $id );
		ts_ls_delete_keyword( $id );
		ts_ls_admin_redirect( __( 'Keyword deleted.', 'ts-live-search' ) );
	}

	// Bulk action on selected keywords.
	if ( isset( $_POST['ts_ls_bulk'] ) && ! empty( $_POST['ids'] ) ) {
		check_admin_referer( 'ts_ls_bulk' );
		$bulk = sanitize_key( wp_unslash( $_POST['ts_ls_bulk'] ) );
		$ids  = array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) );
		foreach ( $ids as $id ) {
			if ( 'written' === $bulk ) {
				ts_ls_set_written( $id, true );
			} elseif ( 'unwritten' === $bulk ) {
				ts_ls_set_written( $id, false );
			} elseif ( 'delete' === $bulk ) {
				ts_ls_delete_keyword( $id );
			}
		}
		ts_ls_admin_redirect( __( 'Bulk action applied.', 'ts-live-search' ) );
	}

	// Save settings.
	if ( isset( $_POST['ts_ls_action'] ) && 'settings' === $_POST['ts_ls_action'] ) {
		check_admin_referer( 'ts_ls_settings' );

		$s = ts_ls_get();

		$s['placeholder']   = isset( $_POST['placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['placeholder'] ) ) : $s['placeholder'];
		$s['min_chars']     = isset( $_POST['min_chars'] ) ? max( 1, min( 10, absint( $_POST['min_chars'] ) ) ) : $s['min_chars'];
		$s['max_results']   = isset( $_POST['max_results'] ) ? max( 1, min( 20, absint( $_POST['max_results'] ) ) ) : $s['max_results'];
		$s['auto_homepage'] = isset( $_POST['auto_homepage'] ) ? 1 : 0;
		$s['log_searches']  = isset( $_POST['log_searches'] ) ? 1 : 0;

		$valid_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $valid_types['attachment'] );
		$chosen = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$chosen = array_values( array_intersect( $chosen, array_values( $valid_types ) ) );
		if ( empty( $chosen ) ) {
			$chosen = array( 'post' );
		}
		$s['post_types'] = $chosen;

		update_option( TS_LS_OPTION, $s );
		ts_ls_admin_redirect( __( 'Settings saved.', 'ts-live-search' ) );
	}
} );

/**
 * Redirect back to the admin page after an action, preserving filters.
 *
 * @param string $message Notice text.
 */
function ts_ls_admin_redirect( $message ) {
	set_transient( 'ts_ls_notice_' . get_current_user_id(), $message, 30 );

	$args = array( 'page' => 'ts-live-search' );
	foreach ( array( 's', 'status', 'orderby', 'order', 'paged' ) as $key ) {
		if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
			$args[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
		}
	}
	wp_safe_redirect( ts_ls_admin_url( $args ) );
	exit;
}

/**
 * Query keyword rows for the admin list.
 *
 * @param array $args Filter args.
 * @return object[]
 */
function ts_ls_get_rows( $args = array() ) {
	global $wpdb;
	$table = ts_ls_table();

	$args = wp_parse_args(
		$args,
		array(
			'search'   => '',
			'status'   => 'all', // all | written | pending
			'orderby'  => 'searches',
			'order'    => 'DESC',
			'per_page' => 25,
			'paged'    => 1,
		)
	);

	$allowed_orderby = array( 'keyword', 'searches', 'last_results', 'updated_at', 'created_at', 'is_written' );
	$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'searches';
	$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

	$per_page = max( 1, (int) $args['per_page'] );
	$paged    = max( 1, (int) $args['paged'] );
	$offset   = ( $paged - 1 ) * $per_page;

	list( $where, $params ) = ts_ls_build_where( $args['search'], $args['status'] );

	$params[] = $per_page;
	$params[] = $offset;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where/$orderby/$order are whitelisted above.
	$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
}

/**
 * Build the shared WHERE clause + params for search/status filters.
 *
 * @param string $search Search term.
 * @param string $status all | written | pending.
 * @return array [ string $where, array $params ]
 */
function ts_ls_build_where( $search, $status ) {
	global $wpdb;
	$clauses = array();
	$params  = array();

	if ( '' !== $search ) {
		$clauses[] = 'keyword LIKE %s';
		$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
	}
	if ( 'written' === $status ) {
		$clauses[] = 'is_written = 1';
	} elseif ( 'pending' === $status ) {
		$clauses[] = 'is_written = 0';
	}

	$where = $clauses ? ( 'WHERE ' . implode( ' AND ', $clauses ) ) : '';
	return array( $where, $params );
}

/**
 * Count keyword rows for a filter.
 *
 * @param string $search Search term.
 * @param string $status all | written | pending.
 * @return int
 */
function ts_ls_count_rows( $search = '', $status = 'all' ) {
	global $wpdb;
	$table = ts_ls_table();

	list( $where, $params ) = ts_ls_build_where( $search, $status );

	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $params ) );
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
}

/**
 * Render the admin keyword panel.
 */
function ts_ls_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ts-live-search' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
	$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'searches';
	$order    = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC';
	$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! in_array( $status, array( 'all', 'written', 'pending' ), true ) ) {
		$status = 'all';
	}

	$per_page = 25;
	$total    = ts_ls_count_rows( $search, $status );
	$rows     = ts_ls_get_rows(
		array(
			'search'   => $search,
			'status'   => $status,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'paged'    => $paged,
		)
	);
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );

	$count_all     = ts_ls_count_rows( $search, 'all' );
	$count_written = ts_ls_count_rows( $search, 'written' );
	$count_pending = ts_ls_count_rows( $search, 'pending' );

	$notice = get_transient( 'ts_ls_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'ts_ls_notice_' . get_current_user_id() );
	}

	$s          = ts_ls_get();
	$base_args  = array( 'page' => 'ts-live-search' );
	$keep_args  = array();
	if ( '' !== $search ) {
		$keep_args['s'] = $search;
	}
	if ( 'all' !== $status ) {
		$keep_args['status'] = $status;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Search Keywords', 'ts-live-search' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Every term visitors type into the homepage live search is recorded here. Mark a keyword as “article written” once you have published a post for it, and use the filters to see what still needs content.', 'ts-live-search' ); ?>
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<ul class="subsubsub">
			<?php
			$tabs = array(
				'all'     => array( __( 'All', 'ts-live-search' ), $count_all ),
				'pending' => array( __( 'Not written', 'ts-live-search' ), $count_pending ),
				'written' => array( __( 'Written', 'ts-live-search' ), $count_written ),
			);
			$i = 0;
			foreach ( $tabs as $key => $tab ) :
				$i++;
				$url = ts_ls_admin_url(
					array_merge(
						$base_args,
						'all' === $key ? array() : array( 'status' => $key ),
						'' !== $search ? array( 's' => $search ) : array()
					)
				);
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
						<?php echo esc_html( $tab[0] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $tab[1] ) ); ?>)</span>
					</a><?php echo $i < count( $tabs ) ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" style="margin:8px 0 4px;">
			<input type="hidden" name="page" value="ts-live-search">
			<?php if ( 'all' !== $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<?php endif; ?>
			<p class="search-box" style="float:none;">
				<label class="screen-reader-text" for="ts-ls-search"><?php esc_html_e( 'Search keywords', 'ts-live-search' ); ?></label>
				<input type="search" id="ts-ls-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter keywords…', 'ts-live-search' ); ?>">
				<?php submit_button( __( 'Search', 'ts-live-search' ), '', '', false ); ?>
			</p>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'ts_ls_bulk' ); ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<select name="ts_ls_bulk">
						<option value=""><?php esc_html_e( 'Bulk actions', 'ts-live-search' ); ?></option>
						<option value="written"><?php esc_html_e( 'Mark as written', 'ts-live-search' ); ?></option>
						<option value="unwritten"><?php esc_html_e( 'Mark as not written', 'ts-live-search' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'ts-live-search' ); ?></option>
					</select>
					<?php submit_button( __( 'Apply', 'ts-live-search' ), 'action', '', false ); ?>
				</div>
			</div>

			<?php
			// Helper to build a sortable column header link.
			$sort_link = function ( $col, $label ) use ( $orderby, $order, $status, $search ) {
				$next = ( $orderby === $col && 'ASC' === $order ) ? 'desc' : 'asc';
				$url  = ts_ls_admin_url(
					array_merge(
						array( 'page' => 'ts-live-search', 'orderby' => $col, 'order' => $next ),
						'all' !== $status ? array( 'status' => $status ) : array(),
						'' !== $search ? array( 's' => $search ) : array()
					)
				);
				$arrow = '';
				if ( $orderby === $col ) {
					$arrow = 'ASC' === $order ? ' ▲' : ' ▼';
				}
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . esc_html( $arrow ) . '</a>';
			};
			?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="var c=this.checked;document.querySelectorAll('.ts-ls-cb').forEach(function(x){x.checked=c;});"></td>
						<th><?php echo wp_kses_post( $sort_link( 'keyword', __( 'Keyword', 'ts-live-search' ) ) ); ?></th>
						<th style="width:110px;"><?php echo wp_kses_post( $sort_link( 'searches', __( 'Searches', 'ts-live-search' ) ) ); ?></th>
						<th style="width:110px;"><?php echo wp_kses_post( $sort_link( 'last_results', __( 'Results', 'ts-live-search' ) ) ); ?></th>
						<th style="width:140px;"><?php echo wp_kses_post( $sort_link( 'is_written', __( 'Article', 'ts-live-search' ) ) ); ?></th>
						<th style="width:170px;"><?php echo wp_kses_post( $sort_link( 'updated_at', __( 'Last searched', 'ts-live-search' ) ) ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Actions', 'ts-live-search' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No search keywords recorded yet.', 'ts-live-search' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$toggle_action = $row->is_written ? 'unwritten' : 'written';
							$toggle_url    = wp_nonce_url(
								ts_ls_admin_url( array_merge( array( 'ts_ls_action' => $toggle_action, 'id' => $row->id ), $keep_args ) ),
								'ts_ls_written_' . $row->id
							);
							$delete_url = wp_nonce_url(
								ts_ls_admin_url( array_merge( array( 'ts_ls_action' => 'delete', 'id' => $row->id ), $keep_args ) ),
								'ts_ls_delete_' . $row->id
							);
							$search_url = add_query_arg( 's', rawurlencode( $row->keyword ), home_url( '/' ) );
							?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" class="ts-ls-cb" name="ids[]" value="<?php echo esc_attr( $row->id ); ?>"></th>
								<td>
									<strong><a href="<?php echo esc_url( $search_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->keyword ); ?></a></strong>
								</td>
								<td><?php echo esc_html( number_format_i18n( $row->searches ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->last_results ) ); ?></td>
								<td>
									<?php if ( $row->is_written ) : ?>
										<span style="display:inline-flex;align-items:center;gap:6px;color:#128a2b;font-weight:600;">
											<span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Written', 'ts-live-search' ); ?>
										</span>
									<?php else : ?>
										<span style="display:inline-flex;align-items:center;gap:6px;color:#b26a00;font-weight:600;">
											<span class="dashicons dashicons-edit"></span><?php esc_html_e( 'To write', 'ts-live-search' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row->updated_at && '0000-00-00 00:00:00' !== $row->updated_at ? mysql2date( 'Y-m-d H:i', $row->updated_at ) : '—' ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $toggle_url ); ?>">
										<?php echo $row->is_written ? esc_html__( 'Mark to-write', 'ts-live-search' ) : esc_html__( 'Mark written', 'ts-live-search' ); ?>
									</a>
									<a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this keyword?', 'ts-live-search' ) ); ?>');" style="color:#b32d2e;">
										<?php esc_html_e( 'Delete', 'ts-live-search' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => ts_ls_admin_url(
									array_merge(
										array( 'page' => 'ts-live-search' ),
										'all' !== $status ? array( 'status' => $status ) : array(),
										'' !== $search ? array( 's' => $search ) : array(),
										array( 'orderby' => $orderby, 'order' => strtolower( $order ) )
									)
								) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<hr style="margin:28px 0;">

		<h2><?php esc_html_e( 'Settings', 'ts-live-search' ); ?></h2>
		<form method="post" action="<?php echo esc_url( ts_ls_admin_url() ); ?>">
			<?php wp_nonce_field( 'ts_ls_settings' ); ?>
			<input type="hidden" name="ts_ls_action" value="settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ts-ls-placeholder"><?php esc_html_e( 'Placeholder text', 'ts-live-search' ); ?></label></th>
					<td><input name="placeholder" id="ts-ls-placeholder" type="text" class="regular-text" value="<?php echo esc_attr( $s['placeholder'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show on homepage', 'ts-live-search' ); ?></th>
					<td>
						<label><input type="checkbox" name="auto_homepage" value="1" <?php checked( ! empty( $s['auto_homepage'] ) ); ?>> <?php esc_html_e( 'Automatically display the search bar at the top of the homepage.', 'ts-live-search' ); ?></label>
						<p class="description"><?php esc_html_e( 'You can also place it anywhere with the shortcode', 'ts-live-search' ); ?> <code>[ts_live_search]</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Record searches', 'ts-live-search' ); ?></th>
					<td><label><input type="checkbox" name="log_searches" value="1" <?php checked( ! empty( $s['log_searches'] ) ); ?>> <?php esc_html_e( 'Save search terms to this panel.', 'ts-live-search' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search in', 'ts-live-search' ); ?></th>
					<td>
						<?php
						$selected_types = (array) $s['post_types'];
						$types          = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $types as $name => $obj ) :
							if ( 'attachment' === $name ) {
								continue;
							}
							?>
							<label style="display:inline-block;margin-right:14px;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $selected_types, true ) ); ?>>
								<?php echo esc_html( $obj->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ts-ls-min"><?php esc_html_e( 'Start after N characters', 'ts-live-search' ); ?></label></th>
					<td><input name="min_chars" id="ts-ls-min" type="number" min="1" max="10" value="<?php echo esc_attr( (int) $s['min_chars'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ts-ls-max"><?php esc_html_e( 'Max results in dropdown', 'ts-live-search' ); ?></label></th>
					<td><input name="max_results" id="ts-ls-max" type="number" min="1" max="20" value="<?php echo esc_attr( (int) $s['max_results'] ); ?>" class="small-text"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'ts-live-search' ) ); ?>
		</form>
	</div>
	<?php
}
