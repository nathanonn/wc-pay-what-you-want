<?php

namespace WcPwyw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// When activated via WP-CLI or wp-env, wp_die() kills the process.
			// Instead, skip setup — the plugins_loaded check will show an admin notice.
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}
			deactivate_plugins( WCPWYW_BASENAME );
			wp_die(
				esc_html__( 'WC Pay What You Want requires WooCommerce.', 'wc-pay-what-you-want' ),
				esc_html__( 'Plugin Activation Error', 'wc-pay-what-you-want' ),
				[ 'back_link' => true ]
			);
		}

		self::create_tables();
		self::set_default_options();
		\WcPwyw\Admin\BlockCartNotice::runOnActivation();
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'wcpwyw_analytics';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id  BIGINT UNSIGNED NOT NULL,
  order_item_id  BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  variation_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_price  DECIMAL(19,4) NOT NULL,
  suggested_price  DECIMAL(19,4) NOT NULL DEFAULT 0,
  regular_price  DECIMAL(19,4) NOT NULL DEFAULT 0,
  currency  VARCHAR(10) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_created_at (created_at),
  KEY idx_product_id (product_id)
) {$charset};";

		dbDelta( $sql );
	}

	private static function set_default_options(): void {
		add_option( 'wcpwyw_enabled', 'no' );
		add_option( 'wcpwyw_version', WCPWYW_VERSION );

		// Security defaults (P6).
		add_option( 'wcpwyw_price_cap_enabled',   'yes' );
		add_option( 'wcpwyw_price_cap_value',     '9999.00' );
		add_option( 'wcpwyw_validation_logging',  'no' );

		$settings_defaults = [
			'wcpwyw_default_min_pct'        => '50',
			'wcpwyw_default_max_pct'        => '200',
			'wcpwyw_default_suggested_pct'  => '100',
			'wcpwyw_global_preset_buttons'  => '10,15,20,25',
			'wcpwyw_price_display_style'    => 'A',
			'wcpwyw_archive_display_style'  => 'range',
			'wcpwyw_quick_add_default'      => 'suggested',
			'wcpwyw_coupon_mode'            => 'allow',
			'wcpwyw_mixed_cart_restriction' => 'no',
			'wcpwyw_label_input'            => 'Name Your Price',
			'wcpwyw_label_minimum'          => 'Minimum: {amount}',
			'wcpwyw_label_maximum'          => 'Maximum: {amount}',
			'wcpwyw_label_suggested'        => 'Suggested: {amount}',
			'wcpwyw_error_below_min'        => 'Please enter at least {amount}.',
			'wcpwyw_error_above_max'        => 'Please enter no more than {amount}.',
			'wcpwyw_error_invalid'          => 'Please enter a valid price.',
			'wcpwyw_email_below_enabled'    => 'yes',
			'wcpwyw_email_above_enabled'    => 'yes',
			'wcpwyw_email_threshold_below'  => '30',
			'wcpwyw_email_threshold_above'  => '50',
			'wcpwyw_email_alert_recipient'  => '',
			'wcpwyw_price_cap_enabled'      => 'yes',
			'wcpwyw_price_cap_value'        => '9999.00',
			'wcpwyw_validation_logging'     => 'no',
		];

		add_option( 'woocommerce_wcpwyw_settings', $settings_defaults );
		add_option( 'wcpwyw_global_preset_buttons', '10,15,20,25' );
	}
}
