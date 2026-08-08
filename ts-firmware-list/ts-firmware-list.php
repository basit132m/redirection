<?php
/**
 * Plugin Name: TS Firmware List
 * Description: A simple manager for Nintendo Switch firmware downloads. Add each firmware (Name, Version,
 *              Download URL) under Settings -> Firmwares, then drop the [firmwares] shortcode on any page.
 *              Each firmware renders as a single row: name, version and a download button. The page has a
 *              live search box (also reads #fw= / ?fw= / ?q= to pre-filter from a link). The list is a
 *              responsive multi-column grid of compact cards.
 * Version: 1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_FW_OPTION', 'ts_fw_items' );

/* ==========================================================
 * 1. SETTINGS PAGE (repeatable rows)
 * ========================================================== */

/**
 * Read the stored firmware rows.
 *
 * @return array List of [ name, version, url ] rows.
 */
function ts_fw_get_items() {
	$items = get_option( TS_FW_OPTION, array() );
	return is_array( $items ) ? $items : array();
}

add_action( 'admin_menu', function () {
	add_options_page( 'Firmwares', 'Firmwares', 'manage_options', 'ts-firmwares', 'ts_fw_settings_page' );
} );

/**
 * Save handler.
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_POST['ts_fw_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ts_fw_save' );

	$names    = isset( $_POST['fw_name'] ) ? (array) wp_unslash( $_POST['fw_name'] ) : array();
	$versions = isset( $_POST['fw_version'] ) ? (array) wp_unslash( $_POST['fw_version'] ) : array();
	$urls     = isset( $_POST['fw_url'] ) ? (array) wp_unslash( $_POST['fw_url'] ) : array();

	$items = array();
	foreach ( $names as $i => $name ) {
		$name = sanitize_text_field( $name );
		$url  = isset( $urls[ $i ] ) ? esc_url_raw( trim( $urls[ $i ] ) ) : '';
		if ( '' === $name && '' === $url ) {
			continue; // skip empty rows
		}
		$items[] = array(
			'name'    => $name,
			'version' => isset( $versions[ $i ] ) ? sanitize_text_field( $versions[ $i ] ) : '',
			'url'     => $url,
		);
	}

	update_option( TS_FW_OPTION, $items );
	set_transient( 'ts_fw_saved', 1, 30 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ts-firmwares' ) );
	exit;
} );

/**
 * Render the settings screen.
 */
function ts_fw_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$items = ts_fw_get_items();
	if ( empty( $items ) ) {
		$items = array( array( 'name' => '', 'version' => '', 'url' => '' ) );
	}
	if ( get_transient( 'ts_fw_saved' ) ) {
		delete_transient( 'ts_fw_saved' );
		echo '<div class="notice notice-success is-dismissible"><p>Firmwares saved.</p></div>';
	}
	?>
	<div class="wrap">
		<h1>Firmwares</h1>
		<p>Add each firmware below, then place the shortcode <code>[firmwares]</code> on the page where you want the list to appear. Optional title: <code>[firmwares title="Nintendo Switch Firmwares"]</code>.</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'ts_fw_save' ); ?>
			<input type="hidden" name="ts_fw_action" value="1">

			<div id="ts-fw-rows">
				<?php foreach ( $items as $it ) :
					$it = array_merge( array( 'name' => '', 'version' => '', 'url' => '' ), (array) $it ); ?>
					<div class="ts-fw-adminrow" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
						<input type="text" name="fw_name[]"    placeholder="Name (e.g. Switch Firmware 18.1.0)" value="<?php echo esc_attr( $it['name'] ); ?>" style="flex:2;">
						<input type="text" name="fw_version[]" placeholder="Version (e.g. 18.1.0)"           value="<?php echo esc_attr( $it['version'] ); ?>" style="flex:1;">
						<input type="url"  name="fw_url[]"     placeholder="https://download-url"            value="<?php echo esc_attr( $it['url'] ); ?>" style="flex:3;">
						<button type="button" class="button ts-fw-remove" style="flex:0 0 auto;">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="button" class="button button-secondary" id="ts-fw-add">+ Add Firmware</button>
			<?php submit_button( 'Save firmwares' ); ?>
		</form>
	</div>
	<script>
	jQuery(function($){
		$('#ts-fw-add').on('click', function(){
			$('#ts-fw-rows').append(
				'<div class="ts-fw-adminrow" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
				'<input type="text" name="fw_name[]" placeholder="Name (e.g. Switch Firmware 18.1.0)" style="flex:2;">' +
				'<input type="text" name="fw_version[]" placeholder="Version (e.g. 18.1.0)" style="flex:1;">' +
				'<input type="url" name="fw_url[]" placeholder="https://download-url" style="flex:3;">' +
				'<button type="button" class="button ts-fw-remove" style="flex:0 0 auto;">&times;</button></div>'
			);
		});
		$(document).on('click', '.ts-fw-remove', function(){
			if ($('#ts-fw-rows .ts-fw-adminrow').length > 1) { $(this).closest('.ts-fw-adminrow').remove(); }
			else { $(this).closest('.ts-fw-adminrow').find('input').val(''); }
		});
	});
	</script>
	<?php
}

/* ==========================================================
 * 2. FRONTEND — [firmwares] shortcode
 * ========================================================== */

/**
 * Download icon (inline SVG, inherits button colour).
 *
 * @return string
 */
function ts_fw_download_icon() {
	return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
		. '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>';
}

add_shortcode( 'firmwares', function ( $atts ) {
	$atts  = shortcode_atts( array( 'title' => '' ), $atts, 'firmwares' );
	$items = ts_fw_get_items();

	ts_fw_styles();

	ob_start();
	?>
	<div class="ts-fw-list">
		<?php if ( '' !== trim( $atts['title'] ) ) : ?>
			<h2 class="ts-fw-title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( empty( $items ) ) : ?>
			<p class="ts-fw-empty">No firmwares have been added yet.</p>
		<?php else : ?>
			<div class="ts-fw-search">
				<svg class="ts-fw-search-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
					<circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
				</svg>
				<input type="search" class="ts-fw-search-input" placeholder="Search firmware version…" aria-label="Search firmware">
			</div>

			<div class="ts-fw-rows">
				<?php foreach ( $items as $it ) :
					$name    = isset( $it['name'] ) ? $it['name'] : '';
					$version = isset( $it['version'] ) ? $it['version'] : '';
					$url     = isset( $it['url'] ) ? $it['url'] : '';
					if ( '' === $name && '' === $url ) {
						continue;
					}
					$haystack = strtolower( trim( $name . ' ' . $version ) );
					?>
					<div class="ts-fw-row" data-search="<?php echo esc_attr( $haystack ); ?>">
						<span class="ts-fw-name"><?php echo esc_html( $name ); ?></span>
						<div class="ts-fw-foot">
							<?php if ( '' !== $version ) : ?>
								<span class="ts-fw-ver"><?php echo esc_html( $version ); ?></span>
							<?php else : ?>
								<span></span>
							<?php endif; ?>
							<?php if ( '' !== $url ) : ?>
								<a class="ts-fw-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="nofollow noopener">
									<?php echo ts_fw_download_icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- static inline SVG ?>
									<span>Download</span>
								</a>
							<?php else : ?>
								<span class="ts-fw-btn ts-fw-btn-disabled">Soon</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="ts-fw-noresults" hidden>No firmware matches your search.</p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
} );

/**
 * Front-end filtering for the firmware list. Reads a ?fw= (or ?q=) query param
 * so a link like /firmwares/?fw=18.1.0 pre-filters to that version.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="ts-fw-js">
	(function(){
		var lists = document.querySelectorAll('.ts-fw-list');
		if (!lists.length) return;

		function qparam(name){
			try { return new URLSearchParams(window.location.search).get(name) || ''; }
			catch(e){ return ''; }
		}
		function hparam(name){
			try {
				var h = (window.location.hash || '').replace(/^#/, '');
				return new URLSearchParams(h).get(name) || '';
			} catch(e){ return ''; }
		}
		// Prefer the SEO-safe hash (#fw=...), fall back to ?fw= / ?q=.
		function preset(){ return (hparam('fw') || qparam('fw') || qparam('q') || '').trim(); }

		Array.prototype.forEach.call(lists, function(list){
			var input = list.querySelector('.ts-fw-search-input');
			var rows  = Array.prototype.slice.call(list.querySelectorAll('.ts-fw-row'));
			var none  = list.querySelector('.ts-fw-noresults');
			if (!input) return;

			function apply(){
				var q = input.value.trim().toLowerCase();
				var shown = 0;
				rows.forEach(function(r){
					var hay = r.getAttribute('data-search') || '';
					var match = !q || hay.indexOf(q) !== -1;
					r.style.display = match ? '' : 'none';
					if (match) shown++;
				});
				if (none) none.hidden = shown !== 0;
			}

			var p = preset();
			if (p){ input.value = p; }
			input.addEventListener('input', apply);
			// Re-apply if the visitor arrives via a #fw= link after load.
			window.addEventListener('hashchange', function(){
				var np = preset();
				if (np){ input.value = np; apply(); }
			});
			apply();
		});
	})();
	</script>
	<?php
} );

/* ==========================================================
 * 3. STYLES
 * ========================================================== */
function ts_fw_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-fw-styles">
	.ts-fw-list{max-width:1180px;margin:24px auto;display:flex;flex-direction:column;gap:14px;}
	.ts-fw-title{font-size:22px;font-weight:800;color:#101828;margin:0 0 2px;}
	.ts-fw-search{position:relative;width:100%;max-width:460px;}
	.ts-fw-search-ic{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;color:#98a2b3;pointer-events:none;}
	.ts-fw-search-input{width:100%;box-sizing:border-box;border:1px solid #d0d5dd;border-radius:12px;
		background:#fff;padding:12px 16px 12px 42px;font-size:15px;color:#101828;outline:none;
		transition:border-color .15s ease,box-shadow .15s ease;}
	.ts-fw-search-input:focus{border-color:#e8394c;box-shadow:0 0 0 3px rgba(232,57,76,.12);}

	/* Multi-column grid of compact cards: fills the width, less scrolling. */
	.ts-fw-rows{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;}
	.ts-fw-row{
		display:flex;flex-direction:column;justify-content:space-between;gap:12px;
		background:#fff;border:1px solid #e8e8ea;border-radius:12px;
		padding:14px 16px;box-shadow:0 1px 3px rgba(16,24,40,.05);
		transition:border-color .15s ease,box-shadow .15s ease;
	}
	.ts-fw-row:hover{border-color:#f1c2c8;box-shadow:0 4px 14px rgba(16,24,40,.08);}
	.ts-fw-name{font-weight:700;font-size:14.5px;line-height:1.35;color:#101828;word-break:break-word;}
	.ts-fw-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;}
	.ts-fw-ver{
		flex:0 0 auto;font-weight:700;font-size:12px;color:#15803d;
		background:#ecfdf3;border:1px solid #d1fadf;border-radius:999px;
		padding:3px 10px;white-space:nowrap;font-variant-numeric:tabular-nums;
	}
	.ts-fw-btn{
		flex:0 0 auto;display:inline-flex;align-items:center;gap:7px;
		background:#e8394c;color:#fff !important;font-weight:700;font-size:13px;
		text-decoration:none;padding:8px 14px;border-radius:9px;
		transition:background .18s ease;box-shadow:0 2px 8px rgba(232,57,76,.24);
	}
	.ts-fw-btn:hover{background:#cf2a3c;}
	.ts-fw-btn-disabled{background:#c7ccd3 !important;box-shadow:none;cursor:default;}
	.ts-fw-empty,.ts-fw-noresults{color:#667085;}
	@media (max-width:520px){
		.ts-fw-rows{grid-template-columns:1fr;}
		.ts-fw-search{max-width:100%;}
	}
	</style>
	<?php
}
