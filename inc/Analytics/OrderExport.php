<?php

namespace WcPwyw\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderExport {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'woocommerce_order_export_column_headers', [ self::class, 'addColumnHeaders' ] );
		add_filter( 'woocommerce_order_export_column_data',    [ self::class, 'addColumnData' ], 10, 2 );
	}

	/**
	 * Add five PWYW columns to the CSV export header row.
	 *
	 * @param array $headers Column key => Column heading.
	 * @return array
	 */
	public static function addColumnHeaders( array $headers ): array {
		$headers['pwyw_enabled']         = __( 'PWYW Enabled',         'wc-pay-what-you-want' );
		$headers['pwyw_customer_price']  = __( 'PWYW Customer Price',  'wc-pay-what-you-want' );
		$headers['pwyw_suggested_price'] = __( 'PWYW Suggested Price', 'wc-pay-what-you-want' );
		$headers['pwyw_difference']      = __( 'PWYW Difference',      'wc-pay-what-you-want' );
		$headers['pwyw_difference_pct']  = __( 'PWYW Difference %',    'wc-pay-what-you-want' );
		return $headers;
	}

	/**
	 * Populate PWYW column data for each exported order row.
	 *
	 * @param array     $data  Existing row data (key => value).
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array
	 */
	public static function addColumnData( array $data, \WC_Order $order ): array {
		$pwyw_items  = [];
		$has_regular = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( 'yes' === $item->get_meta( '_wcpwyw_enabled' ) ) {
				$pwyw_items[] = $item;
			} else {
				$has_regular = true;
			}
		}

		$has_pwyw = ! empty( $pwyw_items );

		if ( ! $has_pwyw ) {
			$data['pwyw_enabled']         = 'No';
			$data['pwyw_customer_price']  = '';
			$data['pwyw_suggested_price'] = '';
			$data['pwyw_difference']      = '';
			$data['pwyw_difference_pct']  = '';
			return $data;
		}

		$total_customer  = 0.0;
		$total_suggested = 0.0;
		foreach ( $pwyw_items as $item ) {
			$total_customer  += (float) $item->get_meta( '_wcpwyw_customer_price' );
			$total_suggested += (float) $item->get_meta( '_wcpwyw_suggested_price' );
		}

		$difference     = $total_customer - $total_suggested;
		$difference_pct = ( $total_suggested > 0 ) ? round( ( $difference / $total_suggested ) * 100 ) : 0;
		$decimals       = wc_get_price_decimals();

		$data['pwyw_enabled']         = $has_regular ? 'Mixed' : 'Yes';
		$data['pwyw_customer_price']  = number_format( $total_customer,  $decimals, '.', '' );
		$data['pwyw_suggested_price'] = number_format( $total_suggested, $decimals, '.', '' );
		$data['pwyw_difference']      = number_format( $difference,      $decimals, '.', '' );
		$data['pwyw_difference_pct']  = (string) $difference_pct;

		return $data;
	}
}
