<?php

namespace WcPwyw\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CartHandler {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Guard: global PWYW must be enabled.
		if ( 'yes' !== get_option( 'wcpwyw_enabled', 'yes' ) ) {
			return;
		}

		self::$initialized = true;

		// Add-to-cart: validation and data capture.
		add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validateAddToCart' ], 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'addCartItemData' ], 10, 3 );

		// Session persistence and price application.
		add_filter( 'woocommerce_get_cart_item_from_session', [ self::class, 'restoreCartItemFromSession' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ self::class, 'applyCartItemPrice' ] );

		// Order: save last-paid price to user meta.
		add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'saveLastPriceToUserMeta' ], 10, 4 );

		// Cart display: editable input, badge, boundary label, subtotal.
		add_filter( 'woocommerce_cart_item_name', [ self::class, 'renderCartItemName' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_price', [ self::class, 'renderCartItemPrice' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ self::class, 'renderCartItemSubtotal' ], 10, 3 );

		// AJAX cart price update.
		add_action( 'wp_ajax_wcpwyw_update_cart_price', [ self::class, 'handleUpdateCartPrice' ] );
		add_action( 'wp_ajax_nopriv_wcpwyw_update_cart_price', [ self::class, 'handleUpdateCartPrice' ] );

		// Quick-add: loop button filter.
		add_filter( 'woocommerce_loop_add_to_cart_link', [ self::class, 'filterLoopAddToCartLink' ], 10, 2 );
	}

	// ──────────────────────────────────────────────────────────────────────
	// ADD-TO-CART HOOKS
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Validate the PWYW price before adding to cart.
	 *
	 * Handles two paths:
	 * 1. Product-page add-to-cart (wcpwyw_nonce present)
	 * 2. Archive quick-add (no wcpwyw_nonce — auto-price or block)
	 *
	 * Mixed-cart restriction check runs first (before nonce check) for all paths.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $qty
	 * @return bool
	 */
	public static function validateAddToCart( bool $passed, int $product_id, int $qty ): bool {
		// ── Mixed cart restriction (all products, all paths) ──────────────
		$settings_raw = get_option( 'woocommerce_wcpwyw_settings', [] );
		$settings     = is_string( $settings_raw ) ? (array) json_decode( $settings_raw, true ) : (array) $settings_raw;
		$restriction  = $settings['wcpwyw_mixed_cart_restriction'] ?? 'no';

		if ( 'yes' === $restriction && WC()->cart && ! WC()->cart->is_empty() ) {
			$incoming_config = ProductPage::resolveConfig( $product_id );
			$incoming_pwyw   = ! empty( $incoming_config['pwyw_enabled'] );
			$cart_has_pwyw   = self::cartContainsPwyw();

			if ( $incoming_pwyw && ! $cart_has_pwyw ) {
				wc_add_notice(
					__( 'Your cart contains regular-priced items. Please complete your current purchase before adding Pay What You Want products.', 'wc-pay-what-you-want' ),
					'error'
				);
				return false;
			}

			if ( ! $incoming_pwyw && $cart_has_pwyw ) {
				wc_add_notice(
					__( 'Your cart contains a Pay What You Want item. Please complete your current purchase before adding regular-priced products.', 'wc-pay-what-you-want' ),
					'error'
				);
				return false;
			}
		}

		// ── Archive quick-add path (no PWYW nonce) ────────────────────────
		if ( empty( $_POST['wcpwyw_nonce'] ) ) {
			$config = ProductPage::resolveConfig( $product_id );
			if ( empty( $config['pwyw_enabled'] ) ) {
				return $passed; // Not a PWYW product — standard WC handles it.
			}
			$quick_add = $config['quick_add_default'] ?? 'suggested';
			if ( 'blocked' === $quick_add ) {
				wc_add_notice(
					__( 'Please visit the product page to set your price before adding this item to your cart.', 'wc-pay-what-you-want' ),
					'error'
				);
				return false;
			}
			return $passed; // Auto-price applied in addCartItemData.
		}

		// ── Product-page path (wcpwyw_nonce present) ──────────────────────
		$nonce = sanitize_text_field( wp_unslash( $_POST['wcpwyw_nonce'] ) );
		if ( ! wcpwyw_verify_nonce( $nonce, 'wcpwyw_add_to_cart' ) ) {
			return $passed;
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$config       = ProductPage::resolveConfig( $product_id, $variation_id );

		if ( empty( $config['pwyw_enabled'] ) ) {
			return $passed;
		}

		// Sanitize and validate the submitted price.
		$raw_price = isset( $_POST['wcpwyw_price'] ) ? $_POST['wcpwyw_price'] : '';
		$price     = wcpwyw_sanitize_price( $raw_price );

		if ( false === $price ) {
			wc_add_notice(
				__( 'Please enter a valid price.', 'wc-pay-what-you-want' ),
				'error'
			);
			return false;
		}

		$price = (float) wc_format_decimal( $price );

		// Zero price check.
		if ( ! $config['allow_zero'] && 0.0 === $price ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: formatted minimum price */
					__( 'Please enter at least %s.', 'wc-pay-what-you-want' ),
					wp_strip_all_tags( wc_price( $config['min_price'] ) )
				),
				'error'
			);
			return false;
		}

		// Below minimum.
		if ( $price < $config['min_price'] ) {
			if ( 'yes' === get_option( 'wcpwyw_validation_logging', 'no' ) ) {
				error_log( sprintf(
					'[wcpwyw] Price rejected: product_id=%d, attempted=%.4f, min=%.4f, max=%.4f, ip=%s',
					$product_id,
					$price,
					$config['min_price'],
					$config['max_price'],
					sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) )
				) );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: formatted minimum price */
					__( 'Please enter at least %s.', 'wc-pay-what-you-want' ),
					wp_strip_all_tags( wc_price( $config['min_price'] ) )
				),
				'error'
			);
			return false;
		}

		// Above maximum.
		if ( $price > $config['max_price'] ) {
			if ( 'yes' === get_option( 'wcpwyw_validation_logging', 'no' ) ) {
				error_log( sprintf(
					'[wcpwyw] Price rejected: product_id=%d, attempted=%.4f, min=%.4f, max=%.4f, ip=%s',
					$product_id,
					$price,
					$config['min_price'],
					$config['max_price'],
					sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) )
				) );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: formatted maximum price */
					__( 'Please enter no more than %s.', 'wc-pay-what-you-want' ),
					wp_strip_all_tags( wc_price( $config['max_price'] ) )
				),
				'error'
			);
			return false;
		}

		// Absolute price cap check (TC-612, TC-614).
		$cap_enabled = get_option( 'wcpwyw_price_cap_enabled', 'yes' );
		if ( 'yes' === $cap_enabled ) {
			$cap_value = (float) get_option( 'wcpwyw_price_cap_value', 9999.00 );
			if ( $price > $cap_value ) {
				if ( 'yes' === get_option( 'wcpwyw_validation_logging', 'no' ) ) {
					error_log( sprintf(
						'[wcpwyw] Price cap exceeded: product_id=%d, attempted=%.4f, cap=%.4f, ip=%s',
						$product_id,
						$price,
						$cap_value,
						sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) )
					) );
				}
				wc_add_notice(
					sprintf(
						/* translators: %s: formatted maximum allowed price */
						__( 'Please enter no more than %s.', 'wc-pay-what-you-want' ),
						wp_strip_all_tags( wc_price( $cap_value ) )
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Add PWYW price to cart item data.
	 *
	 * Handles:
	 * 1. Product-page path (wcpwyw_nonce present).
	 * 2. Archive quick-add path (no nonce — auto-price from setting).
	 *
	 * @param array $cart_item_data
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @return array
	 */
	public static function addCartItemData( array $cart_item_data, int $product_id, int $variation_id ): array {
		// ── Archive quick-add path ────────────────────────────────────────
		if ( empty( $_POST['wcpwyw_nonce'] ) ) {
			$config = ProductPage::resolveConfig( $product_id, $variation_id );
			if ( empty( $config['pwyw_enabled'] ) ) {
				return $cart_item_data;
			}
			$quick_add = $config['quick_add_default'] ?? 'suggested';
			if ( 'block' === $quick_add ) {
				return $cart_item_data; // Blocked — validateAddToCart already rejected.
			}
			$auto_price = ( 'minimum' === $quick_add )
				? $config['min_price']
				: $config['suggested_price'];

			$cart_item_data['wcpwyw_price']        = (float) wc_format_decimal( $auto_price );
			$cart_item_data['wcpwyw_product_id']   = absint( $product_id );
			$cart_item_data['wcpwyw_variation_id'] = absint( $variation_id );
			return $cart_item_data;
		}

		// ── Product-page path ─────────────────────────────────────────────
		$nonce = sanitize_text_field( wp_unslash( $_POST['wcpwyw_nonce'] ) );
		if ( ! wcpwyw_verify_nonce( $nonce, 'wcpwyw_add_to_cart' ) ) {
			return $cart_item_data;
		}

		if ( ! isset( $_POST['wcpwyw_price'] ) ) {
			return $cart_item_data;
		}

		$price = wcpwyw_sanitize_price( $_POST['wcpwyw_price'] );
		if ( false === $price ) {
			return $cart_item_data;
		}

		// Round to store decimal precision.
		$cart_item_data['wcpwyw_price']        = (float) wc_format_decimal( $price );
		$cart_item_data['wcpwyw_product_id']   = absint( $product_id );
		$cart_item_data['wcpwyw_variation_id'] = absint( $variation_id );

		return $cart_item_data;
	}

	/**
	 * Restore PWYW cart item data from session.
	 *
	 * @param array $cart_item
	 * @param array $session_values
	 * @return array
	 */
	public static function restoreCartItemFromSession( array $cart_item, array $session_values ): array {
		if ( isset( $session_values['wcpwyw_price'] ) ) {
			$cart_item['wcpwyw_price'] = (float) $session_values['wcpwyw_price'];
		}
		if ( isset( $session_values['wcpwyw_product_id'] ) ) {
			$cart_item['wcpwyw_product_id'] = absint( $session_values['wcpwyw_product_id'] );
		}
		if ( isset( $session_values['wcpwyw_variation_id'] ) ) {
			$cart_item['wcpwyw_variation_id'] = absint( $session_values['wcpwyw_variation_id'] );
		}
		return $cart_item;
	}

	/**
	 * Apply PWYW price to cart items before totals are calculated.
	 *
	 * Runs on both cart and checkout contexts (woocommerce_before_calculate_totals).
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	public static function applyCartItemPrice( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		static $allow_zero_cache = [];

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
				continue;
			}

			$price      = (float) $cart_item['wcpwyw_price'];
			$product_id = isset( $cart_item['wcpwyw_product_id'] ) ? (int) $cart_item['wcpwyw_product_id'] : (int) $cart_item['product_id'];

			// Allow $0.00 if configured.
			if ( $price === 0.0 ) {
				if ( ! isset( $allow_zero_cache[ $product_id ] ) ) {
					$allow_zero_cache[ $product_id ] = ( 'yes' === get_post_meta( $product_id, '_wcpwyw_allow_zero', true ) );
				}
				if ( ! $allow_zero_cache[ $product_id ] ) {
					continue;
				}
			}

			if ( $price >= 0.0 ) {
				$cart_item['data']->set_price( $price );
			}
		}
	}

	/**
	 * Save last-paid PWYW price to user meta at checkout.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $values
	 * @param \WC_Order              $order
	 * @return void
	 */
	public static function saveLastPriceToUserMeta( $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( ! isset( $values['wcpwyw_price'] ) ) {
			return;
		}

		$user_id    = (int) $order->get_user_id();
		$product_id = isset( $values['wcpwyw_product_id'] ) ? (int) $values['wcpwyw_product_id'] : (int) $values['product_id'];

		if ( $user_id > 0 && $product_id > 0 ) {
			update_user_meta( $user_id, '_wcpwyw_last_price_' . $product_id, (float) $values['wcpwyw_price'] );
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	// CART DISPLAY HOOKS
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Append PWYW badge to cart item name.
	 *
	 * On cart: simple "Pay What You Want" badge.
	 * On checkout: badge includes the customer-chosen price.
	 *
	 * @param string $name
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public static function renderCartItemName( string $name, array $cart_item, string $cart_item_key ): string {
		if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
			return $name;
		}

		if ( is_checkout() ) {
			$chosen_price = wc_price( (float) $cart_item['wcpwyw_price'] );
			$badge        = sprintf(
				'<span class="wcpwyw-checkout-badge">%s &middot; %s</span>',
				esc_html__( 'Pay What You Want', 'wc-pay-what-you-want' ),
				sprintf(
					/* translators: %s: formatted customer-chosen price */
					esc_html__( 'Your chosen price: %s', 'wc-pay-what-you-want' ),
					$chosen_price
				)
			);
		} else {
			$badge = '<span class="wcpwyw-cart-badge">' . esc_html__( 'Pay What You Want', 'wc-pay-what-you-want' ) . '</span>';
		}

		return $name . '<br>' . $badge;
	}

	/**
	 * Replace the price cell with an editable PWYW input (cart) or static price (checkout).
	 *
	 * @param string $price_html
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public static function renderCartItemPrice( string $price_html, array $cart_item, string $cart_item_key ): string {
		if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
			return $price_html;
		}

		// Checkout: static read-only price — no input.
		if ( is_checkout() ) {
			return wc_price( (float) $cart_item['wcpwyw_price'] );
		}

		// Cart: editable input.
		$product_id   = isset( $cart_item['wcpwyw_product_id'] ) ? (int) $cart_item['wcpwyw_product_id'] : (int) $cart_item['product_id'];
		$variation_id = isset( $cart_item['wcpwyw_variation_id'] ) ? (int) $cart_item['wcpwyw_variation_id'] : 0;
		$config       = ProductPage::resolveConfig( $product_id, $variation_id );

		$decimals      = wc_get_price_decimals();
		$step          = number_format( 1 / pow( 10, $decimals ), $decimals, '.', '' );
		$current_price = number_format( (float) $cart_item['wcpwyw_price'], $decimals, '.', '' );
		$min_price     = isset( $config['min_price'] ) ? $config['min_price'] : 0;
		$max_price     = isset( $config['max_price'] ) ? $config['max_price'] : 0;
		$currency      = get_woocommerce_currency_symbol();
		$nonce         = wp_create_nonce( 'wcpwyw_cart_update' );

		// Build boundary label.
		if ( $min_price > 0 && $max_price > 0 ) {
			/* translators: 1: formatted minimum price, 2: formatted maximum price */
			$boundary_text = sprintf(
				__( 'Pay between %1$s – %2$s', 'wc-pay-what-you-want' ),
				wp_strip_all_tags( wc_price( $min_price ) ),
				wp_strip_all_tags( wc_price( $max_price ) )
			);
		} elseif ( $min_price > 0 ) {
			/* translators: %s: formatted minimum price */
			$boundary_text = sprintf(
				__( 'Minimum: %s', 'wc-pay-what-you-want' ),
				wp_strip_all_tags( wc_price( $min_price ) )
			);
		} else {
			$boundary_text = '';
		}

		ob_start();
		?>
		<div class="wcpwyw-cart-price-wrap">
			<div class="wcpwyw-cart-price-row">
				<span class="wcpwyw-cart-currency"><?php echo esc_html( $currency ); ?></span>
				<input
					type="number"
					class="wcpwyw-cart-price-input"
					data-wcpwyw-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
					data-wcpwyw-min="<?php echo esc_attr( $min_price ); ?>"
					data-wcpwyw-max="<?php echo esc_attr( $max_price ); ?>"
					data-wcpwyw-nonce="<?php echo esc_attr( $nonce ); ?>"
					value="<?php echo esc_attr( $current_price ); ?>"
					step="<?php echo esc_attr( $step ); ?>"
					autocomplete="off"
					aria-label="<?php esc_attr_e( 'Your price', 'wc-pay-what-you-want' ); ?>"
					aria-valuemin="<?php echo esc_attr( $min_price ); ?>"
					aria-valuemax="<?php echo esc_attr( $max_price ); ?>"
				/>
			</div>
			<p class="wcpwyw-cart-error" role="alert" aria-live="assertive" style="display:none;"></p>
			<?php if ( $boundary_text ) : ?>
			<p class="wcpwyw-cart-boundary"><?php echo esc_html( $boundary_text ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Replace the subtotal cell with the PWYW price × quantity.
	 *
	 * Ensures the displayed subtotal is correct even before an AJAX
	 * update cycle completes.
	 *
	 * @param string $subtotal
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public static function renderCartItemSubtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
		if ( ! isset( $cart_item['wcpwyw_price'] ) ) {
			return $subtotal;
		}

		return wc_price( (float) $cart_item['wcpwyw_price'] * (int) $cart_item['quantity'] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// AJAX: CART PRICE UPDATE
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Handle AJAX request to update a PWYW cart item's price.
	 *
	 * @return void
	 */
	public static function handleUpdateCartPrice(): void {
		// 1. Nonce check.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wcpwyw_cart_update' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Security check failed.', 'wc-pay-what-you-want' ) ],
				403
			);
		}

		// 2. Get cart key and raw price.
		$cart_item_key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
		$raw_price     = isset( $_POST['price'] ) ? $_POST['price'] : '';

		// 3. Look up cart item.
		$cart     = WC()->cart->get_cart();
		if ( ! isset( $cart[ $cart_item_key ] ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Cart item not found.', 'wc-pay-what-you-want' ) ],
				400
			);
		}

		$cart_item    = $cart[ $cart_item_key ];
		$product_id   = (int) $cart_item['product_id'];
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

		// 4. Resolve config; confirm item is PWYW.
		$config = ProductPage::resolveConfig( $product_id, $variation_id );
		if ( empty( $config['pwyw_enabled'] ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Not a PWYW product.', 'wc-pay-what-you-want' ) ],
				400
			);
		}

		// 5. Sanitize price.
		$price = wcpwyw_sanitize_price( $raw_price );
		if ( false === $price ) {
			wp_send_json_error(
				[
					'message' => __( 'Please enter a valid price.', 'wc-pay-what-you-want' ),
					'field'   => 'invalid',
				],
				422
			);
		}

		$price = (float) wc_format_decimal( $price );

		// 6. Zero price check.
		if ( ! $config['allow_zero'] && 0.0 === $price ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: formatted minimum price */
						__( 'Min. price is %s.', 'wc-pay-what-you-want' ),
						wp_strip_all_tags( wc_price( $config['min_price'] ) )
					),
					'field'   => 'below_min',
				],
				422
			);
		}

		// 7. Below minimum.
		if ( $price < $config['min_price'] ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: formatted minimum price */
						__( 'Min. price is %s.', 'wc-pay-what-you-want' ),
						wp_strip_all_tags( wc_price( $config['min_price'] ) )
					),
					'field'   => 'below_min',
				],
				422
			);
		}

		// 8. Above maximum.
		if ( $price > $config['max_price'] ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: formatted maximum price */
						__( 'Max. price is %s.', 'wc-pay-what-you-want' ),
						wp_strip_all_tags( wc_price( $config['max_price'] ) )
					),
					'field'   => 'above_max',
				],
				422
			);
		}

		// 9. Update cart session.
		WC()->cart->cart_contents[ $cart_item_key ]['wcpwyw_price'] = $price;
		WC()->cart->set_session();
		WC()->cart->calculate_totals();

		// 10. Build and return success response.
		$totals = [
			'subtotal'   => WC()->cart->get_cart_subtotal(),
			'total'      => WC()->cart->get_total( 'edit' ),
			'line_total' => wc_price( $price * (int) $cart_item['quantity'] ),
			'new_price'  => number_format( $price, wc_get_price_decimals(), '.', '' ),
		];

		wp_send_json_success( $totals );
	}

	// ──────────────────────────────────────────────────────────────────────
	// QUICK-ADD: SHOP/ARCHIVE PAGES
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Replace "Add to Cart" button with "View Product" link for blocked PWYW products
	 * on shop/archive pages.
	 *
	 * @param string      $link
	 * @param \WC_Product $product
	 * @return string
	 */
	public static function filterLoopAddToCartLink( string $link, \WC_Product $product ): string {
		$config = ProductPage::resolveConfig( $product->get_id() );
		if ( empty( $config['pwyw_enabled'] ) ) {
			return $link;
		}

		$quick_add = $config['quick_add_default'] ?? 'suggested';

		if ( 'blocked' === $quick_add ) {
			return '<a href="' . esc_url( $product->get_permalink() ) . '" class="button wcpwyw-view-product">'
				. esc_html__( 'View Product', 'wc-pay-what-you-want' )
				. '</a>';
		}

		return $link; // Auto-price path: keep standard "Add to Cart" button.
	}

	// ──────────────────────────────────────────────────────────────────────
	// HELPERS
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Check whether the current cart contains at least one PWYW item.
	 *
	 * @return bool
	 */
	private static function cartContainsPwyw(): bool {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['wcpwyw_price'] ) ) {
				return true;
			}
		}
		return false;
	}
}
