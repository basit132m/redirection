<?php
/**
 * Plugin Name: TS Homepage Content
 * Description: Adds editable content above and below the latest-posts list on the homepage, without
 *              switching to a static front page. Edit it under Settings -> Homepage Content.
 *              Shortcodes are supported, so [top_roms] / [az_roms] can be embedded.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_HOME_VERSION', '1.0' );
define( 'TS_HOME_OPTION', 'ts_home_content' );

/* ==========================================================
 * 1. SETTINGS
 * ========================================================== */

/**
 * Default settings.
 *
 * @return array
 */
function ts_home_defaults() {
	return array(
		'top'          => '',
		'bottom'       => '',
		'position'     => 'auto', // auto | astra | loop
		'on_static'    => 0,      // also show when a static page is the front page
		'hide_paged'   => 1,      // hide on /page/2/ etc. (recommended for SEO)
		'boxed'        => 1,      // wrap in a light card
	);
}

/**
 * Merged settings.
 *
 * @return array
 */
function ts_home_get() {
	$saved = get_option( TS_HOME_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( ts_home_defaults(), $saved );
}

/**
 * Settings page.
 */
add_action( 'admin_menu', function () {
	add_options_page(
		'Homepage Content',
		'Homepage Content',
		'manage_options',
		'ts-home-content',
		'ts_home_settings_page'
	);
} );

/**
 * Handle the save.
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_POST['ts_home_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ts_home_save_settings' );

	$s = ts_home_get();

	// wp_kses_post keeps normal editorial HTML and strips anything unsafe.
	$s['top']    = isset( $_POST['ts_home_top'] ) ? wp_kses_post( wp_unslash( $_POST['ts_home_top'] ) ) : '';
	$s['bottom'] = isset( $_POST['ts_home_bottom'] ) ? wp_kses_post( wp_unslash( $_POST['ts_home_bottom'] ) ) : '';

	$pos             = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'auto';
	$s['position']   = in_array( $pos, array( 'auto', 'astra', 'loop' ), true ) ? $pos : 'auto';
	$s['on_static']  = isset( $_POST['on_static'] ) ? 1 : 0;
	$s['hide_paged'] = isset( $_POST['hide_paged'] ) ? 1 : 0;
	$s['boxed']      = isset( $_POST['boxed'] ) ? 1 : 0;

	update_option( TS_HOME_OPTION, $s );

	set_transient( 'ts_home_saved', 1, 30 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ts-home-content' ) );
	exit;
} );

/**
 * Render the settings screen.
 */
function ts_home_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = ts_home_get();

	if ( get_transient( 'ts_home_saved' ) ) {
		delete_transient( 'ts_home_saved' );
		echo '<div class="notice notice-success is-dismissible"><p>Homepage content saved.</p></div>';
	}

	$show_on   = get_option( 'show_on_front' ); // 'posts' or 'page'
	$is_posts  = ( 'posts' === $show_on );
	$editor_cfg = array(
		'textarea_rows' => 10,
		'media_buttons' => true,
		'teeny'         => false,
	);
	?>
	<div class="wrap">
		<h1>Homepage Content</h1>

		<?php if ( $is_posts ) : ?>
			<p>Your homepage is set to <strong>show your latest posts</strong>. Anything you add below appears
			   around that list &mdash; the post list itself keeps working exactly as it does now.</p>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				Your homepage is currently a <strong>static page</strong>. Normally you would just edit that page.
				If you still want these blocks to show there, tick &ldquo;Also show on a static front page&rdquo; below.
			</p></div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'ts_home_save_settings' ); ?>
			<input type="hidden" name="ts_home_save" value="1">

			<h2 class="title">Content above the posts</h2>
			<p class="description">A short welcome / intro. Keep it brief so the posts stay visible without scrolling.</p>
			<?php wp_editor( $s['top'], 'ts_home_top', array_merge( $editor_cfg, array( 'textarea_name' => 'ts_home_top' ) ) ); ?>

			<h2 class="title" style="margin-top:28px;">Content below the posts</h2>
			<p class="description">The best place for longer SEO text &mdash; what your site offers, how to use the files, an FAQ, and internal links.</p>
			<?php wp_editor( $s['bottom'], 'ts_home_bottom', array_merge( $editor_cfg, array( 'textarea_name' => 'ts_home_bottom' ) ) ); ?>

			<h2 class="title" style="margin-top:28px;">Options</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="position">Insert using</label></th>
					<td>
						<select name="position" id="position">
							<option value="auto"  <?php selected( 'auto', $s['position'] ); ?>>Automatic (recommended)</option>
							<option value="astra" <?php selected( 'astra', $s['position'] ); ?>>Astra theme hooks</option>
							<option value="loop"  <?php selected( 'loop', $s['position'] ); ?>>Around the post loop (works on most themes)</option>
						</select>
						<p class="description">
							Automatic uses Astra&rsquo;s own hooks when Astra is active, otherwise it wraps the post loop.
							If the content appears in an odd place, try the other option.
							Detected theme: <code><?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?></code>.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Display</th>
					<td>
						<label><input type="checkbox" name="boxed" value="1" <?php checked( 1, (int) $s['boxed'] ); ?>> Wrap the blocks in a light card</label><br>
						<label><input type="checkbox" name="hide_paged" value="1" <?php checked( 1, (int) $s['hide_paged'] ); ?>> Only show on page 1 <span class="description">(recommended &mdash; avoids duplicate text on /page/2/)</span></label><br>
						<label><input type="checkbox" name="on_static" value="1" <?php checked( 1, (int) $s['on_static'] ); ?>> Also show on a static front page</label>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save homepage content' ); ?>
		</form>

		<hr>
		<h2>Tips</h2>
		<ul style="list-style:disc;padding-left:20px;max-width:820px;">
			<li>Shortcodes work here. For example <code>[top_roms count="10"]</code> or <code>[az_roms]</code> can be dropped straight into either box.</li>
			<li>Use one <code>H2</code> near the top of the &ldquo;above&rdquo; block for your main keyword, then <code>H2</code>/<code>H3</code> subheadings in the &ldquo;below&rdquo; block.</li>
			<li>Link internally from the bottom block to your key pages &mdash; it helps those pages rank.</li>
		</ul>
	</div>
	<?php
}

/* ==========================================================
 * 2. FRONT-END OUTPUT
 * ========================================================== */

/**
 * Should the blocks render on this request?
 *
 * @return bool
 */
function ts_home_should_show() {
	if ( is_admin() || ! is_main_query() ) {
		return false;
	}

	$s = ts_home_get();

	// The blog posts index used as the front page.
	$on_blog_home = ( is_home() && is_front_page() );

	// A static page used as the front page.
	$on_static = ( is_front_page() && ! is_home() && ! empty( $s['on_static'] ) );

	if ( ! $on_blog_home && ! $on_static ) {
		return false;
	}

	if ( ! empty( $s['hide_paged'] ) && is_paged() ) {
		return false;
	}

	return true;
}

/**
 * Prepare stored content for output (same pipeline core uses for the_content).
 *
 * @param string $raw Raw stored HTML.
 * @return string
 */
function ts_home_prepare( $raw ) {
	$raw = (string) $raw;
	if ( '' === trim( $raw ) ) {
		return '';
	}

	$out = wptexturize( $raw );
	$out = wpautop( $out );
	$out = shortcode_unautop( $out );
	$out = do_shortcode( $out );

	return $out;
}

/**
 * Render one block.
 *
 * @param string $which 'top' or 'bottom'.
 * @return string
 */
function ts_home_block( $which ) {
	$s   = ts_home_get();
	$raw = isset( $s[ $which ] ) ? $s[ $which ] : '';
	$out = ts_home_prepare( $raw );

	if ( '' === $out ) {
		return '';
	}

	$classes = 'ts-home-block ts-home-' . $which . ( ! empty( $s['boxed'] ) ? ' is-boxed' : '' );

	return '<div class="' . esc_attr( $classes ) . '">' . $out . '</div>';
}

/**
 * Echo a block once per request.
 *
 * @param string $which 'top' or 'bottom'.
 */
function ts_home_print( $which ) {
	static $done = array();
	if ( isset( $done[ $which ] ) ) {
		return;
	}
	$html = ts_home_block( $which );
	if ( '' === $html ) {
		return;
	}
	$done[ $which ] = true;
	ts_home_styles();
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- already sanitised with wp_kses_post.
}

/**
 * Which insertion strategy to use.
 *
 * @return string 'astra' or 'loop'
 */
function ts_home_strategy() {
	$s = ts_home_get();
	if ( 'astra' === $s['position'] ) {
		return 'astra';
	}
	if ( 'loop' === $s['position'] ) {
		return 'loop';
	}

	// Auto: prefer Astra's hooks when the active theme provides them.
	$theme = wp_get_theme();
	$names = array( $theme->get( 'Name' ), $theme->get_template() );
	foreach ( $names as $n ) {
		if ( false !== stripos( (string) $n, 'astra' ) ) {
			return 'astra';
		}
	}

	return 'loop';
}

/* --- Astra hooks --- */
add_action( 'astra_primary_content_top', function () {
	if ( ts_home_should_show() && 'astra' === ts_home_strategy() ) {
		ts_home_print( 'top' );
	}
} );

add_action( 'astra_primary_content_bottom', function () {
	if ( ts_home_should_show() && 'astra' === ts_home_strategy() ) {
		ts_home_print( 'bottom' );
	}
} );

/* --- Generic loop hooks (works on most themes) --- */
add_action( 'loop_start', function ( $query ) {
	if ( ! $query->is_main_query() ) {
		return;
	}
	if ( ts_home_should_show() && 'loop' === ts_home_strategy() ) {
		ts_home_print( 'top' );
	}
} );

add_action( 'loop_end', function ( $query ) {
	if ( ! $query->is_main_query() ) {
		return;
	}
	if ( ts_home_should_show() && 'loop' === ts_home_strategy() ) {
		ts_home_print( 'bottom' );
	}
} );

/**
 * Safety net: if a static front page is used and neither hook fired, append the
 * blocks to the page content instead.
 */
add_filter( 'the_content', function ( $content ) {
	$s = ts_home_get();
	if ( empty( $s['on_static'] ) ) {
		return $content;
	}
	if ( ! is_front_page() || is_home() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	if ( 'astra' === ts_home_strategy() ) {
		return $content; // Astra hooks already handle it.
	}

	$top    = ts_home_block( 'top' );
	$bottom = ts_home_block( 'bottom' );
	if ( '' === $top && '' === $bottom ) {
		return $content;
	}
	ts_home_styles();

	return $top . $content . $bottom;
}, 20 );

/* ==========================================================
 * 3. STYLES
 * ========================================================== */
function ts_home_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-home-styles">
	/* The blocks are injected inside the theme's post loop, whose container is
	   often a CSS grid or flexbox. Without this the block becomes a single
	   grid/flex cell and collapses into a narrow one-column strip. Forcing it to
	   span the full row makes it a full-width, centered horizontal band at the
	   top and bottom of the post list. */
	.ts-home-block{
		grid-column:1 / -1;   /* span every column when the parent is a grid   */
		flex-basis:100%;      /* take a whole row when the parent is a flexbox */
		align-self:stretch;
		width:100%;
		box-sizing:border-box;
		max-width:100%;margin:0 0 26px;color:#1a1a1a;line-height:1.65;
	}
	.ts-home-block.ts-home-bottom{margin:34px 0 0;}
	.ts-home-block.is-boxed{background:#fff;border:1px solid #e8e8ea;border-radius:14px;padding:22px 26px;box-shadow:0 1px 3px rgba(16,24,40,.05);}
	.ts-home-block h1,.ts-home-block h2,.ts-home-block h3{margin:0 0 12px;line-height:1.3;color:#101828;}
	.ts-home-block h2{font-size:22px;font-weight:800;}
	.ts-home-block h3{font-size:18px;font-weight:700;margin-top:18px;}
	.ts-home-block p{margin:0 0 12px;}
	.ts-home-block ul,.ts-home-block ol{margin:0 0 14px;padding-left:22px;}
	.ts-home-block li{margin:0 0 6px;}
	.ts-home-block a{color:#b81d2f;text-decoration:none;font-weight:600;}
	.ts-home-block a:hover{text-decoration:underline;}
	.ts-home-block img{max-width:100%;height:auto;border-radius:10px;}
	/* Red accent bar on headings, matching the rest of the site */
	.ts-home-block > h2:first-child{display:flex;align-items:center;gap:10px;}
	.ts-home-block > h2:first-child::before{content:"";display:inline-block;width:4px;height:22px;background:#e8394c;border-radius:2px;flex:0 0 4px;}
	@media (max-width:600px){
		.ts-home-block.is-boxed{padding:18px 16px;}
		.ts-home-block h2{font-size:19px;}
	}
	</style>
	<?php
}
