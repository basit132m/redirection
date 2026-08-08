<?php
/**
 * Plugin Name: TS Emulators
 * Description: Publish emulators from the admin as their own pages. Registers an "Emulators" custom post
 *              type (each emulator gets its own page at /emulator/<slug>/ with logo, info fields and a
 *              download button), and provides the [emulators] shortcode to list them all in a grid on any
 *              page. Includes SoftwareApplication JSON-LD for SEO.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_EMU_CPT', 'emulator' );

/* ==========================================================
 * 1. CUSTOM POST TYPE
 * ========================================================== */
function ts_emu_register_cpt() {
	register_post_type( TS_EMU_CPT, array(
		'labels'       => array(
			'name'               => 'Emulators',
			'singular_name'      => 'Emulator',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Emulator',
			'edit_item'          => 'Edit Emulator',
			'new_item'           => 'New Emulator',
			'view_item'          => 'View Emulator',
			'search_items'       => 'Search Emulators',
			'not_found'          => 'No emulators found',
			'not_found_in_trash' => 'No emulators found in Trash',
			'all_items'          => 'All Emulators',
			'menu_name'          => 'Emulators',
		),
		'public'       => true,
		'has_archive'  => false, // use a Page + [emulators] for the index
		'menu_icon'    => 'dashicons-desktop',
		'menu_position'=> 26,
		'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'rewrite'      => array( 'slug' => 'emulator', 'with_front' => false ),
		'show_in_rest' => true, // block editor + REST
	) );
}
add_action( 'init', 'ts_emu_register_cpt' );

// Flush rewrite rules on activation/deactivation so /emulator/<slug>/ works.
register_activation_hook( __FILE__, function () {
	ts_emu_register_cpt();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* ==========================================================
 * 2. META FIELDS
 * ========================================================== */
/**
 * The emulator info fields: meta key => label.
 *
 * @return array
 */
function ts_emu_fields() {
	return array(
		'emu_developer' => 'Developer',
		'emu_version'   => 'Latest Version',
		'emu_platforms' => 'Platforms (comma-separated, e.g. Windows, Android, Linux)',
		'emu_license'   => 'License (e.g. Open source, Freeware)',
		'emu_size'      => 'Size (e.g. 45 MB)',
		'emu_official'  => 'Official Site URL',
		'emu_download'  => 'Download URL',
	);
}

add_action( 'init', function () {
	foreach ( array_keys( ts_emu_fields() ) as $key ) {
		register_post_meta( TS_EMU_CPT, $key, array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'ts_emu_box', 'Emulator Details', 'ts_emu_meta_box_html', TS_EMU_CPT, 'normal', 'high' );
} );

function ts_emu_meta_box_html( $post ) {
	wp_nonce_field( 'ts_emu_save', 'ts_emu_nonce' );
	echo '<table class="form-table">';
	foreach ( ts_emu_fields() as $key => $label ) {
		$val  = get_post_meta( $post->ID, $key, true );
		$type = ( in_array( $key, array( 'emu_official', 'emu_download' ), true ) ) ? 'url' : 'text';
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%;"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $val )
		);
	}
	echo '</table>';
	echo '<p class="description">The logo comes from the <strong>Featured image</strong>. The main description is the editor content above. Show the full list anywhere with the <code>[emulators]</code> shortcode.</p>';
}

add_action( 'save_post_' . TS_EMU_CPT, function ( $post_id ) {
	if ( ! isset( $_POST['ts_emu_nonce'] ) || ! wp_verify_nonce( $_POST['ts_emu_nonce'], 'ts_emu_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( ts_emu_fields() ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );
		if ( in_array( $key, array( 'emu_official', 'emu_download' ), true ) ) {
			update_post_meta( $post_id, $key, esc_url_raw( trim( $raw ) ) );
		} else {
			update_post_meta( $post_id, $key, sanitize_text_field( $raw ) );
		}
	}
} );

/* ==========================================================
 * 3. HELPERS
 * ========================================================== */
function ts_emu_platform_chips( $platforms ) {
	$platforms = trim( (string) $platforms );
	if ( '' === $platforms ) {
		return '';
	}
	$parts = array_filter( array_map( 'trim', explode( ',', $platforms ) ) );
	$out   = '';
	foreach ( $parts as $p ) {
		$out .= '<span class="ts-emu-chip">' . esc_html( $p ) . '</span>';
	}
	return $out ? '<div class="ts-emu-chips">' . $out . '</div>' : '';
}

function ts_emu_download_icon() {
	return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
		. '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>';
}

/* ==========================================================
 * 4. SINGLE EMULATOR — info card prepended to the content
 * ========================================================== */
function ts_emu_render_card( $post_id ) {
	$logo      = get_the_post_thumbnail_url( $post_id, 'medium' );
	$developer = get_post_meta( $post_id, 'emu_developer', true );
	$version   = get_post_meta( $post_id, 'emu_version', true );
	$platforms = get_post_meta( $post_id, 'emu_platforms', true );
	$license   = get_post_meta( $post_id, 'emu_license', true );
	$size      = get_post_meta( $post_id, 'emu_size', true );
	$official  = get_post_meta( $post_id, 'emu_official', true );
	$download  = get_post_meta( $post_id, 'emu_download', true );

	ob_start();
	?>
	<div class="ts-emu-card">
		<?php if ( $logo ) : ?>
			<div class="ts-emu-logo"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?> logo" loading="eager"></div>
		<?php endif; ?>
		<div class="ts-emu-body">
			<div class="ts-emu-rows">
				<?php if ( $developer ) : ?><div class="ts-emu-row"><span>Developer</span><strong><?php echo esc_html( $developer ); ?></strong></div><?php endif; ?>
				<?php if ( $version ) : ?><div class="ts-emu-row"><span>Latest Version</span><strong class="ts-emu-ver"><?php echo esc_html( $version ); ?></strong></div><?php endif; ?>
				<?php if ( $license ) : ?><div class="ts-emu-row"><span>License</span><strong><?php echo esc_html( $license ); ?></strong></div><?php endif; ?>
				<?php if ( $size ) : ?><div class="ts-emu-row"><span>Size</span><strong><?php echo esc_html( $size ); ?></strong></div><?php endif; ?>
			</div>

			<?php echo ts_emu_platform_chips( $platforms ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>

			<div class="ts-emu-actions">
				<?php if ( $download ) : ?>
					<a class="ts-emu-btn" href="<?php echo esc_url( $download ); ?>" target="_blank" rel="nofollow noopener">
						<?php echo ts_emu_download_icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?>
						<span>Download</span>
					</a>
				<?php endif; ?>
				<?php if ( $official && filter_var( $official, FILTER_VALIDATE_URL ) ) : ?>
					<a class="ts-emu-official" href="<?php echo esc_url( $official ); ?>" target="_blank" rel="nofollow noopener">Official Site</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( TS_EMU_CPT ) && is_main_query() && in_the_loop() ) {
		ts_emu_styles();
		$content = ts_emu_render_card( get_the_ID() ) . $content;
	}
	return $content;
} );

/* ==========================================================
 * 5. [emulators] — grid of all emulators
 * ========================================================== */
add_shortcode( 'emulators', function ( $atts ) {
	$atts = shortcode_atts( array(
		'orderby' => 'title', // title | date | menu_order
		'order'   => 'ASC',
	), $atts, 'emulators' );

	$order = strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC';
	$q = new WP_Query( array(
		'post_type'      => TS_EMU_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => in_array( $atts['orderby'], array( 'title', 'date', 'menu_order' ), true ) ? $atts['orderby'] : 'title',
		'order'          => $order,
		'no_found_rows'  => true,
	) );

	ts_emu_styles();

	ob_start();

	if ( ! $q->have_posts() ) {
		echo '<p class="ts-emu-empty">No emulators published yet.</p>';
		return ob_get_clean();
	}
	?>
	<div class="ts-emu-grid">
		<?php while ( $q->have_posts() ) : $q->the_post();
			$pid       = get_the_ID();
			$logo      = get_the_post_thumbnail_url( $pid, 'medium' );
			$version   = get_post_meta( $pid, 'emu_version', true );
			$platforms = get_post_meta( $pid, 'emu_platforms', true );
			?>
			<a class="ts-emu-item" href="<?php the_permalink(); ?>">
				<div class="ts-emu-item-logo">
					<?php if ( $logo ) : ?>
						<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?> logo" loading="lazy">
					<?php else : ?>
						<span class="ts-emu-item-noimg"><?php echo esc_html( mb_substr( get_the_title(), 0, 1 ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="ts-emu-item-name"><?php the_title(); ?></div>
				<?php if ( $version ) : ?><div class="ts-emu-item-ver"><?php echo esc_html( $version ); ?></div><?php endif; ?>
				<?php echo ts_emu_platform_chips( $platforms ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>
				<span class="ts-emu-item-cta">View &amp; Download &rarr;</span>
			</a>
		<?php endwhile; ?>
	</div>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
} );

/* ==========================================================
 * 6. SCHEMA.ORG JSON-LD (single emulator)
 * ========================================================== */
add_action( 'wp_head', function () {
	if ( ! is_singular( TS_EMU_CPT ) ) {
		return;
	}
	$id        = get_the_ID();
	$platforms = get_post_meta( $id, 'emu_platforms', true );
	$version   = get_post_meta( $id, 'emu_version', true );

	$schema = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'SoftwareApplication',
		'name'                => get_the_title( $id ),
		'applicationCategory' => 'GameApplication',
		'url'                 => get_permalink( $id ),
		'offers'              => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD' ),
	);
	if ( $platforms ) {
		$schema['operatingSystem'] = $platforms;
	}
	if ( $version ) {
		$schema['softwareVersion'] = $version;
	}
	if ( has_post_thumbnail( $id ) ) {
		$schema['image'] = get_the_post_thumbnail_url( $id, 'large' );
	}
	$excerpt = get_the_excerpt( $id );
	if ( $excerpt ) {
		$schema['description'] = wp_strip_all_tags( $excerpt );
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
} );

/* ==========================================================
 * 7. STYLES
 * ========================================================== */
function ts_emu_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-emu-styles">
	/* Single emulator info card */
	.ts-emu-card{display:flex;gap:22px;align-items:flex-start;background:#fff;border:1px solid #e8e8ea;
		border-radius:16px;padding:22px 24px;margin:0 0 24px;box-shadow:0 1px 3px rgba(16,24,40,.06);}
	.ts-emu-logo{flex:0 0 auto;width:120px;height:120px;border-radius:16px;overflow:hidden;background:#f4f4f6;
		display:flex;align-items:center;justify-content:center;}
	.ts-emu-logo img{width:100%;height:100%;object-fit:cover;display:block;}
	.ts-emu-body{flex:1;min-width:0;}
	.ts-emu-rows{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px 22px;}
	.ts-emu-row{display:flex;align-items:center;justify-content:space-between;gap:12px;
		padding:8px 0;border-bottom:1px solid #f0f0f2;font-size:14px;}
	.ts-emu-row span{color:#667085;}
	.ts-emu-row strong{color:#101828;font-weight:600;text-align:right;word-break:break-word;}
	.ts-emu-ver{color:#15803d !important;}
	.ts-emu-chips{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 0;}
	.ts-emu-chip{font-size:12px;font-weight:700;color:#334155;background:#f1f5f9;border:1px solid #e2e8f0;
		border-radius:999px;padding:3px 11px;}
	.ts-emu-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}
	.ts-emu-btn{display:inline-flex;align-items:center;gap:8px;background:#e8394c;color:#fff !important;
		font-weight:700;font-size:14px;text-decoration:none;padding:10px 22px;border-radius:10px;
		box-shadow:0 2px 8px rgba(232,57,76,.26);transition:background .18s ease;}
	.ts-emu-btn:hover{background:#cf2a3c;}
	.ts-emu-official{display:inline-flex;align-items:center;gap:8px;background:#111827;color:#fff !important;
		font-weight:700;font-size:14px;text-decoration:none;padding:10px 18px;border-radius:10px;transition:background .18s ease;}
	.ts-emu-official:hover{background:#1f2937;}

	/* [emulators] grid */
	.ts-emu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;
		max-width:1180px;margin:22px auto;}
	.ts-emu-item{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;
		background:#fff;border:1px solid #e8e8ea;border-radius:14px;padding:22px 16px;text-decoration:none;
		color:#101828;box-shadow:0 1px 3px rgba(16,24,40,.05);transition:border-color .15s ease,box-shadow .15s ease,transform .05s ease;}
	.ts-emu-item:hover{border-color:#f1c2c8;box-shadow:0 6px 18px rgba(16,24,40,.10);}
	.ts-emu-item:active{transform:translateY(1px);}
	.ts-emu-item-logo{width:76px;height:76px;border-radius:16px;overflow:hidden;background:#f4f4f6;
		display:flex;align-items:center;justify-content:center;}
	.ts-emu-item-logo img{width:100%;height:100%;object-fit:cover;display:block;}
	.ts-emu-item-noimg{font-size:30px;font-weight:800;color:#98a2b3;}
	.ts-emu-item-name{font-weight:800;font-size:16px;}
	.ts-emu-item-ver{font-size:12px;font-weight:700;color:#15803d;background:#ecfdf3;border:1px solid #d1fadf;
		border-radius:999px;padding:2px 10px;}
	.ts-emu-item .ts-emu-chips{justify-content:center;}
	.ts-emu-item-cta{margin-top:6px;font-size:13px;font-weight:700;color:#e8394c;}
	.ts-emu-empty{color:#667085;}
	@media (max-width:640px){
		.ts-emu-card{flex-direction:column;align-items:center;text-align:center;}
		.ts-emu-rows{grid-template-columns:1fr;width:100%;}
		.ts-emu-row strong{text-align:left;}
		.ts-emu-actions{justify-content:center;}
	}
	</style>
	<?php
}
