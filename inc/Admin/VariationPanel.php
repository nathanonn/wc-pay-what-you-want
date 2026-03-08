<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VariationPanel {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_product_after_variable_attributes', [ self::class, 'renderVariationFields' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ self::class, 'saveVariationMeta' ], 10, 2 );
	}

	public static function renderVariationFields( int $loop, array $variation_data, \WP_Post $variation ): void {
		$variation_id = $variation->ID;

		// Read saved meta values for this variation
		$mode     = get_post_meta( $variation_id, '_wcpwyw_variation_mode', true );
		$mode     = in_array( $mode, [ 'inherit', 'enable', 'disable' ], true ) ? $mode : 'inherit';
		$allow_zero     = get_post_meta( $variation_id, '_wcpwyw_allow_zero', true );
		$min_price      = get_post_meta( $variation_id, '_wcpwyw_min_price', true );
		$max_price      = get_post_meta( $variation_id, '_wcpwyw_max_price', true );
		$suggested      = get_post_meta( $variation_id, '_wcpwyw_suggested_price', true );
		$preset_buttons = get_post_meta( $variation_id, '_wcpwyw_preset_buttons', true );

		echo '<div class="wcpwyw-variation-panel form-row form-row-full wcpwyw_admin-layout">';
		echo '<p class="wcpwyw-variation-heading">' . esc_html__( 'Pay What You Want', 'wc-pay-what-you-want' ) . '</p>';

		// Radio group: three modes
		$modes = [
			'inherit' => __( 'Inherit from parent product', 'wc-pay-what-you-want' ),
			'enable'  => __( 'Enable PWYW for this variation', 'wc-pay-what-you-want' ),
			'disable' => __( 'Disable PWYW for this variation', 'wc-pay-what-you-want' ),
		];

		echo '<fieldset class="wcpwyw-variation-mode-fieldset">';
		echo '<legend>' . esc_html__( 'PWYW for this variation:', 'wc-pay-what-you-want' ) . '</legend>';

		foreach ( $modes as $value => $label ) {
			printf(
				'<label class="wcpwyw-radio-label"><input type="radio" name="_wcpwyw_variation_mode[%d]" value="%s" %s /> %s</label>',
				$loop,
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';

		// Override fields — shown only when mode is 'enable'
		$fields_style = ( 'enable' === $mode ) ? '' : 'display:none;';
		echo '<div class="wcpwyw-variation-override-fields" style="' . esc_attr( $fields_style ) . '">';

		// Allow $0 checkbox for this variation
		echo '<p class="form-field">';
		printf(
			'<label><input type="checkbox" name="_wcpwyw_variation_allow_zero[%d]" value="yes" %s /> %s</label>',
			$loop,
			checked( $allow_zero, 'yes', false ),
			esc_html__( 'Allow $0 for this variation', 'wc-pay-what-you-want' )
		);
		echo '</p>';

		// Pricing override fields with $loop in the name attribute
		$price_fields = [
			'_wcpwyw_min_price'       => [ __( 'Minimum Price ($)', 'wc-pay-what-you-want' ), $min_price ],
			'_wcpwyw_max_price'       => [ __( 'Maximum Price ($)', 'wc-pay-what-you-want' ), $max_price ],
			'_wcpwyw_suggested_price' => [ __( 'Suggested Price ($)', 'wc-pay-what-you-want' ), $suggested ],
		];

		foreach ( $price_fields as $key => $field_data ) {
			[ $label, $current ] = $field_data;
			woocommerce_wp_text_input( [
				'id'                => "{$key}[{$loop}]",
				'name'              => "{$key}[{$loop}]",
				'label'             => $label,
				'value'             => $current,
				'type'              => 'number',
				'custom_attributes' => [ 'step' => 'any', 'min' => '0' ],
				'wrapper_class'     => 'form-row form-row-full',
			] );
		}

		// Preset buttons for this variation
		woocommerce_wp_text_input( [
			'id'            => "_wcpwyw_preset_buttons[{$loop}]",
			'name'          => "_wcpwyw_preset_buttons[{$loop}]",
			'label'         => __( 'Preset Amounts ($)', 'wc-pay-what-you-want' ),
			'value'         => $preset_buttons,
			'placeholder'   => __( 'Leave blank to use parent presets', 'wc-pay-what-you-want' ),
			'wrapper_class' => 'form-row form-row-full',
		] );

		// Quick-Add Default
		woocommerce_wp_select( [
			'id'            => "_wcpwyw_quick_add_default[{$loop}]",
			'name'          => "_wcpwyw_quick_add_default[{$loop}]",
			'label'         => __( 'Quick-Add Default', 'wc-pay-what-you-want' ),
			'value'         => get_post_meta( $variation_id, '_wcpwyw_quick_add_default', true ),
			'options'       => [
				''          => __( '— Use global default', 'wc-pay-what-you-want' ),
				'suggested' => __( 'Pre-fill with suggested price', 'wc-pay-what-you-want' ),
				'minimum'   => __( 'Pre-fill with minimum price', 'wc-pay-what-you-want' ),
				'blocked'   => __( 'Block quick-add (require product page)', 'wc-pay-what-you-want' ),
			],
			'wrapper_class' => 'form-row form-row-full',
		] );

		// Price Input Label
		woocommerce_wp_text_input( [
			'id'            => "_wcpwyw_label_input[{$loop}]",
			'name'          => "_wcpwyw_label_input[{$loop}]",
			'label'         => __( 'Price Input Label', 'wc-pay-what-you-want' ),
			'value'         => get_post_meta( $variation_id, '_wcpwyw_label_input', true ),
			'placeholder'   => __( 'Leave blank to use global/product default', 'wc-pay-what-you-want' ),
			'wrapper_class' => 'form-row form-row-full',
		] );

		echo '</div>'; // .wcpwyw-variation-override-fields

		// Info message for inherit mode (State 3A) and disable mode (State 3C)
		echo '<p class="wcpwyw-inherit-info" style="' . ( 'inherit' === $mode ? '' : 'display:none;' ) . '">' .
			esc_html__( 'Inheriting parent product settings. Prices and behaviour are resolved at runtime from the parent product and global settings.', 'wc-pay-what-you-want' ) .
			'</p>';

		echo '<p class="wcpwyw-disable-info" style="' . ( 'disable' === $mode ? '' : 'display:none;' ) . '">' .
			esc_html__( 'PWYW is disabled for this variation. It will display with its normal WooCommerce price instead of a PWYW input.', 'wc-pay-what-you-want' ) .
			'</p>';

		echo '</div>'; // .wcpwyw-variation-panel
	}

	public static function saveVariationMeta( int $variation_id, int $loop ): void {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		// Radio: one of 'inherit', 'enable', 'disable'
		$allowed_modes = [ 'inherit', 'enable', 'disable' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC variation save nonce verified upstream
		$mode_raw      = isset( $_POST['_wcpwyw_variation_mode'][ $loop ] )
			? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_variation_mode'][ $loop ] ) )
			: 'inherit';
		$mode = in_array( $mode_raw, $allowed_modes, true ) ? $mode_raw : 'inherit';
		update_post_meta( $variation_id, '_wcpwyw_variation_mode', $mode );

		if ( 'enable' === $mode ) {
			// Allow $0
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$allow_zero = isset( $_POST['_wcpwyw_variation_allow_zero'][ $loop ] ) ? 'yes' : 'no';
			update_post_meta( $variation_id, '_wcpwyw_allow_zero', $allow_zero );

			// Price overrides
			foreach ( [ '_wcpwyw_min_price', '_wcpwyw_max_price', '_wcpwyw_suggested_price' ] as $key ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw = isset( $_POST[ $key ][ $loop ] ) ? trim( (string) $_POST[ $key ][ $loop ] ) : '';
				if ( '' === $raw ) {
					update_post_meta( $variation_id, $key, '' );
				} else {
					update_post_meta( $variation_id, $key, wc_format_decimal( $raw ) );
				}
			}

			// Preset buttons
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$presets = isset( $_POST['_wcpwyw_preset_buttons'][ $loop ] )
				? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_preset_buttons'][ $loop ] ) )
				: '';
			update_post_meta( $variation_id, '_wcpwyw_preset_buttons', $presets );

			// Quick-add default
			$allowed_qa = [ '', 'suggested', 'minimum', 'blocked' ];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$quick_add = isset( $_POST['_wcpwyw_quick_add_default'][ $loop ] )
				? sanitize_key( wp_unslash( $_POST['_wcpwyw_quick_add_default'][ $loop ] ) )
				: '';
			if ( ! in_array( $quick_add, $allowed_qa, true ) ) {
				$quick_add = '';
			}
			update_post_meta( $variation_id, '_wcpwyw_quick_add_default', $quick_add );

			// Price input label
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$label_input = isset( $_POST['_wcpwyw_label_input'][ $loop ] )
				? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_label_input'][ $loop ] ) )
				: '';
			update_post_meta( $variation_id, '_wcpwyw_label_input', $label_input );
		} else {
			// 'inherit' or 'disable': clear pricing overrides
			update_post_meta( $variation_id, '_wcpwyw_allow_zero', 'no' );
			foreach ( [
				'_wcpwyw_min_price', '_wcpwyw_max_price', '_wcpwyw_suggested_price',
				'_wcpwyw_preset_buttons',
				'_wcpwyw_quick_add_default',
				'_wcpwyw_label_input',
			] as $key ) {
				update_post_meta( $variation_id, $key, '' );
			}
		}
	}
}
