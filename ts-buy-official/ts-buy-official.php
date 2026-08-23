<?php
/**
 * Plugin Name: TS Buy Official
 * Description: Adds a "Buy the official version" box to posts. Paste a Nintendo eShop URL and fetch the live price (Nintendo price API) plus title, platform, excerpt and image from the store page. Every field stays editable as a manual fallback.
 * Version: 1.1
 * Author: NSPVault
 * License: GPL-2.0-or-later
 * Text Domain: ts-buy-official
 *
 * @package TS_Buy_Official
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_BO_VER', '1.1' );

/* ============================================================
 * Settings helpers
 * ============================================================ */

/**
 * Get merged settings.
 *
 * @return array
 */
function ts_bo_settings() {
	$defaults = array(
		'post_types'      => array( 'post' ),
		'auto_append'     => 1,
		'default_country' => 'US',
		'heading'         => 'Support the developers',
		'subheading'      => 'If you enjoy this game, please buy the official version.',
		'button_text'     => 'Buy on Nintendo eShop',
		'disclaimer'      => 'Prices are fetched from the official store and may change. We are not affiliated with Nintendo.',
		'show_excerpt'    => 1,
		'output_schema'   => 0,
	);
	$saved = get_option( 'ts_bo_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$out = array_merge( $defaults, $saved );
	if ( empty( $out['post_types'] ) || ! is_array( $out['post_types'] ) ) {
		$out['post_types'] = array( 'post' );
	}
	return $out;
}

/**
 * Post types the box applies to (filterable).
 *
 * @return array
 */
function ts_bo_post_types() {
	$s = ts_bo_settings();
	return apply_filters( 'ts_bo_post_types', $s['post_types'] );
}

/**
 * Supported price regions: country code => label.
 *
 * @return array
 */
function ts_bo_countries() {
	return array(
		'US' => 'United States (USD)',
		'CA' => 'Canada (CAD)',
		'GB' => 'United Kingdom (GBP)',
		'DE' => 'Germany (EUR)',
		'FR' => 'France (EUR)',
		'ES' => 'Spain (EUR)',
		'IT' => 'Italy (EUR)',
		'AU' => 'Australia (AUD)',
		'JP' => 'Japan (JPY)',
		'MX' => 'Mexico (MXN)',
		'BR' => 'Brazil (BRL)',
	);
}

/* ============================================================
 * Meta box
 * ============================================================ */

add_action( 'add_meta_boxes', 'ts_bo_add_meta_box' );

/**
 * Register the meta box for configured post types.
 */
function ts_bo_add_meta_box() {
	foreach ( ts_bo_post_types() as $pt ) {
		add_meta_box(
			'ts_bo_box',
			'Buy Official (Nintendo eShop)',
			'ts_bo_render_meta_box',
			$pt,
			'normal',
			'default'
		);
	}
}

/**
 * Meta box fields.
 *
 * @param WP_Post $post Current post.
 */
function ts_bo_render_meta_box( $post ) {
	wp_nonce_field( 'ts_bo_save', 'ts_bo_nonce' );
	$s = ts_bo_settings();

	$enabled  = get_post_meta( $post->ID, '_ts_bo_enabled', true );
	$url      = get_post_meta( $post->ID, '_ts_bo_url', true );
	$country  = get_post_meta( $post->ID, '_ts_bo_country', true );
	$nsuid    = get_post_meta( $post->ID, '_ts_bo_nsuid', true );
	$otitle   = get_post_meta( $post->ID, '_ts_bo_title', true );
	$platform = get_post_meta( $post->ID, '_ts_bo_platform', true );
	$price    = get_post_meta( $post->ID, '_ts_bo_price', true );
	$note     = get_post_meta( $post->ID, '_ts_bo_price_note', true );
	$excerpt  = get_post_meta( $post->ID, '_ts_bo_excerpt', true );
	$image    = get_post_meta( $post->ID, '_ts_bo_image', true );

	if ( '' === $country ) {
		$country = $s['default_country'];
	}
	// Default enabled state for a fresh post: off until a URL/price is set.
	$is_enabled = ( '1' === $enabled );
	?>
	<style>
		.ts-bo-wrap label{ font-weight:600; display:block; margin:12px 0 4px; }
		.ts-bo-wrap input[type=text], .ts-bo-wrap input[type=url], .ts-bo-wrap textarea, .ts-bo-wrap select{ width:100%; }
		.ts-bo-wrap textarea{ min-height:70px; }
		.ts-bo-row{ display:flex; gap:14px; flex-wrap:wrap; }
		.ts-bo-row > div{ flex:1; min-width:180px; }
		.ts-bo-fetch{ margin-top:10px; }
		.ts-bo-status{ margin-left:10px; font-style:italic; color:#555; }
		.ts-bo-hint{ color:#777; font-size:12px; margin:3px 0 0; }
		.ts-bo-preview img{ max-width:120px; height:auto; border-radius:8px; margin-top:6px; display:block; }
	</style>
	<div class="ts-bo-wrap">
		<p>
			<label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
				<input type="checkbox" name="ts_bo_enabled" value="1" <?php checked( $is_enabled ); ?>>
				Show the "Buy Official" box on this post
			</label>
		</p>

		<label for="ts_bo_url">Nintendo eShop URL</label>
		<input type="url" id="ts_bo_url" name="ts_bo_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.nintendo.com/us/store/products/...">
		<p class="ts-bo-hint">Paste the official store page for this game, then click Fetch to auto-fill the fields below.</p>

		<div class="ts-bo-row">
			<div>
				<label for="ts_bo_country">Price region</label>
				<select id="ts_bo_country" name="ts_bo_country">
					<?php foreach ( ts_bo_countries() as $cc => $label ) : ?>
						<option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $country, $cc ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="ts_bo_nsuid">nsuID (eShop ID)</label>
				<input type="text" id="ts_bo_nsuid" name="ts_bo_nsuid" value="<?php echo esc_attr( $nsuid ); ?>" placeholder="auto-detected">
			</div>
		</div>

		<p class="ts-bo-fetch">
			<button type="button" class="button button-secondary" id="ts_bo_fetch_btn">Fetch details</button>
			<span class="ts-bo-status" id="ts_bo_status"></span>
		</p>

		<hr>

		<div class="ts-bo-row">
			<div>
				<label for="ts_bo_title">Official title</label>
				<input type="text" id="ts_bo_title" name="ts_bo_title" value="<?php echo esc_attr( $otitle ); ?>">
			</div>
			<div>
				<label for="ts_bo_platform">Platform</label>
				<input type="text" id="ts_bo_platform" name="ts_bo_platform" value="<?php echo esc_attr( $platform ); ?>" placeholder="Nintendo Switch">
			</div>
		</div>

		<div class="ts-bo-row">
			<div>
				<label for="ts_bo_price">Price</label>
				<input type="text" id="ts_bo_price" name="ts_bo_price" value="<?php echo esc_attr( $price ); ?>" placeholder="$59.99">
			</div>
			<div>
				<label for="ts_bo_price_note">Price note (optional)</label>
				<input type="text" id="ts_bo_price_note" name="ts_bo_price_note" value="<?php echo esc_attr( $note ); ?>" placeholder="e.g. On sale — was $59.99">
			</div>
		</div>

		<label for="ts_bo_excerpt">Short description</label>
		<textarea id="ts_bo_excerpt" name="ts_bo_excerpt"><?php echo esc_textarea( $excerpt ); ?></textarea>

		<label for="ts_bo_image">Box art / image URL</label>
		<input type="url" id="ts_bo_image" name="ts_bo_image" value="<?php echo esc_attr( $image ); ?>">
		<input type="hidden" id="ts_bo_img_w" name="ts_bo_img_w" value="<?php echo esc_attr( get_post_meta( $post->ID, '_ts_bo_img_w', true ) ); ?>">
		<input type="hidden" id="ts_bo_img_h" name="ts_bo_img_h" value="<?php echo esc_attr( get_post_meta( $post->ID, '_ts_bo_img_h', true ) ); ?>">
		<div class="ts-bo-preview"><?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" alt=""><?php endif; ?></div>
	</div>

	<script>
	(function(){
		var btn = document.getElementById('ts_bo_fetch_btn');
		if ( ! btn ) { return; }
		btn.addEventListener('click', function(){
			var status = document.getElementById('ts_bo_status');
			var url    = document.getElementById('ts_bo_url').value.trim();
			var cc     = document.getElementById('ts_bo_country').value;
			if ( ! url ) { status.textContent = 'Enter the eShop URL first.'; return; }
			status.textContent = 'Fetching…';
			btn.disabled = true;

			var data = new FormData();
			data.append('action', 'ts_bo_fetch');
			data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ts_bo_fetch' ) ); ?>');
			data.append('url', url);
			data.append('country', cc);

			fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
				.then(function(r){ return r.json(); })
				.then(function(res){
					btn.disabled = false;
					if ( ! res || ! res.success ) {
						status.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Fetch failed.';
						return;
					}
					var d = res.data;
					function set(id, val){ var el = document.getElementById(id); if ( el && val ) { el.value = val; } }
					set('ts_bo_nsuid', d.nsuid);
					set('ts_bo_title', d.title);
					set('ts_bo_platform', d.platform);
					set('ts_bo_price', d.price);
					set('ts_bo_price_note', d.price_note);
					set('ts_bo_excerpt', d.excerpt);
					set('ts_bo_image', d.image);
					// Image dimensions (reserve space -> no layout shift).
					var wEl = document.getElementById('ts_bo_img_w');
					var hEl = document.getElementById('ts_bo_img_h');
					if ( wEl ) { wEl.value = d.img_w || ''; }
					if ( hEl ) { hEl.value = d.img_h || ''; }
					// Auto-tick the enable checkbox once we have something to show.
					var chk = document.querySelector('input[name="ts_bo_enabled"]');
					if ( chk && ( d.price || d.title ) ) { chk.checked = true; }
					// Refresh preview image.
					var prev = document.querySelector('.ts-bo-preview');
					if ( prev && d.image ) { prev.innerHTML = '<img src="' + d.image.replace(/"/g,'&quot;') + '" alt="">'; }
					status.textContent = d.notice ? d.notice : 'Done.';
				})
				.catch(function(){
					btn.disabled = false;
					status.textContent = 'Network error.';
				});
		});
	})();
	</script>
	<?php
}

add_action( 'save_post', 'ts_bo_save_meta' );

/**
 * Persist meta box fields.
 *
 * @param int $post_id Post ID.
 */
function ts_bo_save_meta( $post_id ) {
	if ( ! isset( $_POST['ts_bo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ts_bo_nonce'] ) ), 'ts_bo_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_ts_bo_enabled', isset( $_POST['ts_bo_enabled'] ) ? '1' : '0' );

	$text_fields = array(
		'_ts_bo_url'        => 'ts_bo_url',
		'_ts_bo_country'    => 'ts_bo_country',
		'_ts_bo_nsuid'      => 'ts_bo_nsuid',
		'_ts_bo_title'      => 'ts_bo_title',
		'_ts_bo_platform'   => 'ts_bo_platform',
		'_ts_bo_price'      => 'ts_bo_price',
		'_ts_bo_price_note' => 'ts_bo_price_note',
		'_ts_bo_image'      => 'ts_bo_image',
	);
	foreach ( $text_fields as $meta_key => $field ) {
		$val = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '_ts_bo_url' === $meta_key || '_ts_bo_image' === $meta_key ) {
			$val = esc_url_raw( $val );
		}
		if ( '_ts_bo_nsuid' === $meta_key ) {
			$val = preg_replace( '/[^0-9]/', '', $val );
		}
		update_post_meta( $post_id, $meta_key, $val );
	}

	$excerpt = isset( $_POST['ts_bo_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ts_bo_excerpt'] ) ) : '';
	update_post_meta( $post_id, '_ts_bo_excerpt', $excerpt );

	$img_w = isset( $_POST['ts_bo_img_w'] ) ? absint( $_POST['ts_bo_img_w'] ) : 0;
	$img_h = isset( $_POST['ts_bo_img_h'] ) ? absint( $_POST['ts_bo_img_h'] ) : 0;
	update_post_meta( $post_id, '_ts_bo_img_w', $img_w );
	update_post_meta( $post_id, '_ts_bo_img_h', $img_h );
}

/* ============================================================
 * AJAX: fetch details from the eShop page + price API
 * ============================================================ */

add_action( 'wp_ajax_ts_bo_fetch', 'ts_bo_ajax_fetch' );

/**
 * Handle the "Fetch details" button.
 */
function ts_bo_ajax_fetch() {
	check_ajax_referer( 'ts_bo_fetch', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'Not allowed.' ) );
	}

	$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$country = isset( $_POST['country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', wp_unslash( $_POST['country'] ) ) ) : 'US';
	if ( '' === $url ) {
		wp_send_json_error( array( 'message' => 'No URL.' ) );
	}

	$out = array(
		'nsuid'      => '',
		'title'      => '',
		'platform'   => 'Nintendo Switch',
		'price'      => '',
		'price_note' => '',
		'excerpt'    => '',
		'image'      => '',
		'img_w'      => 0,
		'img_h'      => 0,
		'notice'     => '',
	);

	$html = ts_bo_remote_html( $url );
	if ( is_wp_error( $html ) ) {
		wp_send_json_error( array( 'message' => 'Could not open the eShop page: ' . $html->get_error_message() ) );
	}

	// Open Graph metadata.
	$out['title']   = ts_bo_meta_content( $html, 'og:title' );
	$out['excerpt'] = ts_bo_meta_content( $html, 'og:description' );
	$out['image']   = ts_bo_meta_content( $html, 'og:image' );

	// Intrinsic image dimensions so the front end can reserve space (no CLS).
	if ( $out['image'] ) {
		$dims = ts_bo_image_dims( $out['image'] );
		if ( $dims ) {
			$out['img_w'] = $dims[0];
			$out['img_h'] = $dims[1];
		}
	}

	// Clean the title (drop trailing store suffixes).
	if ( $out['title'] ) {
		$out['title'] = preg_replace( '/\s*(?:for Nintendo Switch.*|\|\s*Nintendo.*|-\s*Nintendo.*)$/i', '', $out['title'] );
		$out['title'] = trim( $out['title'] );
	}

	// Platform hint.
	if ( preg_match( '/Nintendo\s+Switch\s*2/i', $html ) ) {
		$out['platform'] = 'Nintendo Switch 2';
	}

	// nsuID.
	$out['nsuid'] = ts_bo_extract_nsuid( $html );

	// Price via Nintendo price API.
	if ( $out['nsuid'] ) {
		$price = ts_bo_fetch_price( $out['nsuid'], $country );
		if ( ! is_wp_error( $price ) ) {
			$out['price']      = $price['price'];
			$out['price_note'] = $price['note'];
		} else {
			$out['notice'] = 'Loaded details, but price lookup failed (' . $price->get_error_message() . '). You can enter the price manually.';
		}
	} else {
		$out['notice'] = 'Loaded details, but could not detect the eShop ID for live price. Enter the price manually, or paste the nsuID.';
	}

	if ( '' === $out['notice'] ) {
		$out['notice'] = 'Done.';
	}

	wp_send_json_success( $out );
}

/**
 * Fetch remote HTML with a browser user-agent.
 *
 * @param string $url URL.
 * @return string|WP_Error
 */
function ts_bo_remote_html( $url ) {
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'http', 'HTTP ' . $code );
	}
	$body = wp_remote_retrieve_body( $res );
	if ( '' === $body ) {
		return new WP_Error( 'empty', 'Empty response' );
	}
	return $body;
}

/**
 * Get an image's pixel dimensions by downloading it once (admin-time only).
 *
 * @param string $url Image URL.
 * @return array|false [ width, height ] or false.
 */
function ts_bo_image_dims( $url ) {
	if ( ! function_exists( 'getimagesizefromstring' ) ) {
		return false;
	}
	$res = wp_remote_get(
		$url,
		array(
			'timeout'    => 12,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		)
	);
	if ( is_wp_error( $res ) ) {
		return false;
	}
	$body = wp_remote_retrieve_body( $res );
	if ( '' === $body ) {
		return false;
	}
	$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
		return false;
	}
	return array( (int) $info[0], (int) $info[1] );
}

/**
 * Pull a <meta property="..."> (or name) content value.
 *
 * @param string $html HTML.
 * @param string $prop Property, e.g. og:title.
 * @return string
 */
function ts_bo_meta_content( $html, $prop ) {
	$p = preg_quote( $prop, '/' );
	// property="og:title" content="..."
	if ( preg_match( '/<meta[^>]+(?:property|name)=["\']' . $p . '["\'][^>]*content=["\'](.*?)["\']/is', $html, $m ) ) {
		return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
	}
	// content="..." before property="og:title"
	if ( preg_match( '/<meta[^>]+content=["\'](.*?)["\'][^>]*(?:property|name)=["\']' . $p . '["\']/is', $html, $m ) ) {
		return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
	}
	return '';
}

/**
 * Try to find the game's nsuID in the store page.
 *
 * @param string $html HTML.
 * @return string
 */
function ts_bo_extract_nsuid( $html ) {
	$patterns = array(
		'/"nsuid"\s*:\s*"?(\d{14})"?/i',
		'/nsuid["\']?\s*[:=]\s*["\']?(\d{14})/i',
		'/\\\\"nsuid\\\\"\s*:\s*\\\\"(\d{14})\\\\"/i',
		'/\b(7000\d{10}|7001\d{10})\b/',
	);
	foreach ( $patterns as $re ) {
		if ( preg_match( $re, $html, $m ) ) {
			return $m[1];
		}
	}
	return '';
}

/**
 * Query Nintendo's price API.
 *
 * @param string $nsuid   14-digit eShop ID.
 * @param string $country ISO country code.
 * @return array|WP_Error [ 'price' => string, 'note' => string ]
 */
function ts_bo_fetch_price( $nsuid, $country ) {
	$nsuid = preg_replace( '/[^0-9]/', '', $nsuid );
	if ( '' === $nsuid ) {
		return new WP_Error( 'nsuid', 'No nsuID' );
	}
	$api = add_query_arg(
		array(
			'country' => $country,
			'lang'    => 'en',
			'ids'     => $nsuid,
		),
		'https://api.ec.nintendo.com/v1/price'
	);
	$res = wp_remote_get(
		$api,
		array(
			'timeout'    => 12,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'http', 'price API HTTP ' . $code );
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['prices'][0] ) ) {
		return new WP_Error( 'parse', 'no price data' );
	}
	$row    = $data['prices'][0];
	$status = isset( $row['sales_status'] ) ? $row['sales_status'] : '';

	if ( 'not_found' === $status || 'unreleased' === $status ) {
		return new WP_Error( 'status', 'not on sale in this region' );
	}

	$regular  = isset( $row['regular_price']['amount'] ) ? $row['regular_price']['amount'] : '';
	$discount = isset( $row['discount_price']['amount'] ) ? $row['discount_price']['amount'] : '';

	if ( $discount ) {
		return array(
			'price' => $discount,
			'note'  => $regular ? 'On sale — normally ' . $regular : 'On sale',
		);
	}
	if ( $regular ) {
		return array(
			'price' => $regular,
			'note'  => '',
		);
	}
	return new WP_Error( 'parse', 'price missing' );
}

/* ============================================================
 * Front-end rendering
 * ============================================================ */

/**
 * Build the box HTML for a post, or '' if nothing to show.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ts_bo_render_box( $post_id ) {
	if ( '1' !== get_post_meta( $post_id, '_ts_bo_enabled', true ) ) {
		return '';
	}
	$s        = ts_bo_settings();
	$url      = get_post_meta( $post_id, '_ts_bo_url', true );
	$title    = get_post_meta( $post_id, '_ts_bo_title', true );
	$platform = get_post_meta( $post_id, '_ts_bo_platform', true );
	$price    = get_post_meta( $post_id, '_ts_bo_price', true );
	$note     = get_post_meta( $post_id, '_ts_bo_price_note', true );
	$excerpt  = get_post_meta( $post_id, '_ts_bo_excerpt', true );
	$image    = get_post_meta( $post_id, '_ts_bo_image', true );
	$img_w    = (int) get_post_meta( $post_id, '_ts_bo_img_w', true );
	$img_h    = (int) get_post_meta( $post_id, '_ts_bo_img_h', true );

	if ( empty( $s['show_excerpt'] ) ) {
		$excerpt = '';
	}

	// Need at least a buy link to be useful.
	if ( '' === $url && '' === $price && '' === $title ) {
		return '';
	}
	if ( '' === $title ) {
		$title = get_the_title( $post_id );
	}

	ob_start();
	?>
	<div class="ts-bo-card" role="complementary" aria-label="Buy the official version">
		<div class="ts-bo-eyebrow">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
			<span><?php echo esc_html( $s['heading'] ); ?></span>
		</div>

		<div class="ts-bo-body">
			<?php if ( $image ) : ?>
				<div class="ts-bo-art"<?php echo ( $img_w && $img_h ) ? ' style="aspect-ratio:' . (int) $img_w . '/' . (int) $img_h . ';"' : ''; ?>>
					<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $title ); ?> — official cover art"
						<?php echo ( $img_w && $img_h ) ? 'width="' . (int) $img_w . '" height="' . (int) $img_h . '" ' : ''; ?>loading="lazy" decoding="async">
				</div>
			<?php endif; ?>

			<div class="ts-bo-main">
				<?php if ( $s['subheading'] ) : ?><p class="ts-bo-sub"><?php echo esc_html( $s['subheading'] ); ?></p><?php endif; ?>
				<h3 class="ts-bo-title"><?php echo esc_html( $title ); ?></h3>

				<div class="ts-bo-chips">
					<?php if ( $platform ) : ?><span class="ts-bo-chip ts-bo-chip-plat"><?php echo esc_html( $platform ); ?></span><?php endif; ?>
					<?php if ( $price ) : ?><span class="ts-bo-chip ts-bo-chip-price"><?php echo esc_html( $price ); ?></span><?php endif; ?>
					<?php if ( $note ) : ?><span class="ts-bo-note"><?php echo esc_html( $note ); ?></span><?php endif; ?>
				</div>

				<?php if ( $excerpt ) : ?><p class="ts-bo-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 45, '…' ) ); ?></p><?php endif; ?>

				<?php if ( $url ) : ?>
					<a class="ts-bo-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="nofollow noopener sponsored">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
						<?php echo esc_html( $s['button_text'] ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $s['disclaimer'] ) : ?><p class="ts-bo-disclaimer"><?php echo esc_html( $s['disclaimer'] ); ?></p><?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', 'ts_bo_append_content', 20 );

/**
 * Auto-append the box to the end of single post content.
 *
 * @param string $content Content.
 * @return string
 */
function ts_bo_append_content( $content ) {
	$s = ts_bo_settings();
	if ( empty( $s['auto_append'] ) ) {
		return $content;
	}
	if ( ! is_singular( ts_bo_post_types() ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$box = ts_bo_render_box( get_the_ID() );
	if ( '' === $box ) {
		return $content;
	}
	return $content . $box;
}

add_shortcode( 'ts_buy_official', 'ts_bo_shortcode' );

/**
 * Shortcode: [ts_buy_official] (optionally id="123").
 *
 * @param array $atts Attributes.
 * @return string
 */
function ts_bo_shortcode( $atts ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'ts_buy_official' );
	$post_id = (int) $atts['id'];
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	return $post_id ? ts_bo_render_box( $post_id ) : '';
}

/* ============================================================
 * Structured data (optional, off by default)
 * ============================================================ */

add_action( 'wp_head', 'ts_bo_schema', 5 );

/**
 * Output a VideoGame JSON-LD node for the current post, if enabled.
 *
 * Deliberately describes the GAME (name, platform, image) — never a
 * third-party Offer/price — so it can't be flagged as misleading markup.
 * Off by default: enable only if no other plugin already emits game schema
 * for these posts, to avoid duplicate VideoGame entities.
 */
function ts_bo_schema() {
	if ( ! is_singular( ts_bo_post_types() ) ) {
		return;
	}
	$s = ts_bo_settings();
	if ( empty( $s['output_schema'] ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id || '1' !== get_post_meta( $post_id, '_ts_bo_enabled', true ) ) {
		return;
	}
	$title = get_post_meta( $post_id, '_ts_bo_title', true );
	if ( '' === $title ) {
		$title = get_the_title( $post_id );
	}
	if ( '' === $title ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'VideoGame',
		'name'     => $title,
		'url'      => get_permalink( $post_id ),
	);

	$image = get_post_meta( $post_id, '_ts_bo_image', true );
	if ( $image ) {
		$schema['image'] = $image;
	}
	$platform = get_post_meta( $post_id, '_ts_bo_platform', true );
	if ( $platform ) {
		$schema['gamePlatform'] = $platform;
	}
	$excerpt = get_post_meta( $post_id, '_ts_bo_excerpt', true );
	if ( $excerpt && ! empty( $s['show_excerpt'] ) ) {
		$schema['description'] = wp_strip_all_tags( $excerpt );
	}
	$url = get_post_meta( $post_id, '_ts_bo_url', true );
	if ( $url ) {
		$schema['sameAs'] = $url;
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}

/* ============================================================
 * Front-end styles
 * ============================================================ */

add_action( 'wp_head', 'ts_bo_styles' );

/**
 * Inline CSS for the box.
 */
function ts_bo_styles() {
	if ( ! is_singular( ts_bo_post_types() ) ) {
		return;
	}
	?>
	<style id="ts-bo-css">
	.ts-bo-card{
		--bo-accent:#e8394c; --bo-accent2:#ff6a5a; --bo-ink:#0f172a; --bo-muted:#64748b;
		border:1px solid #eceef3; border-radius:18px; background:#fff;
		box-shadow:0 12px 30px rgba(15,23,42,.07); overflow:hidden; margin:32px 0;
	}
	.ts-bo-eyebrow{ display:flex; align-items:center; gap:8px; padding:12px 18px;
		font-size:13px; font-weight:800; letter-spacing:.02em; color:#fff;
		background:linear-gradient(90deg,var(--bo-accent),var(--bo-accent2)); }
	.ts-bo-eyebrow svg{ width:16px; height:16px; }
	.ts-bo-body{ display:flex; gap:20px; padding:20px; align-items:flex-start; }
	.ts-bo-art{ flex:0 0 auto; width:150px; max-width:38%; border-radius:12px;
		background:#f1f5f9; overflow:hidden; }
	.ts-bo-art img{ width:100%; height:auto; border-radius:12px; display:block;
		box-shadow:0 8px 20px rgba(15,23,42,.16); }
	.ts-bo-main{ flex:1; min-width:0; }
	.ts-bo-sub{ margin:0 0 6px; color:var(--bo-muted); font-size:14px; }
	.ts-bo-title{ margin:0 0 12px; font-size:20px; font-weight:800; line-height:1.25; color:var(--bo-ink); }
	.ts-bo-chips{ display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:12px; }
	.ts-bo-chip{ font-size:13px; font-weight:800; padding:4px 12px; border-radius:999px; }
	.ts-bo-chip-plat{ color:#334155; background:#f1f5f9; border:1px solid #e6eaf1; }
	.ts-bo-chip-price{ color:var(--bo-accent); background:#fff1f2; border:1px solid #fbdfe3; }
	.ts-bo-note{ font-size:12.5px; color:#15803d; font-weight:700; }
	.ts-bo-excerpt{ margin:0 0 16px; color:#334155; font-size:14.5px; line-height:1.6; }
	.ts-bo-btn{ display:inline-flex; align-items:center; gap:9px; text-decoration:none;
		background:linear-gradient(135deg,var(--bo-accent),var(--bo-accent2)); color:#fff;
		font-weight:800; font-size:15px; padding:12px 22px; border-radius:12px;
		box-shadow:0 8px 18px rgba(232,57,76,.30); transition:transform .15s ease, box-shadow .15s ease; }
	.ts-bo-btn:hover{ transform:translateY(-1px); box-shadow:0 12px 22px rgba(232,57,76,.38); color:#fff; }
	.ts-bo-btn svg{ width:18px; height:18px; }
	.ts-bo-disclaimer{ margin:14px 0 0; color:#94a3b8; font-size:12px; line-height:1.5; }
	@media (max-width:560px){ .ts-bo-body{ flex-direction:column; } .ts-bo-art{ width:130px; max-width:130px; } }
	</style>
	<?php
}

/* ============================================================
 * Settings page
 * ============================================================ */

add_action( 'admin_menu', 'ts_bo_admin_menu' );

/**
 * Add the settings page under Settings.
 */
function ts_bo_admin_menu() {
	add_options_page( 'Buy Official', 'Buy Official', 'manage_options', 'ts-buy-official', 'ts_bo_settings_page' );
}

add_action( 'admin_init', 'ts_bo_register_settings' );

/**
 * Register the settings option with a sanitizer.
 */
function ts_bo_register_settings() {
	register_setting( 'ts_bo_group', 'ts_bo_settings', 'ts_bo_sanitize_settings' );
}

/**
 * Sanitize settings.
 *
 * @param array $input Raw input.
 * @return array
 */
function ts_bo_sanitize_settings( $input ) {
	$out = array();
	$out['post_types']      = ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) )
		? array_map( 'sanitize_key', $input['post_types'] ) : array( 'post' );
	if ( empty( $out['post_types'] ) ) {
		$out['post_types'] = array( 'post' );
	}
	$out['auto_append']     = empty( $input['auto_append'] ) ? 0 : 1;
	$out['show_excerpt']    = empty( $input['show_excerpt'] ) ? 0 : 1;
	$out['output_schema']   = empty( $input['output_schema'] ) ? 0 : 1;
	$out['default_country'] = isset( $input['default_country'] ) ? strtoupper( sanitize_text_field( $input['default_country'] ) ) : 'US';
	$out['heading']         = isset( $input['heading'] ) ? sanitize_text_field( $input['heading'] ) : '';
	$out['subheading']      = isset( $input['subheading'] ) ? sanitize_text_field( $input['subheading'] ) : '';
	$out['button_text']     = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : '';
	$out['disclaimer']      = isset( $input['disclaimer'] ) ? sanitize_textarea_field( $input['disclaimer'] ) : '';
	return $out;
}

/**
 * Render the settings page.
 */
function ts_bo_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s          = ts_bo_settings();
	$all_types  = get_post_types( array( 'public' => true ), 'objects' );
	?>
	<div class="wrap">
		<h1>Buy Official</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ts_bo_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Show on post types</th>
					<td>
						<?php foreach ( $all_types as $pt ) : ?>
							<label style="display:inline-block;margin:0 16px 6px 0;">
								<input type="checkbox" name="ts_bo_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, $s['post_types'], true ) ); ?>>
								<?php echo esc_html( $pt->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Auto-append to content</th>
					<td><label><input type="checkbox" name="ts_bo_settings[auto_append]" value="1" <?php checked( ! empty( $s['auto_append'] ) ); ?>> Show the box automatically at the end of each post. (Turn off to place it manually with the <code>[ts_buy_official]</code> shortcode.)</label></td>
				</tr>
				<tr>
					<th scope="row">Show description</th>
					<td><label><input type="checkbox" name="ts_bo_settings[show_excerpt]" value="1" <?php checked( ! empty( $s['show_excerpt'] ) ); ?>> Include the short description in the box. (Turn off to avoid reusing the store's copy — the box still shows title, platform, price and button.)</label></td>
				</tr>
				<tr>
					<th scope="row">Structured data</th>
					<td><label><input type="checkbox" name="ts_bo_settings[output_schema]" value="1" <?php checked( ! empty( $s['output_schema'] ) ); ?>> Output <code>VideoGame</code> schema for the game. <strong>Leave OFF</strong> if another plugin (e.g. your Game Info box) already adds game schema to these posts, to avoid duplicate entities.</label></td>
				</tr>
				<tr>
					<th scope="row">Default price region</th>
					<td>
						<select name="ts_bo_settings[default_country]">
							<?php foreach ( ts_bo_countries() as $cc => $label ) : ?>
								<option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $s['default_country'], $cc ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Heading</th>
					<td><input type="text" class="regular-text" name="ts_bo_settings[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Subheading</th>
					<td><input type="text" class="large-text" name="ts_bo_settings[subheading]" value="<?php echo esc_attr( $s['subheading'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Button text</th>
					<td><input type="text" class="regular-text" name="ts_bo_settings[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Disclaimer</th>
					<td><textarea class="large-text" rows="2" name="ts_bo_settings[disclaimer]"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2>How it works</h2>
		<ol>
			<li>Edit a game post and find the <strong>Buy Official (Nintendo eShop)</strong> box.</li>
			<li>Paste the game's official Nintendo eShop URL and click <strong>Fetch details</strong>.</li>
			<li>The live price, title, platform, description and image auto-fill. Edit anything by hand if needed, then Update.</li>
		</ol>
		<p><em>Live prices come from Nintendo's price API; the title/description/image are read from the eShop page. If Nintendo changes their page layout the auto-fill may miss a field — just type it in manually.</em></p>
	</div>
	<?php
}
