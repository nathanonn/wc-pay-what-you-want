<?php
/**
 * WordPress calls this file automatically when the plugin is deleted.
 *
 * This file must NOT use the Composer autoloader — it runs after the plugin
 * directory may have been partially removed. All cleanup is inline.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Read choice transient (set by UninstallPage before triggering deletion).
$choice = get_transient( 'wcpwyw_uninstall_choice' );
delete_transient( 'wcpwyw_uninstall_choice' );

// Default to keep data if no transient found (safety fallback).
$action            = is_array( $choice ) ? ( $choice['action'] ?? 'keep' ) : 'keep';
$delete_order_meta = is_array( $choice ) && ! empty( $choice['delete_order_meta'] );

if ( 'delete' !== $action ) {
	// Keep all data — nothing to do.
	return;
}

// ── Delete global settings ────────────────────────────────────────────────
$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wcpwyw_%'"
);
foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}
delete_option( 'woocommerce_wcpwyw_settings' );

// ── Delete product meta ───────────────────────────────────────────────────
$meta_keys_raw = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wcpwyw_%'"
);
foreach ( $meta_keys_raw as $key ) {
	delete_post_meta_by_key( $key );
}

// ── Drop analytics table ──────────────────────────────────────────────────
$analytics_table = $wpdb->prefix . 'wcpwyw_analytics';
$wpdb->query( "DROP TABLE IF EXISTS `{$analytics_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// ── Optionally delete order item meta ────────────────────────────────────
if ( $delete_order_meta ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '_wcpwyw_%'"
	);
}
