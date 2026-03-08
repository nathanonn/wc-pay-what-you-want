<?php

namespace WcPwyw\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderColumns {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// HPOS Orders list (WC 7.x+).
		add_filter( 'manage_woocommerce_page_wc-orders_columns',        [ self::class, 'addColumns' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column',  [ self::class, 'renderColumn' ], 10, 2 );

		// Legacy shop_order post type list (pre-HPOS).
		add_filter( 'manage_edit-shop_order_columns',            [ self::class, 'addColumns' ] );
		add_action( 'manage_shop_order_posts_custom_column',     [ self::class, 'renderColumn' ], 10, 2 );
	}

	public static function addColumns( array $columns ): array {
		$insert_after = 'order_total';
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === $insert_after ) {
				$new['wcpwyw_status']         = __( 'PWYW?',       'wc-pay-what-you-want' );
				$new['wcpwyw_customer_price'] = __( 'Cust. Price', 'wc-pay-what-you-want' );
				$new['wcpwyw_suggested_price'] = __( 'Sugg. Price', 'wc-pay-what-you-want' );
				$new['wcpwyw_deviation']       = __( 'Deviation',   'wc-pay-what-you-want' );
			}
		}
		return $new;
	}

	public static function renderColumn( string $column, $order_or_id ): void {
		if ( $order_or_id instanceof \WC_Order ) {
			$order = $order_or_id;
		} else {
			$order = wc_get_order( (int) $order_or_id );
		}

		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $column, [ 'wcpwyw_status', 'wcpwyw_customer_price', 'wcpwyw_suggested_price', 'wcpwyw_deviation' ], true ) ) {
			return;
		}

		$pwyw_items  = self::getPwywLineItems( $order );
		$has_pwyw    = ! empty( $pwyw_items );
		$has_regular = false;

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				if ( 'yes' !== $item->get_meta( '_wcpwyw_enabled' ) ) {
					$has_regular = true;
					break;
				}
			}
		}

		if ( 'wcpwyw_status' === $column ) {
			if ( ! $has_pwyw ) {
				echo '<span class="wcpwyw-col-no">' . esc_html__( 'No', 'wc-pay-what-you-want' ) . '</span>';
			} elseif ( $has_regular ) {
				echo '<span class="wcpwyw-col-mixed" title="' . esc_attr__( 'This order contains both PWYW and regular products. Prices shown are for PWYW items only.', 'wc-pay-what-you-want' ) . '">' . esc_html__( 'Mixed', 'wc-pay-what-you-want' ) . '</span>';
			} else {
				echo '<span class="wcpwyw-col-yes">' . esc_html__( 'Yes', 'wc-pay-what-you-want' ) . '</span>';
			}
			return;
		}

		if ( ! $has_pwyw ) {
			echo '<span class="wcpwyw-col-dash">&mdash;</span>';
			return;
		}

		$total_customer  = 0.0;
		$total_suggested = 0.0;
		foreach ( $pwyw_items as $item ) {
			$total_customer  += (float) $item->get_meta( '_wcpwyw_customer_price' );
			$total_suggested += (float) $item->get_meta( '_wcpwyw_suggested_price' );
		}

		if ( 'wcpwyw_customer_price' === $column ) {
			echo wp_kses_post( wc_price( $total_customer ) );
			return;
		}

		if ( 'wcpwyw_suggested_price' === $column ) {
			echo wp_kses_post( wc_price( $total_suggested ) );
			return;
		}

		if ( 'wcpwyw_deviation' === $column ) {
			$deviation_amount = $total_customer - $total_suggested;
			$deviation_pct    = ( $total_suggested > 0 ) ? ( $deviation_amount / $total_suggested ) * 100 : 0.0;

			if ( abs( $deviation_amount ) < 0.005 ) {
				printf(
					'<span class="wcpwyw-col-deviation wcpwyw-col-deviation--zero">%s (%s%%)</span>',
					wp_kses_post( wc_price( 0 ) ),
					'0'
				);
			} elseif ( $deviation_amount > 0 ) {
				printf(
					'<span class="wcpwyw-col-deviation wcpwyw-col-deviation--above">+%s (+%s%%)</span>',
					wp_kses_post( wc_price( $deviation_amount ) ),
					esc_html( number_format( $deviation_pct, 1 ) )
				);
			} else {
				printf(
					'<span class="wcpwyw-col-deviation wcpwyw-col-deviation--below">%s (%s%%)</span>',
					wp_kses_post( wc_price( $deviation_amount ) ),
					esc_html( '-' . number_format( abs( $deviation_pct ), 1 ) )
				);
			}
		}
	}

	/**
	 * Return only the PWYW line items from an order.
	 *
	 * @param \WC_Order $order
	 * @return \WC_Order_Item_Product[]
	 */
	private static function getPwywLineItems( \WC_Order $order ): array {
		$pwyw = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && 'yes' === $item->get_meta( '_wcpwyw_enabled' ) ) {
				$pwyw[] = $item;
			}
		}
		return $pwyw;
	}
}
