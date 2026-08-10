<?php
/**
 * Plugin Name: LG Game Info Box
 * Description: Game information card for the LG site. Landscape 400x225 cover, a trimmed info list
 *              (Genre, Developer, Version, File Size, Language) and a public "Platform" taxonomy so each
 *              platform gets its own archive (e.g. /games/windows/). Includes VideoGame JSON-LD.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type(s) the card + taxonomy attach to. Filterable for a custom "game" CPT.
 *
 * @return string[]
 */
function lg_gi_post_types() {
	$types = apply_filters( 'lg_gi_post_types', array( 'post' ) );
	$types = array_values( array_filter( (array) $types ) );
	return $types ? $types : array( 'post' );
}

/**
 * The rewrite base for the Platform taxonomy. Change with the 'lg_platform_slug'
 * filter, e.g. return 'games' -> /games/windows/. Re-save Permalinks after
 * changing it.
 *
 * @return string
 */
function lg_platform_slug() {
	$slug = apply_filters( 'lg_platform_slug', 'games' );
	return trim( (string) $slug, '/' ) ?: 'games';
}

/* ==========================================================
 * 1. PLATFORM TAXONOMY  ->  /games/windows/  etc.
 * ========================================================== */
function lg_gi_register_platform() {
	register_taxonomy( 'lg_platform', lg_gi_post_types(), array(
		'labels'            => array(
			'name'          => 'Platforms',
			'singular_name' => 'Platform',
			'menu_name'     => 'Platforms',
			'all_items'     => 'All Platforms',
			'edit_item'     => 'Edit Platform',
			'add_new_item'  => 'Add New Platform',
			'search_items'  => 'Search Platforms',
		),
		'public'            => true,
		'hierarchical'      => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => lg_platform_slug(), 'with_front' => false ),
	) );
}
add_action( 'init', 'lg_gi_register_platform' );

// Flush rewrite rules on activation so /games/<platform>/ works immediately.
register_activation_hook( __FILE__, function () {
	lg_gi_register_platform();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* ==========================================================
 * 2. META FIELDS (Developer, Version, File Size, Language, links)
 * ========================================================== */
function lg_gi_fields() {
	return array(
		'lg_developer' => 'Developer',
		'lg_version'   => 'Version',
		'lg_filesize'  => 'File Size',
		'lg_language'  => 'Language',
		'lg_download'  => 'Download URL',
		'lg_official'  => 'Official Site URL',
	);
}

add_action( 'init', function () {
	foreach ( array_keys( lg_gi_fields() ) as $key ) {
		register_post_meta( 'post', $key, array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
		) );
	}
} );

add_action( 'add_meta_boxes', function () {
	foreach ( lg_gi_post_types() as $pt ) {
		add_meta_box( 'lg_gi_box', 'Game Information', 'lg_gi_meta_box_html', $pt, 'normal', 'high' );
	}
} );

function lg_gi_meta_box_html( $post ) {
	wp_nonce_field( 'lg_gi_save', 'lg_gi_nonce' );
	echo '<table class="form-table">';
	foreach ( lg_gi_fields() as $key => $label ) {
		$val  = get_post_meta( $post->ID, $key, true );
		$type = ( in_array( $key, array( 'lg_download', 'lg_official' ), true ) ) ? 'url' : 'text';
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%;"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $val )
		);
	}
	echo '</table>';
	echo '<p class="description">Genre = the post\'s Category. Platforms are set in the <strong>Platforms</strong> box (they link to /' . esc_html( lg_platform_slug() ) . '/&lt;platform&gt;/). Cover = the Featured image (shown at 400&times;225).</p>';
}

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['lg_gi_nonce'] ) || ! wp_verify_nonce( $_POST['lg_gi_nonce'], 'lg_gi_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( lg_gi_fields() ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );
		if ( in_array( $key, array( 'lg_download', 'lg_official' ), true ) ) {
			update_post_meta( $post_id, $key, esc_url_raw( trim( $raw ) ) );
		} else {
			update_post_meta( $post_id, $key, sanitize_text_field( $raw ) );
		}
	}
} );

/* ==========================================================
 * 3. IMAGE SIZE — 400x225 landscape cover
 * ========================================================== */
add_action( 'after_setup_theme', function () {
	add_image_size( 'lg_cover', 400, 225, true ); // hard crop 16:9
} );

/* ==========================================================
 * 4. FRONTEND — the card
 * ========================================================== */
function lg_gi_render( $post_id ) {
	$developer = get_post_meta( $post_id, 'lg_developer', true );
	$version   = get_post_meta( $post_id, 'lg_version', true );
	$filesize  = get_post_meta( $post_id, 'lg_filesize', true );
	$language  = get_post_meta( $post_id, 'lg_language', true );
	$download  = get_post_meta( $post_id, 'lg_download', true );
	$official  = get_post_meta( $post_id, 'lg_official', true );

	$genre     = get_the_category_list( ', ', '', $post_id );
	$platforms = get_the_term_list( $post_id, 'lg_platform', '', '', '' );
	$cover     = get_the_post_thumbnail_url( $post_id, 'lg_cover' );
	if ( ! $cover ) {
		$cover = get_the_post_thumbnail_url( $post_id, 'large' );
	}

	ob_start();
	?>
	<div class="lg-gi-card">
		<div class="lg-gi-media">
			<?php if ( $cover ) : ?>
				<div class="lg-gi-cover"><img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" loading="eager" width="400" height="225"></div>
			<?php endif; ?>
			<div class="lg-gi-actions">
				<?php if ( $download ) : ?>
					<a class="lg-gi-btn" href="<?php echo esc_url( $download ); ?>" target="_blank" rel="nofollow noopener">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
						Download Now
					</a>
				<?php endif; ?>
				<?php if ( $official && filter_var( $official, FILTER_VALIDATE_URL ) ) : ?>
					<a class="lg-gi-official" href="<?php echo esc_url( $official ); ?>" target="_blank" rel="nofollow noopener">Official Site</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="lg-gi-info">
			<h2 class="lg-gi-heading">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
				Game Information
			</h2>
			<div class="lg-gi-rows">
				<?php if ( $genre ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">Genre</span><span class="lg-gi-value lg-gi-link"><?php echo $genre; // phpcs:ignore WordPress.Security.EscapeOutput -- get_the_category_list is safe ?></span></div>
				<?php endif; ?>
				<?php if ( $developer ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">Developer</span><span class="lg-gi-value"><?php echo esc_html( $developer ); ?></span></div>
				<?php endif; ?>
				<?php if ( $version ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">Version</span><span class="lg-gi-value lg-gi-ver"><?php echo esc_html( $version ); ?></span></div>
				<?php endif; ?>
				<?php if ( $filesize ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">File Size</span><span class="lg-gi-value lg-gi-size"><?php echo esc_html( $filesize ); ?></span></div>
				<?php endif; ?>
				<?php if ( $language ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">Language</span><span class="lg-gi-value"><?php echo esc_html( $language ); ?></span></div>
				<?php endif; ?>
				<?php if ( $platforms && ! is_wp_error( $platforms ) ) : ?>
					<div class="lg-gi-row"><span class="lg-gi-label">Platforms</span><span class="lg-gi-value lg-gi-link lg-gi-platforms"><?php echo $platforms; // phpcs:ignore WordPress.Security.EscapeOutput -- get_the_term_list is safe ?></span></div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( lg_gi_post_types() ) && is_main_query() && in_the_loop() ) {
		lg_gi_styles();
		$content = lg_gi_render( get_the_ID() ) . $content;
	}
	return $content;
}, 9 );

add_shortcode( 'lg_game_info', function () {
	lg_gi_styles();
	return lg_gi_render( get_the_ID() );
} );

/* ==========================================================
 * 5. SCHEMA.ORG JSON-LD (VideoGame)
 * ========================================================== */
add_action( 'wp_head', function () {
	if ( ! is_singular( lg_gi_post_types() ) ) {
		return;
	}
	$id        = get_the_ID();
	$developer = get_post_meta( $id, 'lg_developer', true );
	$version   = get_post_meta( $id, 'lg_version', true );
	$language  = get_post_meta( $id, 'lg_language', true );
	$size      = get_post_meta( $id, 'lg_filesize', true );
	$genres    = wp_get_post_categories( $id, array( 'fields' => 'names' ) );
	$platforms = wp_get_post_terms( $id, 'lg_platform', array( 'fields' => 'names' ) );

	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'VideoGame',
		'name'     => get_the_title( $id ),
		'url'      => get_permalink( $id ),
		'offers'   => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD' ),
	);
	if ( ! empty( $genres ) ) {
		$schema['genre'] = $genres;
	}
	if ( ! empty( $platforms ) ) {
		$schema['operatingSystem'] = implode( ', ', $platforms );
		$schema['gamePlatform']    = $platforms;
	}
	if ( $developer ) {
		$schema['author'] = array( '@type' => 'Organization', 'name' => $developer );
	}
	if ( $version ) {
		$schema['softwareVersion'] = $version;
	}
	if ( $size ) {
		$schema['fileSize'] = $size;
	}
	if ( $language ) {
		$schema['inLanguage'] = $language;
	}
	if ( has_post_thumbnail( $id ) ) {
		$schema['image'] = get_the_post_thumbnail_url( $id, 'large' );
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
} );

/* ==========================================================
 * 6. STYLES
 * ========================================================== */
function lg_gi_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="lg-gi-styles">
	.lg-gi-card{display:flex;flex-direction:column;gap:22px;background:#fff;border:1px solid #e8e8ea;border-radius:16px;
		padding:22px 24px;margin:0 0 26px;box-shadow:0 1px 3px rgba(16,24,40,.06);}
	.lg-gi-media{flex:0 0 auto;display:flex;flex-direction:column;gap:12px;}
	/* Landscape 400x225 cover */
	.lg-gi-cover{width:400px;max-width:100%;aspect-ratio:16/9;border-radius:12px;overflow:hidden;
		box-shadow:0 6px 18px rgba(16,24,40,.16);background:#f4f4f6;}
	.lg-gi-cover img{width:100%;height:100%;object-fit:cover;display:block;}
	.lg-gi-actions{display:flex;flex-wrap:wrap;gap:10px;width:400px;max-width:100%;}
	.lg-gi-btn{flex:1 1 auto;display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#e8394c;color:#fff !important;
		font-weight:700;font-size:14px;text-decoration:none;padding:11px 20px;border-radius:10px;box-shadow:0 2px 8px rgba(232,57,76,.26);transition:background .18s ease;}
	.lg-gi-btn:hover{background:#cf2a3c;}
	.lg-gi-btn svg{width:16px;height:16px;}
	.lg-gi-official{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;background:#111827;color:#fff !important;
		font-weight:700;font-size:14px;text-decoration:none;padding:11px 16px;border-radius:10px;transition:background .18s ease;}
	.lg-gi-official:hover{background:#1f2937;}

	.lg-gi-info{flex:1;min-width:0;}
	.lg-gi-heading{display:flex;align-items:center;gap:8px;font-size:18px;font-weight:800;color:#101828;margin:0 0 10px;}
	.lg-gi-heading svg{width:18px;height:18px;color:#98a2b3;}
	.lg-gi-rows{display:block;}
	.lg-gi-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:11px 0;border-bottom:1px solid #f0f0f2;font-size:15px;}
	.lg-gi-row:last-child{border-bottom:0;}
	.lg-gi-label{color:#667085;flex-shrink:0;}
	.lg-gi-value{color:#101828;font-weight:600;text-align:right;min-width:0;word-break:break-word;}
	.lg-gi-ver{color:#15803d;}
	.lg-gi-size{color:#e8394c;}
	.lg-gi-link a{color:#e8394c;text-decoration:none;font-weight:600;}
	.lg-gi-link a:hover{text-decoration:underline;}
	.lg-gi-platforms{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end;}
	.lg-gi-platforms a{font-size:12px;font-weight:700;color:#334155 !important;background:#f1f5f9;border:1px solid #e2e8f0;
		border-radius:999px;padding:3px 11px;text-decoration:none;}
	.lg-gi-platforms a:hover{border-color:#e8394c;color:#e8394c !important;}

	@media (min-width:720px){
		.lg-gi-card{flex-direction:row;align-items:flex-start;}
	}
	@media (max-width:719px){
		.lg-gi-cover,.lg-gi-actions{width:100%;}
		.lg-gi-value{text-align:right;}
	}
	</style>
	<?php
}
