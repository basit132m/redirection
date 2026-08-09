<?php
/**
 * Plugin Name: TS Popular ROMs
 * Description: A hand-picked "Popular ROMs" section for the homepage (shown below the top homepage content).
 *              Choose the ROMs in Settings -> Popular ROMs (search & add, drag to reorder). Renders a
 *              responsive card grid with cover, title, genre and download count, plus ItemList JSON-LD for SEO.
 *              Also available as the [popular_roms] shortcode.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_PR_OPTION', 'ts_pr_settings' );

/* ==========================================================
 * SETTINGS
 * ========================================================== */
function ts_pr_defaults() {
	return array(
		'ids'       => array(),
		'heading'   => 'Popular ROMs',
		'subheading'=> 'The most-downloaded Nintendo Switch games right now.',
		'auto_home' => 1,
	);
}

function ts_pr_get() {
	$saved = get_option( TS_PR_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$s        = array_merge( ts_pr_defaults(), $saved );
	$s['ids'] = array_values( array_filter( array_map( 'absint', (array) $s['ids'] ) ) );
	return $s;
}

/**
 * Public post types that hold ROMs (games are usually a custom post type).
 *
 * @return array
 */
function ts_pr_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'], $types['page'] );
	return apply_filters( 'ts_pr_post_types', array_values( $types ) );
}

/* ==========================================================
 * ADMIN — pick & order the popular ROMs
 * ========================================================== */
$GLOBALS['ts_pr_hook'] = '';

add_action( 'admin_menu', function () {
	$GLOBALS['ts_pr_hook'] = add_options_page(
		'Popular ROMs',
		'Popular ROMs',
		'manage_options',
		'ts-popular-roms',
		'ts_pr_settings_page'
	);
} );

// Save.
add_action( 'admin_init', function () {
	if ( ! isset( $_POST['ts_pr_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ts_pr_save' );

	$s              = ts_pr_get();
	$s['ids']       = isset( $_POST['ts_pr_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $_POST['ts_pr_ids'] ) ) ) : array();
	$s['heading']   = isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : 'Popular ROMs';
	$s['subheading']= isset( $_POST['subheading'] ) ? sanitize_text_field( wp_unslash( $_POST['subheading'] ) ) : '';
	$s['auto_home'] = isset( $_POST['auto_home'] ) ? 1 : 0;

	update_option( TS_PR_OPTION, $s );
	set_transient( 'ts_pr_saved', 1, 30 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ts-popular-roms' ) );
	exit;
} );

// Enqueue autocomplete + sortable only on our screen.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( empty( $GLOBALS['ts_pr_hook'] ) || $hook !== $GLOBALS['ts_pr_hook'] ) {
		return;
	}
	wp_enqueue_script( 'jquery-ui-autocomplete' );
	wp_enqueue_script( 'jquery-ui-sortable' );
} );

// AJAX search for ROMs by title.
add_action( 'wp_ajax_ts_pr_search', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ts_pr_search', 'nonce', false ) ) {
		wp_send_json( array() );
	}
	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	$q    = new WP_Query( array(
		'post_type'      => ts_pr_post_types(),
		'post_status'    => 'publish',
		's'              => $term,
		'posts_per_page' => 15,
		'no_found_rows'  => true,
	) );
	$out = array();
	foreach ( $q->posts as $p ) {
		$out[] = array(
			'id'    => $p->ID,
			'label' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) . ' (#' . $p->ID . ')',
			'value' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		);
	}
	wp_reset_postdata();
	wp_send_json( $out );
} );

function ts_pr_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = ts_pr_get();
	if ( get_transient( 'ts_pr_saved' ) ) {
		delete_transient( 'ts_pr_saved' );
		echo '<div class="notice notice-success is-dismissible"><p>Popular ROMs saved.</p></div>';
	}
	$nonce = wp_create_nonce( 'ts_pr_search' );
	?>
	<div class="wrap">
		<h1>Popular ROMs</h1>
		<p>Search a game by name and add it, then drag the rows to set the order. This list appears on the homepage below the top content (and via the <code>[popular_roms]</code> shortcode).</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'ts_pr_save' ); ?>
			<input type="hidden" name="ts_pr_action" value="1">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="heading">Heading</label></th>
					<td><input name="heading" id="heading" type="text" class="regular-text" value="<?php echo esc_attr( $s['heading'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="subheading">Subheading</label></th>
					<td><input name="subheading" id="subheading" type="text" class="large-text" value="<?php echo esc_attr( $s['subheading'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Homepage</th>
					<td><label><input type="checkbox" name="auto_home" value="1" <?php checked( 1, (int) $s['auto_home'] ); ?>> Show automatically on the homepage (below the top content)</label></td>
				</tr>
			</table>

			<h2>Selected ROMs</h2>
			<p>
				<label for="ts-pr-search"><strong>Add a ROM:</strong></label>
				<input type="text" id="ts-pr-search" class="regular-text" placeholder="Start typing a game name…" autocomplete="off">
				<span class="description">Pick from the suggestions.</span>
			</p>

			<ul id="ts-pr-list" class="ts-pr-adminlist">
				<?php foreach ( $s['ids'] as $id ) :
					$title = get_the_title( $id );
					if ( '' === $title ) {
						continue;
					}
					?>
					<li class="ts-pr-adminitem">
						<span class="dashicons dashicons-menu ts-pr-handle"></span>
						<input type="hidden" name="ts_pr_ids[]" value="<?php echo (int) $id; ?>">
						<span class="ts-pr-adminlabel"><?php echo esc_html( $title ); ?> <span style="color:#888;">(#<?php echo (int) $id; ?>)</span></span>
						<button type="button" class="button-link ts-pr-remove" style="color:#b32d2e;">Remove</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description" id="ts-pr-empty" <?php echo empty( $s['ids'] ) ? '' : 'style="display:none;"'; ?>>No ROMs selected yet.</p>

			<?php submit_button( 'Save Popular ROMs' ); ?>
		</form>
	</div>

	<style>
		.ts-pr-adminlist{margin:10px 0;max-width:640px;padding:0;list-style:none;}
		.ts-pr-adminitem{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin-bottom:6px;}
		.ts-pr-handle{cursor:move;color:#a7aaad;}
		.ts-pr-adminlabel{flex:1;}
		.ts-pr-placeholder{border:1px dashed #c3c4c7;border-radius:6px;height:38px;margin-bottom:6px;background:#f6f7f7;}
		.ui-autocomplete{max-height:320px;overflow-y:auto;z-index:100001;background:#fff;border:1px solid #c3c4c7;box-shadow:0 2px 8px rgba(0,0,0,.12);border-radius:4px;padding:2px;}
		.ui-autocomplete .ui-menu-item{list-style:none;}
		.ui-autocomplete .ui-menu-item-wrapper{display:block;padding:7px 12px;cursor:pointer;font-size:13px;}
		.ui-autocomplete .ui-menu-item-wrapper.ui-state-active{background:#2271b1;color:#fff;}
	</style>
	<script>
	jQuery(function($){
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		function refreshEmpty(){ $('#ts-pr-empty').toggle($('#ts-pr-list li').length === 0); }

		$('#ts-pr-list').sortable({ handle:'.ts-pr-handle', placeholder:'ts-pr-placeholder', axis:'y' });

		$('#ts-pr-search').autocomplete({
			minLength: 2,
			source: function(request, response){
				$.getJSON(ajaxurl, { action:'ts_pr_search', nonce:nonce, term:request.term }, response);
			},
			select: function(event, ui){
				event.preventDefault();
				if ($('#ts-pr-list input[value="'+ui.item.id+'"]').length){ $(this).val(''); return; } // no dupes
				var li = $('<li class="ts-pr-adminitem"><span class="dashicons dashicons-menu ts-pr-handle"></span>' +
					'<input type="hidden" name="ts_pr_ids[]" value="'+ui.item.id+'">' +
					'<span class="ts-pr-adminlabel"></span>' +
					'<button type="button" class="button-link ts-pr-remove" style="color:#b32d2e;">Remove</button></li>');
				li.find('.ts-pr-adminlabel').text(ui.item.value + ' (#' + ui.item.id + ')');
				$('#ts-pr-list').append(li);
				$(this).val('');
				refreshEmpty();
			}
		});

		$('#ts-pr-list').on('click', '.ts-pr-remove', function(){ $(this).closest('li').remove(); refreshEmpty(); });
	});
	</script>
	<?php
}

/* ==========================================================
 * FRONTEND — render the section
 * ========================================================== */
function ts_pr_render() {
	static $printed = false;
	if ( $printed ) {
		return '';
	}

	$s = ts_pr_get();
	if ( empty( $s['ids'] ) ) {
		return '';
	}

	$q = new WP_Query( array(
		'post_type'           => ts_pr_post_types(),
		'post_status'         => 'publish',
		'post__in'            => $s['ids'],
		'orderby'             => 'post__in',
		'posts_per_page'      => count( $s['ids'] ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );

	if ( ! $q->have_posts() ) {
		return '';
	}

	$printed = true;
	ts_pr_styles();

	ob_start();
	?>
	<section class="ts-pr" aria-labelledby="ts-pr-title">
		<div class="ts-pr-head">
			<h2 class="ts-pr-title" id="ts-pr-title">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
				<?php echo esc_html( $s['heading'] ?: 'Popular ROMs' ); ?>
			</h2>
			<?php if ( ! empty( $s['subheading'] ) ) : ?>
				<p class="ts-pr-sub"><?php echo esc_html( $s['subheading'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="ts-pr-grid">
			<?php
			$rank = 0;
			while ( $q->have_posts() ) : $q->the_post();
				$rank++;
				$pid   = get_the_ID();
				$cover = get_the_post_thumbnail_url( $pid, 'medium' );
				$cats  = get_the_category( $pid );
				$genre = ! empty( $cats ) ? $cats[0]->name : '';
				$hits  = (int) get_post_meta( $pid, 'ts_dl_hits', true );
				?>
				<a class="ts-pr-card" href="<?php the_permalink(); ?>">
					<div class="ts-pr-cover">
						<?php if ( $cover ) : ?>
							<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
						<?php else : ?>
							<span class="ts-pr-noimg"><?php echo esc_html( mb_substr( get_the_title(), 0, 1 ) ); ?></span>
						<?php endif; ?>
						<span class="ts-pr-rank">#<?php echo (int) $rank; ?></span>
					</div>
					<div class="ts-pr-info">
						<span class="ts-pr-name"><?php the_title(); ?></span>
						<span class="ts-pr-meta">
							<?php if ( $genre ) : ?><span class="ts-pr-genre"><?php echo esc_html( $genre ); ?></span><?php endif; ?>
							<?php if ( $hits > 0 ) : ?><span class="ts-pr-dl">&#8681;&nbsp;<?php echo esc_html( number_format_i18n( $hits ) ); ?></span><?php endif; ?>
						</span>
					</div>
				</a>
			<?php endwhile; ?>
		</div>
	</section>
	<?php
	$html = ob_get_clean();

	$html .= ts_pr_schema( $q );

	wp_reset_postdata();
	return $html;
}

/**
 * ItemList JSON-LD for the popular ROMs (SEO).
 *
 * @param WP_Query $q Query with the selected posts.
 * @return string
 */
function ts_pr_schema( $q ) {
	$items = array();
	$pos   = 0;
	foreach ( $q->posts as $p ) {
		$pos++;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'url'      => get_permalink( $p ),
			'name'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		);
	}
	if ( empty( $items ) ) {
		return '';
	}
	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'name'            => 'Popular ROMs',
		'itemListElement' => $items,
	);
	return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}

add_shortcode( 'popular_roms', 'ts_pr_render' );

// Auto-insert on the homepage, just below the top content block.
add_action( 'loop_start', function ( $query ) {
	$s = ts_pr_get();
	if ( empty( $s['auto_home'] ) || is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! ( is_home() && is_front_page() ) || is_paged() ) {
		return;
	}
	echo ts_pr_render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder
}, 15 ); // after TS Homepage Content (10), before the posts grid

/* ==========================================================
 * STYLES
 * ========================================================== */
function ts_pr_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-pr-styles">
	.ts-pr{grid-column:1 / -1;flex-basis:100%;width:100%;box-sizing:border-box;max-width:1180px;margin:30px auto;}
	.ts-pr-head{margin:0 0 16px;}
	.ts-pr-title{display:flex;align-items:center;gap:9px;font-size:24px;font-weight:800;color:#101828;margin:0;}
	.ts-pr-title svg{width:22px;height:22px;color:#e8394c;flex:0 0 auto;}
	.ts-pr-sub{margin:4px 0 0;color:#667085;font-size:14px;}
	.ts-pr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;}
	.ts-pr-card{display:flex;flex-direction:column;text-decoration:none;color:#101828;background:#fff;
		border:1px solid #e8e8ea;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,.05);
		transition:border-color .15s ease,box-shadow .15s ease,transform .05s ease;}
	.ts-pr-card:hover{border-color:#f1c2c8;box-shadow:0 8px 22px rgba(16,24,40,.12);transform:translateY(-2px);}
	.ts-pr-cover{position:relative;aspect-ratio:3/4;background:#101326;overflow:hidden;}
	.ts-pr-cover img{width:100%;height:100%;object-fit:cover;display:block;}
	.ts-pr-noimg{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#7dd3fc;font-size:44px;font-weight:800;}
	.ts-pr-rank{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.72);color:#fff;font-size:12px;font-weight:800;
		padding:2px 8px;border-radius:999px;letter-spacing:.3px;}
	.ts-pr-info{padding:10px 12px 12px;display:flex;flex-direction:column;gap:6px;}
	.ts-pr-name{font-weight:700;font-size:14px;line-height:1.3;
		display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
	.ts-pr-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;}
	.ts-pr-genre{font-size:11px;font-weight:700;color:#e8394c;background:#fdecef;border-radius:999px;padding:2px 8px;
		text-transform:uppercase;letter-spacing:.3px;}
	.ts-pr-dl{font-size:12px;font-weight:700;color:#667085;}
	@media (max-width:560px){
		.ts-pr-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;}
		.ts-pr-title{font-size:20px;}
	}
	</style>
	<?php
}
