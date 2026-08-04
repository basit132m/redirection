<?php
/**
 * Plugin Name: TS Download Box
 * Description: Adds download links to a game/post via a repeatable metabox. On the public page it shows a single "Get It Now" button that sends visitors to an external download page. Exposes the links via a REST endpoint so the external page can display them. The external download-page domain is configurable in Settings.
 * Version: 3.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_DL_VERSION', '3.7' );

// Ignore repeat clicks from the same visitor within this many seconds, so a
// double-click or quick refresh does not inflate the download count.
if ( ! defined( 'TS_DL_HIT_DEDUPE_SECONDS' ) ) {
	define( 'TS_DL_HIT_DEDUPE_SECONDS', 15 );
}

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
		// Where the download page reads each game-info value from.
		// Empty = use this plugin's own field. A meta key = read that custom
		// field. "tax:slug" = read the terms of that taxonomy (e.g. Genre).
		'map_genre'         => '',
		'map_size'          => '',
		'map_version'       => '',
		'map_title_id'      => '',
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

	foreach ( array( 'map_genre', 'map_size', 'map_version', 'map_title_id' ) as $mkey ) {
		$settings[ $mkey ] = isset( $_POST[ $mkey ] ) ? sanitize_text_field( wp_unslash( $_POST[ $mkey ] ) ) : '';
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
			<h2>Game information source</h2>
				<p class="description" style="max-width:820px;">The download page shows Genre, Game Size, Version and Title ID. Choose where each value is read from: leave <strong>blank</strong> to use this plugin&#8217;s own field, enter a <strong>custom field (meta) key</strong>, or enter <code>tax:slug</code> to read a taxonomy&#8217;s terms (good for Genre). Use the inspector below to find the exact keys.</p>
				<table class="form-table" role="presentation">
					<?php
					$ts_dl_map_fields = array(
						'map_genre'    => 'Genre',
						'map_size'     => 'Game Size',
						'map_version'  => 'Version',
						'map_title_id' => 'Title ID',
					);
					foreach ( $ts_dl_map_fields as $ts_dl_mkey => $ts_dl_mlabel ) :
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $ts_dl_mkey ); ?>"><?php echo esc_html( $ts_dl_mlabel ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $ts_dl_mkey ); ?>" id="<?php echo esc_attr( $ts_dl_mkey ); ?>" type="text" class="regular-text code"
									value="<?php echo esc_attr( $settings[ $ts_dl_mkey ] ); ?>"
									placeholder="meta_key  or  tax:genre">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( 'Save changes' ); ?>
		</form>

		<hr>
		<h2>Field inspector</h2>
			<p class="description">Enter a published game&#8217;s ID to list every custom field and taxonomy stored on it, so you can find the right key to map above.</p>
			<form method="get" action="">
				<input type="hidden" name="page" value="ts-download-box">
				<input type="number" name="inspect" min="1" value="<?php echo isset( $_GET['inspect'] ) ? (int) $_GET['inspect'] : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>" placeholder="Post ID" class="small-text">
				<?php submit_button( 'Inspect', 'secondary', 'do_inspect', false ); ?>
			</form>
			<?php
			if ( isset( $_GET['inspect'] ) && (int) $_GET['inspect'] > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification
				ts_dl_render_inspector( (int) $_GET['inspect'] );
			}
			?>

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
/**
 * List all custom fields and taxonomy terms for a post, to help find the
 * correct key to map in "Game information source".
 */
function ts_dl_render_inspector( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		echo '<div class="notice notice-error inline"><p>No post found with that ID.</p></div>';
		return;
	}

	echo '<h3>' . esc_html( get_the_title( $post_id ) ) . ' <span style="font-weight:400;color:#777;">(ID ' . (int) $post_id . ', type: ' . esc_html( $post->post_type ) . ')</span></h3>';

	// Taxonomies (Genre is often a taxonomy).
	$taxes = get_object_taxonomies( $post->post_type, 'objects' );
	echo '<h4>Taxonomies</h4>';
	if ( empty( $taxes ) ) {
		echo '<p><em>None.</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>Map value</th><th>Taxonomy</th><th>Terms on this post</th></tr></thead><tbody>';
		foreach ( $taxes as $tax ) {
			$terms      = get_the_terms( $post_id, $tax->name );
			$term_names = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '—';
			printf(
				'<tr><td><code>tax:%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $tax->name ),
				esc_html( $tax->labels->singular_name ),
				esc_html( $term_names )
			);
		}
		echo '</tbody></table>';
	}

	// Custom fields (meta).
	$meta = get_post_custom( $post_id );
	echo '<h4>Custom fields (meta)</h4>';
	if ( empty( $meta ) ) {
		echo '<p><em>None.</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>Map value (meta key)</th><th>Value</th></tr></thead><tbody>';
		foreach ( $meta as $key => $vals ) {
			$value = isset( $vals[0] ) ? (string) $vals[0] : '';
			if ( strlen( $value ) > 200 ) {
				$value = substr( $value, 0, 200 ) . '…';
			}
			printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $key ), esc_html( $value ) );
		}
		echo '</tbody></table>';
	}
}

/**
 * Resolve a single game-info value for a post using the configured mapping.
 *
 * @param int    $post_id       Post ID.
 * @param string $source        Mapping value: '' (use fallback meta), a meta key, or "tax:slug".
 * @param string $fallback_meta This plugin's own meta key, used when $source is empty.
 * @return string
 */
function ts_dl_resolve_field( $post_id, $source, $fallback_meta ) {
	$source = trim( (string) $source );

	if ( '' === $source ) {
		return (string) get_post_meta( $post_id, $fallback_meta, true );
	}

	if ( 0 === strpos( $source, 'tax:' ) ) {
		$taxonomy = substr( $source, 4 );
		$terms    = get_the_terms( $post_id, $taxonomy );
		if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
			return implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}
		return '';
	}

	$value = get_post_meta( $post_id, $source, true );
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
	}
	return (string) $value;
}

function ts_dl_add_meta_box() {
	foreach ( ts_dl_post_types() as $pt ) {
		add_meta_box( 'ts_dl_box_meta', 'Download Links', 'ts_dl_meta_box_html', $pt, 'normal', 'high' );
	}
}
add_action( 'add_meta_boxes', 'ts_dl_add_meta_box' );

function ts_dl_meta_box_html( $post ) {
	wp_nonce_field( 'ts_dl_save', 'ts_dl_nonce' );

	$genre      = get_post_meta( $post->ID, 'ts_dl_genre', true );
	$version    = get_post_meta( $post->ID, 'ts_dl_version', true );
	$total_size = get_post_meta( $post->ID, 'ts_dl_total_size', true );
	$title_id   = get_post_meta( $post->ID, 'ts_dl_title_id', true );

	$links = get_post_meta( $post->ID, 'ts_downloads', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	if ( empty( $links ) ) {
		$links = array( array( 'section' => '', 'title' => '', 'size' => '', 'type' => '', 'url' => '' ) );
	}
	?>
	<p style="margin:0 0 8px;font-weight:600;">Game information <span style="font-weight:400;color:#777;">(shown with the featured image at the top of the post)</span></p>
	<div style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 16px;">
		<span style="flex:1 1 45%;min-width:180px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Genre</label>
			<input type="text" name="ts_dl_genre" value="<?php echo esc_attr( $genre ); ?>" placeholder="e.g. Action, RPG" style="width:100%;">
		</span>
		<span style="flex:1 1 45%;min-width:180px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Game Size</label>
			<input type="text" name="ts_dl_total_size" value="<?php echo esc_attr( $total_size ); ?>" placeholder="e.g. 4.06 GB" style="width:100%;">
		</span>
		<span style="flex:1 1 45%;min-width:180px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Version</label>
			<input type="text" name="ts_dl_version" value="<?php echo esc_attr( $version ); ?>" placeholder="e.g. 2.0.2" style="width:100%;">
		</span>
		<span style="flex:1 1 45%;min-width:180px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">Title ID</label>
			<input type="text" name="ts_dl_title_id" value="<?php echo esc_attr( $title_id ); ?>" placeholder="e.g. 0100000000010000" style="width:100%;">
		</span>
	</div>

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

	update_post_meta( $post_id, 'ts_dl_genre', sanitize_text_field( wp_unslash( $_POST['ts_dl_genre'] ?? '' ) ) );
	update_post_meta( $post_id, 'ts_dl_version', sanitize_text_field( wp_unslash( $_POST['ts_dl_version'] ?? '' ) ) );
	update_post_meta( $post_id, 'ts_dl_total_size', sanitize_text_field( wp_unslash( $_POST['ts_dl_total_size'] ?? '' ) ) );
	update_post_meta( $post_id, 'ts_dl_title_id', sanitize_text_field( wp_unslash( $_POST['ts_dl_title_id'] ?? '' ) ) );

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

	$image    = has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, 'large' ) : '';
	$settings = ts_dl_get_settings();

	return array(
		'id'       => $id,
		'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
		'image'    => (string) $image,
		'genre'    => ts_dl_resolve_field( $id, $settings['map_genre'], 'ts_dl_genre' ),
		'version'  => ts_dl_resolve_field( $id, $settings['map_version'], 'ts_dl_version' ),
		'size'     => ts_dl_resolve_field( $id, $settings['map_size'], 'ts_dl_total_size' ),
		'title_id' => ts_dl_resolve_field( $id, $settings['map_title_id'], 'ts_dl_title_id' ),
		'files'    => count( $out ),
		'links'    => $out,
	);
}

/* ==========================================================
 * 2b. REST ENDPOINT — download hit counter
 * ========================================================== */
add_action( 'rest_api_init', function () {
	register_rest_route(
		'tsdl/v1',
		'/hit/(?P<id>\d+)',
		array(
			// GET returns the current count; POST records a click and returns the new count.
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'ts_dl_rest_hit',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'ts_dl_rest_hit',
			),
		)
	);
} );

/**
 * Best-effort client IP (REMOTE_ADDR only — X-Forwarded-For is spoofable).
 */
function ts_dl_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	return sanitize_text_field( $ip );
}

/**
 * Read (GET) or increment (POST) a post's download-click counter.
 */
function ts_dl_rest_hit( $request ) {
	$id   = (int) $request['id'];
	$post = get_post( $id );

	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}

	$hits = (int) get_post_meta( $id, 'ts_dl_hits', true );

	if ( 'POST' === $request->get_method() ) {
		// Debounce repeat hits from the same IP so a double-click or refresh
		// does not inflate the count.
		$dedupe_key = 'ts_dl_hit_' . md5( ts_dl_client_ip() . '|' . $id );
		if ( ! get_transient( $dedupe_key ) ) {
			++$hits;
			update_post_meta( $id, 'ts_dl_hits', $hits );
			set_transient( $dedupe_key, 1, TS_DL_HIT_DEDUPE_SECONDS );
		}
	}

	return array(
		'id'   => $id,
		'hits' => $hits,
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

	$hits    = (int) get_post_meta( $post_id, 'ts_dl_hits', true );
	$hit_url = rest_url( 'tsdl/v1/hit/' . $post_id );

	ob_start();
	?>
	<div id="ts-downloads" class="ts-dl-wrap">
		<a href="<?php echo esc_url( $href ); ?>" class="ts-dl-getnow" target="_blank" rel="nofollow noopener" data-ts-hit-url="<?php echo esc_url( $hit_url ); ?>">
			<span class="ts-dl-getnow-icon"><?php echo $nintendo_icon; // phpcs:ignore WordPress.Security.EscapeOutput -- static inline SVG ?></span>
			<?php echo esc_html( $settings['button_text'] ?: 'Get It Now' ); ?>
			<span class="ts-dl-count" data-count="<?php echo esc_attr( $hits ); ?>" title="Total downloads">&#8681;&nbsp;<span class="ts-dl-count-n"><?php echo esc_html( number_format_i18n( $hits ) ); ?></span></span>
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

/**
 * Front-end tracking: refresh the live count on load (accurate even when the
 * page is cached) and record a hit on click via a non-blocking beacon.
 */
add_action( 'wp_footer', function () {
	if ( ! is_singular( ts_dl_post_types() ) ) {
		return;
	}
	?>
	<script>
	(function(){
		var btn = document.querySelector('.ts-dl-getnow[data-ts-hit-url]');
		if(!btn){ return; }
		var url = btn.getAttribute('data-ts-hit-url');
		var badge = btn.querySelector('.ts-dl-count');
		var numEl = btn.querySelector('.ts-dl-count-n');
		function fmt(n){ try { return Number(n).toLocaleString(); } catch(e){ return String(n); } }
		function setCount(n){ if(numEl){ numEl.textContent = fmt(n); } if(badge){ badge.setAttribute('data-count', n); } }

		// Live count so the number is fresh even behind a full-page cache.
		fetch(url, { headers: { 'Accept':'application/json' } })
			.then(function(r){ return r.json(); })
			.then(function(d){ if(d && typeof d.hits !== 'undefined'){ setCount(d.hits); } })
			.catch(function(){});

		// Record the click without delaying navigation.
		btn.addEventListener('click', function(){
			try {
				if (navigator.sendBeacon) { navigator.sendBeacon(url); }
				else { fetch(url, { method:'POST', keepalive:true }); }
			} catch(e){}
			var current = parseInt((badge && badge.getAttribute('data-count')) || '0', 10) || 0;
			setCount(current + 1); // optimistic bump for immediate feedback
		});
	})();
	</script>
	<?php
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
	.ts-dl-count{display:inline-flex;align-items:center;background:rgba(255,255,255,.22);color:#fff;font-weight:700;font-size:13px;line-height:1;padding:5px 10px;border-radius:999px;margin-left:2px;white-space:nowrap;}
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

/* ==========================================================
 * 5. ADMIN — "Downloads" column (sortable) for popularity
 * ========================================================== */
function ts_dl_add_hits_column( $columns ) {
	$columns['ts_dl_hits'] = __( 'Downloads', 'ts-download-box' );
	return $columns;
}

function ts_dl_render_hits_column( $column, $post_id ) {
	if ( 'ts_dl_hits' === $column ) {
		echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, 'ts_dl_hits', true ) ) );
	}
}

function ts_dl_sortable_hits_column( $columns ) {
	$columns['ts_dl_hits'] = 'ts_dl_hits';
	return $columns;
}

add_action( 'admin_init', function () {
	foreach ( ts_dl_post_types() as $pt ) {
		add_filter( "manage_edit-{$pt}_columns", 'ts_dl_add_hits_column' );
		add_filter( "manage_edit-{$pt}_sortable_columns", 'ts_dl_sortable_hits_column' );
		add_action( "manage_{$pt}_posts_custom_column", 'ts_dl_render_hits_column', 10, 2 );
	}
} );

// Make the Downloads column sort by the stored count.
add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'ts_dl_hits' === $query->get( 'orderby' ) ) {
		$query->set( 'meta_key', 'ts_dl_hits' );
		$query->set( 'orderby', 'meta_value_num' );
	}
} );

/* ==========================================================
 * 6. TOP ROMS PAGE — [top_roms] shortcode
 * ========================================================== */

/**
 * Default post types for the Top ROMs list (prefer the "game" CPT).
 *
 * @return string[]
 */
function ts_dl_toproms_default_types() {
	if ( post_type_exists( 'game' ) ) {
		return array( 'game' );
	}
	$types = ts_dl_post_types();
	return $types ? $types : array( 'post' );
}

/**
 * Sum of all recorded downloads across the given post types.
 *
 * @param string[] $types Post types.
 * @return int
 */
function ts_dl_toproms_total_downloads( $types ) {
	global $wpdb;
	$types = array_map( 'sanitize_key', $types );
	if ( empty( $types ) ) {
		return 0;
	}
	$in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is sanitized post-type keys.
	$sql = "SELECT COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 )
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'ts_dl_hits' AND p.post_status = 'publish' AND p.post_type IN ($in)";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $sql );
}

/**
 * kk Star Ratings average + vote count for a post (best-effort, safe if absent).
 *
 * @param int $post_id Post ID.
 * @return array{avg:float,casts:int}
 */
function ts_dl_get_rating( $post_id ) {
	$avg   = get_post_meta( $post_id, '_kksr_avg', true );
	$casts = get_post_meta( $post_id, '_kksr_casts', true );
	return array(
		'avg'   => is_numeric( $avg ) ? (float) $avg : 0.0,
		'casts' => is_numeric( $casts ) ? (int) $casts : 0,
	);
}

/**
 * Render a 5-star rating bar (partial fill supported).
 *
 * @param float $avg Average out of 5.
 * @return string
 */
function ts_dl_stars_html( $avg ) {
	$pct = max( 0, min( 100, ( $avg / 5 ) * 100 ) );
	return '<span class="tr-stars"><span class="tr-fill" style="width:' . esc_attr( $pct ) . '%"></span></span>';
}

/**
 * Ordered list of post IDs by downloads (games with hits first, then newest).
 *
 * @param string[] $types Post types.
 * @param int      $count Max items.
 * @return int[]
 */
function ts_dl_toproms_ids( $types, $count ) {
	$with = new WP_Query(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'meta_key'       => 'ts_dl_hits', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		)
	);
	$ids = $with->posts;

	// Fill any remaining slots with games that have no recorded downloads yet.
	if ( count( $ids ) < $count ) {
		$without = new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $count - count( $ids ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'post__not_in'   => $ids ? $ids : array( 0 ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'ts_dl_hits',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$ids = array_merge( $ids, $without->posts );
	}

	return $ids;
}

/**
 * [top_roms count="50" post_type="game" title="..."] — most-downloaded list.
 */
function ts_dl_toproms_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'count'     => 50,
			'post_type' => '',
			'title'     => '',
			'subtitle'  => '',
		),
		$atts,
		'top_roms'
	);

	$count = max( 1, (int) $atts['count'] );
	$types = '' !== $atts['post_type']
		? array_map( 'trim', explode( ',', $atts['post_type'] ) )
		: ts_dl_toproms_default_types();
	$types = array_values( array_filter( $types, 'post_type_exists' ) );
	if ( empty( $types ) ) {
		$types = array( 'post' );
	}

	$heading  = '' !== $atts['title'] ? $atts['title'] : sprintf( 'Top %s Most Downloaded Games', number_format_i18n( $count ) );
	$subtitle = '' !== $atts['subtitle'] ? $atts['subtitle'] : sprintf( 'The most popular games on %s, ranked by total downloads.', get_bloginfo( 'name' ) );

	$ids   = ts_dl_toproms_ids( $types, $count );
	$total = ts_dl_toproms_total_downloads( $types );

	if ( empty( $ids ) ) {
		return '<div class="ts-toproms"><p style="text-align:center;color:#777;">No games to show yet.</p></div>';
	}

	$dl_icon = '&#8681;';

	ob_start();
	ts_dl_toproms_styles();
	?>
	<div class="ts-toproms">
		<div class="tr-hero">
			<div class="tr-trophy">&#127942;</div>
			<h2 class="tr-heading"><?php echo esc_html( $heading ); ?></h2>
			<p class="tr-sub"><?php echo esc_html( $subtitle ); ?></p>
			<div class="tr-total"><?php echo wp_kses_post( $dl_icon ); ?>&nbsp;<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>&nbsp;total downloads</div>
		</div>

		<?php
		// Podium: top 3.
		$podium = array_slice( $ids, 0, 3 );
		if ( $podium ) :
			?>
			<div class="tr-podium">
				<?php
				foreach ( $podium as $i => $pid ) :
					$rank    = $i + 1;
					$hits    = (int) get_post_meta( $pid, 'ts_dl_hits', true );
					$rating  = ts_dl_get_rating( $pid );
					$thumb   = has_post_thumbnail( $pid ) ? get_the_post_thumbnail( $pid, 'medium', array( 'class' => 'tr-card-img', 'alt' => esc_attr( get_the_title( $pid ) ) ) ) : '<div class="tr-card-img tr-noimg"></div>';
					?>
					<a class="tr-card rank<?php echo (int) $rank; ?>" href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
						<div class="tr-badge">&#127942; #<?php echo (int) $rank; ?></div>
						<div class="tr-card-imgwrap"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<div class="tr-card-title"><?php echo esc_html( get_the_title( $pid ) ); ?></div>
						<div class="tr-card-rating">
							<?php echo ts_dl_stars_html( $rating['avg'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( $rating['avg'] > 0 ) : ?><span class="tr-avg"><?php echo esc_html( number_format( $rating['avg'], 1 ) ); ?></span><?php endif; ?>
						</div>
						<div class="tr-card-dl"><?php echo wp_kses_post( $dl_icon ); ?>&nbsp;<?php echo esc_html( number_format_i18n( $hits ) ); ?> downloads</div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php
		// Ranked list: 4..count.
		$rest = array_slice( $ids, 3 );
		if ( $rest ) :
			?>
			<div class="tr-list">
				<?php
				foreach ( $rest as $j => $rid ) :
					$rank   = $j + 4;
					$hits   = (int) get_post_meta( $rid, 'ts_dl_hits', true );
					$rating = ts_dl_get_rating( $rid );
					$thumb  = has_post_thumbnail( $rid ) ? get_the_post_thumbnail( $rid, 'thumbnail', array( 'class' => 'tr-thumb', 'alt' => esc_attr( get_the_title( $rid ) ) ) ) : '<div class="tr-thumb tr-noimg"></div>';
					?>
					<a class="tr-row" href="<?php echo esc_url( get_permalink( $rid ) ); ?>">
						<span class="tr-rank"><?php echo (int) $rank; ?></span>
						<?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="tr-info">
							<span class="tr-title"><?php echo esc_html( get_the_title( $rid ) ); ?></span>
							<span class="tr-rating">
								<?php echo ts_dl_stars_html( $rating['avg'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( $rating['avg'] > 0 ) : ?><span class="tr-avg"><?php echo esc_html( number_format( $rating['avg'], 1 ) ); ?></span><?php endif; ?>
							</span>
						</span>
						<span class="tr-downloads"><?php echo wp_kses_post( $dl_icon ); ?>&nbsp;<?php echo esc_html( number_format_i18n( $hits ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'top_roms', 'ts_dl_toproms_shortcode' );

/**
 * Print the Top ROMs styles once per request.
 */
function ts_dl_toproms_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style>
	.ts-toproms{--tr-red:#e8394c;--tr-red2:#b81d2f;max-width:900px;margin:0 auto;color:#1a1a1a;}
	.ts-toproms *{box-sizing:border-box;}
	.tr-hero{background:linear-gradient(135deg,#e8394c 0%,#b81d2f 100%);color:#fff;border-radius:18px;padding:34px 24px;text-align:center;margin:0 0 26px;box-shadow:0 10px 30px rgba(184,29,47,.25);}
	.tr-trophy{font-size:34px;line-height:1;margin-bottom:6px;}
	.tr-heading{margin:0 0 8px;font-size:26px;font-weight:800;color:#fff;}
	.tr-sub{margin:0 0 16px;font-size:14px;opacity:.92;}
	.tr-total{display:inline-flex;align-items:center;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:8px 18px;font-size:15px;}
	.tr-total strong{margin:0 4px;}
	.tr-podium{display:flex;gap:16px;justify-content:center;align-items:stretch;flex-wrap:wrap;margin:0 0 26px;}
	.tr-card{flex:1 1 240px;max-width:280px;background:#fff;border:1px solid #eee;border-radius:16px;padding:18px 16px 16px;text-align:center;text-decoration:none;color:#1a1a1a;box-shadow:0 4px 14px rgba(0,0,0,.06);transition:transform .15s,box-shadow .15s;position:relative;}
	.tr-card:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(0,0,0,.12);}
	.tr-card.rank1{order:2;border:2px solid #f4b400;box-shadow:0 10px 26px rgba(244,180,0,.28);}
	.tr-card.rank2{order:1;}
	.tr-card.rank3{order:3;}
	.tr-badge{display:inline-block;background:linear-gradient(135deg,#e8394c,#b81d2f);color:#fff;font-weight:800;font-size:13px;padding:4px 12px;border-radius:999px;margin-bottom:12px;}
	.tr-card.rank1 .tr-badge{background:linear-gradient(135deg,#f6c33f,#e0a416);}
	.tr-card-imgwrap{margin:0 0 12px;}
	.tr-card-img{width:100%;height:150px;object-fit:cover;border-radius:12px;display:block;}
	.tr-card.rank1 .tr-card-img{height:170px;}
	.tr-noimg{background:#f0f0f0;}
	.tr-card-title{font-weight:700;font-size:15px;margin:0 0 8px;line-height:1.3;}
	.tr-card-rating,.tr-rating{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:8px;}
	.tr-card-dl{display:inline-flex;align-items:center;color:var(--tr-red2);font-weight:800;font-size:14px;}
	.tr-stars{position:relative;display:inline-block;color:#e0e0e0;font-size:14px;letter-spacing:2px;font-family:Arial,sans-serif;}
	.tr-stars::before{content:"\2605\2605\2605\2605\2605";}
	.tr-fill{position:absolute;left:0;top:0;overflow:hidden;white-space:nowrap;color:#f5a623;}
	.tr-fill::before{content:"\2605\2605\2605\2605\2605";}
	.tr-avg{color:#6b7280;font-size:13px;font-weight:700;}
	.tr-list{display:flex;flex-direction:column;gap:10px;}
	.tr-row{display:flex;align-items:center;gap:14px;padding:12px 16px;border:1px solid #eee;border-radius:12px;background:#fff;text-decoration:none;color:#1a1a1a;transition:border-color .15s,background .15s;}
	.tr-row:hover{border-color:var(--tr-red);background:#fdf2f4;}
	.tr-rank{flex:0 0 auto;width:30px;text-align:center;font-weight:800;color:var(--tr-red);font-size:16px;}
	.tr-thumb{width:54px;height:54px;object-fit:cover;border-radius:8px;flex:0 0 auto;}
	.tr-info{flex:1;min-width:0;}
	.tr-title{display:block;font-weight:700;font-size:15px;margin:0 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
	.tr-rating{justify-content:flex-start;margin:0;}
	.tr-downloads{flex:0 0 auto;display:inline-flex;align-items:center;color:#1a1a1a;font-weight:800;font-size:14px;white-space:nowrap;}
	@media (max-width:640px){
		.tr-card.rank1,.tr-card.rank2,.tr-card.rank3{order:0;flex-basis:100%;max-width:100%;}
		.tr-heading{font-size:22px;}
		.tr-title{white-space:normal;}
	}
	</style>
	<?php
}
