<?php

namespace WcPwyw\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AlertMailer {

	private static bool $initialized = false;

	private const ALERT_STATUSES = [ 'processing', 'completed' ];

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_order_status_changed', [ self::class, 'evaluate' ], 10, 4 );
	}

	/**
	 * Evaluate all PWYW line items in an order and send threshold alert emails.
	 *
	 * @param int       $order_id   WooCommerce order ID.
	 * @param string    $old_status Previous order status (without 'wc-' prefix).
	 * @param string    $new_status New order status (without 'wc-' prefix).
	 * @param \WC_Order $order      WooCommerce order object.
	 */
	public static function evaluate( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		if ( ! in_array( $new_status, self::ALERT_STATUSES, true ) ) {
			return;
		}

		$below_enabled   = get_option( 'wcpwyw_email_below_enabled', 'yes' );
		$above_enabled   = get_option( 'wcpwyw_email_above_enabled', 'yes' );
		$threshold_below = (int) get_option( 'wcpwyw_email_threshold_below', 30 );
		$threshold_above = (int) get_option( 'wcpwyw_email_threshold_above', 50 );
		$recipient_raw   = get_option( 'wcpwyw_email_alert_recipient', '' );

		$recipient = trim( $recipient_raw );
		if ( '' === $recipient ) {
			$recipient = get_option( 'admin_email' );
		}

		if ( 'yes' !== $below_enabled && 'yes' !== $above_enabled ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( 'yes' !== $item->get_meta( '_wcpwyw_enabled' ) ) {
				continue;
			}

			$customer_price  = (float) $item->get_meta( '_wcpwyw_customer_price' );
			$suggested_price = (float) $item->get_meta( '_wcpwyw_suggested_price' );

			if ( $suggested_price <= 0 ) {
				continue;
			}

			$deviation_pct = ( ( $customer_price - $suggested_price ) / $suggested_price ) * 100;

			if ( 'yes' === $below_enabled && $deviation_pct < -$threshold_below ) {
				self::sendAlert( $order, $item, $customer_price, $suggested_price, $deviation_pct, 'below', $threshold_below, $recipient );
			}

			if ( 'yes' === $above_enabled && $deviation_pct > $threshold_above ) {
				self::sendAlert( $order, $item, $customer_price, $suggested_price, $deviation_pct, 'above', $threshold_above, $recipient );
			}
		}
	}

	/**
	 * Send a single PWYW threshold alert email.
	 *
	 * @param \WC_Order              $order
	 * @param \WC_Order_Item_Product $item
	 * @param float                  $customer_price
	 * @param float                  $suggested_price
	 * @param float                  $deviation_pct   Signed percentage.
	 * @param string                 $type            'below' or 'above'.
	 * @param int                    $threshold        Threshold percentage.
	 * @param string                 $recipient       Comma-separated email addresses.
	 */
	private static function sendAlert(
		\WC_Order $order,
		\WC_Order_Item_Product $item,
		float $customer_price,
		float $suggested_price,
		float $deviation_pct,
		string $type,
		int $threshold,
		string $recipient
	): void {
		$order_id     = $order->get_id();
		$order_number = $order->get_order_number();
		$order_url    = admin_url( 'admin.php?page=wc-orders&id=' . $order_id );

		// Fallback to legacy post.php URL on non-HPOS sites.
		if ( ! class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ||
			 ! wc_get_container()->get( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )->custom_orders_table_usage_is_enabled() ) {
			$order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		}

		$product      = $item->get_product();
		$product_name = $product ? $product->get_name() : $item->get_name();

		$deviation_amount = $customer_price - $suggested_price;
		$deviation_sign   = $deviation_pct >= 0 ? '+' : '';
		$amount_sign      = $deviation_amount >= 0 ? '+' : '';

		if ( 'below' === $type ) {
			/* translators: %s: WooCommerce order number */
			$subject       = sprintf( __( 'PWYW Alert: Customer paid below threshold — Order %s', 'wc-pay-what-you-want' ), '#' . $order_number );
			$alert_heading = __( 'Pay What You Want — Below-threshold alert', 'wc-pay-what-you-want' );
			$alert_intro   = __( "A customer's payment is significantly below the suggested price.", 'wc-pay-what-you-want' );
			/* translators: %d: threshold percentage */
			$threshold_label = sprintf( __( '%d%% below suggested', 'wc-pay-what-you-want' ), $threshold );
		} else {
			/* translators: %s: WooCommerce order number */
			$subject       = sprintf( __( 'PWYW Alert: Customer paid above threshold — Order %s', 'wc-pay-what-you-want' ), '#' . $order_number );
			$alert_heading = __( 'Pay What You Want — Above-threshold alert', 'wc-pay-what-you-want' );
			$alert_intro   = __( "A customer's payment is significantly above the suggested price.", 'wc-pay-what-you-want' );
			/* translators: %d: threshold percentage */
			$threshold_label = sprintf( __( '%d%% above suggested', 'wc-pay-what-you-want' ), $threshold );
		}

		$wc_email      = WC()->mailer()->emails['WC_Email_New_Order'] ?? null;
		$email_heading = $alert_heading;

		ob_start();
		if ( $wc_email ) {
			do_action( 'woocommerce_email_header', $email_heading, $wc_email );
		} else {
			echo '<h1 style="font-size:22px;margin:0 0 14px;">' . esc_html( $email_heading ) . '</h1>';
		}

		echo '<p>' . esc_html( $alert_intro ) . '</p>';

		echo '<h2 style="font-size:16px;">' . esc_html__( 'Order details', 'wc-pay-what-you-want' ) . '</h2>';
		echo '<table cellpadding="6" cellspacing="0" style="border:1px solid #e4e4e4;width:100%;">';
		echo '<tr><td><strong>' . esc_html__( 'Order', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html( '#' . $order_number ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Date', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td></tr>';
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = $order->get_billing_email();
		echo '<tr><td><strong>' . esc_html__( 'Customer', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html( $name . ' (' . $email . ')' ) . '</td></tr>';
		echo '</table>';

		echo '<h2 style="font-size:16px;margin-top:20px;">' . esc_html__( 'PWYW pricing summary', 'wc-pay-what-you-want' ) . '</h2>';
		echo '<table cellpadding="6" cellspacing="0" style="border:1px solid #e4e4e4;width:100%;">';
		echo '<tr><td><strong>' . esc_html__( 'Product', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html( $product_name ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Customer price', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . wp_kses_post( wc_price( $customer_price ) ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Suggested price', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . wp_kses_post( wc_price( $suggested_price ) ) . ' <em style="color:#777;">' . esc_html__( '(at time of purchase)', 'wc-pay-what-you-want' ) . '</em></td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Deviation', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html(
			$amount_sign . wp_strip_all_tags( wc_price( $deviation_amount ) ) .
			' (' . $deviation_sign . round( $deviation_pct, 1 ) . '%)'
		) . '</td></tr>';
		echo '<tr style="border-top:1px solid #e4e4e4;"><td><strong>' . esc_html__( 'Threshold', 'wc-pay-what-you-want' ) . '</strong></td>';
		echo '<td>' . esc_html( $threshold_label ) . '</td></tr>';
		echo '</table>';

		echo '<p style="margin-top:20px;">';
		echo '<a href="' . esc_url( $order_url ) . '" style="background:#7f54b3;color:#fff;padding:10px 16px;text-decoration:none;border-radius:3px;">';
		echo esc_html( sprintf(
			/* translators: %s: WooCommerce order number */
			__( 'View Order %s in your store admin', 'wc-pay-what-you-want' ),
			'#' . $order_number
		) );
		echo '</a></p>';

		echo '<p style="font-size:12px;color:#777;margin-top:30px;">';
		echo esc_html__( 'You are receiving this email because PWYW price alerts are enabled.', 'wc-pay-what-you-want' );
		echo ' ';
		echo esc_html__( 'To change alert settings, go to WooCommerce → Settings → Pay What You Want.', 'wc-pay-what-you-want' );
		echo '</p>';

		if ( $wc_email ) {
			do_action( 'woocommerce_email_footer', $wc_email );
		}

		$email_body = ob_get_clean();

		$recipients = array_map( 'trim', explode( ',', $recipient ) );
		$headers    = [ 'Content-Type: text/html; charset=UTF-8' ];

		foreach ( $recipients as $to ) {
			if ( is_email( $to ) ) {
				wp_mail( $to, $subject, $email_body, $headers );
			}
		}
	}
}
