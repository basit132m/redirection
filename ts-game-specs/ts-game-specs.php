<?php
/**
 * Plugin Name: TS Game Specs
 * Description: Adds an "About this item" specs table to game posts. Paste a Nintendo eShop URL and best-effort auto-fill file size, play modes, players, publisher, developer, languages, release date, ESRB rating and genre. Every field stays editable. Meant to sit under your own images, download button and write-up — not to replace them.
 * Version: 1.0
 * Author: NSPVault
 * License: GPL-2.0-or-later
 * Text Domain: ts-game-specs
 *
 * @package TS_Game_Specs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_GS_VER', '1.0' );

/* ============================================================
 * Settings
 * ============================================================ */

/**
 * Merged settings.
 *
 * @return array
 */
function ts_gs_settings() {
	$defaults = array(
		'post_types'    => array( 'post' ),
		'auto_append'   => 1,
		'heading'       => 'About this item',
		'output_schema' => 0,
	);
	$saved = get_option( 'ts_gs_settings', array() );
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
 * Post types the table applies to.
 *
 * @return array
 */
function ts_gs_post_types() {
	$s = ts_gs_settings();
	return apply_filters( 'ts_gs_post_types', $s['post_types'] );
}

/**
 * The spec fields: meta key => label. Order here is display order.
 *
 * @return array
 */
function ts_gs_fields() {
	return array(
		'filesize'  => 'Game file size',
		'playmodes' => 'Supported play modes',
		'players'   => 'No. of players',
		'system'    => 'System',
		'publisher' => 'Publisher',
		'developer' => 'Developer',
		'genre'     => 'Genre',
		'languages' => 'Supported languages',
		'release'   => 'Release date',
		'esrb'      => 'ESRB rating',
		'online'    => 'Online features',
	);
}

/**
 * Known play modes for the chip renderer.
 *
 * @return array
 */
function ts_gs_play_modes() {
	return array( 'TV', 'Tabletop', 'Handheld' );
}

/* ============================================================
 * Meta box
 * ============================================================ */

add_action( 'add_meta_boxes', 'ts_gs_add_meta_box' );

/**
 * Register the meta box.
 */
function ts_gs_add_meta_box() {
	foreach ( ts_gs_post_types() as $pt ) {
		add_meta_box( 'ts_gs_box', 'Game Specs (About this item)', 'ts_gs_render_meta_box', $pt, 'normal', 'default' );
	}
}

/**
 * Meta box UI.
 *
 * @param WP_Post $post Post.
 */
function ts_gs_render_meta_box( $post ) {
	wp_nonce_field( 'ts_gs_save', 'ts_gs_nonce' );

	$enabled = get_post_meta( $post->ID, '_ts_gs_enabled', true );
	$url     = get_post_meta( $post->ID, '_ts_gs_url', true );
	$vals    = array();
	foreach ( ts_gs_fields() as $key => $label ) {
		$vals[ $key ] = get_post_meta( $post->ID, '_ts_gs_' . $key, true );
	}
	?>
	<style>
		.ts-gs-wrap label{ font-weight:600; display:block; margin:12px 0 4px; }
		.ts-gs-wrap input[type=text], .ts-gs-wrap input[type=url]{ width:100%; }
		.ts-gs-grid{ display:grid; grid-template-columns:1fr 1fr; gap:6px 18px; }
		.ts-gs-hint{ color:#777; font-size:12px; margin:3px 0 0; }
		.ts-gs-status{ margin-left:10px; font-style:italic; color:#555; }
		.ts-gs-modes{ display:flex; gap:16px; flex-wrap:wrap; margin-top:6px; }
		.ts-gs-modes label{ display:inline-flex; align-items:center; gap:6px; font-weight:500; margin:0; }
	</style>
	<div class="ts-gs-wrap">
		<p>
			<label style="display:inline-flex;align-items:center;gap:8px;">
				<input type="checkbox" name="ts_gs_enabled" value="1" <?php checked( '1' === $enabled ); ?>>
				Show the specs table on this post
			</label>
		</p>

		<label for="ts_gs_url">Nintendo eShop URL</label>
		<input type="url" id="ts_gs_url" name="ts_gs_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.nintendo.com/us/store/products/...">
		<p class="ts-gs-hint">Paste the official store page, then click Fetch. Auto-fill is best-effort — please verify each field before publishing.</p>
		<p>
			<button type="button" class="button button-secondary" id="ts_gs_fetch_btn">Fetch details</button>
			<span class="ts-gs-status" id="ts_gs_status"></span>
		</p>

		<hr>

		<label>Supported play modes</label>
		<div class="ts-gs-modes">
			<?php
			$current_modes = array_map( 'trim', explode( ',', (string) $vals['playmodes'] ) );
			foreach ( ts_gs_play_modes() as $mode ) :
				?>
				<label><input type="checkbox" class="ts-gs-mode" name="ts_gs_playmodes[]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( in_array( $mode, $current_modes, true ) ); ?>> <?php echo esc_html( $mode ); ?></label>
			<?php endforeach; ?>
		</div>

		<div class="ts-gs-grid">
			<?php
			foreach ( ts_gs_fields() as $key => $label ) :
				if ( 'playmodes' === $key ) {
					continue; // Rendered as checkboxes above.
				}
				?>
				<div>
					<label for="ts_gs_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="text" id="ts_gs_<?php echo esc_attr( $key ); ?>" name="ts_gs_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $vals[ $key ] ); ?>">
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<script>
	(function(){
		var btn = document.getElementById('ts_gs_fetch_btn');
		if ( ! btn ) { return; }
		btn.addEventListener('click', function(){
			var status = document.getElementById('ts_gs_status');
			var url = document.getElementById('ts_gs_url').value.trim();
			if ( ! url ) { status.textContent = 'Enter the eShop URL first.'; return; }
			status.textContent = 'Fetching…';
			btn.disabled = true;

			var data = new FormData();
			data.append('action', 'ts_gs_fetch');
			data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ts_gs_fetch' ) ); ?>');
			data.append('url', url);

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
					set('ts_gs_filesize', d.filesize);
					set('ts_gs_players', d.players);
					set('ts_gs_system', d.system);
					set('ts_gs_publisher', d.publisher);
					set('ts_gs_developer', d.developer);
					set('ts_gs_genre', d.genre);
					set('ts_gs_languages', d.languages);
					set('ts_gs_release', d.release);
					set('ts_gs_esrb', d.esrb);
					set('ts_gs_online', d.online);
					// Play mode checkboxes.
					if ( d.playmodes ) {
						var found = d.playmodes.split(',').map(function(s){ return s.trim().toLowerCase(); });
						document.querySelectorAll('.ts-gs-mode').forEach(function(cb){
							cb.checked = found.indexOf(cb.value.toLowerCase()) !== -1;
						});
					}
					var chk = document.querySelector('input[name="ts_gs_enabled"]');
					if ( chk ) { chk.checked = true; }
					status.textContent = d.notice ? d.notice : 'Done — please verify each field.';
				})
				.catch(function(){ btn.disabled = false; status.textContent = 'Network error.'; });
		});
	})();
	</script>
	<?php
}

add_action( 'save_post', 'ts_gs_save_meta' );

/**
 * Save meta.
 *
 * @param int $post_id Post ID.
 */
function ts_gs_save_meta( $post_id ) {
	if ( ! isset( $_POST['ts_gs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ts_gs_nonce'] ) ), 'ts_gs_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_ts_gs_enabled', isset( $_POST['ts_gs_enabled'] ) ? '1' : '0' );

	$url = isset( $_POST['ts_gs_url'] ) ? esc_url_raw( wp_unslash( $_POST['ts_gs_url'] ) ) : '';
	update_post_meta( $post_id, '_ts_gs_url', $url );

	// Play modes (checkbox array).
	$modes = array();
	if ( isset( $_POST['ts_gs_playmodes'] ) && is_array( $_POST['ts_gs_playmodes'] ) ) {
		foreach ( wp_unslash( $_POST['ts_gs_playmodes'] ) as $m ) {
			$modes[] = sanitize_text_field( $m );
		}
	}
	update_post_meta( $post_id, '_ts_gs_playmodes', implode( ',', $modes ) );

	foreach ( ts_gs_fields() as $key => $label ) {
		if ( 'playmodes' === $key ) {
			continue;
		}
		$field = 'ts_gs_' . $key;
		$val   = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		update_post_meta( $post_id, '_ts_gs_' . $key, $val );
	}
}

/* ============================================================
 * AJAX fetch (best-effort scrape of the eShop page)
 * ============================================================ */

add_action( 'wp_ajax_ts_gs_fetch', 'ts_gs_ajax_fetch' );

/**
 * Fetch + parse the store page.
 */
function ts_gs_ajax_fetch() {
	check_ajax_referer( 'ts_gs_fetch', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'Not allowed.' ) );
	}

	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	if ( '' === $url ) {
		wp_send_json_error( array( 'message' => 'No URL.' ) );
	}

	$html = ts_gs_remote_html( $url );
	if ( is_wp_error( $html ) ) {
		wp_send_json_error( array( 'message' => 'Could not open the page: ' . $html->get_error_message() ) );
	}

	// Collect every embedded JSON blob we can find (Next.js data, JSON-LD, etc.).
	$json = ts_gs_collect_json( $html );

	$out = array(
		'filesize'  => ts_gs_pick( $json, array( 'downloadsize', 'romsize', 'filesize' ) ),
		'players'   => ts_gs_pick( $json, array( 'numberofplayers', 'playercount', 'playerss', 'players' ) ),
		'system'    => ts_gs_pick( $json, array( 'platform', 'hardware', 'system' ) ),
		'publisher' => ts_gs_pick( $json, array( 'publisher', 'softwarepublisher' ) ),
		'developer' => ts_gs_pick( $json, array( 'developer', 'softwaredeveloper', 'developername' ) ),
		'genre'     => ts_gs_pick( $json, array( 'genres', 'genre', 'category' ) ),
		'languages' => ts_gs_pick( $json, array( 'languages', 'supportedlanguages', 'language' ) ),
		'release'   => ts_gs_pick( $json, array( 'releasedatedisplay', 'releasedate', 'release' ) ),
		'esrb'      => ts_gs_pick( $json, array( 'esrbrating', 'esrb', 'contentrating', 'rating' ) ),
		'playmodes' => ts_gs_pick( $json, array( 'playmodes', 'supportedplaymodes', 'playmode' ) ),
		'online'    => ts_gs_pick( $json, array( 'onlinefeatures', 'nso', 'onlineplay' ) ),
		'notice'    => '',
	);

	// Normalise a few things.
	if ( '' === $out['system'] && preg_match( '/Nintendo\s+Switch\s*2/i', $html ) ) {
		$out['system'] = 'Nintendo Switch 2';
	} elseif ( '' === $out['system'] && preg_match( '/Nintendo\s+Switch/i', $html ) ) {
		$out['system'] = 'Nintendo Switch';
	}

	$any = false;
	foreach ( $out as $k => $v ) {
		if ( 'notice' !== $k && '' !== $v ) {
			$any = true;
			break;
		}
	}
	$out['notice'] = $any
		? 'Fetched what we could — please verify each field before publishing.'
		: 'Could not auto-read the spec fields from this page (Nintendo renders them dynamically). Please fill them in manually.';

	wp_send_json_success( $out );
}

/**
 * Remote GET with a browser UA.
 *
 * @param string $url URL.
 * @return string|WP_Error
 */
function ts_gs_remote_html( $url ) {
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
	return '' === $body ? new WP_Error( 'empty', 'Empty response' ) : $body;
}

/**
 * Collect decoded JSON from <script> blobs on the page (Next data, JSON-LD, etc.).
 *
 * @param string $html HTML.
 * @return array Merged decoded structures.
 */
function ts_gs_collect_json( $html ) {
	$blobs = array();

	// __NEXT_DATA__ and application/json / ld+json script tags.
	if ( preg_match_all( '#<script[^>]*type=["\'](?:application/json|application/ld\+json)["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
		foreach ( $m[1] as $raw ) {
			$blobs[] = $raw;
		}
	}
	if ( preg_match( '#<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $mm ) ) {
		$blobs[] = $mm[1];
	}

	$decoded = array();
	foreach ( $blobs as $raw ) {
		$data = json_decode( trim( $raw ), true );
		if ( is_array( $data ) ) {
			$decoded[] = $data;
		}
	}
	return $decoded;
}

/**
 * Search decoded JSON structures for the first key matching any needle,
 * returning a human-readable string.
 *
 * @param array $structures Decoded JSON blobs.
 * @param array $needles    Lower-case key fragments to match.
 * @return string
 */
function ts_gs_pick( $structures, $needles ) {
	foreach ( $structures as $struct ) {
		$hit = ts_gs_deep_find( $struct, $needles );
		if ( '' !== $hit ) {
			return $hit;
		}
	}
	return '';
}

/**
 * Recursively find a value whose key contains one of the needles.
 *
 * @param mixed $node    Current node.
 * @param array $needles Lower-case fragments.
 * @return string
 */
function ts_gs_deep_find( $node, $needles ) {
	if ( ! is_array( $node ) ) {
		return '';
	}
	foreach ( $node as $key => $val ) {
		if ( is_string( $key ) ) {
			$lk = strtolower( $key );
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $lk, $needle ) ) {
					$str = ts_gs_stringify( $val );
					if ( '' !== $str ) {
						return $str;
					}
				}
			}
		}
	}
	// Recurse.
	foreach ( $node as $val ) {
		if ( is_array( $val ) ) {
			$deep = ts_gs_deep_find( $val, $needles );
			if ( '' !== $deep ) {
				return $deep;
			}
		}
	}
	return '';
}

/**
 * Flatten a scalar / list / object into a readable comma string.
 *
 * @param mixed $val Value.
 * @return string
 */
function ts_gs_stringify( $val ) {
	if ( is_string( $val ) || is_numeric( $val ) ) {
		$s = trim( (string) $val );
		return ( '' === $s || 'null' === strtolower( $s ) ) ? '' : $s;
	}
	if ( is_array( $val ) ) {
		// List of scalars -> join.
		$parts = array();
		foreach ( $val as $item ) {
			if ( is_string( $item ) || is_numeric( $item ) ) {
				$parts[] = trim( (string) $item );
			} elseif ( is_array( $item ) ) {
				// Prefer common label keys inside objects.
				foreach ( array( 'name', 'label', 'value', 'text', 'title', 'code' ) as $lk ) {
					if ( isset( $item[ $lk ] ) && ( is_string( $item[ $lk ] ) || is_numeric( $item[ $lk ] ) ) ) {
						$parts[] = trim( (string) $item[ $lk ] );
						break;
					}
				}
			}
		}
		$parts = array_filter( array_unique( $parts ) );
		return implode( ', ', $parts );
	}
	return '';
}

/* ============================================================
 * Front-end rendering
 * ============================================================ */

/**
 * Icon SVG for a given field key.
 *
 * @param string $key Field key.
 * @return string
 */
function ts_gs_icon( $key ) {
	$icons = array(
		'filesize'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2zm0 0v10c0 1.1 3.6 2 8 2s8-.9 8-2V7"/>',
		'playmodes' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18v11H3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 20h8"/>',
		'players'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 10a4 4 0 100-8 4 4 0 000 8zm14 10v-2a4 4 0 00-3-3.87"/>',
		'system'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 3v18M15 3v18M4 8h5m6 0h5M4 16h5m6 0h5M6 3h12a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2z"/>',
		'publisher' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>',
		'developer' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 105.66 5.66L18 14l2 2 2-2-6.3-6.3zM7 17l-4 4M10 8l-7 7 4 4 7-7"/>',
		'genre'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M3 12l9 9 9-9-9-9-9 9z"/>',
		'languages' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18 15 15 0 010-18z"/>',
		'release'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/>',
		'esrb'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/>',
		'online'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8"/>',
	);
	$path = isset( $icons[ $key ] ) ? $icons[ $key ] : '<circle cx="12" cy="12" r="9"/>';
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">' . $path . '</svg>';
}

/**
 * Render the specs table for a post, or '' if empty.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ts_gs_render_table( $post_id ) {
	if ( '1' !== get_post_meta( $post_id, '_ts_gs_enabled', true ) ) {
		return '';
	}
	$s      = ts_gs_settings();
	$fields = ts_gs_fields();

	$rows = array();
	foreach ( $fields as $key => $label ) {
		$val = get_post_meta( $post_id, '_ts_gs_' . $key, true );
		if ( '' !== trim( (string) $val ) ) {
			$rows[ $key ] = array( 'label' => $label, 'value' => $val );
		}
	}
	if ( empty( $rows ) ) {
		return '';
	}

	ob_start();
	?>
	<section class="ts-gs-card" aria-label="<?php echo esc_attr( $s['heading'] ); ?>">
		<h2 class="ts-gs-heading"><?php echo esc_html( $s['heading'] ); ?></h2>
		<dl class="ts-gs-list">
			<?php foreach ( $rows as $key => $row ) : ?>
				<div class="ts-gs-row">
					<dt class="ts-gs-key">
						<span class="ts-gs-ic"><?php echo ts_gs_icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php echo esc_html( $row['label'] ); ?>
					</dt>
					<dd class="ts-gs-val">
						<?php
						if ( 'playmodes' === $key ) {
							$modes = array_filter( array_map( 'trim', explode( ',', $row['value'] ) ) );
							echo '<span class="ts-gs-modes">';
							foreach ( $modes as $mode ) {
								echo '<span class="ts-gs-mode">' . esc_html( $mode ) . ' mode</span>';
							}
							echo '</span>';
						} else {
							echo esc_html( $row['value'] );
						}
						?>
					</dd>
				</div>
			<?php endforeach; ?>
		</dl>
	</section>
	<?php
	return ob_get_clean();
}

add_filter( 'the_content', 'ts_gs_append_content', 15 );

/**
 * Auto-append the table to single post content.
 *
 * @param string $content Content.
 * @return string
 */
function ts_gs_append_content( $content ) {
	$s = ts_gs_settings();
	if ( empty( $s['auto_append'] ) ) {
		return $content;
	}
	if ( ! is_singular( ts_gs_post_types() ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$table = ts_gs_render_table( get_the_ID() );
	return '' === $table ? $content : $content . $table;
}

add_shortcode( 'ts_game_specs', 'ts_gs_shortcode' );

/**
 * Shortcode [ts_game_specs id="123"].
 *
 * @param array $atts Attributes.
 * @return string
 */
function ts_gs_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ts_game_specs' );
	$pid  = (int) $atts['id'];
	if ( ! $pid ) {
		$pid = get_the_ID();
	}
	return $pid ? ts_gs_render_table( $pid ) : '';
}

/* ============================================================
 * Structured data (optional, off by default)
 * ============================================================ */

add_action( 'wp_head', 'ts_gs_schema', 6 );

/**
 * Emit VideoGame JSON-LD from the specs (off by default to avoid duplicates).
 */
function ts_gs_schema() {
	if ( ! is_singular( ts_gs_post_types() ) ) {
		return;
	}
	$s = ts_gs_settings();
	if ( empty( $s['output_schema'] ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id || '1' !== get_post_meta( $post_id, '_ts_gs_enabled', true ) ) {
		return;
	}

	$get = function ( $k ) use ( $post_id ) {
		return trim( (string) get_post_meta( $post_id, '_ts_gs_' . $k, true ) );
	};

	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'VideoGame',
		'name'     => get_the_title( $post_id ),
		'url'      => get_permalink( $post_id ),
	);

	if ( $get( 'system' ) ) {
		$schema['gamePlatform'] = $get( 'system' );
	}
	if ( $get( 'genre' ) ) {
		$schema['genre'] = array_values( array_filter( array_map( 'trim', explode( ',', $get( 'genre' ) ) ) ) );
	}
	if ( $get( 'publisher' ) ) {
		$schema['publisher'] = array( '@type' => 'Organization', 'name' => $get( 'publisher' ) );
	}
	if ( $get( 'developer' ) ) {
		$schema['author'] = array( '@type' => 'Organization', 'name' => $get( 'developer' ) );
	}
	if ( $get( 'languages' ) ) {
		$schema['inLanguage'] = array_values( array_filter( array_map( 'trim', explode( ',', $get( 'languages' ) ) ) ) );
	}
	if ( $get( 'esrb' ) ) {
		$schema['contentRating'] = $get( 'esrb' );
	}
	if ( $get( 'filesize' ) ) {
		$schema['fileSize'] = $get( 'filesize' );
	}
	if ( $get( 'release' ) ) {
		$ts = strtotime( $get( 'release' ) );
		$schema['datePublished'] = $ts ? gmdate( 'Y-m-d', $ts ) : $get( 'release' );
	}
	if ( $get( 'playmodes' ) ) {
		$modes = array_map( 'strtolower', array_map( 'trim', explode( ',', $get( 'playmodes' ) ) ) );
		$schema['playMode'] = in_array( 'multiplayer', $modes, true ) ? 'MultiPlayer' : 'SinglePlayer';
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}

/* ============================================================
 * Styles
 * ============================================================ */

add_action( 'wp_head', 'ts_gs_styles' );

/**
 * Inline CSS.
 */
function ts_gs_styles() {
	if ( ! is_singular( ts_gs_post_types() ) ) {
		return;
	}
	?>
	<style id="ts-gs-css">
	.ts-gs-card{
		--gs-ink:#0f172a; --gs-muted:#64748b; --gs-line:#eceef3; --gs-accent:#e8394c;
		box-sizing:border-box; border:1px solid #dfe3ec; border-radius:16px; background:#fff;
		padding:8px 24px 12px; margin:32px 0; color:var(--gs-ink);
		box-shadow:0 10px 30px rgba(15,23,42,.06);
	}
	.ts-gs-card *{ box-sizing:border-box; }
	.ts-gs-heading{ font-size:19px; font-weight:800; letter-spacing:-.01em; margin:16px 0 4px; color:var(--gs-ink); }
	.ts-gs-list{ margin:0; padding:0; }
	.ts-gs-row{ display:grid; grid-template-columns:220px 1fr; gap:20px; align-items:start;
		padding:16px 0; border-top:1px solid var(--gs-line); }
	.ts-gs-row:first-child{ border-top:0; }
	.ts-gs-key{ display:flex; align-items:center; gap:10px; margin:0; font-weight:700; font-size:14.5px; color:var(--gs-ink); }
	.ts-gs-ic{ display:inline-flex; flex:0 0 auto; color:var(--gs-muted); }
	.ts-gs-ic svg{ width:20px; height:20px; display:block; }
	.ts-gs-val{ margin:0; font-size:14.5px; line-height:1.6; color:#334155; overflow-wrap:anywhere; }
	.ts-gs-modes{ display:inline-flex; flex-wrap:wrap; gap:8px; }
	.ts-gs-mode{ font-size:13px; font-weight:600; color:#334155; background:#f1f5f9;
		border:1px solid #e6eaf1; border-radius:8px; padding:4px 11px; }
	@media (max-width:560px){
		.ts-gs-row{ grid-template-columns:1fr; gap:6px; }
		.ts-gs-val{ padding-left:30px; }
	}
	</style>
	<?php
}

/* ============================================================
 * Settings page
 * ============================================================ */

add_action( 'admin_menu', 'ts_gs_admin_menu' );

/**
 * Settings menu.
 */
function ts_gs_admin_menu() {
	add_options_page( 'Game Specs', 'Game Specs', 'manage_options', 'ts-game-specs', 'ts_gs_settings_page' );
}

add_action( 'admin_init', 'ts_gs_register_settings' );

/**
 * Register settings.
 */
function ts_gs_register_settings() {
	register_setting( 'ts_gs_group', 'ts_gs_settings', 'ts_gs_sanitize' );
}

/**
 * Sanitize settings.
 *
 * @param array $input Input.
 * @return array
 */
function ts_gs_sanitize( $input ) {
	$out                  = array();
	$out['post_types']    = ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) )
		? array_map( 'sanitize_key', $input['post_types'] ) : array( 'post' );
	if ( empty( $out['post_types'] ) ) {
		$out['post_types'] = array( 'post' );
	}
	$out['auto_append']   = empty( $input['auto_append'] ) ? 0 : 1;
	$out['output_schema'] = empty( $input['output_schema'] ) ? 0 : 1;
	$out['heading']       = isset( $input['heading'] ) ? sanitize_text_field( $input['heading'] ) : 'About this item';
	return $out;
}

/**
 * Settings page.
 */
function ts_gs_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s         = ts_gs_settings();
	$all_types = get_post_types( array( 'public' => true ), 'objects' );
	?>
	<div class="wrap">
		<h1>Game Specs</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ts_gs_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Show on post types</th>
					<td>
						<?php foreach ( $all_types as $pt ) : ?>
							<label style="display:inline-block;margin:0 16px 6px 0;">
								<input type="checkbox" name="ts_gs_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $s['post_types'], true ) ); ?>>
								<?php echo esc_html( $pt->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Auto-append to content</th>
					<td><label><input type="checkbox" name="ts_gs_settings[auto_append]" value="1" <?php checked( ! empty( $s['auto_append'] ) ); ?>> Show the specs table automatically after the post content. (Turn off to place it with the <code>[ts_game_specs]</code> shortcode where you want it.)</label></td>
				</tr>
				<tr>
					<th scope="row">Heading</th>
					<td><input type="text" class="regular-text" name="ts_gs_settings[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Structured data</th>
					<td><label><input type="checkbox" name="ts_gs_settings[output_schema]" value="1" <?php checked( ! empty( $s['output_schema'] ) ); ?>> Output <code>VideoGame</code> schema from these specs. <strong>Leave OFF</strong> if another plugin already adds game schema to these posts, to avoid duplicate entities.</label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2>How it works</h2>
		<ol>
			<li>Edit a game post, open the <strong>Game Specs (About this item)</strong> box.</li>
			<li>Paste the Nintendo eShop URL and click <strong>Fetch details</strong> — it best-effort fills what it can read.</li>
			<li><strong>Verify every field</strong> (Nintendo renders specs dynamically, so some may need typing in), tick “Show the specs table”, and Update.</li>
		</ol>
		<p><em>This table is meant to sit under your own images, download button and write-up — supporting original content, not replacing it.</em></p>
	</div>
	<?php
}
