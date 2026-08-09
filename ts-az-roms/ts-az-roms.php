<?php
/**
 * Plugin Name: TS A-Z ROMs
 * Description: SEO-optimised A-Z directory of every published ROM. Drop [az_roms] on a page.
 *              All links are rendered server-side (crawlable), grouped under letter headings with
 *              anchor navigation, plus CollectionPage/ItemList structured data. The search box only
 *              filters markup that is already in the DOM, so nothing is hidden from search engines.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_AZ_VERSION', '1.1' );

/* ==========================================================
 * 1. WHICH POST TYPES
 * ========================================================== */

/**
 * Post types listed by default: the 'game' CPT when it exists, else posts.
 *
 * @return string[]
 */
function ts_az_default_types() {
	$types = post_type_exists( 'game' ) ? array( 'game' ) : array( 'post' );
	$types = apply_filters( 'ts_az_post_types', $types );
	$types = array_values( array_filter( (array) $types, 'post_type_exists' ) );

	return $types ? $types : array( 'post' );
}

/* ==========================================================
 * 2. INDEX BUILDING (cached)
 * ========================================================== */

/**
 * Plain-text title with a leading "Download" verb stripped for indexing.
 *
 * Most ROM posts are titled "Download <Name> Switch NSP/XCI". Left as-is every
 * one of them would bucket (and sort) under D, drowning the real D titles. This
 * removes a single leading "Download" token — together with the punctuation or
 * whitespace that follows it — so the entry files under its real first letter.
 * The displayed title is never altered; this key is used for bucketing/sorting
 * only. Titles that are literally just "Download" fall back to the original so
 * they never become empty.
 *
 * @param string $title Post title.
 * @return string Plain-text title for indexing.
 */
function ts_az_index_title( $title ) {
	$t = remove_accents( wp_strip_all_tags( (string) $title ) );
	// \b keeps "Downloadable …" intact; the trailing class eats the separator
	// ("Download A …" -> "A …", "Download: A …" -> "A …").
	$stripped = preg_replace( '/^\s*download\b[\s:._\-]*/i', '', $t );

	return ( null !== $stripped && '' !== trim( $stripped ) ) ? $stripped : $t;
}

/**
 * Bucket a title into 0-9, A-Z or #.
 *
 * Leading articles are not stripped: ROM titles are proper nouns and users look
 * for them under their literal first letter. A leading "Download" verb, however,
 * is stripped (see ts_az_index_title) so those posts do not all pile up under D.
 *
 * @param string $title Post title.
 * @return string Bucket key.
 */
function ts_az_letter( $title ) {
	$t = ts_az_index_title( $title );
	// Drop leading punctuation/whitespace so "[Prototype]" files under P.
	$t = ltrim( $t, " \t\n\r\0\x0B\"'`([{<-–—_*.#!¡¿" );

	if ( '' === $t ) {
		return '#';
	}

	$c = function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 1, 'UTF-8' ) : substr( $t, 0, 1 );
	$c = strtoupper( $c );

	if ( $c >= '0' && $c <= '9' ) {
		return '0-9';
	}
	if ( $c >= 'A' && $c <= 'Z' ) {
		return $c;
	}

	return '#';
}

/**
 * Ordered list of buckets: 0-9, A..Z, #.
 *
 * @return string[]
 */
function ts_az_buckets() {
	$b = array( '0-9' );
	foreach ( range( 'A', 'Z' ) as $l ) {
		$b[] = $l;
	}
	$b[] = '#';

	return $b;
}

/**
 * Cache-busting version, bumped whenever content changes.
 *
 * @return int
 */
function ts_az_cache_version() {
	return (int) get_option( 'ts_az_cache_ver', 1 );
}

/**
 * Invalidate the cached index when posts change.
 */
function ts_az_flush_cache() {
	update_option( 'ts_az_cache_ver', ts_az_cache_version() + 1, false );
}
add_action( 'save_post', 'ts_az_flush_cache' );
add_action( 'deleted_post', 'ts_az_flush_cache' );
add_action( 'trashed_post', 'ts_az_flush_cache' );
add_action( 'untrashed_post', 'ts_az_flush_cache' );

/**
 * Build (or fetch from cache) the grouped index of published items.
 *
 * Returns: [ 'A' => [ [ 't' => title, 'u' => url ], ... ], ... ]
 *
 * @param string[] $types Post types.
 * @return array
 */
function ts_az_get_index( $types ) {
	$key    = 'ts_az_idx_' . TS_AZ_VERSION . '_' . ts_az_cache_version() . '_' . substr( md5( implode( ',', $types ) ), 0, 12 );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$q = new WP_Query(
		array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$ids = $q->posts;
	if ( empty( $ids ) ) {
		set_transient( $key, array(), DAY_IN_SECONDS );
		return array();
	}

	// One query for all post rows; skips meta and term caches we do not need.
	if ( function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $ids, false, false );
	}

	$groups = array();
	foreach ( $ids as $id ) {
		$title = get_the_title( $id );
		if ( '' === trim( $title ) ) {
			continue;
		}
		$groups[ ts_az_letter( $title ) ][] = array(
			't' => $title,
			'u' => get_permalink( $id ),
		);
	}

	// WP_Query ordered by the raw title, which clumps every "Download …" entry
	// together inside each bucket. Re-sort each bucket by the indexing title so
	// items read alphabetically by their real name.
	foreach ( $groups as $bucket => $rows ) {
		usort(
			$rows,
			function ( $a, $b ) {
				return strcasecmp( ts_az_index_title( $a['t'] ), ts_az_index_title( $b['t'] ) );
			}
		);
		$groups[ $bucket ] = $rows;
	}

	set_transient( $key, $groups, DAY_IN_SECONDS );

	return $groups;
}

/* ==========================================================
 * 3. STRUCTURED DATA (printed in the footer)
 * ========================================================== */

/**
 * Collected schema items for this request.
 *
 * @param array|null $set Items to store.
 * @return array
 */
function ts_az_schema_store( $set = null ) {
	static $items = array();
	if ( null !== $set ) {
		$items = $set;
	}
	return $items;
}

add_action( 'wp_footer', function () {
	$items = ts_az_schema_store();
	if ( empty( $items ) ) {
		return;
	}

	$elements = array();
	$pos      = 0;
	foreach ( $items as $it ) {
		$pos++;
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'url'      => $it['u'],
			'name'     => $it['t'],
		);
	}

	$data = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'CollectionPage',
		'name'       => wp_get_document_title(),
		'url'        => home_url( add_query_arg( array() ) ),
		'mainEntity' => array(
			'@type'           => 'ItemList',
			'numberOfItems'   => count( $elements ),
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'itemListElement' => $elements,
		),
	);

	echo '<script type="application/ld+json">'
		. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}, 99 );

/* ==========================================================
 * 4. SHORTCODE
 * ========================================================== */

/**
 * [az_roms] — full A-Z directory.
 *
 * Attributes:
 *   post_type   Comma-separated post types (default: the 'game' CPT).
 *   title       Hero heading (default "All ROMs — A to Z"). Empty hides the hero.
 *   subtitle    Hero subtitle. Empty hides it.
 *   search      1|0  show the filter box (default 1).
 *   columns     Max columns for each letter's list (default 3).
 *   schema      1|0  output ItemList structured data (default 1).
 *   schema_max  Cap on schema entries to keep page weight sane (default 300).
 */
function ts_az_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'post_type'  => '',
			'title'      => 'All ROMs — A to Z',
			'subtitle'   => '',
			'search'     => '1',
			'columns'    => 3,
			'schema'     => '1',
			'schema_max' => 300,
		),
		$atts,
		'az_roms'
	);

	$types = '' !== $atts['post_type']
		? array_values( array_filter( array_map( 'trim', explode( ',', $atts['post_type'] ) ), 'post_type_exists' ) )
		: ts_az_default_types();
	if ( empty( $types ) ) {
		$types = ts_az_default_types();
	}

	$groups = ts_az_get_index( $types );

	$total = 0;
	foreach ( $groups as $rows ) {
		$total += count( $rows );
	}

	if ( ! $total ) {
		return '<div class="ts-az"><p class="ts-az-empty">No ROMs have been published yet.</p></div>';
	}

	$columns  = max( 1, min( 4, (int) $atts['columns'] ) );
	$show_sea = '1' === (string) $atts['search'];
	$subtitle = '' !== $atts['subtitle']
		? $atts['subtitle']
		: sprintf( 'Browse every ROM on %s in alphabetical order — %s titles in total.', get_bloginfo( 'name' ), number_format_i18n( $total ) );

	// Collect structured-data items (capped).
	if ( '1' === (string) $atts['schema'] ) {
		$cap   = max( 0, (int) $atts['schema_max'] );
		$flat  = array();
		foreach ( ts_az_buckets() as $b ) {
			if ( empty( $groups[ $b ] ) ) {
				continue;
			}
			foreach ( $groups[ $b ] as $row ) {
				if ( $cap && count( $flat ) >= $cap ) {
					break 2;
				}
				$flat[] = $row;
			}
		}
		ts_az_schema_store( $flat );
	}

	// Inside the buffer on purpose: a shortcode must RETURN its output. Echoing
	// here would dump the CSS wherever the_content happens to be filtered first
	// (SEO plugins run it early to build meta tags).
	ob_start();
	ts_az_styles();
	?>
	<div class="ts-az" id="ts-az-top">

		<?php if ( '' !== $atts['title'] ) : ?>
			<div class="ts-az-hero">
				<h2 class="ts-az-h"><?php echo esc_html( $atts['title'] ); ?></h2>
				<p class="ts-az-sub"><?php echo esc_html( $subtitle ); ?></p>
				<div class="ts-az-count"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>&nbsp;<?php echo esc_html( 1 === $total ? 'ROM' : 'ROMs' ); ?></div>
			</div>
		<?php endif; ?>

		<?php if ( $show_sea ) : ?>
			<div class="ts-az-tools">
				<label class="screen-reader-text" for="ts-az-q">Search ROMs by name</label>
				<input type="search" id="ts-az-q" class="ts-az-q" placeholder="Search ROMs by name…" autocomplete="off">
				<span class="ts-az-status" id="ts-az-status" role="status" aria-live="polite"></span>
			</div>
		<?php endif; ?>

		<nav class="ts-az-nav" aria-label="Jump to letter">
			<?php
			foreach ( ts_az_buckets() as $b ) :
				$has   = ! empty( $groups[ $b ] );
				$label = ( '#' === $b ) ? 'Other' : $b;
				if ( $has ) :
					?>
					<a class="ts-az-navlink" href="#az-<?php echo esc_attr( ts_az_slug( $b ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php
				else :
					?>
					<span class="ts-az-navlink is-off" aria-disabled="true"><?php echo esc_html( $label ); ?></span>
					<?php
				endif;
			endforeach;
			?>
		</nav>

		<div class="ts-az-body" data-cols="<?php echo (int) $columns; ?>">
			<?php
			foreach ( ts_az_buckets() as $b ) :
				if ( empty( $groups[ $b ] ) ) {
					continue;
				}
				$rows  = $groups[ $b ];
				$label = ( '#' === $b ) ? 'Other' : $b;
				?>
				<section class="ts-az-sec" id="az-<?php echo esc_attr( ts_az_slug( $b ) ); ?>">
					<h3 class="ts-az-letter">
						<span class="ts-az-letter-badge"><?php echo esc_html( $label ); ?></span>
						<span class="ts-az-letter-count"><?php echo esc_html( ts_az_count_label( count( $rows ) ) ); ?></span>
						<a class="ts-az-top" href="#ts-az-top" aria-label="Back to top">&#8593; Top</a>
					</h3>
					<ul class="ts-az-list">
						<?php foreach ( $rows as $row ) : ?>
							<li class="ts-az-item" data-t="<?php echo esc_attr( ts_az_needle( $row['t'] ) ); ?>">
								<a href="<?php echo esc_url( $row['u'] ); ?>"><?php echo esc_html( $row['t'] ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>

			<p class="ts-az-none" id="ts-az-none" hidden>No ROMs match your search.</p>
		</div>
	</div>

	<?php
	$html = ob_get_clean();

	/**
	 * Collapse whitespace between tags so wpautop cannot inject stray </p> tags.
	 * NOTE: this must never run over JavaScript — flattening newlines turns "//"
	 * line comments into one that swallows the rest of the script. The filter JS
	 * is therefore printed separately on wp_footer, not returned from here.
	 */
	$html = preg_replace( '/>\s+</', '><', $html );
	$html = preg_replace( '/\s*\R\s*/', ' ', $html );

	if ( $show_sea ) {
		ts_az_want_js( true );
	}

	return trim( $html );
}
add_shortcode( 'az_roms', 'ts_az_shortcode' );

/**
 * Flag: does this request need the filter script?
 *
 * @param bool|null $set Set the flag.
 * @return bool
 */
function ts_az_want_js( $set = null ) {
	static $want = false;
	if ( null !== $set ) {
		$want = (bool) $set;
	}
	return $want;
}

/**
 * Print the search/filter script. Kept out of the shortcode return value so the
 * whitespace-collapsing above can never touch it.
 */
add_action( 'wp_footer', function () {
	if ( ! ts_az_want_js() ) {
		return;
	}
	?>
	<script id="ts-az-js">
	(function(){
		var q = document.getElementById('ts-az-q');
		if(!q) return;
		var root   = document.querySelector('.ts-az-body');
		var secs   = Array.prototype.slice.call(root.querySelectorAll('.ts-az-sec'));
		var items  = Array.prototype.slice.call(root.querySelectorAll('.ts-az-item'));
		var none   = document.getElementById('ts-az-none');
		var status = document.getElementById('ts-az-status');
		var nav    = document.querySelector('.ts-az-nav');
		var total  = items.length;
		var timer;

		function norm(s){
			s = (s||'').toLowerCase();
			// \u0300-\u036f = combining diacritics, written as escapes so a JS
			// minifier or a charset change can never mangle the literal characters.
			if (s.normalize) { try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g,''); } catch(e){} }
			return s;
		}

		function apply(){
			var term = norm(q.value).trim();
			if (!term){
				items.forEach(function(li){ li.hidden = false; });
				secs.forEach(function(s){ s.hidden = false; });
				none.hidden = true;
				if (nav) nav.hidden = false;
				status.textContent = '';
				return;
			}
			var shown = 0;
			secs.forEach(function(sec){
				var any = false;
				sec.querySelectorAll('.ts-az-item').forEach(function(li){
					var hit = li.getAttribute('data-t').indexOf(term) !== -1;
					li.hidden = !hit;
					if (hit){ any = true; shown++; }
				});
				sec.hidden = !any;
			});
			none.hidden = shown > 0;
			if (nav) nav.hidden = true;
			status.textContent = shown + ' of ' + total + ' ROMs';
		}

		q.addEventListener('input', function(){
			clearTimeout(timer);
			timer = setTimeout(apply, 120);
		});
		q.addEventListener('search', apply);
	})();
	</script>
	<?php
} );

/**
 * "1 ROM" / "12 ROMs".
 *
 * @param int $n Count.
 * @return string
 */
function ts_az_count_label( $n ) {
	$n = (int) $n;
	return number_format_i18n( $n ) . ( 1 === $n ? ' ROM' : ' ROMs' );
}

/**
 * Anchor-safe slug for a bucket key.
 *
 * @param string $bucket Bucket.
 * @return string
 */
function ts_az_slug( $bucket ) {
	if ( '0-9' === $bucket ) {
		return '0-9';
	}
	if ( '#' === $bucket ) {
		return 'other';
	}
	return strtolower( $bucket );
}

/**
 * Lowercase, accent-free search key for a title.
 *
 * @param string $title Title.
 * @return string
 */
function ts_az_needle( $title ) {
	return strtolower( remove_accents( wp_strip_all_tags( (string) $title ) ) );
}

/* ==========================================================
 * 5. STYLES (printed once)
 * ========================================================== */
function ts_az_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-az-styles">
	.ts-az{--az-red:#e8394c;--az-red2:#b81d2f;max-width:1000px;margin:0 auto;color:#1a1a1a;scroll-margin-top:90px;}
	.ts-az *{box-sizing:border-box;}
	.ts-az-hero{background:linear-gradient(135deg,#e8394c 0%,#b81d2f 100%);color:#fff;border-radius:18px;padding:30px 24px;text-align:center;margin:0 0 20px;box-shadow:0 10px 30px rgba(184,29,47,.22);}
	.ts-az-h{margin:0 0 8px;font-size:26px;font-weight:800;color:#fff;}
	.ts-az-sub{margin:0 0 14px;font-size:14px;opacity:.93;}
	.ts-az-count{display:inline-flex;align-items:center;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:7px 16px;font-size:14px;}
	.ts-az-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 16px;}
	.ts-az-q{flex:1 1 260px;min-width:0;padding:12px 16px;border:1px solid #e2e2e6;border-radius:10px;font-size:15px;background:#fff;color:#1a1a1a;}
	.ts-az-q:focus{outline:none;border-color:var(--az-red);box-shadow:0 0 0 3px rgba(232,57,76,.15);}
	.ts-az-status{color:#6b7280;font-size:13px;font-weight:600;}
	.ts-az-nav{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;position:sticky;top:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);padding:10px 8px;border:1px solid #eee;border-radius:12px;margin:0 0 22px;}
	.ts-az-navlink{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 9px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;color:#1a1a1a;background:#f6f6f7;transition:background .15s,color .15s;}
	a.ts-az-navlink:hover{background:linear-gradient(135deg,#e8394c,#b81d2f);color:#fff;}
	.ts-az-navlink.is-off{opacity:.32;cursor:default;}
	.ts-az-sec{margin:0 0 26px;content-visibility:auto;contain-intrinsic-size:auto 320px;scroll-margin-top:80px;}
	.ts-az-letter{display:flex;align-items:center;gap:12px;margin:0 0 12px;padding:0 0 10px;border-bottom:2px solid #f0f0f2;font-size:18px;}
	.ts-az-letter-badge{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 10px;border-radius:10px;background:linear-gradient(135deg,#e8394c,#b81d2f);color:#fff;font-weight:800;font-size:17px;}
	.ts-az-letter-count{color:#6b7280;font-size:13px;font-weight:600;}
	.ts-az-top{margin-left:auto;font-size:12px;font-weight:700;color:var(--az-red2);text-decoration:none;}
	.ts-az-top:hover{text-decoration:underline;}
	.ts-az-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2px 18px;}
	.ts-az-body[data-cols="1"] .ts-az-list{grid-template-columns:1fr;}
	.ts-az-body[data-cols="2"] .ts-az-list{grid-template-columns:repeat(2,minmax(0,1fr));}
	.ts-az-body[data-cols="4"] .ts-az-list{grid-template-columns:repeat(4,minmax(0,1fr));}
	.ts-az-item{margin:0;padding:0;}
	.ts-az-item>a{display:block;padding:8px 10px;border-radius:8px;color:#1a1a1a;text-decoration:none;font-size:14.5px;line-height:1.45;border-left:3px solid transparent;transition:background .14s,border-color .14s,color .14s;}
	.ts-az-item>a:hover{background:#fdf2f4;border-left-color:var(--az-red);color:var(--az-red2);}
	.ts-az-none{text-align:center;color:#6b7280;padding:26px 0;font-size:15px;}
	.ts-az-empty{text-align:center;color:#6b7280;padding:30px 0;}
	.ts-az .screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap;}
	@media (max-width:900px){ .ts-az-list,.ts-az-body[data-cols="4"] .ts-az-list,.ts-az-body[data-cols="3"] .ts-az-list{grid-template-columns:repeat(2,minmax(0,1fr));} }
	@media (max-width:560px){
		.ts-az-list,.ts-az-body[data-cols="2"] .ts-az-list,.ts-az-body[data-cols="3"] .ts-az-list,.ts-az-body[data-cols="4"] .ts-az-list{grid-template-columns:1fr;}
		.ts-az-h{font-size:22px;}
		.ts-az-nav{gap:4px;padding:8px 6px;}
		.ts-az-navlink{min-width:30px;height:30px;font-size:13px;padding:0 7px;}
	}
	</style>
	<?php
}
