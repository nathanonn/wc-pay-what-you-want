<?php

namespace WcPwyw\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles coupon interaction modes for PWYW cart items.
 *
 * Supports three modes (per product, falling back to global setting):
 *   - allow            — standard WooCommerce coupon math, no intervention
 *   - allow_with_floor — coupon discount is capped so price never drops below min_price
 *   - block            — coupon discount zeroed for PWYW line items
 */
class CouponHandler {

	private static bool $initialized = false;

	/**
	 * Tracks which notices have already been shown this request (coupon_code + product_id).
	 *
	 * @var array<string, bool>
	 */
	private static array $noticesSent = [];

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		if ( 'yes' !== get_option( 'wcpwyw_enabled', 'yes' ) ) {
			return;
		}

		self::$initialized = true;

		add_filter( 'woocommerce_coupon_get_discount_amount', [ self::class, 'filterDiscountAmount' ], 10, 5 );
		add_action( 'woocommerce_applied_coupon', [ self::class, 'maybeShowCouponNotice' ] );
	}

	/**
	 * Filter coupon discount amount for PWYW cart items.
	 *
	 * @param float      $discount           Discount amount being applied.
	 * @param float      $discounting_amount Price being discounted (unit or line).
	 * @param array      $cart_item          WooCommerce cart item array.
	 * @param bool       $single             True if per-unit calculation.
	 * @param \WC_Coupon $coupon             The coupon being applied.
	 * @return float
	 */
	public static function filterDiscountAmount(
		float $discount,
		float $discounting_amount,
		array $cart_item,
		bool $single,
		\WC_Coupon $coupon
	): float {
		if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
			return $discount; // Not a PWYW item.
		}

		$product_id   = (int) ( $cart_item['wcpwyw_product_id'] ?? $cart_item['product_id'] );
		$variation_id = (int) ( $cart_item['wcpwyw_variation_id'] ?? $cart_item['variation_id'] ?? 0 );

		$config      = \WcPwyw\Frontend\ProductPage::resolveConfig( $product_id, $variation_id );
		$settings    = get_option( 'woocommerce_wcpwyw_settings', [] );
		$global_mode = $settings['wcpwyw_coupon_mode'] ?? 'allow';
		$mode        = $config['coupon_mode'] ?? $global_mode;

		switch ( $mode ) {
			case 'allow':
				return $discount;

			case 'block':
				return 0.0;

			case 'allow_with_floor':
				$pwyw_price = (float) $cart_item['wcpwyw_price'];
				$min_price  = (float) ( $config['min_price'] ?? 0.0 );
				$quantity   = (int) $cart_item['quantity'];

				// Compare per-unit or per-line depending on $single flag.
				$line_price = $single ? $pwyw_price : $pwyw_price * $quantity;
				$min_total  = $single ? $min_price  : $min_price  * $quantity;

				$discounted = $line_price - $discount;

				if ( $discounted < $min_total ) {
					$capped = max( 0.0, $line_price - $min_total );
					return (float) wc_format_decimal( $capped );
				}

				return $discount;

			default:
				return $discount;
		}
	}

	/**
	 * Show informational notice when a coupon is applied to PWYW items in
	 * block or allow_with_floor mode.
	 *
	 * @param string $coupon_code
	 * @return void
	 */
	public static function maybeShowCouponNotice( string $coupon_code ): void {
		if ( ! WC()->cart ) {
			return;
		}

		$settings    = get_option( 'woocommerce_wcpwyw_settings', [] );
		$global_mode = $settings['wcpwyw_coupon_mode'] ?? 'allow';

		$coupon = new \WC_Coupon( $coupon_code );

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
				continue;
			}

			$product_id   = (int) ( $cart_item['wcpwyw_product_id'] ?? $cart_item['product_id'] );
			$variation_id = (int) ( $cart_item['wcpwyw_variation_id'] ?? $cart_item['variation_id'] ?? 0 );

			$config = \WcPwyw\Frontend\ProductPage::resolveConfig( $product_id, $variation_id );
			$mode   = $config['coupon_mode'] ?? $global_mode;

			// Deduplicate notices per coupon+product.
			$notice_key = $coupon_code . '_' . $product_id;
			if ( isset( self::$noticesSent[ $notice_key ] ) ) {
				continue;
			}

			if ( 'block' === $mode ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Coupon "%s" does not apply to Pay What You Want items. The discount has been applied to the remaining eligible items in your cart.', 'wc-pay-what-you-want' ),
						esc_html( $coupon_code )
					),
					'notice'
				);
				self::$noticesSent[ $notice_key ] = true;
			} elseif ( 'allow_with_floor' === $mode ) {
				$pwyw_price = (float) $cart_item['wcpwyw_price'];
				$min_price  = (float) ( $config['min_price'] ?? 0.0 );

				// Only show notice if the floor would actually be hit.
				$discount_amount = $coupon->get_amount();
				$coupon_type     = $coupon->get_discount_type();

				$would_hit_floor = false;
				if ( 'percent' === $coupon_type ) {
					$discounted = $pwyw_price * ( 1 - $discount_amount / 100 );
					$would_hit_floor = $discounted < $min_price;
				} else {
					// Fixed discount.
					$discounted      = $pwyw_price - $discount_amount;
					$would_hit_floor = $discounted < $min_price;
				}

				if ( $would_hit_floor ) {
					$product = wc_get_product( $product_id );
					$name    = $product ? $product->get_name() : '';
					wc_add_notice(
						sprintf(
							/* translators: 1: product name, 2: formatted minimum price */
							__( 'The coupon discount on "%1$s" has been limited to maintain the minimum price of %2$s.', 'wc-pay-what-you-want' ),
							esc_html( $name ),
							wp_strip_all_tags( wc_price( $min_price ) )
						),
						'notice'
					);
					self::$noticesSent[ $notice_key ] = true;
				}
			}
		}
	}
}
