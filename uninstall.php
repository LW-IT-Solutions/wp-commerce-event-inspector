<?php
/**
 * Remove everything the plugin stored: the setting, the per-user inspector switch and the observations at the orders.
 *
 * @package CommerceEventInspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Orders in the WooCommerce order tables; the plugin keeps a list of the orders it wrote to.
$cevi_index = get_option( 'cevi_orders' );
if ( is_array( $cevi_index ) && function_exists( 'wc_get_order' ) ) {
	foreach ( $cevi_index as $cevi_id ) {
		$cevi_order = wc_get_order( (int) $cevi_id );
		if ( $cevi_order ) {
			$cevi_order->delete_meta_data( '_cevi_observations' );
			$cevi_order->save_meta_data();
		}
	}
}
// Orders stored as posts, also when WooCommerce is no longer active.
delete_metadata( 'post', 0, '_cevi_observations', '', true );
// The WooCommerce order table has no WordPress API while WooCommerce is inactive, so remove the rows there directly.
global $wpdb;
$cevi_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cevi_table ) ) ) === $cevi_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time cleanup on uninstall.
	$wpdb->delete( $cevi_table, array( 'meta_key' => '_cevi_observations' ) );
}
delete_metadata( 'user', 0, 'cevi_inspector', '', true );
delete_option( 'cevi_orders' );
delete_option( 'cevi_settings' );
