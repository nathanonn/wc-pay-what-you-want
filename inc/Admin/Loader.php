<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Loader {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		SettingsTab::init();
		ProductPanel::init();
		VariationPanel::init();
		BulkActions::init();
		OrderPanel::init();
		\WcPwyw\Analytics\OrderColumns::init();
		\WcPwyw\Analytics\OrderExport::init();
		BlockCartNotice::init();

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueUninstallAssets' ] );
	}

	public static function enqueueUninstallAssets( string $hook_suffix ): void {
		// Enqueue only on the PWYW uninstall confirmation page.
		$is_uninstall_page = (
			'admin_page_wcpwyw-uninstall' === $hook_suffix
		);

		if ( ! $is_uninstall_page ) {
			return;
		}

		wp_enqueue_style(
			'wcpwyw-admin-uninstall',
			WCPWYW_URL . 'assets/css/admin-uninstall.css',
			[],
			WCPWYW_VERSION
		);

		wp_enqueue_script(
			'wcpwyw-admin-uninstall',
			WCPWYW_URL . 'assets/js/admin-uninstall.js',
			[],
			WCPWYW_VERSION,
			true
		);
	}

	public static function enqueueAssets( string $hook_suffix ): void {
		// Enqueue on WooCommerce settings page (settings tab)
		$is_wc_settings = ( 'woocommerce_page_wc-settings' === $hook_suffix );

		// Enqueue on product edit screen (product panel, variation panel)
		if ( 'post.php' === $hook_suffix && isset( $_GET['post'] ) ) {
			$is_product_edit = ( 'product' === get_post_type( (int) $_GET['post'] ) );
		} elseif ( 'post-new.php' === $hook_suffix ) {
			$is_product_edit = ( ! isset( $_GET['post_type'] ) || 'product' === $_GET['post_type'] );
		} else {
			$is_product_edit = false;
		}

		// Enqueue on products list (bulk actions, PWYW column badges)
		$is_product_list = (
			'edit.php' === $hook_suffix &&
			isset( $_GET['post_type'] ) &&
			'product' === $_GET['post_type']
		);

		// Enqueue on order edit screen (PWYW order panel)
		$is_order_edit = (
			'woocommerce_page_wc-orders' === $hook_suffix &&
			isset( $_GET['id'] )
		);
		if ( ! $is_order_edit && 'post.php' === $hook_suffix && isset( $_GET['post'] ) ) {
			$is_order_edit = ( 'shop_order' === get_post_type( (int) $_GET['post'] ) );
		}

		// Enqueue on HPOS orders list (no ?id= param)
		$is_orders_list = (
			'woocommerce_page_wc-orders' === $hook_suffix &&
			! isset( $_GET['id'] )
		);

		// Enqueue on WP Admin Dashboard (analytics widget)
		$is_dashboard = ( 'index.php' === $hook_suffix );

		if ( ! $is_wc_settings && ! $is_product_edit && ! $is_product_list && ! $is_order_edit && ! $is_orders_list && ! $is_dashboard ) {
			return;
		}

		wp_enqueue_style(
			'wcpwyw-admin',
			WCPWYW_URL . 'assets/css/admin.css',
			[],
			WCPWYW_VERSION
		);

		wp_enqueue_script(
			'wcpwyw-admin',
			WCPWYW_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WCPWYW_VERSION,
			true
		);

		if ( $is_dashboard ) {
			wp_enqueue_style(
				'wcpwyw-admin-analytics',
				WCPWYW_URL . 'assets/css/admin-analytics.css',
				[],
				WCPWYW_VERSION
			);

			wp_enqueue_script(
				'wcpwyw-admin-analytics',
				WCPWYW_URL . 'assets/js/admin-analytics.js',
				[ 'jquery' ],
				WCPWYW_VERSION,
				true
			);

			wp_localize_script(
				'wcpwyw-admin-analytics',
				'wcpwywAnalytics',
				[
					'i18n' => [
						'totalRevenue'  => __( 'Total Revenue',                                        'wc-pay-what-you-want' ),
						'totalOrders'   => __( 'Total Orders',                                         'wc-pay-what-you-want' ),
						'avgPrice'      => __( 'Avg Customer Price',                                   'wc-pay-what-you-want' ),
						'avgAbove'      => __( 'Customers pay on average %s above suggested',          'wc-pay-what-you-want' ),
						'avgBelow'      => __( 'Customers pay on average %s below suggested',          'wc-pay-what-you-want' ),
						'distribution'  => __( 'Price Distribution',                                   'wc-pay-what-you-want' ),
						'distBelowMin'  => __( 'Below min',                                            'wc-pay-what-you-want' ),
						'distBelowSugg' => __( '&lt; Suggest',                                        'wc-pay-what-you-want' ),
						'distAtSugg'    => __( 'At suggest',                                           'wc-pay-what-you-want' ),
						'distAboveSugg' => __( '&gt; Suggest',                                        'wc-pay-what-you-want' ),
						'top5Title'     => __( 'Top 5 Products by PWYW Revenue',                       'wc-pay-what-you-want' ),
						'col_product'   => __( 'Product',                                              'wc-pay-what-you-want' ),
						'col_revenue'   => __( 'Revenue',                                              'wc-pay-what-you-want' ),
						'col_orders'    => __( 'Orders',                                               'wc-pay-what-you-want' ),
						'col_avg'       => __( 'Avg Price',                                            'wc-pay-what-you-want' ),
						'enableHint'    => __( 'Enable PWYW on your products to start collecting data.', 'wc-pay-what-you-want' ),
						'viewProducts'  => __( 'View Products',                                        'wc-pay-what-you-want' ),
						'errorMsg'      => __( 'Unable to load analytics data. Please refresh the page.', 'wc-pay-what-you-want' ),
						'retry'         => __( 'Retry',                                                'wc-pay-what-you-want' ),
					],
				]
			);
		}
	}
}
