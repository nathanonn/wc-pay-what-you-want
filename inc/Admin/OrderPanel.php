<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderPanel {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_after_order_itemmeta', [ self::class, 'renderPanel' ], 10, 3 );
	}

	/**
	 * Render the read-only PWYW data panel below a line item in the admin order detail view.
	 *
	 * @param int            $item_id  Order item ID.
	 * @param \WC_Order_Item $item     The order item object.
	 * @param \WC_Product|false|null $product The product (may be false/null if deleted).
	 */
	public static function renderPanel( int $item_id, \WC_Order_Item $item, $product ): void {
		// Only render for PWYW line items.
		$enabled = $item->get_meta( '_wcpwyw_enabled' );
		if ( 'yes' !== $enabled ) {
			return;
		}

		$customer_price  = (float) $item->get_meta( '_wcpwyw_customer_price' );
		$suggested_price = (float) $item->get_meta( '_wcpwyw_suggested_price' );

		// Variance: customer paid vs. suggested.
		$variance     = $customer_price - $suggested_price;
		$variance_pct = ( $suggested_price > 0 )
			? ( $variance / $suggested_price ) * 100
			: 0.0;

		if ( 0.0 === $variance ) {
			$variance_class = '';
			$variance_label = sprintf(
				'%s (%s%%)',
				wp_strip_all_tags( wc_price( 0 ) ),
				number_format( 0.0, 1 )
			);
		} elseif ( $variance > 0 ) {
			$variance_class = 'wcpwyw-order-panel__variance--above';
			$variance_label = sprintf(
				'+%s (+%s%%)',
				wp_strip_all_tags( wc_price( $variance ) ),
				number_format( $variance_pct, 1 )
			);
		} else {
			$variance_class = 'wcpwyw-order-panel__variance--below';
			$variance_label = sprintf(
				'%s (-%s%%)',
				wp_strip_all_tags( wc_price( $variance ) ),
				number_format( abs( $variance_pct ), 1 )
			);
		}

		?>
		<div class="wcpwyw-order-panel">
			<table class="wcpwyw-order-panel__table">
				<tr>
					<td class="wcpwyw-order-panel__label">
						<?php esc_html_e( 'Customer Price', 'wc-pay-what-you-want' ); ?>
					</td>
					<td class="wcpwyw-order-panel__value">
						<?php echo wp_kses_post( wc_price( $customer_price ) ); ?>
					</td>
				</tr>
				<tr>
					<td class="wcpwyw-order-panel__label">
						<?php esc_html_e( 'Suggested Price', 'wc-pay-what-you-want' ); ?>
					</td>
					<td class="wcpwyw-order-panel__value">
						<?php echo wp_kses_post( wc_price( $suggested_price ) ); ?>
					</td>
				</tr>
				<tr>
					<td class="wcpwyw-order-panel__label">
						<?php esc_html_e( 'Variance', 'wc-pay-what-you-want' ); ?>
					</td>
					<td class="<?php echo esc_attr( trim( 'wcpwyw-order-panel__value ' . $variance_class ) ); ?>">
						<?php echo esc_html( $variance_label ); ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
