<?php
/**
 * Plugin Name: TS Download Box
 * Description: Adds download links to a game/post via a repeatable metabox. On the public page it shows a single "Get It Now" button that sends visitors to an external download page. Exposes the links via a REST endpoint so the external page can display them. The external download-page domain is configurable in Settings.
 * Version: 3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_DL_VERSION', '3.2' );

/* ==========================================================
 * SETTINGS
 * ========================================================== */

/**
 * Default settings.
 */
function ts_dl_default_settings() {
	return array(
		'download_page_url' => '', // e.g. https://dlsitex.online/download.php
		'source_id'         => '', // optional key so one download.php can serve several sites
		'button_text'       => 'Get It Now',
		'post_types'        => array( 'post', 'game' ),
	);
}

/**
 * Get merged settings.
 */
function ts_dl_get_settings() {
	$saved = get_option( 'ts_dl_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( ts_dl_default_settings(), $saved );
}

/**
 * Which post types the download box applies to.
 */
function ts_dl_post_types() {
	$settings = ts_dl_get_settings();
	$types    = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
	// Only keep types that actually exist on this site.
	return array_values( array_filter( $types, 'post_type_exists' ) );
}

/**
 * Register the settings page under Settings.
 */
function ts_dl_admin_menu() {
	add_options_page(
		'TS Download Box',
		'TS Download Box',
		'manage_options',
		'ts-download-box',
		'ts_dl_settings_page_html'
	);
}
add_action( 'admin_menu', 'ts_dl_admin_menu' );

/**
 * Save settings (own nonce-checked handler, kept simple & explicit).
 */
function ts_dl_maybe_save_settings() {
	if ( ! isset( $_POST['ts_dl_settings_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ts_dl_save_settings' );

	$settings = ts_dl_get_settings();

	$settings['download_page_url'] = isset( $_POST['download_page_url'] )
		? esc_url_raw( trim( wp_unslash( $_POST['download_page_url'] ) ) )
		: '';

	$settings['source_id'] = isset( $_POST['source_id'] )
		? sanitize_key( wp_unslash( $_POST['source_id'] ) )
		: '';

	$settings['button_text'] = isset( $_POST['button_text'] ) && '' !== trim( wp_unslash( $_POST['button_text'] ) )
		? sanitize_text_field( wp_unslash( $_POST['button_text'] ) )
		: 'Get It Now';

	$chosen = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
	$settings['post_types'] = array_values( array_filter( $chosen, 'post_type_exists' ) );
	if ( empty( $settings['post_types'] ) ) {
		$settings['post_types'] = array( 'post' );
	}

	update_option( 'ts_dl_settings', $settings );

	add_settings_error( 'ts_dl', 'saved', 'Settings saved.', 'updated' );
	set_transient( 'ts_dl_settings_errors', get_settings_errors(), 30 );

	wp_safe_redirect( admin_url( 'options-general.php?page=ts-download-box' ) );
	exit;
}
add_action( 'admin_init', 'ts_dl_maybe_save_settings' );

/**
 * Render the settings page.
 */
function ts_dl_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = ts_dl_get_settings();

	$errors = get_transient( 'ts_dl_settings_errors' );
	if ( $errors ) {
		delete_transient( 'ts_dl_settings_errors' );
		foreach ( $errors as $e ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $e['message'] ) );
		}
	}

	$public_types = get_post_types( array( 'public' => true ), 'objects' );
	?>
	<div class="wrap">
		<h1>TS Download Box</h1>
		<p>Configure the external download page. When set, game pages show a single <strong>“<?php echo esc_html( $settings['button_text'] ); ?>”</strong> button that sends visitors to that page.</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'ts_dl_save_settings' ); ?>
			<input type="hidden" name="ts_dl_settings_action" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="download_page_url">Download page URL</label></th>
					<td>
						<input name="download_page_url" id="download_page_url" type="url" class="regular-text code"
							value="<?php echo esc_attr( $settings['download_page_url'] ); ?>"
							placeholder="https://dlsitex.online/download.php">
						<p class="description">The external page that shows the timer and the links. The button links to this URL with <code>?p=POST_ID</code> added. Change it anytime.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="source_id">Source ID <span style="font-weight:400;color:#777;">(optional)</span></label></th>
					<td>
						<input name="source_id" id="source_id" type="text" class="regular-text code"
							value="<?php echo esc_attr( $settings['source_id'] ); ?>"
							placeholder="repacklabs">
						<p class="description">Only needed if <strong>one</strong> download.php serves several websites. It is added to the link as <code>&amp;s=…</code> so download.php knows which site to read. Leave blank if this download page serves only this site.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="button_text">Button text</label></th>
					<td><input name="button_text" id="button_text" type="text" class="regular-text"
						value="<?php echo esc_attr( $settings['button_text'] ); ?>" placeholder="Get It Now"></td>
				</tr>
				<tr>
					<th scope="row">Show box on</th>
					<td>
						<?php
						foreach ( $public_types as $name => $obj ) {
							if ( 'attachment' === $name ) {
								continue;
							}
							$checked = in_array( $name, (array) $settings['post_types'], true );
							printf(
								'<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="post_types[]" value="%s" %s> %s</label>',
								esc_attr( $name ),
								checked( $checked, true, false ),
								esc_html( $obj->labels->singular_name )
							);
						}
						?>
						<p class="description">Where the “Download Links” box appears in the editor. For RepackLabs, tick <strong>Game</strong>.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save changes' ); ?>
		</form>

		<hr>
		<h2>REST endpoint</h2>
		<p>The external page reads links from:</p>
		<p><code><?php echo esc_html( rest_url( 'tsdl/v1/links/POST_ID' ) ); ?></code></p>
	</div>
	<?php
}

/* ==========================================================
 * 1. ADMIN META BOX — repeatable rows + game header fields
 * ========================================================== */
function ts_dl_add_meta_box() {
	foreach ( ts_dl_post_types() as $pt ) {
		add_meta_box( 'ts_dl_box_meta', 'Download Links', 'ts_dl_meta_box_html', $pt, 'normal', 'high' );
	}
}
add_action( 'add_meta_boxes', 'ts_dl_add_meta_box' );

function ts_dl_meta_box_html( $post ) {
	wp_nonce_field( 'ts_dl_save', 'ts_dl_nonce' );

	$version    = get_post_meta( $post->ID, 'ts_dl_version', true );
	$total_size = get_post_meta( $post->ID, 'ts_dl_total_size', true );

	$links = get_post_meta( $post->ID, 'ts_downloads', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	if ( empty( $links ) ) {
		$links = array( array( 'section' => '', 'title' => '', 'size' => '', 'type' => '', 'url' => '' ) );
	}
	?>
	<p style="display:flex;gap:12px;margin:0 0 14px;">
		<span style="flex:1;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Version <span style="font-weight:400;color:#777;">(optional)</span></label>
			<input type="text" name="ts_dl_version" value="<?php echo esc_attr( $version ); ?>" placeholder="e.g. 2.0.2" style="width:100%;">
		</span>
		<span style="flex:1;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Total size <span style="font-weight:400;color:#777;">(optional)</span></label>
			<input type="text" name="ts_dl_total_size" value="<?php echo esc_attr( $total_size ); ?>" placeholder="e.g. 4.06 GB" style="width:100%;">
		</span>
	</p>

	<p style="margin:0 0 6px;color:#555;">Each row is one download link. <strong>Section</strong> groups links on the download page (e.g. “Base Game”, “Update v2.0.2”). <strong>Title</strong> is the row label (e.g. “Direct”, “Datanotes”).</p>

	<div id="ts-dl-rows">
		<?php
		foreach ( $links as $link ) :
			$link = array_merge( array( 'section' => '', 'title' => '', 'size' => '', 'type' => '', 'url' => '' ), (array) $link );
			?>
			<div class="ts-dl-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
				<input type="text" name="ts_dl_section[]" placeholder="Section (e.g. Base Game)" value="<?php echo esc_attr( $link['section'] ); ?>" style="flex:1.2;" />
				<input type="text" name="ts_dl_title[]" placeholder="Title (e.g. Direct)" value="<?php echo esc_attr( $link['title'] ); ?>" style="flex:1;" />
				<input type="text" name="ts_dl_type[]" placeholder="Type (e.g. NSP)" value="<?php echo esc_attr( $link['type'] ); ?>" style="flex:0.7;" />
				<input type="text" name="ts_dl_size[]" placeholder="Size" value="<?php echo esc_attr( $link['size'] ); ?>" style="flex:0.8;" />
				<input type="url" name="ts_dl_url[]" placeholder="https://..." value="<?php echo esc_attr( $link['url'] ); ?>" style="flex:2;" />
				<button type="button" class="button ts-dl-remove-row" style="flex:0 0 auto;">&times;</button>
			</div>
		<?php endforeach; ?>
	</div>
	<button type="button" class="button button-secondary" id="ts-dl-add-row">+ Add Download Link</button>

	<script>
	jQuery(function($){
		$('#ts-dl-add-row').on('click', function(){
			var row = $('<div class="ts-dl-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
				'<input type="text" name="ts_dl_section[]" placeholder="Section (e.g. Base Game)" style="flex:1.2;" />' +
				'<input type="text" name="ts_dl_title[]" placeholder="Title (e.g. Direct)" style="flex:1;" />' +
				'<input type="text" name="ts_dl_type[]" placeholder="Type (e.g. NSP)" style="flex:0.7;" />' +
				'<input type="text" name="ts_dl_size[]" placeholder="Size" style="flex:0.8;" />' +
				'<input type="url" name="ts_dl_url[]" placeholder="https://..." style="flex:2;" />' +
				'<button type="button" class="button ts-dl-remove-row" style="flex:0 0 auto;">&times;</button>' +
				'</div>');
			$('#ts-dl-rows').append(row);
		});
		$(document).on('click', '.ts-dl-remove-row', function(){
			if ($('#ts-dl-rows .ts-dl-row').length > 1) {
				$(this).closest('.ts-dl-row').remove();
			} else {
				$(this).closest('.ts-dl-row').find('input').val('');
			}
		});
	});
	</script>
	<?php
}

function ts_dl_save_meta( $post_id ) {
	if ( ! isset( $_POST['ts_dl_nonce'] ) || ! wp_verify_nonce( $_POST['ts_dl_nonce'], 'ts_dl_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, 'ts_dl_version', sanitize_text_field( wp_unslash( $_POST['ts_dl_version'] ?? '' ) ) );
	update_post_meta( $post_id, 'ts_dl_total_size', sanitize_text_field( wp_unslash( $_POST['ts_dl_total_size'] ?? '' ) ) );

	$sections = isset( $_POST['ts_dl_section'] ) ? (array) wp_unslash( $_POST['ts_dl_section'] ) : array();
	$titles   = isset( $_POST['ts_dl_title'] ) ? (array) wp_unslash( $_POST['ts_dl_title'] ) : array();
	$sizes    = isset( $_POST['ts_dl_size'] ) ? (array) wp_unslash( $_POST['ts_dl_size'] ) : array();
	$types    = isset( $_POST['ts_dl_type'] ) ? (array) wp_unslash( $_POST['ts_dl_type'] ) : array();
	$urls     = isset( $_POST['ts_dl_url'] ) ? (array) wp_unslash( $_POST['ts_dl_url'] ) : array();

	$links = array();
	foreach ( $titles as $i => $title ) {
		$title = sanitize_text_field( $title );
		$url   = isset( $urls[ $i ] ) ? esc_url_raw( $urls[ $i ] ) : '';
		if ( '' === $title && '' === $url ) {
			continue; // skip empty rows
		}
		$links[] = array(
			'section' => isset( $sections[ $i ] ) ? sanitize_text_field( $sections[ $i ] ) : '',
			'title'   => $title,
			'size'    => isset( $sizes[ $i ] ) ? sanitize_text_field( $sizes[ $i ] ) : '',
			'type'    => isset( $types[ $i ] ) ? sanitize_text_field( $types[ $i ] ) : '',
			'url'     => $url,
		);
	}
	update_post_meta( $post_id, 'ts_downloads', $links );
}
add_action( 'save_post', 'ts_dl_save_meta' );

/* ==========================================================
 * 2. REST ENDPOINT — links for the external download page
 * ========================================================== */
add_action( 'rest_api_init', function () {
	register_rest_route(
		'tsdl/v1',
		'/links/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'ts_dl_rest_links',
			'args'                => array(
				'id' => array(
					'validate_callback' => function ( $param ) {
						return is_numeric( $param );
					},
				),
			),
		)
	);
} );

function ts_dl_rest_links( $request ) {
	$id   = (int) $request['id'];
	$post = get_post( $id );

	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
	}

	$links = get_post_meta( $id, 'ts_downloads', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}

	$out = array();
	foreach ( $links as $link ) {
		if ( empty( $link['url'] ) ) {
			continue;
		}
		$out[] = array(
			'section' => isset( $link['section'] ) ? (string) $link['section'] : '',
			'title'   => isset( $link['title'] ) ? (string) $link['title'] : '',
			'type'    => isset( $link['type'] ) ? (string) $link['type'] : '',
			'size'    => isset( $link['size'] ) ? (string) $link['size'] : '',
			'url'     => (string) $link['url'],
		);
	}

	return array(
		'id'      => $id,
		'title'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
		'version' => (string) get_post_meta( $id, 'ts_dl_version', true ),
		'size'    => (string) get_post_meta( $id, 'ts_dl_total_size', true ),
		'files'   => count( $out ),
		'links'   => $out,
	);
}

/* ==========================================================
 * 3. FRONTEND — single "Get It Now" button
 * ========================================================== */
function ts_dl_get_download_page_link( $post_id ) {
	$settings = ts_dl_get_settings();
	$base     = trim( $settings['download_page_url'] );
	if ( '' === $base ) {
		return '';
	}
	$args = array( 'p' => $post_id );
	if ( ! empty( $settings['source_id'] ) ) {
		$args['s'] = $settings['source_id'];
	}
	return add_query_arg( $args, $base );
}

function ts_dl_render_button( $post_id ) {
	$links = get_post_meta( $post_id, 'ts_downloads', true );
	if ( ! is_array( $links ) || empty( $links ) ) {
		return '';
	}

	$settings = ts_dl_get_settings();
	$href     = ts_dl_get_download_page_link( $post_id );

	// If no external page is configured yet, fall back to listing links inline
	// so the site never shows a dead button.
	if ( '' === $href ) {
		return ts_dl_render_inline_fallback( $links );
	}

	// Nintendo Switch icon (inline SVG, inherits the button's text color).
	$nintendo_icon = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false">'
		. '<path d="M9 2H6.6A4.6 4.6 0 0 0 2 6.6v10.8A4.6 4.6 0 0 0 6.6 22H9V2zM6.75 8.3a1.65 1.65 0 1 1 0-3.3 1.65 1.65 0 0 1 0 3.3z"/>'
		. '<path d="M17.4 2H15v20h2.4a4.6 4.6 0 0 0 4.6-4.6V6.6A4.6 4.6 0 0 0 17.4 2zm-.15 16.95a1.65 1.65 0 1 1 0-3.3 1.65 1.65 0 0 1 0 3.3z"/>'
		. '</svg>';

	ob_start();
	?>
	<div id="ts-downloads" class="ts-dl-wrap">
		<a href="<?php echo esc_url( $href ); ?>" class="ts-dl-getnow" target="_blank" rel="nofollow noopener">
			<span class="ts-dl-getnow-icon"><?php echo $nintendo_icon; // phpcs:ignore WordPress.Security.EscapeOutput -- static inline SVG ?></span>
			<?php echo esc_html( $settings['button_text'] ?: 'Get It Now' ); ?>
		</a>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Fallback: original inline list, used only when no download page URL is set.
 */
function ts_dl_render_inline_fallback( $links ) {
	ob_start();
	?>
	<div id="ts-downloads" class="ts-dl-box">
		<div class="ts-dl-header"><span class="ts-dl-icon">&#8681;</span><span>Download Links</span></div>
		<div class="ts-dl-list">
			<?php
			foreach ( $links as $link ) :
				if ( empty( $link['url'] ) ) {
					continue;
				}
				$meta_parts = array_filter( array( $link['type'] ?? '', $link['size'] ?? '' ) );
				$meta_str   = implode( ' &bull; ', array_map( 'esc_html', $meta_parts ) );
				?>
				<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="nofollow noopener" class="ts-dl-btn">
					<span class="ts-dl-btn-title">&#8681; <?php echo esc_html( ( $link['title'] ?? '' ) ?: 'Download' ); ?></span>
					<?php if ( $meta_str ) : ?><span class="ts-dl-btn-meta"><?php echo $meta_str; // phpcs:ignore ?></span><?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( ts_dl_post_types() ) && is_main_query() && in_the_loop() ) {
		$box = ts_dl_render_button( get_the_ID() );
		if ( $box ) {
			$content .= $box;
		}
	}
	return $content;
}, 25 );

add_shortcode( 'download_box', function () {
	return ts_dl_render_button( get_the_ID() );
} );

/* ==========================================================
 * 4. STYLES
 * ========================================================== */
function ts_dl_styles() {
	?>
	<style>
	#ts-downloads{scroll-margin-top:80px;}
	.ts-dl-box{background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:24px 28px;color:#1a1a1a;max-width:700px;margin:20px 0;box-shadow:0 1px 4px rgba(0,0,0,.06);}
	.ts-dl-header{display:flex;align-items:center;gap:8px;font-weight:700;font-size:18px;margin-bottom:14px;color:#1a1a1a;}
	.ts-dl-icon{color:#e8394c;}
	.ts-dl-wrap{text-align:center;margin:24px auto;}
	.ts-dl-getnow{display:inline-flex;align-items:center;justify-content:center;gap:10px;background:#e8394c;color:#fff;font-weight:700;font-size:16px;text-decoration:none;padding:16px 56px;min-width:320px;border-radius:12px;transition:background .2s,transform .05s;}
	.ts-dl-getnow:hover{background:#cf2a3c;color:#fff;}
	.ts-dl-getnow:active{transform:translateY(1px);}
	.ts-dl-getnow-icon{display:inline-flex;align-items:center;}
	.ts-dl-getnow-icon svg{width:20px;height:20px;display:block;}
	@media (max-width:480px){ .ts-dl-getnow{min-width:0;width:100%;padding:16px 24px;} }
	.ts-dl-list{display:flex;flex-direction:column;gap:12px;}
	.ts-dl-btn{display:flex;justify-content:space-between;align-items:center;background:#f7f7f7;border:1px solid #e5e5e5;border-radius:10px;padding:14px 18px;text-decoration:none;transition:border-color .2s,background .2s;}
	.ts-dl-btn:hover{background:#f0f0f0;border-color:#e8394c;}
	.ts-dl-btn-title{color:#1a1a1a;font-weight:700;font-size:15px;}
	.ts-dl-btn-meta{color:#6b7280;font-size:13px;}
	@media (max-width:480px){ .ts-dl-btn{flex-direction:column;align-items:flex-start;gap:4px;} }
	</style>
	<?php
}
add_action( 'wp_head', 'ts_dl_styles' );
