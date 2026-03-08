<?php

namespace WcPwyw\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductPage {

	private static bool $initialized = false;

	/**
	 * Resolve the complete PWYW config for a product/variation.
	 *
	 * Resolution order:
	 * 1. If variation_id > 0 and _wcpwyw_variation_mode == 'enable': use variation overrides, fall back to product
	 * 2. If variation mode is 'disable': return ['pwyw_enabled' => false]
	 * 3. If 'inherit' or variation_id === 0: use parent product values
	 * 4. For blank fields: calculate from global settings (regular_price × global_pct / 100)
	 *
	 * @param int $product_id
	 * @param int $variation_id
	 * @return array
	 */
	public static function resolveConfig( int $product_id, int $variation_id = 0 ): array {
		$global_settings_raw = get_option( 'woocommerce_wcpwyw_settings', [] );
		$global_settings     = is_string( $global_settings_raw ) ? (array) json_decode( $global_settings_raw, true ) : (array) $global_settings_raw;

		// Check if PWYW is enabled on this product.
		$product_pwyw_enabled = get_post_meta( $product_id, '_wcpwyw_enabled', true );
		if ( 'yes' !== $product_pwyw_enabled ) {
			return [ 'pwyw_enabled' => false ];
		}

		// For variations, check variation mode.
		if ( $variation_id > 0 ) {
			$variation_mode = get_post_meta( $variation_id, '_wcpwyw_variation_mode', true );

			if ( 'disable' === $variation_mode ) {
				return [ 'pwyw_enabled' => false ];
			}
		}

		// Determine which product to pull prices from (variation or parent).
		$price_product_id = ( $variation_id > 0 ) ? $variation_id : $product_id;
		$price_product    = wc_get_product( $price_product_id );
		$regular_price    = $price_product ? (float) wc_format_decimal( $price_product->get_regular_price() ) : 0.0;

		// --- Helper to resolve a field from variation → product → global % ---
		$resolve_price = function ( string $meta_key, string $global_pct_key ) use (
			$product_id,
			$variation_id,
			$regular_price,
			$global_settings
		): float {
			// Try variation-level override.
			if ( $variation_id > 0 ) {
				$val = get_post_meta( $variation_id, $meta_key, true );
				if ( '' !== $val && null !== $val ) {
					return (float) wc_format_decimal( $val );
				}
			}
			// Try product-level override.
			$val = get_post_meta( $product_id, $meta_key, true );
			if ( '' !== $val && null !== $val ) {
				return (float) wc_format_decimal( $val );
			}
			// Fall back to global percentage × regular price.
			$pct = isset( $global_settings[ $global_pct_key ] ) ? (float) $global_settings[ $global_pct_key ] : 0.0;
			return (float) wc_format_decimal( $regular_price * $pct / 100.0 );
		};

		// --- Allow zero ---
		$allow_zero_meta = '';
		if ( $variation_id > 0 ) {
			$allow_zero_meta = get_post_meta( $variation_id, '_wcpwyw_allow_zero', true );
		}
		if ( '' === $allow_zero_meta ) {
			$allow_zero_meta = get_post_meta( $product_id, '_wcpwyw_allow_zero', true );
		}
		$allow_zero = ( 'yes' === $allow_zero_meta );

		$min_price       = $resolve_price( '_wcpwyw_min_price', 'wcpwyw_default_min_pct' );
		// If allow_zero is set and no explicit min price override, force min to 0.
		$raw_min_override = ( $variation_id > 0 )
			? get_post_meta( $variation_id, '_wcpwyw_min_price', true )
			: '';
		if ( '' === $raw_min_override ) {
			$raw_min_override = get_post_meta( $product_id, '_wcpwyw_min_price', true );
		}
		if ( $allow_zero && '' === $raw_min_override ) {
			$min_price = 0.0;
		}

		$max_price       = $resolve_price( '_wcpwyw_max_price', 'wcpwyw_default_max_pct' );
		$suggested_price = $resolve_price( '_wcpwyw_suggested_price', 'wcpwyw_default_suggested_pct' );

		// --- Preset buttons ---
		// Resolution order: variation → product → global.
		// An explicitly saved empty string ("") means "no presets" (opt-out) and does NOT
		// fall through to global. We use metadata_exists() to distinguish an explicit empty
		// string (opt-out) from a meta key that was never saved (inherit global).
		$preset_buttons            = [];
		$presets_raw               = '';
		$presets_explicitly_set    = false;

		if ( $variation_id > 0 ) {
			if ( metadata_exists( 'post', $variation_id, '_wcpwyw_preset_buttons' ) ) {
				$presets_explicitly_set = true;
				$presets_raw            = (string) get_post_meta( $variation_id, '_wcpwyw_preset_buttons', true );
			}
		}
		if ( ! $presets_explicitly_set ) {
			if ( metadata_exists( 'post', $product_id, '_wcpwyw_preset_buttons' ) ) {
				$presets_explicitly_set = true;
				$presets_raw            = (string) get_post_meta( $product_id, '_wcpwyw_preset_buttons', true );
			}
		}
		if ( ! $presets_explicitly_set ) {
			if ( isset( $global_settings['wcpwyw_global_preset_buttons'] ) ) {
				$presets_raw = $global_settings['wcpwyw_global_preset_buttons'];
			} else {
				// Secondary fallback: standalone option (written by saveTab() dual-write
				// and by Activator). Handles split-brain WC settings / missing key.
				$presets_raw = get_option( 'wcpwyw_global_preset_buttons', '10,15,20,25' );
			}
		}

		if ( '' !== $presets_raw ) {
			foreach ( explode( ',', $presets_raw ) as $token ) {
				$float = (float) trim( $token );
				if ( $float > 0 || ( 0.0 === $float && $allow_zero ) ) {
					$preset_buttons[] = $float;
				}
			}
			$preset_buttons = array_unique( $preset_buttons );
			sort( $preset_buttons );
		}

		// --- Display style ---
		$display_style = '';
		if ( $variation_id > 0 ) {
			$display_style = get_post_meta( $variation_id, '_wcpwyw_display_style', true );
		}
		if ( '' === $display_style ) {
			$display_style = get_post_meta( $product_id, '_wcpwyw_display_style', true );
		}
		if ( '' === $display_style ) {
			// Support both the WC Settings API key (wcpwyw_price_display_style) and the legacy key.
			if ( isset( $global_settings['wcpwyw_price_display_style'] ) ) {
				$display_style = $global_settings['wcpwyw_price_display_style'];
			} elseif ( isset( $global_settings['wcpwyw_display_style'] ) ) {
				$display_style = $global_settings['wcpwyw_display_style'];
			} else {
				$display_style = get_option( 'wcpwyw_price_display_style', 'A' );
			}
		}
		if ( ! in_array( $display_style, [ 'A', 'B', 'C', 'D' ], true ) ) {
			$display_style = 'A';
		}

		// --- Archive display style (product → global; NOT variation-level) ---
		$archive_display_style = get_post_meta( $product_id, '_wcpwyw_archive_display_style', true );
		if ( '' === $archive_display_style && isset( $global_settings['wcpwyw_archive_display_style'] ) ) {
			$archive_display_style = $global_settings['wcpwyw_archive_display_style'];
		}
		$valid_archive_styles = [ 'range', 'suggested', 'from_min', 'label' ];
		if ( ! in_array( $archive_display_style, $valid_archive_styles, true ) ) {
			$archive_display_style = 'range';
		}

		// --- Quick-add default ---
		$quick_add_default = '';
		if ( $variation_id > 0 ) {
			$quick_add_default = get_post_meta( $variation_id, '_wcpwyw_quick_add_default', true );
		}
		if ( '' === $quick_add_default ) {
			$quick_add_default = get_post_meta( $product_id, '_wcpwyw_quick_add_default', true );
		}
		if ( '' === $quick_add_default && isset( $global_settings['wcpwyw_quick_add_default'] ) ) {
			$quick_add_default = $global_settings['wcpwyw_quick_add_default'];
		}
		if ( ! in_array( $quick_add_default, [ 'suggested', 'minimum', 'blocked' ], true ) ) {
			$quick_add_default = 'suggested';
		}

		// --- Label input ---
		$label_input = '';
		if ( $variation_id > 0 ) {
			$label_input = get_post_meta( $variation_id, '_wcpwyw_label_input', true );
		}
		if ( '' === $label_input ) {
			$label_input = get_post_meta( $product_id, '_wcpwyw_label_input', true );
		}
		if ( '' === $label_input && isset( $global_settings['wcpwyw_label_input'] ) ) {
			$label_input = $global_settings['wcpwyw_label_input'];
		}
		if ( '' === $label_input ) {
			$label_input = __( 'Name Your Price', 'wc-pay-what-you-want' );
		}

		// --- Coupon mode ---
		$coupon_mode = '';
		if ( $variation_id > 0 ) {
			$coupon_mode = get_post_meta( $variation_id, '_wcpwyw_coupon_mode', true );
		}
		if ( '' === $coupon_mode ) {
			$coupon_mode = get_post_meta( $product_id, '_wcpwyw_coupon_mode', true );
		}
		if ( '' === $coupon_mode && isset( $global_settings['wcpwyw_coupon_mode'] ) ) {
			$coupon_mode = $global_settings['wcpwyw_coupon_mode'];
		}
		if ( ! in_array( $coupon_mode, [ 'allow', 'allow_with_floor', 'block' ], true ) ) {
			$coupon_mode = 'allow';
		}

		$decimals        = wc_get_price_decimals();
		// Decode HTML entities (e.g. &#36; → $) so the symbol can be safely output with esc_html() or used in JS .text().
		$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return [
			'pwyw_enabled'          => true,
			'allow_zero'            => $allow_zero,
			'min_price'             => $min_price,
			'max_price'             => $max_price,
			'suggested_price'       => $suggested_price,
			'preset_buttons'        => $preset_buttons,
			'display_style'         => $display_style,
			'archive_display_style' => $archive_display_style,
			'quick_add_default'     => $quick_add_default,
			'label_input'           => $label_input,
			'coupon_mode'           => $coupon_mode,
			'regular_price'         => $regular_price,
			'currency_symbol'       => $currency_symbol,
			'decimals'              => $decimals,
			'formatted_min'         => wp_strip_all_tags( wc_price( $min_price ) ),
			'formatted_max'         => wp_strip_all_tags( wc_price( $max_price ) ),
			'formatted_suggested'   => wp_strip_all_tags( wc_price( $suggested_price ) ),
		];
	}

	/**
	 * Register hooks. Guarded by global PWYW enabled option and $initialized flag.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Guard: global PWYW must be enabled.
		if ( 'yes' !== get_option( 'wcpwyw_enabled', 'yes' ) ) {
			return;
		}

		self::$initialized = true;

		add_action( 'woocommerce_single_product_summary', [ self::class, 'injectPwywSection' ], 25 );
		add_filter( 'woocommerce_get_price_html', [ self::class, 'filterPriceHtml' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'localizeScript' ], 20 );
		add_filter( 'woocommerce_variation_is_purchasable', [ self::class, 'filterVariationIsPurchasable' ], 10, 2 );
		add_filter( 'woocommerce_variation_is_visible', [ self::class, 'filterVariationIsVisible' ], 10, 4 );
		add_filter( 'woocommerce_variation_is_active', [ self::class, 'filterVariationIsActive' ], 10, 2 );
		add_filter( 'woocommerce_available_variation', [ self::class, 'addVariationPwywData' ], 10, 3 );

		// PWYW + sale price are mutually exclusive: suppress WooCommerce sale indicators.
		add_filter( 'woocommerce_product_is_on_sale', [ self::class, 'filterIsOnSale' ], 10, 2 );
		add_filter( 'woocommerce_sale_flash', [ self::class, 'filterSaleFlash' ], 10, 3 );
	}

	/**
	 * Inject the PWYW price-input section into the single product summary.
	 *
	 * Hooked to woocommerce_single_product_summary at priority 25
	 * (after the default price block at 10 and before add-to-cart at 30).
	 */
	public static function injectPwywSection(): void {
		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$config = self::resolveConfig( $product_id );
		if ( empty( $config['pwyw_enabled'] ) ) {
			return;
		}

		$product     = wc_get_product( $product_id );
		$is_variable = $product && $product->is_type( 'variable' );

		// For variable products, build the variations config map.
		$variations_config = [];
		if ( $is_variable ) {
			/** @var \WC_Product_Variable $product */
			$variation_ids = $product->get_children();
			foreach ( $variation_ids as $variation_id ) {
				$variations_config[ $variation_id ] = self::resolveConfig( $product_id, (int) $variation_id );
			}
		}

		// Returning customer last-price logic.
		$user_id    = get_current_user_id();
		$raw_last   = ( $user_id > 0 )
			? \WcPwyw\Services\OrderDataService::get_last_customer_price( $user_id, $product_id )
			: null;

		// Clamp a price within the current min/max boundaries.
		$clamp_price = static function ( float $price ) use ( $config ): float {
			return max( $config['min_price'], min( $config['max_price'], $price ) );
		};

		$pre_fill_price     = $clamp_price( $config['suggested_price'] );
		$has_history        = false;
		$out_of_bounds      = false;
		$last_price_display = null;
		$last_price_note    = null;

		if ( null !== $raw_last ) {
			$last_price_display = $raw_last;

			// Boundary check.
			if ( $raw_last >= $config['min_price'] && $raw_last <= $config['max_price'] ) {
				$pre_fill_price = $raw_last;
				$has_history    = true;
				$last_price_note = sprintf(
					/* translators: %s: formatted price */
					__( 'Last time you paid: %s', 'wc-pay-what-you-want' ),
					wc_price( $raw_last )
				);
			} else {
				$out_of_bounds   = true;
				$pre_fill_price  = $clamp_price( $config['suggested_price'] );
				$last_price_note = sprintf(
					/* translators: %s: formatted price */
					__( 'Your previous price of %s is no longer available', 'wc-pay-what-you-want' ),
					wc_price( $raw_last )
				);
			}
		}

		// Render template.
		$template_vars = [
			'config'          => $config,
			'last_price'      => $last_price_display,
			'last_price_note' => $last_price_note,
			'has_history'     => $has_history,
			'out_of_bounds'   => $out_of_bounds,
			'is_variable'     => $is_variable,
			'product_id'      => $product_id,
			'pre_fill_price'  => $pre_fill_price,
		];

		$template_file = WCPWYW_DIR . 'templates/product-page-pwyw.php';
		if ( file_exists( $template_file ) ) {
			extract( $template_vars ); // phpcs:ignore WordPress.PHP.DontExtract
			include $template_file;
		}
	}

	/**
	 * Filter the WooCommerce price HTML for PWYW-enabled products.
	 *
	 * On single product pages: returns '' so the PWYW input is the only
	 * price element shown.
	 *
	 * On archive/shop pages: returns formatted price HTML based on the
	 * product's display_style setting (A/B/C/D).
	 *
	 * @param string      $price_html
	 * @param \WC_Product $product
	 * @return string
	 */
	public static function filterPriceHtml( string $price_html, \WC_Product $product ): string {
		$config = self::resolveConfig( $product->get_id() );
		if ( empty( $config['pwyw_enabled'] ) ) {
			return $price_html;
		}

		// Single product page: suppress default price so the PWYW section takes over.
		if ( is_product() ) {
			return '';
		}

		// Variable products on archive pages: aggregate min/max across all enabled variations.
		if ( $product instanceof \WC_Product_Variable ) {
			$variation_ids = $product->get_children();
			$all_mins      = [];
			$all_maxs      = [];
			foreach ( $variation_ids as $variation_id ) {
				$vcfg = self::resolveConfig( $product->get_id(), (int) $variation_id );
				if ( ! empty( $vcfg['pwyw_enabled'] ) ) {
					$all_mins[] = $vcfg['min_price'];
					$all_maxs[] = $vcfg['max_price'];
				}
			}
			if ( empty( $all_mins ) ) {
				return $price_html;
			}
			$config['min_price'] = min( $all_mins );
			$config['max_price'] = max( $all_maxs );
		}

		// Archive / shop pages: render archive display style.
		switch ( $config['archive_display_style'] ) {
			case 'suggested':
				return wc_price( $config['suggested_price'] );

			case 'from_min':
				return sprintf(
					/* translators: %s: formatted minimum price */
					__( 'From %s', 'wc-pay-what-you-want' ),
					wc_price( $config['min_price'] )
				);

			case 'label':
				return '<span class="wcpwyw-archive-badge">'
					. esc_html__( 'Name Your Price', 'wc-pay-what-you-want' )
					. '</span>';

			case 'range':
			default:
				return wc_price( $config['min_price'] ) . ' &ndash; ' . wc_price( $config['max_price'] );
		}
	}

	/**
	 * Suppress WooCommerce "is on sale" flag for PWYW-enabled products.
	 *
	 * PWYW and sale price are mutually exclusive. When PWYW is enabled the
	 * customer sets the price, so the WC sale semantics don't apply.
	 *
	 * @param bool        $on_sale
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function filterIsOnSale( bool $on_sale, \WC_Product $product ): bool {
		if ( ! $on_sale ) {
			return false;
		}

		$product_id = $product->get_parent_id() ?: $product->get_id();
		$config     = self::resolveConfig( $product_id );

		if ( ! empty( $config['pwyw_enabled'] ) ) {
			return false;
		}

		return $on_sale;
	}

	/**
	 * Remove the "Sale!" flash badge for PWYW-enabled products.
	 *
	 * @param string      $html    The sale flash HTML.
	 * @param \WP_Post    $post    The post object.
	 * @param \WC_Product $product The product object.
	 * @return string
	 */
	public static function filterSaleFlash( string $html, \WP_Post $post, \WC_Product $product ): string {
		$product_id = $product->get_parent_id() ?: $product->get_id();
		$config     = self::resolveConfig( $product_id );

		if ( ! empty( $config['pwyw_enabled'] ) ) {
			return '';
		}

		return $html;
	}

	/**
	 * Retrieve the last price paid by a customer for this product, validated
	 * against the current min/max config.
	 *
	 * Returns null when the user is not logged in, has no order history for this
	 * product, or the stored price falls outside the current allowed range.
	 *
	 * @param int $product_id
	 * @param int $user_id
	 * @return float|null
	 */
	public static function getLastPaidPrice( int $product_id, int $user_id ): ?float {
		if ( 0 === $user_id ) {
			return null;
		}

		$last_price = \WcPwyw\Services\OrderDataService::get_last_customer_price( $user_id, $product_id );
		if ( null === $last_price ) {
			return null;
		}

		$config = self::resolveConfig( $product_id );

		if (
			isset( $config['min_price'], $config['max_price'] ) &&
			$last_price >= $config['min_price'] &&
			$last_price <= $config['max_price']
		) {
			return $last_price;
		}

		return null;
	}

	/**
	 * Make PWYW-enabled variations purchasable even when no regular price is set.
	 *
	 * WooCommerce's WC_Product_Variation::is_purchasable() uses the filter
	 * 'woocommerce_variation_is_purchasable'. PWYW variations are valid without
	 * a stored price because the customer sets the price on the product page.
	 *
	 * @param bool                  $is_purchasable
	 * @param \WC_Product_Variation $variation
	 * @return bool
	 */
	public static function filterVariationIsPurchasable( bool $is_purchasable, \WC_Product_Variation $variation ): bool {
		if ( $is_purchasable ) {
			return true;
		}

		$variation_id = $variation->get_id();
		$parent_id    = $variation->get_parent_id();
		$config       = self::resolveConfig( $parent_id, $variation_id );

		if ( ! empty( $config['pwyw_enabled'] ) ) {
			// Published status is still required.
			return 'publish' === get_post_status( $variation_id );
		}

		return $is_purchasable;
	}

	/**
	 * Make PWYW-enabled variations visible in get_available_variations() even when
	 * no regular price is set.
	 *
	 * WooCommerce hides variations with an empty price via 'woocommerce_variation_is_visible'.
	 * PWYW variations should still appear in the dropdown.
	 *
	 * @param bool                  $visible
	 * @param int                   $variation_id
	 * @param int                   $product_id
	 * @param \WC_Product_Variation $variation
	 * @return bool
	 */
	public static function filterVariationIsVisible( bool $visible, int $variation_id, int $product_id, \WC_Product_Variation $variation ): bool {
		if ( $visible ) {
			return true;
		}

		$config = self::resolveConfig( $product_id, $variation_id );

		if ( ! empty( $config['pwyw_enabled'] ) ) {
			// Published status is still required.
			return 'publish' === get_post_status( $variation_id );
		}

		return $visible;
	}

	/**
	 * Make PWYW-enabled variations active/selectable in the variation dropdowns.
	 *
	 * WooCommerce marks a variation as inactive when it has no price, which hides
	 * it from the available options. Override this for PWYW variations.
	 *
	 * @param bool                   $active
	 * @param \WC_Product_Variation  $variation
	 * @return bool
	 */
	public static function filterVariationIsActive( bool $active, \WC_Product_Variation $variation ): bool {
		if ( $active ) {
			return true;
		}

		$variation_id = $variation->get_id();
		$parent_id    = $variation->get_parent_id();
		$config       = self::resolveConfig( $parent_id, $variation_id );

		if ( ! empty( $config['pwyw_enabled'] ) ) {
			return true;
		}

		return $active;
	}

	/**
	 * Append PWYW configuration to the per-variation data sent to the frontend.
	 *
	 * WooCommerce serialises this data into data-product_variations and also
	 * dispatches it via the found_variation jQuery event. Adding a 'wcpwyw'
	 * key here allows frontend-pwyw.js to show/hide the PWYW section and
	 * update its values whenever the customer selects a variation.
	 *
	 * @param array                  $data      Variation data array (passed by reference-copy).
	 * @param \WC_Product_Variable   $product   The parent variable product.
	 * @param \WC_Product_Variation  $variation The specific variation object.
	 * @return array
	 */
	public static function addVariationPwywData( array $data, \WC_Product_Variable $product, \WC_Product_Variation $variation ): array {
		$parent_id    = $product->get_id();
		$variation_id = $variation->get_id();
		$config       = self::resolveConfig( $parent_id, $variation_id );

		$pwyw_enabled = ! empty( $config['pwyw_enabled'] );

		if ( $pwyw_enabled ) {
			$data['wcpwyw'] = [
				'enabled'             => true,
				'min_price'           => $config['min_price'],
				'max_price'           => $config['max_price'],
				'suggested_price'     => $config['suggested_price'],
				'preset_buttons'      => $config['preset_buttons'],
				'allow_zero'          => $config['allow_zero'],
				'display_style'       => $config['display_style'],
				'label_input'         => $config['label_input'],
				'currency_symbol'     => $config['currency_symbol'], // already decoded in resolveConfig()
				'decimals'            => $config['decimals'],
				'formatted_min'       => $config['formatted_min'],
				'formatted_max'       => $config['formatted_max'],
				'formatted_suggested' => $config['formatted_suggested'],
			];
		} else {
			$data['wcpwyw'] = [
				'enabled' => false,
			];

			// Ensure price_html is populated for non-PWYW variations so the
			// standard WooCommerce price can display when this variation is selected.
			if ( empty( $data['price_html'] ) ) {
				$price = $variation->get_price();
				if ( '' !== $price && null !== $price ) {
					$data['price_html'] = wc_price( (float) $price );
				}
			}
		}

		return $data;
	}

	/**
	 * Localize the frontend script with product-specific PWYW configuration.
	 *
	 * Runs on wp_enqueue_scripts at priority 20 (after wcpwyw-frontend is
	 * enqueued at the default priority 10).
	 */
	public static function localizeScript(): void {
		if ( ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$product     = wc_get_product( $product_id );
		$is_variable = $product && $product->is_type( 'variable' );
		$config      = self::resolveConfig( $product_id );

		$variations_config = [];
		if ( $is_variable ) {
			/** @var \WC_Product_Variable $product */
			$variation_ids = $product->get_children();
			foreach ( $variation_ids as $variation_id ) {
				$variations_config[ $variation_id ] = self::resolveConfig( $product_id, (int) $variation_id );
			}
		}

		wp_localize_script(
			'wcpwyw-frontend',
			'wcpwywData',
			[
				'productId'        => $product_id,
				'isVariable'       => $is_variable,
				'simpleConfig'     => $is_variable ? null : $config,
				'variationsConfig' => $variations_config,
				'nonce'            => wp_create_nonce( 'wcpwyw_add_to_cart' ),
				'priceDecimals'    => wc_get_price_decimals(),
				'i18n'             => [
					'errorInvalid'  => __( 'Please enter a valid price.', 'wc-pay-what-you-want' ),
					'errorBelowMin' => __( 'Please enter at least {amount}.', 'wc-pay-what-you-want' ),
					'errorAboveMax' => __( 'Please enter no more than {amount}.', 'wc-pay-what-you-want' ),
				],
			]
		);
	}
}
