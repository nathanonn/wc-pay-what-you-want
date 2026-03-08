<?php

namespace WcPwyw\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderDataService {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action(
			'woocommerce_checkout_create_order_line_item',
			[ self::class, 'captureOrderLineMeta' ],
			10,
			4
		);

		add_action(
			'woocommerce_checkout_order_processed',
			[ self::class, 'insertAnalyticsForOrder' ],
			10,
			1
		);
	}

	/**
	 * Write PWYW snapshot meta to an order line item at checkout.
	 *
	 * Fires once per line item during order creation. $cart_item_values is the
	 * cart item array; the customer-set price is at $cart_item_values['wcpwyw_price'].
	 * If that key is absent this is not a PWYW item and the method returns early.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $cart_item_values
	 * @param \WC_Order              $order
	 */
	public static function captureOrderLineMeta(
		\WC_Order_Item_Product $item,
		string $cart_item_key,
		array $cart_item_values,
		\WC_Order $order
	): void {
		if ( ! isset( $cart_item_values['wcpwyw_price'] ) ) {
			return;
		}

		$customer_price = (float) wc_format_decimal( $cart_item_values['wcpwyw_price'] );
		$product_id     = (int) ( $cart_item_values['product_id'] ?? 0 );
		$variation_id   = (int) ( $cart_item_values['variation_id'] ?? 0 );

		// Resolve config to capture the suggested/min/max at purchase time.
		$config = \WcPwyw\Frontend\ProductPage::resolveConfig( $product_id, $variation_id );

		$item->add_meta_data( '_wcpwyw_enabled',         'yes',                                                true );
		$item->add_meta_data( '_wcpwyw_customer_price',  wc_format_decimal( $customer_price ),                 true );
		$item->add_meta_data( '_wcpwyw_suggested_price', wc_format_decimal( $config['suggested_price'] ?? 0 ), true );
		$item->add_meta_data( '_wcpwyw_min_price',       wc_format_decimal( $config['min_price'] ?? 0 ),       true );
		$item->add_meta_data( '_wcpwyw_max_price',       wc_format_decimal( $config['max_price'] ?? 0 ),       true );
	}

	/**
	 * Return the most recent customer-set price for a given user + product.
	 *
	 * Queries completed orders for the user, iterates line items to find a
	 * matching product ID, and returns the stored _wcpwyw_customer_price.
	 *
	 * Uses wc_get_orders() (WooCommerce order API) rather than raw SQL so the
	 * query works with both classic order storage and HPOS.
	 *
	 * Returns null when:
	 *  - The user has no completed PWYW orders for this product.
	 *  - The stored price cannot be parsed as a positive float.
	 *
	 * @param int $user_id    WordPress user ID (must be > 0).
	 * @param int $product_id WooCommerce product ID.
	 * @return float|null
	 */
	/**
	 * After an order is fully saved at checkout, insert analytics rows for all PWYW items.
	 *
	 * Fires after woocommerce_checkout_create_order_line_item (which writes the
	 * order item meta) so _wcpwyw_enabled and _wcpwyw_customer_price are already available.
	 *
	 * @param int $order_id
	 */
	public static function insertAnalyticsForOrder( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( 'yes' !== $item->get_meta( '_wcpwyw_enabled' ) ) {
				continue;
			}

			$customer_price = (float) $item->get_meta( '_wcpwyw_customer_price' );
			$config         = [
				'suggested_price' => (float) $item->get_meta( '_wcpwyw_suggested_price' ),
			];

			self::insertAnalyticsRow( $order, $item, $customer_price, $config );
		}
	}

	/**
	 * Insert a denormalized PWYW analytics row for a completed order line item.
	 *
	 * Insert failures are silent (logged to PHP error log) to avoid breaking checkout.
	 *
	 * @param \WC_Order              $order
	 * @param \WC_Order_Item_Product $item
	 * @param float                  $customer_price
	 * @param array                  $config          Array with 'suggested_price' key.
	 */
	private static function insertAnalyticsRow(
		\WC_Order $order,
		\WC_Order_Item_Product $item,
		float $customer_price,
		array $config
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wcpwyw_analytics';

		// Guard: if table does not exist (e.g. activation hook did not run), skip silently.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$product_id    = (int) $item->get_product_id();
		$variation_id  = (int) $item->get_variation_id();
		$regular_price = 0.0;
		$product       = $item->get_product();
		if ( $product ) {
			$regular_price = (float) $product->get_regular_price();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			[
				'order_id'       => $order->get_id(),
				'order_item_id'  => $item->get_id(),
				'product_id'     => $product_id,
				'variation_id'   => $variation_id,
				'customer_price' => wc_format_decimal( $customer_price, 4 ),
				'suggested_price' => wc_format_decimal( $config['suggested_price'] ?? 0, 4 ),
				'regular_price'  => wc_format_decimal( $regular_price, 4 ),
				'currency'       => get_woocommerce_currency(),
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( 'wcpwyw: Failed to insert analytics row for order ' . $order->get_id() . ': ' . $wpdb->last_error );
		}
	}

	/**
	 * Return the most recent customer-set price for a given user + product.
	 *
	 * @param int $user_id    WordPress user ID (must be > 0).
	 * @param int $product_id WooCommerce product ID.
	 * @return float|null
	 */
	public static function get_last_customer_price( int $user_id, int $product_id ): ?float {
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return null;
		}

		$orders = wc_get_orders( [
			'customer' => $user_id,
			'status'   => [ 'wc-completed' ],
			'limit'    => 10,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		] );

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				/** @var \WC_Order_Item_Product $item */
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				if ( (int) $item->get_product_id() !== $product_id ) {
					continue;
				}

				$enabled = $item->get_meta( '_wcpwyw_enabled' );
				if ( 'yes' !== $enabled ) {
					continue;
				}

				$raw_price = $item->get_meta( '_wcpwyw_customer_price' );
				if ( '' === $raw_price || null === $raw_price ) {
					continue;
				}

				$price = (float) $raw_price;
				if ( $price > 0 ) {
					return $price;
				}
			}
		}

		return null;
	}
}
