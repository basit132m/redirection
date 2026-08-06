<?php
/**
 * Plugin Name: TS Firmware List
 * Description: A simple manager for Nintendo Switch firmware downloads. Add each firmware (Name, Version,
 *              Download URL) under Settings -> Firmwares, then drop the [firmwares] shortcode on any page.
 *              Each firmware renders as a single row: name, version and a download button.
 * Version: 1.0
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
			<?php foreach ( $items as $it ) :
				$name    = isset( $it['name'] ) ? $it['name'] : '';
				$version = isset( $it['version'] ) ? $it['version'] : '';
				$url     = isset( $it['url'] ) ? $it['url'] : '';
				if ( '' === $name && '' === $url ) {
					continue;
				}
				?>
				<div class="ts-fw-row">
					<span class="ts-fw-name"><?php echo esc_html( $name ); ?></span>
					<?php if ( '' !== $version ) : ?>
						<span class="ts-fw-ver"><?php echo esc_html( $version ); ?></span>
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
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
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
	.ts-fw-list{max-width:820px;margin:24px auto;display:flex;flex-direction:column;gap:10px;}
	.ts-fw-title{font-size:22px;font-weight:800;color:#101828;margin:0 0 6px;}
	.ts-fw-row{
		display:flex;align-items:center;gap:16px;
		background:#fff;border:1px solid #e8e8ea;border-radius:12px;
		padding:14px 18px;box-shadow:0 1px 3px rgba(16,24,40,.05);
	}
	.ts-fw-name{flex:1 1 auto;min-width:0;font-weight:700;font-size:15px;color:#101828;word-break:break-word;}
	.ts-fw-ver{
		flex:0 0 auto;font-weight:700;font-size:13px;color:#15803d;
		background:#ecfdf3;border:1px solid #d1fadf;border-radius:999px;
		padding:4px 12px;white-space:nowrap;font-variant-numeric:tabular-nums;
	}
	.ts-fw-btn{
		flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;
		background:#e8394c;color:#fff !important;font-weight:700;font-size:14px;
		text-decoration:none;padding:9px 18px;border-radius:10px;
		transition:background .18s ease;box-shadow:0 2px 8px rgba(232,57,76,.24);
	}
	.ts-fw-btn:hover{background:#cf2a3c;}
	.ts-fw-btn-disabled{background:#c7ccd3 !important;box-shadow:none;cursor:default;}
	.ts-fw-empty{color:#667085;}
	@media (max-width:560px){
		.ts-fw-row{flex-wrap:wrap;}
		.ts-fw-name{flex:1 1 100%;}
		.ts-fw-btn{flex:1 1 auto;justify-content:center;}
	}
	</style>
	<?php
}
