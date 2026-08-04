<?php
/**
 * Plugin Name:       NSPVault Redirects
 * Plugin URI:        https://www.nspvault.com/
 * Description:        Automatically records old URLs when a post's slug/permalink changes and serves single-hop 301 redirects to the current URL. Guarantees no redirect chains (old -> -1 -> -2), so every historical URL always points directly to the newest one. Protects SEO when re-slugging and re-indexing content.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            NSPVault
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nspvault-redirects
 *
 * @package NSPVault_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'NSPVAULT_REDIRECTS_VERSION', '1.0.0' );
define( 'NSPVAULT_REDIRECTS_FILE', __FILE__ );
define( 'NSPVAULT_REDIRECTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NSPVAULT_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );
define( 'NSPVAULT_REDIRECTS_OPTION', 'nspvault_redirects_settings' );

require_once NSPVAULT_REDIRECTS_DIR . 'includes/class-nspvault-redirects-db.php';
require_once NSPVAULT_REDIRECTS_DIR . 'includes/class-nspvault-redirects-manager.php';
require_once NSPVAULT_REDIRECTS_DIR . 'includes/class-nspvault-redirects-admin.php';

/**
 * Main plugin container. Wires the pieces together and exposes shared services.
 */
final class NSPVault_Redirects {

	/**
	 * Singleton instance.
	 *
	 * @var NSPVault_Redirects|null
	 */
	private static $instance = null;

	/**
	 * Data-access layer.
	 *
	 * @var NSPVault_Redirects_DB
	 */
	public $db;

	/**
	 * Front-end capture + redirect logic.
	 *
	 * @var NSPVault_Redirects_Manager
	 */
	public $manager;

	/**
	 * Admin UI.
	 *
	 * @var NSPVault_Redirects_Admin
	 */
	public $admin;

	/**
	 * Bootstrap the plugin.
	 */
	private function __construct() {
		$this->db      = new NSPVault_Redirects_DB();
		$this->manager = new NSPVault_Redirects_Manager( $this->db );

		$this->manager->register_hooks();

		if ( is_admin() ) {
			$this->admin = new NSPVault_Redirects_Admin( $this->db );
			$this->admin->register_hooks();
		}
	}

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return NSPVault_Redirects
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'auto_capture' => 1,
			// Empty array means "all public post types".
			'post_types'   => array(),
			'status_code'  => 301,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( NSPVAULT_REDIRECTS_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::default_settings() );
	}

	/**
	 * Activation callback: create the table and seed default options.
	 */
	public static function activate() {
		require_once NSPVAULT_REDIRECTS_DIR . 'includes/class-nspvault-redirects-db.php';
		$db = new NSPVault_Redirects_DB();
		$db->create_table();

		if ( false === get_option( NSPVAULT_REDIRECTS_OPTION, false ) ) {
			add_option( NSPVAULT_REDIRECTS_OPTION, self::default_settings() );
		}

		update_option( 'nspvault_redirects_db_version', NSPVAULT_REDIRECTS_VERSION );
	}
}

register_activation_hook( __FILE__, array( 'NSPVault_Redirects', 'activate' ) );

/**
 * Kick things off.
 *
 * @return NSPVault_Redirects
 */
function nspvault_redirects() {
	return NSPVault_Redirects::instance();
}

add_action( 'plugins_loaded', 'nspvault_redirects' );
