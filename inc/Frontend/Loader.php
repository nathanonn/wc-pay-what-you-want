<?php

namespace WcPwyw\Frontend;

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

		ProductPage::init();
		CartHandler::init();
		\WcPwyw\Services\CouponHandler::init();
		\WcPwyw\Services\OrderDataService::init();

		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
	}

	public static function enqueueAssets(): void {
		$on_product  = is_product();
		$on_cart     = is_cart();
		$on_checkout = is_checkout();

		if ( $on_product ) {
			wp_enqueue_style(
				'wcpwyw-frontend',
				WCPWYW_URL . 'assets/css/frontend-pwyw.css',
				[],
				WCPWYW_VERSION
			);

			wp_enqueue_script(
				'wcpwyw-frontend',
				WCPWYW_URL . 'assets/js/frontend-pwyw.js',
				[ 'jquery' ],
				WCPWYW_VERSION,
				true
			);
		}

		if ( $on_cart || $on_checkout ) {
			wp_enqueue_style(
				'wcpwyw-cart',
				WCPWYW_URL . 'assets/css/wcpwyw-cart.css',
				[],
				WCPWYW_VERSION
			);
		}

		if ( $on_cart ) {
			wp_enqueue_script(
				'wcpwyw-cart',
				WCPWYW_URL . 'assets/js/wcpwyw-cart.js',
				[ 'jquery' ],
				WCPWYW_VERSION,
				true
			);

			wp_localize_script(
				'wcpwyw-cart',
				'wcpwywCartData',
				[
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'i18n'           => [
						'invalidPrice' => __( 'Please enter a valid price.', 'wc-pay-what-you-want' ),
						'belowMin'     => __( 'Min. price is %s.', 'wc-pay-what-you-want' ),
						'aboveMax'     => __( 'Max. price is %s.', 'wc-pay-what-you-want' ),
						'serverError'  => __( 'Could not update price. Please try again.', 'wc-pay-what-you-want' ),
					],
					'currencySymbol' => get_woocommerce_currency_symbol(),
					'currencyPos'    => get_option( 'woocommerce_currency_pos' ),
					'decimals'       => wc_get_price_decimals(),
					'decimalSep'     => wc_get_price_decimal_separator(),
					'thousandSep'    => wc_get_price_thousand_separator(),
				]
			);
		}
	}
}
