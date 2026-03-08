<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlockCartNotice {

	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'renderNotice' ] );
		add_action( 'wp_ajax_wcpwyw_switch_to_classic_cart', [ self::class, 'handleSwitchAjax' ] );
		add_action( 'wp_ajax_wcpwyw_dismiss_block_notice', [ self::class, 'handleDismissAjax' ] );
	}

	/**
	 * Detect WooCommerce Cart/Checkout pages that use block content.
	 *
	 * @return int[] Array of affected page IDs.
	 */
	public static function detectBlockPages(): array {
		$affected = [];

		$cart_page_id     = (int) get_option( 'woocommerce_cart_page_id' );
		$checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );

		if ( $cart_page_id > 0 ) {
			$content = get_post_field( 'post_content', $cart_page_id );
			if ( is_string( $content ) && strpos( $content, 'wp:woocommerce/cart' ) !== false ) {
				$affected[] = $cart_page_id;
			}
		}

		if ( $checkout_page_id > 0 ) {
			$content = get_post_field( 'post_content', $checkout_page_id );
			if ( is_string( $content ) && strpos( $content, 'wp:woocommerce/checkout' ) !== false ) {
				$affected[] = $checkout_page_id;
			}
		}

		return $affected;
	}

	/**
	 * Run detection on plugin activation and store results.
	 */
	public static function runOnActivation(): void {
		$affected = self::detectBlockPages();

		if ( ! empty( $affected ) ) {
			update_option( 'wcpwyw_block_cart_detected', json_encode( $affected ) );
			delete_option( 'wcpwyw_block_notice_dismissed' );
		} else {
			update_option( 'wcpwyw_block_cart_detected', '' );
		}
	}

	/**
	 * Render the admin notice if block pages are detected.
	 */
	public static function renderNotice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( 'yes' === get_option( 'wcpwyw_block_notice_dismissed' ) ) {
			return;
		}

		if ( '' === get_option( 'wcpwyw_block_cart_detected', '' ) ) {
			return;
		}

		$affected = self::detectBlockPages();
		if ( empty( $affected ) ) {
			return;
		}

		$cart_page_id     = (int) get_option( 'woocommerce_cart_page_id' );
		$checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );

		$page_names = [];
		foreach ( $affected as $page_id ) {
			if ( $page_id === $cart_page_id ) {
				/* translators: %d: page ID */
				$page_names[] = sprintf( __( 'Cart (ID %d)', 'wc-pay-what-you-want' ), $page_id );
			} elseif ( $page_id === $checkout_page_id ) {
				/* translators: %d: page ID */
				$page_names[] = sprintf( __( 'Checkout (ID %d)', 'wc-pay-what-you-want' ), $page_id );
			} else {
				/* translators: %d: page ID */
				$page_names[] = sprintf( __( 'Page ID %d', 'wc-pay-what-you-want' ), $page_id );
			}
		}

		$page_list = implode( ', ', $page_names );
		$nonce     = wp_create_nonce( 'wcpwyw_block_notice_nonce' );
		?>
		<div class="notice notice-warning wcpwyw-block-notice" id="wcpwyw-block-notice">
			<p><strong><?php esc_html_e( '⚠ WC Pay What You Want', 'wc-pay-what-you-want' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Your Cart and/or Checkout pages use WooCommerce block content, which is incompatible with PWYW cart price editing. Customers will not be able to adjust their price in the cart.', 'wc-pay-what-you-want' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of affected page names and IDs */
					esc_html__( 'Affected pages: %s', 'wc-pay-what-you-want' ),
					esc_html( $page_list )
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="wcpwyw-switch-btn">
					<?php esc_html_e( 'Switch to Classic Cart &amp; Checkout', 'wc-pay-what-you-want' ); ?>
				</button>
				&nbsp;
				<button type="button" class="button" id="wcpwyw-dismiss-btn">
					<?php esc_html_e( 'Dismiss', 'wc-pay-what-you-want' ); ?>
				</button>
			</p>
			<p><em><?php esc_html_e( 'After switching, customers can edit their price in the cart. Your theme layout is unaffected — only the cart widget content is changed.', 'wc-pay-what-you-want' ); ?></em></p>
			<script>
			(function($) {
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

				$('#wcpwyw-switch-btn').on('click', function() {
					var $btn = $(this);
					$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Switching...', 'wc-pay-what-you-want' ) ); ?>);

					$.post(ajaxUrl, {
						action: 'wcpwyw_switch_to_classic_cart',
						nonce: nonce
					})
					.done(function(response) {
						if (response && response.success) {
							$('#wcpwyw-block-notice').html(
								'<p><strong>' + <?php echo wp_json_encode( __( '✓ WC Pay What You Want', 'wc-pay-what-you-want' ) ); ?> + '</strong></p>' +
								'<p>' + <?php echo wp_json_encode( __( 'Cart and Checkout pages have been switched to classic shortcodes. PWYW cart editing is now active.', 'wc-pay-what-you-want' ) ); ?> + '</p>'
							).removeClass('notice-warning').addClass('notice-success');
						} else {
							var msg = (response && response.data && response.data.message) ? response.data.message : <?php echo wp_json_encode( __( 'An error occurred. Please try again.', 'wc-pay-what-you-want' ) ); ?>;
							$btn.replaceWith('<span style="color:#c00;">' + msg + '</span>');
						}
					})
					.fail(function() {
						$btn.replaceWith('<span style="color:#c00;">' + <?php echo wp_json_encode( __( 'Request failed. Please reload and try again.', 'wc-pay-what-you-want' ) ); ?> + '</span>');
					});
				});

				$('#wcpwyw-dismiss-btn').on('click', function() {
					$.post(ajaxUrl, {
						action: 'wcpwyw_dismiss_block_notice',
						nonce: nonce
					})
					.always(function() {
						$('#wcpwyw-block-notice').fadeOut(300, function() {
							$(this).remove();
						});
					});
				});
			})(jQuery);
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX handler: switch Cart and Checkout pages to classic shortcodes.
	 */
	public static function handleSwitchAjax(): void {
		check_ajax_referer( 'wcpwyw_block_notice_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-pay-what-you-want' ) ], 403 );
			return;
		}

		$affected         = self::detectBlockPages();
		$cart_page_id     = (int) get_option( 'woocommerce_cart_page_id' );
		$checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );

		foreach ( $affected as $page_id ) {
			if ( $page_id === $cart_page_id ) {
				$new_content = '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->';
			} elseif ( $page_id === $checkout_page_id ) {
				$new_content = '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->';
			} else {
				continue;
			}

			$result = wp_update_post( [
				'ID'           => $page_id,
				'post_content' => $new_content,
			] );

			if ( 0 === $result || is_wp_error( $result ) ) {
				$error_message = is_wp_error( $result ) ? $result->get_error_message() : '';
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: %d: page ID */
							__( 'Failed to update page ID %d. %s', 'wc-pay-what-you-want' ),
							$page_id,
							$error_message
						),
					],
					500
				);
				return;
			}
		}

		update_option( 'wcpwyw_block_cart_detected', '' );
		wp_send_json_success( [ 'message' => __( 'Pages switched to classic shortcodes.', 'wc-pay-what-you-want' ) ] );
	}

	/**
	 * AJAX handler: dismiss the block cart notice.
	 */
	public static function handleDismissAjax(): void {
		check_ajax_referer( 'wcpwyw_block_notice_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-pay-what-you-want' ) ], 403 );
			return;
		}

		update_option( 'wcpwyw_block_notice_dismissed', 'yes' );
		wp_send_json_success();
	}
}
