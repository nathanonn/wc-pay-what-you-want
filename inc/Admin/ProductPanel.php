<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductPanel {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'woocommerce_product_data_tabs', [ self::class, 'addTab' ] );
		add_action( 'woocommerce_product_data_panels', [ self::class, 'renderPanel' ] );
		add_action( 'woocommerce_process_product_meta', [ self::class, 'saveProductMeta' ] );
		add_action( 'admin_notices', [ self::class, 'maybeShowValidationError' ] );
	}

	public static function addTab( array $tabs ): array {
		$tabs['wcpwyw'] = [
			'label'    => __( 'Pay What You Want', 'wc-pay-what-you-want' ),
			'target'   => 'wcpwyw_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable', 'show_if_virtual', 'show_if_downloadable' ],
			'priority' => 75,
		];
		return $tabs;
	}

	public static function renderPanel(): void {
		global $post;

		$product     = wc_get_product( $post->ID );
		$is_variable = $product && $product->is_type( 'variable' );

		// Retrieve current meta values
		$enabled        = get_post_meta( $post->ID, '_wcpwyw_enabled', true );
		$allow_zero     = get_post_meta( $post->ID, '_wcpwyw_allow_zero', true );
		$min_price      = get_post_meta( $post->ID, '_wcpwyw_min_price', true );
		$max_price      = get_post_meta( $post->ID, '_wcpwyw_max_price', true );
		$suggested      = get_post_meta( $post->ID, '_wcpwyw_suggested_price', true );
		$preset_buttons = get_post_meta( $post->ID, '_wcpwyw_preset_buttons', true );
		$display_style  = get_post_meta( $post->ID, '_wcpwyw_display_style', true );
		$coupon_mode           = get_post_meta( $post->ID, '_wcpwyw_coupon_mode', true );
		$archive_display_style = get_post_meta( $post->ID, '_wcpwyw_archive_display_style', true );
		$quick_add_default     = get_post_meta( $post->ID, '_wcpwyw_quick_add_default', true );
		$label_input           = get_post_meta( $post->ID, '_wcpwyw_label_input', true );

		// Compute global defaults for helper text (TC-203)
		$regular_price = $product ? (float) $product->get_regular_price() : 0.0;
		$settings      = (array) get_option( 'woocommerce_wcpwyw_settings', [] );
		$global_label_input = $settings['wcpwyw_label_input'] ?? 'Name Your Price';
		$min_pct       = isset( $settings['wcpwyw_default_min_pct'] ) ? (float) $settings['wcpwyw_default_min_pct'] : 50.0;
		$max_pct       = isset( $settings['wcpwyw_default_max_pct'] ) ? (float) $settings['wcpwyw_default_max_pct'] : 200.0;
		$sug_pct       = isset( $settings['wcpwyw_default_suggested_pct'] ) ? (float) $settings['wcpwyw_default_suggested_pct'] : 100.0;
		$decimals      = wc_get_price_decimals();

		$global_min_amount = round( $regular_price * ( $min_pct / 100 ), $decimals );
		$global_max_amount = round( $regular_price * ( $max_pct / 100 ), $decimals );
		$global_sug_amount = round( $regular_price * ( $sug_pct / 100 ), $decimals );

		echo '<div id="wcpwyw_product_data" class="panel woocommerce_options_panel wcpwyw_admin-layout">';

		// Enable PWYW checkbox
		woocommerce_wp_checkbox( [
			'id'    => '_wcpwyw_enabled',
			'label' => __( 'Enable PWYW', 'wc-pay-what-you-want' ),
			'desc'  => __( 'Enable Pay What You Want for this product', 'wc-pay-what-you-want' ),
			'value' => $enabled,
		] );

		// Wrapper for all fields that are hidden when PWYW is disabled (JS toggle target)
		echo '<div class="wcpwyw-product-fields" ' . ( 'yes' !== $enabled ? 'style="display:none;"' : '' ) . '>';

		// Allow $0 price checkbox
		woocommerce_wp_checkbox( [
			'id'    => '_wcpwyw_allow_zero',
			'label' => __( 'Allow $0 price', 'wc-pay-what-you-want' ),
			'desc'  => __( 'Allow customers to enter $0 (completely free)', 'wc-pay-what-you-want' ),
			'value' => $allow_zero,
		] );

		if ( $is_variable ) {
			// State 2D: Variable product notice (TC-205)
			echo '<p class="wcpwyw-variation-notice">' .
				esc_html__( 'This is a variable product. Min, max, suggested prices and preset buttons are configured per variation in the Variations tab below. Use the variation override panel to set per-variation pricing.', 'wc-pay-what-you-want' ) .
				'</p>';
		} else {
			// State 2B / 2C: Pricing override fields for simple/virtual/downloadable
			self::renderPriceField( '_wcpwyw_min_price', __( 'Minimum Price ($)', 'wc-pay-what-you-want' ), (string) $min_price, $global_min_amount, $min_pct, $regular_price );
			self::renderPriceField( '_wcpwyw_max_price', __( 'Maximum Price ($)', 'wc-pay-what-you-want' ), (string) $max_price, $global_max_amount, $max_pct, $regular_price );
			self::renderPriceField( '_wcpwyw_suggested_price', __( 'Suggested Price ($)', 'wc-pay-what-you-want' ), (string) $suggested, $global_sug_amount, $sug_pct, $regular_price );

			woocommerce_wp_text_input( [
				'id'          => '_wcpwyw_preset_buttons',
				'label'       => __( 'Preset Amounts ($)', 'wc-pay-what-you-want' ),
				'value'       => $preset_buttons,
				'placeholder' => sprintf(
					/* translators: %s: default preset amounts */
					__( 'Leave blank to use global: %s', 'wc-pay-what-you-want' ),
					$settings['wcpwyw_global_preset_buttons'] ?? '10,15,20,25'
				),
			] );
		}

		// Display Style and Coupon Behaviour (shown for all product types including variable)
		woocommerce_wp_select( [
			'id'      => '_wcpwyw_display_style',
			'label'   => __( 'Display Style', 'wc-pay-what-you-want' ),
			'value'   => $display_style,
			'options' => [
				''  => __( '— Use global default', 'wc-pay-what-you-want' ),
				'A' => __( 'Style A — Input + Labels', 'wc-pay-what-you-want' ),
				'B' => __( 'Style B — Input + Preset Buttons', 'wc-pay-what-you-want' ),
				'C' => __( 'Style C — Input + Presets + Labels', 'wc-pay-what-you-want' ),
				'D' => __( 'Style D — Minimal', 'wc-pay-what-you-want' ),
			],
		] );

		woocommerce_wp_select( [
			'id'      => '_wcpwyw_coupon_mode',
			'label'   => __( 'Coupon Behaviour', 'wc-pay-what-you-want' ),
			'value'   => $coupon_mode,
			'options' => [
				''                 => __( '— Use global default', 'wc-pay-what-you-want' ),
				'allow'            => __( 'Allow coupons (no floor)', 'wc-pay-what-you-want' ),
				'allow_with_floor' => __( 'Allow with floor', 'wc-pay-what-you-want' ),
				'block'            => __( 'Block coupons', 'wc-pay-what-you-want' ),
			],
		] );

		// Archive Display Style
		woocommerce_wp_select( [
			'id'      => '_wcpwyw_archive_display_style',
			'label'   => __( 'Archive Display Style', 'wc-pay-what-you-want' ),
			'value'   => $archive_display_style,
			'options' => [
				''         => __( '— Use global default', 'wc-pay-what-you-want' ),
				'range'    => __( 'Show price range (min – max)', 'wc-pay-what-you-want' ),
				'suggested' => __( 'Show suggested price only', 'wc-pay-what-you-want' ),
				'from_min' => __( 'Show "From $X" (minimum price)', 'wc-pay-what-you-want' ),
				'label'    => __( 'Show "Name Your Price" badge', 'wc-pay-what-you-want' ),
			],
		] );

		// Quick-Add Default
		woocommerce_wp_select( [
			'id'      => '_wcpwyw_quick_add_default',
			'label'   => __( 'Quick-Add Default', 'wc-pay-what-you-want' ),
			'value'   => $quick_add_default,
			'options' => [
				''          => __( '— Use global default', 'wc-pay-what-you-want' ),
				'suggested' => __( 'Pre-fill with suggested price', 'wc-pay-what-you-want' ),
				'minimum'   => __( 'Pre-fill with minimum price', 'wc-pay-what-you-want' ),
				'blocked'   => __( 'Block quick-add (require product page)', 'wc-pay-what-you-want' ),
			],
		] );

		// Price Input Label
		woocommerce_wp_text_input( [
			'id'          => '_wcpwyw_label_input',
			'label'       => __( 'Price Input Label', 'wc-pay-what-you-want' ),
			'value'       => $label_input,
			'placeholder' => sprintf(
				/* translators: %s: global label value */
				__( 'Leave blank to use global: "%s"', 'wc-pay-what-you-want' ),
				$global_label_input
			),
			'desc'        => __( 'Custom label above the price input on the product page.', 'wc-pay-what-you-want' ),
		] );

		echo '</div>'; // .wcpwyw-product-fields

		// Disabled notice — hidden by default when PWYW is enabled, shown by JS when unchecked
		$notice_style = ( 'yes' === $enabled ) ? ' style="display:none;"' : '';
		echo '<p class="wcpwyw-disabled-notice"' . $notice_style . '>' .
			esc_html__( 'PWYW is disabled for this product. Enable it above to configure pricing.', 'wc-pay-what-you-want' ) .
			'</p>';

		echo '</div>'; // #wcpwyw_product_data
	}

	private static function renderPriceField(
		string $meta_key,
		string $label,
		string $current_value,
		float  $global_amount,
		float  $global_pct,
		float  $regular_price
	): void {
		woocommerce_wp_text_input( [
			'id'                => $meta_key,
			'label'             => $label,
			'value'             => $current_value,
			'type'              => 'number',
			'custom_attributes' => [ 'step' => 'any', 'min' => '0' ],
			'class'             => 'wcpwyw-price-override-field',
			'data_type'         => 'price',
		] );

		$formatted_amount = wc_price( $global_amount );

		if ( '' !== $current_value ) {
			// State 2C: override active
			$global_text   = sprintf(
				/* translators: 1: formatted price, 2: percentage, 3: formatted regular price */
				__( 'Using global default: %1$s (%2$s%% of regular price %3$s)', 'wc-pay-what-you-want' ),
				wp_kses_post( $formatted_amount ),
				esc_html( (string) $global_pct ),
				wp_kses_post( wc_price( $regular_price ) )
			);
			$override_text = sprintf(
				/* translators: 1: formatted override price, 2: formatted global default price */
				__( 'Override active: %1$s fixed (global default would be %2$s)', 'wc-pay-what-you-want' ),
				wc_price( (float) $current_value ),
				$formatted_amount
			);
			printf(
				'<p class="description wcpwyw-field-hint wcpwyw-override-active" data-global-text="%s" data-override-text="%s">%s</p>',
				esc_attr( wp_strip_all_tags( $global_text ) ),
				esc_attr( wp_strip_all_tags( $override_text ) ),
				wp_kses_post( $override_text )
			);
		} else {
			// State 2B: using global default
			$global_text   = sprintf(
				/* translators: 1: formatted price, 2: percentage, 3: formatted regular price */
				__( 'Using global default: %1$s (%2$s%% of regular price %3$s)', 'wc-pay-what-you-want' ),
				wp_kses_post( $formatted_amount ),
				esc_html( (string) $global_pct ),
				wp_kses_post( wc_price( $regular_price ) )
			);
			$override_text = __( 'Override active: (enter a value above to set a fixed price)', 'wc-pay-what-you-want' );
			printf(
				'<p class="description wcpwyw-field-hint wcpwyw-using-global" data-global-text="%s" data-override-text="%s">%s</p>',
				esc_attr( wp_strip_all_tags( $global_text ) ),
				esc_attr( wp_strip_all_tags( $override_text ) ),
				wp_kses_post( $global_text )
			);
		}
	}

	public static function saveProductMeta( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC product save nonce verified upstream
		$enabled    = isset( $_POST['_wcpwyw_enabled'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$allow_zero = isset( $_POST['_wcpwyw_allow_zero'] ) ? 'yes' : 'no';

		update_post_meta( $post_id, '_wcpwyw_enabled', $enabled );
		update_post_meta( $post_id, '_wcpwyw_allow_zero', $allow_zero );

		// Price overrides: sanitize via wc_format_decimal(); save empty string if blank
		foreach ( [ '_wcpwyw_min_price', '_wcpwyw_max_price', '_wcpwyw_suggested_price' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST[ $key ] ) ? trim( (string) $_POST[ $key ] ) : '';
			if ( '' === $raw ) {
				update_post_meta( $post_id, $key, '' );
			} else {
				$value = wc_format_decimal( $raw );
				update_post_meta( $post_id, $key, $value );
			}
		}

		// TC-502: cross-field validation — reject if min >= max when both are set
		$min = get_post_meta( $post_id, '_wcpwyw_min_price', true );
		$max = get_post_meta( $post_id, '_wcpwyw_max_price', true );
		if ( '' !== $min && '' !== $max && (float) $min >= (float) $max ) {
			// Revert both to empty and add admin notice
			update_post_meta( $post_id, '_wcpwyw_min_price', '' );
			update_post_meta( $post_id, '_wcpwyw_max_price', '' );
			add_filter(
				'redirect_post_location',
				static function ( string $location ): string {
					return add_query_arg( 'wcpwyw_error', 'min_max', $location );
				}
			);
		}

		// Preset buttons: sanitize text; empty string is valid (hides presets)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$presets = isset( $_POST['_wcpwyw_preset_buttons'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_preset_buttons'] ) ) : '';
		update_post_meta( $post_id, '_wcpwyw_preset_buttons', $presets );

		// Display style: must be one of the allowed values or empty
		$allowed_styles = [ '', 'A', 'B', 'C', 'D' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$display_style  = isset( $_POST['_wcpwyw_display_style'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_display_style'] ) ) : '';
		if ( ! in_array( $display_style, $allowed_styles, true ) ) {
			$display_style = '';
		}
		update_post_meta( $post_id, '_wcpwyw_display_style', $display_style );

		// Coupon mode: must be one of the allowed values or empty
		$allowed_modes = [ '', 'allow', 'allow_with_floor', 'block' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$coupon_mode   = isset( $_POST['_wcpwyw_coupon_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_coupon_mode'] ) ) : '';
		if ( ! in_array( $coupon_mode, $allowed_modes, true ) ) {
			$coupon_mode = '';
		}
		update_post_meta( $post_id, '_wcpwyw_coupon_mode', $coupon_mode );

		// Archive display style
		$allowed_arch = [ '', 'range', 'suggested', 'from_min', 'label' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$arch_style = isset( $_POST['_wcpwyw_archive_display_style'] )
			? sanitize_key( wp_unslash( $_POST['_wcpwyw_archive_display_style'] ) )
			: '';
		if ( ! in_array( $arch_style, $allowed_arch, true ) ) {
			$arch_style = '';
		}
		update_post_meta( $post_id, '_wcpwyw_archive_display_style', $arch_style );

		// Quick-add default
		$allowed_qa = [ '', 'suggested', 'minimum', 'blocked' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quick_add = isset( $_POST['_wcpwyw_quick_add_default'] )
			? sanitize_key( wp_unslash( $_POST['_wcpwyw_quick_add_default'] ) )
			: '';
		if ( ! in_array( $quick_add, $allowed_qa, true ) ) {
			$quick_add = '';
		}
		update_post_meta( $post_id, '_wcpwyw_quick_add_default', $quick_add );

		// Price input label
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$label_input = isset( $_POST['_wcpwyw_label_input'] )
			? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_label_input'] ) )
			: '';
		update_post_meta( $post_id, '_wcpwyw_label_input', $label_input );
	}

	public static function maybeShowValidationError(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcpwyw_error'] ) || 'min_max' !== $_GET['wcpwyw_error'] ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'PWYW Maximum Price must be greater than Minimum Price.', 'wc-pay-what-you-want' ) .
			'</p></div>';
	}
}
