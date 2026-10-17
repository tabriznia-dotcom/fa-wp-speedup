<?php
/**
 * Optional site-local uninstall cleanup.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Site-local cleanup only. Never silently traverse a multisite network.
$faws_options = get_option( 'faws_options', array() );
if ( empty( $faws_options['delete_data'] ) ) {
	return;
}
require_once __DIR__ . '/includes/class-faws-catalog.php';
require_once __DIR__ . '/includes/class-faws-local.php';
require_once __DIR__ . '/includes/class-faws-media.php';
FAWS_Media::cleanup( true );
foreach ( array_keys( FAWS_Catalog::all() ) as $resource_id ) {
	wp_clear_scheduled_hook( 'faws_download', array( $resource_id ) );
	FAWS_Local::remove( $resource_id );
	delete_option( 'faws_lock_' . $resource_id );
	delete_option( 'faws_attempt_' . $resource_id );
	delete_option( 'faws_download_' . $resource_id );
}
foreach ( array( 'faws_options', 'faws_previous', 'faws_resources', 'faws_inventory', 'faws_reports', 'faws_fallbacks', 'faws_advanced', 'faws_advanced_previous', 'faws_advanced_preview' ) as $key ) {
	delete_option( $key );
}
