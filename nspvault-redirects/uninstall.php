<?php
/**
 * Uninstall handler: remove the redirects table and plugin options.
 *
 * @package NSPVault_Redirects
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'nspvault_redirects';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

delete_option( 'nspvault_redirects_settings' );
delete_option( 'nspvault_redirects_db_version' );

// Clean up any per-user notice transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_nspvault_redirects_notices_%' OR option_name LIKE '\_transient\_timeout\_nspvault_redirects_notices_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
