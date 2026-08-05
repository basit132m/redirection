<?php
/**
 * Plugin Name: PRV Game Information & Download
 * Description: Game Information card for Pokemon ROM Vault. Shows the featured image with a download
 *              button below it, plus Genre, Creator, Version, Hack of, Updated, Language, Status and
 *              Official Site. Download links are managed in a repeatable metabox and the download
 *              button can point at an external download page (configurable in Settings). Includes a
 *              per-post download counter and a [top_roms] popularity shortcode.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRV_VERSION', '1.1' );

// Ignore repeat clicks from the same visitor within this many seconds.
if ( ! defined( 'PRV_HIT_DEDUPE_SECONDS' ) ) {
	define( 'PRV_HIT_DEDUPE_SECONDS', 15 );
}

/**
 * The Game Information fields, in display order.
 *   meta key => label
 * "Version" is included here and is the single source for the version value.
 */
function prv_fields() {
	return array(
		'prv_genre'         => 'Genre',
		'prv_creator'       => 'Creator',
		'prv_version'       => 'Version',
		'prv_hack_of'       => 'Hack of',
		'prv_updated'       => 'Updated',
		'prv_language'      => 'Language',
		'prv_status'        => 'Status',
		'prv_official_site' => 'Official Site',
	);
}

/* ==========================================================
 * SETTINGS
 * ========================================================== */

function prv_default_settings() {
	return array(
		'download_page_url' => '', // optional external download page, e.g. https://dlsite.example/download.php
		'source_id'         => '',
		'button_text'       => 'Download',
		'post_types'        => array( 'post', 'game' ),
	);
}

function prv_get_settings() {
	$saved = get_option( 'prv_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( prv_default_settings(), $saved );
}

function prv_post_types() {
	$s     = prv_get_settings();
	$types = ! empty( $s['post_types'] ) ? (array) $s['post_types'] : array( 'post' );
	return array_values( array_filter( $types, 'post_type_exists' ) );
}

add_action( 'admin_menu', function () {
	add_options_page( 'PRV Game Box', 'PRV Game Box', 'manage_options', 'prv-game-box', 'prv_settings_page' );
} );

add_action( 'admin_init', function () {
	if ( ! isset( $_POST['prv_settings_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'prv_save_settings' );

	$s = prv_get_settings();

	$s['download_page_url'] = isset( $_POST['download_page_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['download_page_url'] ) ) ) : '';
	$s['source_id']         = isset( $_POST['source_id'] ) ? sanitize_key( wp_unslash( $_POST['source_id'] ) ) : '';
	$s['button_text']       = isset( $_POST['button_text'] ) && '' !== trim( wp_unslash( $_POST['button_text'] ) )
		? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : 'Download';

	$chosen         = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
	$s['post_types'] = array_values( array_filter( $chosen, 'post_type_exists' ) );
	if ( empty( $s['post_types'] ) ) {
		$s['post_types'] = array( 'post' );
	}

	update_option( 'prv_settings', $s );
	set_transient( 'prv_saved', 1, 30 );
	wp_safe_redirect( admin_url( 'options-general.php?page=prv-game-box' ) );
	exit;
} );

function prv_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = prv_get_settings();
	if ( get_transient( 'prv_saved' ) ) {
		delete_transient( 'prv_saved' );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}
	$public_types = get_post_types( array( 'public' => true ), 'objects' );
	?>
	<div class="wrap">
		<h1>PRV Game Box</h1>
		<p>The download button under the featured image points to your download page. Leave the URL blank to link the button straight to the first download link instead.</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'prv_save_settings' ); ?>
			<input type="hidden" name="prv_settings_action" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="download_page_url">Download page URL <span style="font-weight:400;color:#777;">(optional)</span></label></th>
					<td>
						<input name="download_page_url" id="download_page_url" type="url" class="regular-text code" value="<?php echo esc_attr( $s['download_page_url'] ); ?>" placeholder="https://your-download-domain/download.php">
						<p class="description">If set, the button becomes a &ldquo;<?php echo esc_html( $s['button_text'] ); ?>&rdquo; link to this URL with <code>?p=POST_ID</code> added. If blank, the button links directly to the first download link on the post.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="source_id">Source ID <span style="font-weight:400;color:#777;">(optional)</span></label></th>
					<td><input name="source_id" id="source_id" type="text" class="regular-text code" value="<?php echo esc_attr( $s['source_id'] ); ?>" placeholder="pokemonromvault"></td>
				</tr>
				<tr>
					<th scope="row"><label for="button_text">Button text</label></th>
					<td><input name="button_text" id="button_text" type="text" class="regular-text" value="<?php echo esc_attr( $s['button_text'] ); ?>" placeholder="Download"></td>
				</tr>
				<tr>
					<th scope="row">Show box on</th>
					<td>
						<?php
						foreach ( $public_types as $name => $obj ) {
							if ( 'attachment' === $name ) {
								continue;
							}
							printf(
								'<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="post_types[]" value="%s" %s> %s</label>',
								esc_attr( $name ),
								checked( in_array( $name, (array) $s['post_types'], true ), true, false ),
								esc_html( $obj->labels->singular_name )
							);
						}
						?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save changes' ); ?>
		</form>
	</div>
	<?php
}

/* ==========================================================
 * METABOX — game info fields + download links
 * ========================================================== */
add_action( 'add_meta_boxes', function () {
	foreach ( prv_post_types() as $pt ) {
		add_meta_box( 'prv_box', 'Game Information & Download', 'prv_meta_box_html', $pt, 'normal', 'high' );
	}
} );

function prv_meta_box_html( $post ) {
	wp_nonce_field( 'prv_save', 'prv_nonce' );

	// Game info fields.
	echo '<p style="margin:0 0 8px;font-weight:600;">Game information <span style="font-weight:400;color:#777;">(shown next to the featured image)</span></p>';
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 18px;">';
	foreach ( prv_fields() as $key => $label ) {
		$val         = get_post_meta( $post->ID, $key, true );
		$placeholder = 'prv_official_site' === $key ? 'https://…' : '';
		printf(
			'<span style="flex:1 1 45%%;min-width:200px;"><label style="display:block;font-weight:600;margin-bottom:4px;">%s</label><input type="text" name="%s" value="%s" placeholder="%s" style="width:100%%;"></span>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $val ),
			esc_attr( $placeholder )
		);
	}
	echo '</div>';

	// Download links repeater.
	$links = get_post_meta( $post->ID, 'prv_downloads', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	if ( empty( $links ) ) {
		$links = array( array( 'section' => '', 'title' => '', 'type' => '', 'size' => '', 'url' => '' ) );
	}
	?>
	<p style="margin:0 0 6px;font-weight:600;">Download links</p>
	<p style="margin:0 0 8px;color:#555;">The first link is what the download button uses when no download page URL is set. <strong>Section</strong> groups links on an external download page.</p>
	<div id="prv-rows">
		<?php foreach ( $links as $link ) :
			$link = array_merge( array( 'section' => '', 'title' => '', 'type' => '', 'size' => '', 'url' => '' ), (array) $link ); ?>
			<div class="prv-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
				<input type="text" name="prv_section[]" placeholder="Section (e.g. Base)" value="<?php echo esc_attr( $link['section'] ); ?>" style="flex:1.2;" />
				<input type="text" name="prv_title[]" placeholder="Title (e.g. Direct)" value="<?php echo esc_attr( $link['title'] ); ?>" style="flex:1;" />
				<input type="text" name="prv_type[]" placeholder="Type (e.g. GBA)" value="<?php echo esc_attr( $link['type'] ); ?>" style="flex:0.7;" />
				<input type="text" name="prv_size[]" placeholder="Size" value="<?php echo esc_attr( $link['size'] ); ?>" style="flex:0.8;" />
				<input type="url" name="prv_url[]" placeholder="https://..." value="<?php echo esc_attr( $link['url'] ); ?>" style="flex:2;" />
				<button type="button" class="button prv-remove" style="flex:0 0 auto;">&times;</button>
			</div>
		<?php endforeach; ?>
	</div>
	<button type="button" class="button button-secondary" id="prv-add">+ Add Download Link</button>
	<script>
	jQuery(function($){
		$('#prv-add').on('click', function(){
			$('#prv-rows').append('<div class="prv-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
				'<input type="text" name="prv_section[]" placeholder="Section (e.g. Base)" style="flex:1.2;" />' +
				'<input type="text" name="prv_title[]" placeholder="Title (e.g. Direct)" style="flex:1;" />' +
				'<input type="text" name="prv_type[]" placeholder="Type (e.g. GBA)" style="flex:0.7;" />' +
				'<input type="text" name="prv_size[]" placeholder="Size" style="flex:0.8;" />' +
				'<input type="url" name="prv_url[]" placeholder="https://..." style="flex:2;" />' +
				'<button type="button" class="button prv-remove" style="flex:0 0 auto;">&times;</button></div>');
		});
		$(document).on('click', '.prv-remove', function(){
			if ($('#prv-rows .prv-row').length > 1) { $(this).closest('.prv-row').remove(); }
			else { $(this).closest('.prv-row').find('input').val(''); }
		});
	});
	</script>
	<?php
}

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['prv_nonce'] ) || ! wp_verify_nonce( $_POST['prv_nonce'], 'prv_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Game info fields.
	foreach ( prv_fields() as $key => $label ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		if ( 'prv_official_site' === $key ) {
			update_post_meta( $post_id, $key, esc_url_raw( $raw ) );
		} else {
			update_post_meta( $post_id, $key, sanitize_text_field( $raw ) );
		}
	}

	// Download links.
	$sections = isset( $_POST['prv_section'] ) ? (array) wp_unslash( $_POST['prv_section'] ) : array();
	$titles   = isset( $_POST['prv_title'] ) ? (array) wp_unslash( $_POST['prv_title'] ) : array();
	$types    = isset( $_POST['prv_type'] ) ? (array) wp_unslash( $_POST['prv_type'] ) : array();
	$sizes    = isset( $_POST['prv_size'] ) ? (array) wp_unslash( $_POST['prv_size'] ) : array();
	$urls     = isset( $_POST['prv_url'] ) ? (array) wp_unslash( $_POST['prv_url'] ) : array();

	$links = array();
	foreach ( $titles as $i => $title ) {
		$title = sanitize_text_field( $title );
		$url   = isset( $urls[ $i ] ) ? esc_url_raw( $urls[ $i ] ) : '';
		if ( '' === $title && '' === $url ) {
			continue;
		}
		$links[] = array(
			'section' => isset( $sections[ $i ] ) ? sanitize_text_field( $sections[ $i ] ) : '',
			'title'   => $title,
			'type'    => isset( $types[ $i ] ) ? sanitize_text_field( $types[ $i ] ) : '',
			'size'    => isset( $sizes[ $i ] ) ? sanitize_text_field( $sizes[ $i ] ) : '',
			'url'     => $url,
		);
	}
	update_post_meta( $post_id, 'prv_downloads', $links );
} );

/* ==========================================================
 * DOWNLOAD BUTTON TARGET
 * ========================================================== */
function prv_download_href( $post_id ) {
	$s    = prv_get_settings();
	$base = trim( $s['download_page_url'] );

	if ( '' !== $base ) {
		$args = array( 'p' => $post_id );
		if ( ! empty( $s['source_id'] ) ) {
			$args['s'] = $s['source_id'];
		}
		return array( 'url' => add_query_arg( $args, $base ), 'external' => true );
	}

	// No download page: link straight to the first download link.
	$links = get_post_meta( $post_id, 'prv_downloads', true );
	if ( is_array( $links ) ) {
		foreach ( $links as $l ) {
			if ( ! empty( $l['url'] ) ) {
				return array( 'url' => $l['url'], 'external' => false );
			}
		}
	}

	return array( 'url' => '', 'external' => false );
}

/* ==========================================================
 * FRONTEND — Game Information card (image + fields + button)
 * ========================================================== */
function prv_render_card( $post_id ) {
	$fields = prv_fields();

	// Collect non-empty field values.
	$rows = array();
	foreach ( $fields as $key => $label ) {
		$val = get_post_meta( $post_id, $key, true );
		if ( '' !== trim( (string) $val ) ) {
			$rows[ $key ] = array( 'label' => $label, 'value' => $val );
		}
	}

	$has_img = has_post_thumbnail( $post_id );
	$target  = prv_download_href( $post_id );

	// Nothing to show at all.
	if ( ! $has_img && empty( $rows ) && '' === $target['url'] ) {
		return '';
	}

	$s       = prv_get_settings();
	$hits    = (int) get_post_meta( $post_id, 'prv_hits', true );
	$hit_url = rest_url( 'prv/v1/hit/' . $post_id );

	ob_start();
	?>
	<div class="prv-card" id="prv-download">
		<div class="prv-media">
			<?php if ( $has_img ) : ?>
				<div class="prv-img"><?php echo get_the_post_thumbnail( $post_id, 'medium_large', array( 'alt' => esc_attr( get_the_title( $post_id ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endif; ?>

			<?php if ( '' !== $target['url'] ) : ?>
				<a class="prv-dlbtn"
					href="<?php echo esc_url( $target['url'] ); ?>"
					<?php echo $target['external'] ? '' : 'target="_blank"'; ?>
					rel="nofollow noopener"
					data-prv-hit="<?php echo esc_url( $hit_url ); ?>">
					<span class="prv-dlbtn-ic">&#8681;</span>
					<?php echo esc_html( $s['button_text'] ?: 'Download' ); ?>
					<span class="prv-count" data-count="<?php echo esc_attr( $hits ); ?>" title="Total downloads">&#8681;&nbsp;<span class="prv-count-n"><?php echo esc_html( number_format_i18n( $hits ) ); ?></span></span>
				</a>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $rows ) ) : ?>
			<div class="prv-info">
				<h2 class="prv-info-h"><span class="prv-bar"></span>Game Information</h2>
				<dl class="prv-dl">
					<?php foreach ( $rows as $key => $r ) : ?>
						<div class="prv-dl-row">
							<dt><?php echo esc_html( $r['label'] ); ?></dt>
							<dd>
								<?php
								if ( 'prv_official_site' === $key && filter_var( $r['value'], FILTER_VALIDATE_URL ) ) {
									$host = wp_parse_url( $r['value'], PHP_URL_HOST );
									printf(
										'<a href="%s" target="_blank" rel="nofollow noopener">%s</a>',
										esc_url( $r['value'] ),
										esc_html( $host ? $host : $r['value'] )
									);
								} else {
									echo esc_html( $r['value'] );
								}
								?>
							</dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( prv_post_types() ) && is_main_query() && in_the_loop() ) {
		$card = prv_render_card( get_the_ID() );
		if ( $card ) {
			$content = $card . $content;
		}
	}
	return $content;
}, 9 );

add_shortcode( 'game_box', function () {
	return prv_render_card( get_the_ID() );
} );

/* ==========================================================
 * REST — links (for an external download page) + hit counter
 * ========================================================== */
add_action( 'rest_api_init', function () {
	register_rest_route( 'prv/v1', '/links/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'prv_rest_links',
	) );
	register_rest_route( 'prv/v1', '/hit/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'prv_rest_hit' ),
		array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'prv_rest_hit' ),
	) );
} );

function prv_rest_links( $request ) {
	$id   = (int) $request['id'];
	$post = get_post( $id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}

	$links = get_post_meta( $id, 'prv_downloads', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	$out = array();
	foreach ( $links as $l ) {
		if ( empty( $l['url'] ) ) {
			continue;
		}
		$out[] = array(
			'section' => (string) ( $l['section'] ?? '' ),
			'title'   => (string) ( $l['title'] ?? '' ),
			'type'    => (string) ( $l['type'] ?? '' ),
			'size'    => (string) ( $l['size'] ?? '' ),
			'url'     => (string) $l['url'],
		);
	}

	$info = array();
	foreach ( prv_fields() as $key => $label ) {
		$info[ str_replace( 'prv_', '', $key ) ] = (string) get_post_meta( $id, $key, true );
	}

	return array_merge(
		array(
			'id'    => $id,
			'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'image' => has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, 'large' ) : '',
			'files' => count( $out ),
			'links' => $out,
		),
		$info
	);
}

function prv_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	return sanitize_text_field( $ip );
}

function prv_rest_hit( $request ) {
	$id   = (int) $request['id'];
	$post = get_post( $id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}
	$hits = (int) get_post_meta( $id, 'prv_hits', true );
	if ( 'POST' === $request->get_method() ) {
		$key = 'prv_hit_' . md5( prv_client_ip() . '|' . $id );
		if ( ! get_transient( $key ) ) {
			++$hits;
			update_post_meta( $id, 'prv_hits', $hits );
			set_transient( $key, 1, PRV_HIT_DEDUPE_SECONDS );
		}
	}
	return array( 'id' => $id, 'hits' => $hits );
}

add_action( 'wp_footer', function () {
	if ( ! is_singular( prv_post_types() ) ) {
		return;
	}
	?>
	<script id="prv-hit-js">
	(function(){
		var btn = document.querySelector('.prv-dlbtn[data-prv-hit]');
		if(!btn) return;
		var url = btn.getAttribute('data-prv-hit');
		var badge = btn.querySelector('.prv-count');
		var numEl = btn.querySelector('.prv-count-n');
		function fmt(n){ try { return Number(n).toLocaleString(); } catch(e){ return String(n); } }
		function set(n){ if(numEl){ numEl.textContent = fmt(n); } if(badge){ badge.setAttribute('data-count', n); } }
		fetch(url, { headers: { 'Accept':'application/json' } })
			.then(function(r){ return r.json(); })
			.then(function(d){ if(d && typeof d.hits !== 'undefined'){ set(d.hits); } })
			.catch(function(){});
		btn.addEventListener('click', function(){
			try { if (navigator.sendBeacon) { navigator.sendBeacon(url); } else { fetch(url,{method:'POST',keepalive:true}); } } catch(e){}
			var c = parseInt((badge && badge.getAttribute('data-count')) || '0', 10) || 0;
			set(c + 1);
		});
	})();
	</script>
	<?php
} );

/* ==========================================================
 * ADMIN — sortable "Downloads" column
 * ========================================================== */
add_action( 'admin_init', function () {
	foreach ( prv_post_types() as $pt ) {
		add_filter( "manage_edit-{$pt}_columns", function ( $c ) {
			$c['prv_hits'] = 'Downloads';
			return $c;
		} );
		add_filter( "manage_edit-{$pt}_sortable_columns", function ( $c ) {
			$c['prv_hits'] = 'prv_hits';
			return $c;
		} );
		add_action( "manage_{$pt}_posts_custom_column", function ( $col, $post_id ) {
			if ( 'prv_hits' === $col ) {
				echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, 'prv_hits', true ) ) );
			}
		}, 10, 2 );
	}
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( 'prv_hits' === $q->get( 'orderby' ) ) {
		$q->set( 'meta_key', 'prv_hits' );
		$q->set( 'orderby', 'meta_value_num' );
	}
} );

/* ==========================================================
 * STYLES
 * ========================================================== */
add_action( 'wp_head', function () {
	?>
	<style id="prv-styles">
	/* Brand: Pokemon yellow. Dark text is used on the yellow because white is
	   unreadable on it; links use a darker gold so they pass contrast on white. */
	.prv-card{--prv-brand:#FFCD0A;--prv-brand-dark:#EBBD00;--prv-ink:#1a1a1a;--prv-link:#8a6800;display:flex;gap:24px;align-items:flex-start;background:#fff;border:1px solid #e8e8ea;border-radius:16px;padding:22px 24px;margin:0 0 26px;box-shadow:0 1px 3px rgba(16,24,40,.06);}
	.prv-media{flex:0 0 auto;width:280px;max-width:42%;display:flex;flex-direction:column;gap:14px;}
	.prv-img img{width:100%;height:auto;border-radius:12px;display:block;}
	.prv-dlbtn{display:inline-flex;align-items:center;justify-content:center;gap:10px;background:var(--prv-brand);color:var(--prv-ink);font-weight:800;font-size:16px;text-decoration:none;padding:14px 20px;border-radius:12px;transition:background .2s,transform .05s;}
	.prv-dlbtn:hover{background:var(--prv-brand-dark);color:var(--prv-ink);}
	.prv-dlbtn:active{transform:translateY(1px);}
	.prv-dlbtn-ic{font-size:18px;}
	.prv-count{display:inline-flex;align-items:center;background:rgba(0,0,0,.12);color:var(--prv-ink);font-weight:800;font-size:12px;line-height:1;padding:4px 8px;border-radius:999px;white-space:nowrap;}
	.prv-info{flex:1;min-width:0;}
	.prv-info-h{display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:19px;font-weight:800;color:#101828;}
	.prv-bar{display:inline-block;width:5px;height:20px;background:var(--prv-brand);border-radius:2px;flex:0 0 5px;}
	.prv-dl{margin:0;padding:0;}
	.prv-dl-row{display:flex;gap:14px;padding:9px 0;border-bottom:1px solid #f0f0f2;}
	.prv-dl-row:last-child{border-bottom:0;}
	.prv-dl dt{flex:0 0 130px;color:#6b7280;font-size:14px;font-weight:600;margin:0;}
	.prv-dl dd{flex:1;margin:0;color:#1a1a1a;font-size:14px;font-weight:600;word-break:break-word;}
	.prv-dl dd a{color:var(--prv-link);text-decoration:none;}
	.prv-dl dd a:hover{text-decoration:underline;}
	@media (max-width:640px){
		.prv-card{flex-direction:column;}
		.prv-media{width:100%;max-width:100%;}
		.prv-dlbtn{width:100%;}
		.prv-dl dt{flex-basis:110px;}
	}
	</style>
	<?php
} );
