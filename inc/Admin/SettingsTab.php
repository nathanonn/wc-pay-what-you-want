<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsTab {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'woocommerce_settings_tabs_array', [ self::class, 'addTab' ], 50 );
		add_action( 'woocommerce_settings_tabs_wcpwyw', [ self::class, 'renderTab' ] );
		add_action( 'woocommerce_update_options_wcpwyw', [ self::class, 'saveTab' ] );

		// Custom field type renderers.
		add_action( 'woocommerce_admin_field_wcpwyw_security_info', [ self::class, 'renderSecurityInfoPanel' ] );
		add_action( 'woocommerce_admin_field_wcpwyw_i18n_status', [ self::class, 'renderI18nStatusPanel' ] );
		add_action( 'woocommerce_admin_field_wcpwyw_currency_preview', [ self::class, 'renderCurrencyPreviewPanel' ] );
	}

	public static function addTab( array $tabs ): array {
		$tabs['wcpwyw'] = __( 'Pay What You Want', 'wc-pay-what-you-want' );
		return $tabs;
	}

	public static function renderTab(): void {
		$wc_settings_raw = get_option( 'woocommerce_wcpwyw_settings', [] );
		$wc_settings     = is_array( $wc_settings_raw ) ? $wc_settings_raw : [];
		$enabled_wc      = $wc_settings['wcpwyw_enabled'] ?? 'no';
		$enabled_sa      = get_option( 'wcpwyw_enabled', 'no' );
		$enabled         = ( 'yes' === $enabled_wc || 'yes' === $enabled_sa ) ? 'yes' : 'no';

		// Section 1: General fields
		$general_fields = array_slice( self::getFields(), 0, 3 );
		woocommerce_admin_fields( $general_fields );

		// State 1A: informational banner when master toggle is off (TC-106)
		if ( 'yes' !== $enabled ) {
			echo '<div class="notice notice-info inline wcpwyw-disabled-banner"><p>' .
				esc_html__( 'PWYW is currently disabled. Settings are saved but no PWYW functionality will appear on the frontend until you enable it above.', 'wc-pay-what-you-want' ) .
				'</p></div>';
		} else {
			echo '<div class="notice notice-info inline wcpwyw-disabled-banner" style="display:none;"><p>' .
				esc_html__( 'PWYW is currently disabled. Settings are saved but no PWYW functionality will appear on the frontend until you enable it above.', 'wc-pay-what-you-want' ) .
				'</p></div>';
		}

		// Remaining sections
		$remaining_fields = array_slice( self::getFields(), 3 );
		woocommerce_admin_fields( $remaining_fields );
	}

	public static function saveTab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC Settings API handles nonce
		if ( ! self::validate( $_POST ) ) {
			return;
		}

		woocommerce_update_options( self::getFields() );

		// Save standalone wcpwyw_enabled key (TC-103, TC-106)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC Settings API handles nonce
		$enabled = isset( $_POST['wcpwyw_enabled'] ) ? 'yes' : 'no';
		update_option( 'wcpwyw_enabled', $enabled );

		// Save email notification settings as standalone options so AlertMailer can read them
		// via get_option() without depending on woocommerce_wcpwyw_settings serialized array (TC-511).
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC Settings API handles nonce
		$below_enabled = isset( $_POST['wcpwyw_email_below_enabled'] ) ? 'yes' : 'no';
		update_option( 'wcpwyw_email_below_enabled', $below_enabled );

		$threshold_below = isset( $_POST['wcpwyw_email_threshold_below'] ) ? absint( $_POST['wcpwyw_email_threshold_below'] ) : 30;
		update_option( 'wcpwyw_email_threshold_below', $threshold_below );

		$above_enabled = isset( $_POST['wcpwyw_email_above_enabled'] ) ? 'yes' : 'no';
		update_option( 'wcpwyw_email_above_enabled', $above_enabled );

		$threshold_above = isset( $_POST['wcpwyw_email_threshold_above'] ) ? absint( $_POST['wcpwyw_email_threshold_above'] ) : 50;
		update_option( 'wcpwyw_email_threshold_above', $threshold_above );

		$recipient = isset( $_POST['wcpwyw_email_alert_recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpwyw_email_alert_recipient'] ) ) : '';
		update_option( 'wcpwyw_email_alert_recipient', $recipient );

		// Security options (TC-612 / P6).
		$cap_enabled = isset( $_POST['wcpwyw_price_cap_enabled'] ) ? 'yes' : 'no';
		update_option( 'wcpwyw_price_cap_enabled', $cap_enabled );

		$cap_value = isset( $_POST['wcpwyw_price_cap_value'] )
			? (float) wc_format_decimal( wp_unslash( $_POST['wcpwyw_price_cap_value'] ) )
			: 9999.00;
		update_option( 'wcpwyw_price_cap_value', $cap_value );

		$validation_logging = isset( $_POST['wcpwyw_validation_logging'] ) ? 'yes' : 'no';
		update_option( 'wcpwyw_validation_logging', $validation_logging );

		// Dual-write preset buttons as standalone option (BUG-003 fix).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC Settings API handles nonce
		$presets_raw = isset( $_POST['wcpwyw_global_preset_buttons'] )
			? sanitize_text_field( wp_unslash( $_POST['wcpwyw_global_preset_buttons'] ) )
			: '';
		update_option( 'wcpwyw_global_preset_buttons', $presets_raw );

		// Also sync values into the woocommerce_wcpwyw_settings aggregate array
		// so that renderTab() and resolveConfig() read the correct values from the WC array.
		// woocommerce_update_options() writes individual options but does not reliably update
		// the aggregate array for checkboxes (unchecked checkboxes are not in $_POST); we patch
		// it explicitly here (TC-005, TC-010, TC-106).
		$wc_settings = get_option( 'woocommerce_wcpwyw_settings', [] );
		if ( ! is_array( $wc_settings ) ) {
			$wc_settings = [];
		}
		$wc_settings['wcpwyw_enabled']              = $enabled;
		$wc_settings['wcpwyw_global_preset_buttons'] = $presets_raw;
		update_option( 'woocommerce_wcpwyw_settings', $wc_settings );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function validate( array $data ): bool {
		$valid = true;

		// TC-104: max pct must be strictly greater than min pct
		$min_pct = isset( $data['wcpwyw_default_min_pct'] ) ? absint( $data['wcpwyw_default_min_pct'] ) : 0;
		$max_pct = isset( $data['wcpwyw_default_max_pct'] ) ? absint( $data['wcpwyw_default_max_pct'] ) : 0;
		if ( $max_pct <= $min_pct ) {
			\WC_Admin_Settings::add_error(
				__( 'Maximum percentage must be greater than minimum percentage.', 'wc-pay-what-you-want' )
			);
			$valid = false;
		}

		// TC-105: preset buttons must be empty or comma-separated positive numbers
		$presets_raw = isset( $data['wcpwyw_global_preset_buttons'] ) ? trim( $data['wcpwyw_global_preset_buttons'] ) : '';
		if ( '' !== $presets_raw ) {
			$parts = array_map( 'trim', explode( ',', $presets_raw ) );
			foreach ( $parts as $part ) {
				if ( ! is_numeric( $part ) || (float) $part <= 0 ) {
					\WC_Admin_Settings::add_error(
						__( 'Global Preset Amounts must be comma-separated positive numbers (e.g. 10,15,20,25) or left blank.', 'wc-pay-what-you-want' )
					);
					$valid = false;
					break;
				}
			}
		}

		// TC-511: Validate below-threshold percentage when below-threshold alert is enabled.
		if ( isset( $data['wcpwyw_email_below_enabled'] ) ) {
			$below_pct = isset( $data['wcpwyw_email_threshold_below'] ) ? (int) $data['wcpwyw_email_threshold_below'] : 0;
			if ( $below_pct < 1 || $below_pct > 999 ) {
				\WC_Admin_Settings::add_error(
					__( 'Below-threshold percentage must be a whole number between 1 and 999.', 'wc-pay-what-you-want' )
				);
				$valid = false;
			}
		}

		// TC-511: Validate above-threshold percentage when above-threshold alert is enabled.
		if ( isset( $data['wcpwyw_email_above_enabled'] ) ) {
			$above_pct = isset( $data['wcpwyw_email_threshold_above'] ) ? (int) $data['wcpwyw_email_threshold_above'] : 0;
			if ( $above_pct < 1 || $above_pct > 999 ) {
				\WC_Admin_Settings::add_error(
					__( 'Above-threshold percentage must be a whole number between 1 and 999.', 'wc-pay-what-you-want' )
				);
				$valid = false;
			}
		}

		// TC-511: Validate recipient email(s). Each comma-separated address must be a valid email.
		$recipient_raw = trim( $data['wcpwyw_email_alert_recipient'] ?? '' );
		if ( '' !== $recipient_raw ) {
			$addresses = array_map( 'trim', explode( ',', $recipient_raw ) );
			foreach ( $addresses as $address ) {
				if ( ! is_email( $address ) ) {
					\WC_Admin_Settings::add_error(
						sprintf(
							/* translators: %s: invalid email address */
							__( 'Recipient email address "%s" is not valid.', 'wc-pay-what-you-want' ),
							esc_html( $address )
						)
					);
					$valid = false;
					break;
				}
			}
		}

		// TC-612 / P6 Security: validate price cap value when cap is enabled.
		if ( isset( $data['wcpwyw_price_cap_enabled'] ) ) {
			$cap_val = isset( $data['wcpwyw_price_cap_value'] ) ? (float) $data['wcpwyw_price_cap_value'] : 0.0;
			if ( $cap_val <= 0.0 ) {
				\WC_Admin_Settings::add_error(
					__( 'Maximum price cap must be a positive number.', 'wc-pay-what-you-want' )
				);
				$valid = false;
			}
		}

		return $valid;
	}

	public static function getFields(): array {
		return [
			// Section 1: General
			[ 'title' => __( 'General', 'wc-pay-what-you-want' ), 'type' => 'title', 'id' => 'wcpwyw_general_title' ],
			[
				'title'   => __( 'Enable Pay What You Want', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_enabled',
				'default' => 'no',
				'desc'    => __( 'Enable the PWYW pricing feature globally', 'wc-pay-what-you-want' ),
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_general_end' ],

			// Section 2: Pricing Defaults
			[
				'title' => __( 'Pricing Defaults', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'desc'  => __( 'Applies to all products unless overridden with a fixed $ amount on the product itself.', 'wc-pay-what-you-want' ),
				'id'    => 'wcpwyw_pricing_title',
			],
			[
				'title'             => __( 'Default Minimum Price', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_default_min_pct',
				'default'           => '50',
				'suffix'            => __( '% of regular price', 'wc-pay-what-you-want' ),
				'custom_attributes' => [ 'min' => '0', 'max' => '999', 'step' => '1' ],
			],
			[
				'title'             => __( 'Default Maximum Price', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_default_max_pct',
				'default'           => '200',
				'suffix'            => __( '% of regular price', 'wc-pay-what-you-want' ),
				'custom_attributes' => [ 'min' => '1', 'max' => '9999', 'step' => '1' ],
			],
			[
				'title'             => __( 'Default Suggested Price', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_default_suggested_pct',
				'default'           => '100',
				'suffix'            => __( '% of regular price', 'wc-pay-what-you-want' ),
				'custom_attributes' => [ 'min' => '0', 'max' => '9999', 'step' => '1' ],
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_pricing_end' ],

			// Section 3: Preset Quick-Select Buttons
			[
				'title' => __( 'Preset Quick-Select Buttons', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'desc'  => __( 'Comma-separated fixed amounts. Shown as buttons on the product page. Leave blank to hide preset buttons entirely.', 'wc-pay-what-you-want' ),
				'id'    => 'wcpwyw_presets_title',
			],
			[
				'title'       => __( 'Global Preset Amounts ($)', 'wc-pay-what-you-want' ),
				'type'        => 'text',
				'id'          => 'wcpwyw_global_preset_buttons',
				'default'     => '10,15,20,25',
				'placeholder' => 'e.g. 10,15,20,25',
				'class'       => 'wcpwyw-preset-input',
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_presets_end' ],

			// Section 4: Display Settings
			[ 'title' => __( 'Display Settings', 'wc-pay-what-you-want' ), 'type' => 'title', 'id' => 'wcpwyw_display_title' ],
			[
				'title'   => __( 'Price Display Style', 'wc-pay-what-you-want' ),
				'type'    => 'select',
				'id'      => 'wcpwyw_price_display_style',
				'default' => 'A',
				'options' => [
					'A' => __( 'Style A — Input + Labels (min/suggested/max)', 'wc-pay-what-you-want' ),
					'B' => __( 'Style B — Input + Preset Buttons', 'wc-pay-what-you-want' ),
					'C' => __( 'Style C — Input + Presets + Labels', 'wc-pay-what-you-want' ),
					'D' => __( 'Style D — Minimal (input only)', 'wc-pay-what-you-want' ),
				],
			],
			[
				'title'   => __( 'Archive Display Style', 'wc-pay-what-you-want' ),
				'type'    => 'select',
				'id'      => 'wcpwyw_archive_display_style',
				'default' => 'range',
				'options' => [
					'range'    => __( 'Show price range (min – max)', 'wc-pay-what-you-want' ),
					'suggested' => __( 'Show suggested price only', 'wc-pay-what-you-want' ),
					'from_min' => __( 'Show "From $X" (minimum price)', 'wc-pay-what-you-want' ),
					'label'    => __( 'Show "Name Your Price"', 'wc-pay-what-you-want' ),
				],
			],
			[
				'title'   => __( 'Quick-Add to Cart Default', 'wc-pay-what-you-want' ),
				'type'    => 'select',
				'id'      => 'wcpwyw_quick_add_default',
				'default' => 'suggested',
				'options' => [
					'suggested' => __( 'Pre-fill with suggested price', 'wc-pay-what-you-want' ),
					'minimum'   => __( 'Pre-fill with minimum price', 'wc-pay-what-you-want' ),
					'blocked'   => __( 'Block quick-add (require product page)', 'wc-pay-what-you-want' ),
				],
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_display_end' ],

			// Section 5: Cart & Coupon Settings
			[ 'title' => __( 'Cart & Coupon Settings', 'wc-pay-what-you-want' ), 'type' => 'title', 'id' => 'wcpwyw_cart_title' ],
			[
				'title'   => __( 'Mixed Cart Restriction', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_mixed_cart_restriction',
				'default' => 'no',
				'desc'    => __( 'Prevent PWYW and fixed-price items in the same cart', 'wc-pay-what-you-want' ),
			],
			[
				'title'   => __( 'Coupon Behaviour', 'wc-pay-what-you-want' ),
				'type'    => 'select',
				'id'      => 'wcpwyw_coupon_mode',
				'default' => 'allow',
				'options' => [
					'allow'            => __( 'Allow coupons (no floor)', 'wc-pay-what-you-want' ),
					'allow_with_floor' => __( 'Allow coupons but floor at minimum price', 'wc-pay-what-you-want' ),
					'block'            => __( 'Block all coupons on PWYW items', 'wc-pay-what-you-want' ),
				],
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_cart_end' ],

			// Section 6: Labels & Messages
			[
				'title' => __( 'Labels & Messages', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'desc'  => __( 'Customise customer-facing text. Use {amount} as a placeholder where a formatted price should appear.', 'wc-pay-what-you-want' ),
				'id'    => 'wcpwyw_labels_title',
			],
			[
				'title'   => __( 'Price Input Label', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_label_input',
				'default' => 'Name Your Price',
			],
			[
				'title'   => __( 'Minimum Label', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_label_minimum',
				'default' => 'Minimum: {amount}',
			],
			[
				'title'   => __( 'Maximum Label', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_label_maximum',
				'default' => 'Maximum: {amount}',
			],
			[
				'title'   => __( 'Suggested Label', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_label_suggested',
				'default' => 'Suggested: {amount}',
			],
			[
				'title'   => __( 'Below Minimum Error', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_error_below_min',
				'default' => 'Please enter at least {amount}.',
			],
			[
				'title'   => __( 'Above Maximum Error', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_error_above_max',
				'default' => 'Please enter no more than {amount}.',
			],
			[
				'title'   => __( 'Invalid Input Error', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_error_invalid',
				'default' => 'Please enter a valid price.',
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_labels_end' ],

			// Section 7: Email Notifications
			[ 'title' => __( 'Email Notifications', 'wc-pay-what-you-want' ), 'type' => 'title', 'id' => 'wcpwyw_email_title' ],

			// Below-threshold sub-section
			[
				'title' => __( 'Below-Threshold Alert', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'id'    => 'wcpwyw_email_below_sub_title',
				'desc'  => __( 'Send an alert when a customer pays significantly below the suggested price.', 'wc-pay-what-you-want' ),
			],
			[
				'title'   => __( 'Enable below-threshold alert', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_email_below_enabled',
				'default' => 'yes',
				'desc'    => __( 'Send an alert when a customer pays significantly below the suggested price', 'wc-pay-what-you-want' ),
				'class'   => 'wcpwyw-email-below-toggle',
			],
			[
				'title'             => __( 'Trigger threshold', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_email_threshold_below',
				'default'           => '30',
				'suffix'            => __( '% below suggested price', 'wc-pay-what-you-want' ),
				'desc'              => __( 'Alert fires when: (suggested − customer) / suggested × 100 > this threshold', 'wc-pay-what-you-want' ),
				'class'             => 'wcpwyw-email-below-threshold',
				'custom_attributes' => [ 'min' => '1', 'max' => '999', 'step' => '1' ],
			],

			// Above-threshold sub-section
			[
				'title' => __( 'Above-Threshold Alert', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'id'    => 'wcpwyw_email_above_sub_title',
				'desc'  => __( 'Send an alert when a customer pays significantly above the suggested price.', 'wc-pay-what-you-want' ),
			],
			[
				'title'   => __( 'Enable above-threshold alert', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_email_above_enabled',
				'default' => 'yes',
				'desc'    => __( 'Send an alert when a customer pays significantly above the suggested price', 'wc-pay-what-you-want' ),
				'class'   => 'wcpwyw-email-above-toggle',
			],
			[
				'title'             => __( 'Trigger threshold', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_email_threshold_above',
				'default'           => '50',
				'suffix'            => __( '% above suggested price', 'wc-pay-what-you-want' ),
				'desc'              => __( 'Alert fires when: (customer − suggested) / suggested × 100 > this threshold', 'wc-pay-what-you-want' ),
				'class'             => 'wcpwyw-email-above-threshold',
				'custom_attributes' => [ 'min' => '1', 'max' => '999', 'step' => '1' ],
			],

			// Shared recipient
			[
				'title'   => __( 'Notification recipient', 'wc-pay-what-you-want' ),
				'type'    => 'text',
				'id'      => 'wcpwyw_email_alert_recipient',
				'default' => '',
				'desc'    => __( 'Email address(es) for alerts. Leave blank to use the site admin email. Separate multiple addresses with commas.', 'wc-pay-what-you-want' ),
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_email_end' ],

			// Section 8: Security
			[
				'title' => __( 'Security', 'wc-pay-what-you-want' ),
				'type'  => 'title',
				'id'    => 'wcpwyw_security_title',
				'desc'  => __( 'Fraud prevention and server-side validation controls.', 'wc-pay-what-you-want' ),
			],
			[
				'title'   => __( 'Enable maximum price cap', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_price_cap_enabled',
				'default' => 'yes',
				'desc'    => __( 'Apply an absolute maximum price cap in addition to per-product maximums', 'wc-pay-what-you-want' ),
				'class'   => 'wcpwyw-cap-toggle',
			],
			[
				'title'             => __( 'Absolute maximum price cap', 'wc-pay-what-you-want' ),
				'type'              => 'number',
				'id'                => 'wcpwyw_price_cap_value',
				'default'           => '9999.00',
				'desc'              => __( 'Any customer price above this amount is rejected server-side. Applies in addition to per-product maximums.', 'wc-pay-what-you-want' ),
				'class'             => 'wcpwyw-cap-value',
				'custom_attributes' => [ 'min' => '0.01', 'step' => '0.01' ],
			],
			[
				'title'   => __( 'Log server-side validation failures', 'wc-pay-what-you-want' ),
				'type'    => 'checkbox',
				'id'      => 'wcpwyw_validation_logging',
				'default' => 'no',
				'desc'    => __( 'Write rejected price attempts to wp-content/debug.log. Disable after debugging.', 'wc-pay-what-you-want' ),
			],
			// Custom HTML field: always-active security measures panel (read-only).
			[
				'type' => 'wcpwyw_security_info',
				'id'   => 'wcpwyw_security_info',
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_security_end' ],

			// Section 9: Translation & Internationalisation (read-only)
			[
				'type' => 'wcpwyw_i18n_status',
				'id'   => 'wcpwyw_i18n_status',
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_i18n_end' ],

			// Section 10: Currency Localisation (read-only)
			[
				'type' => 'wcpwyw_currency_preview',
				'id'   => 'wcpwyw_currency_preview',
			],
			[ 'type' => 'sectionend', 'id' => 'wcpwyw_currency_end' ],
		];
	}

	/**
	 * Render the "Always-active security measures" read-only panel.
	 */
	public static function renderSecurityInfoPanel(): void {
		$measures = [
			__( 'Server-side price validation on every add-to-cart and cart update', 'wc-pay-what-you-want' ),
			__( 'Nonce verification on all AJAX requests and form submissions', 'wc-pay-what-you-want' ),
			__( 'Input sanitisation — HTML stripped, non-numeric values rejected', 'wc-pay-what-you-want' ),
			__( 'XSS prevention — all output escaped with esc_html() / wp_kses()', 'wc-pay-what-you-want' ),
			__( 'SQL injection protection — all queries use $wpdb->prepare()', 'wc-pay-what-you-want' ),
			__( 'Capability checks — settings require manage_woocommerce capability', 'wc-pay-what-you-want' ),
		];
		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo esc_html__( 'Always-active protections', 'wc-pay-what-you-want' );
		echo '</th><td class="forminp">';
		echo '<ul style="margin:0;padding:0;list-style:none;">';
		foreach ( $measures as $measure ) {
			echo '<li style="margin:0 0 0.4em;"><span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;margin-right:6px;"></span>';
			echo esc_html( $measure );
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'These protections are always active and cannot be disabled.', 'wc-pay-what-you-want' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Render the Translation & Internationalisation status panel (read-only).
	 */
	public static function renderI18nStatusPanel(): void {
		$pot_path     = WCPWYW_DIR . 'languages/wc-pay-what-you-want.pot';
		$pot_exists   = file_exists( $pot_path );
		$string_count = 0;
		if ( $pot_exists ) {
			$pot_contents = file_get_contents( $pot_path ); // phpcs:ignore WordPress.WP.AlwaysUsesSanitizeFileName
			$string_count = max( 0, substr_count( $pot_contents, 'msgid "' ) - 1 ); // subtract header msgid
		}
		$locale    = get_locale();
		$is_rtl    = is_rtl();
		$pot_url   = WCPWYW_URL . 'languages/wc-pay-what-you-want.pot';

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label>' . esc_html__( 'Translation & Internationalisation', 'wc-pay-what-you-want' ) . '</label>';
		echo '</th><td class="forminp">';
		echo '<table class="widefat striped" style="max-width:500px;">';
		echo '<tbody>';

		$rows = [
			__( 'Text domain', 'wc-pay-what-you-want' )     => '<code>wc-pay-what-you-want</code>',
			__( 'POT file status', 'wc-pay-what-you-want' ) => $pot_exists
				? '<span style="color:#46b450;">&#10003; ' . esc_html__( 'Present', 'wc-pay-what-you-want' ) . '</span>'
				: '<span style="color:#c00;">&#10007; ' . esc_html__( 'Missing', 'wc-pay-what-you-want' ) . '</span>',
			__( 'String count', 'wc-pay-what-you-want' )    => $pot_exists ? esc_html( $string_count ) : '—',
			__( 'Active locale', 'wc-pay-what-you-want' )   => '<code>' . esc_html( $locale ) . '</code>',
			__( 'RTL active', 'wc-pay-what-you-want' )      => $is_rtl
				? esc_html__( 'Yes', 'wc-pay-what-you-want' )
				: esc_html__( 'No', 'wc-pay-what-you-want' ),
		];

		foreach ( $rows as $label => $value ) {
			echo '<tr><td style="width:180px;font-weight:600;">' . esc_html( $label ) . '</td>';
			echo '<td>' . wp_kses_post( $value ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</tbody></table>';

		if ( $pot_exists ) {
			echo '<p style="margin-top:0.75em;">';
			echo '<a href="' . esc_url( $pot_url ) . '" download class="button button-secondary">';
			echo esc_html__( 'Download .pot file', 'wc-pay-what-you-want' );
			echo '</a></p>';
		}

		// LTR / RTL side-by-side preview.
		echo '<div style="display:flex;gap:1em;margin-top:1em;max-width:520px;">';

		echo '<div style="flex:1;border:1px solid #ddd;padding:12px;border-radius:4px;">';
		echo '<p style="font-size:0.8em;font-weight:600;margin:0 0 8px;color:#666;">' . esc_html__( 'LTR preview', 'wc-pay-what-you-want' ) . '</p>';
		echo '<div dir="ltr" style="border:1px solid #ccc;padding:8px;border-radius:3px;background:#f9f9f9;">';
		echo '<p style="margin:0 0 4px;font-size:0.9em;">' . esc_html__( 'Name Your Price', 'wc-pay-what-you-want' ) . '</p>';
		echo '<div style="display:flex;gap:4px;margin:4px 0;"><button type="button" style="padding:2px 8px;font-size:0.85em;">$10</button><button type="button" style="padding:2px 8px;font-size:0.85em;">$25</button></div>';
		echo '<input type="number" value="25" style="width:80px;text-align:left;" readonly />';
		echo '</div></div>';

		echo '<div style="flex:1;border:1px solid #ddd;padding:12px;border-radius:4px;">';
		echo '<p style="font-size:0.8em;font-weight:600;margin:0 0 8px;color:#666;">' . esc_html__( 'RTL preview', 'wc-pay-what-you-want' ) . '</p>';
		echo '<div dir="rtl" style="border:1px solid #ccc;padding:8px;border-radius:3px;background:#f9f9f9;">';
		echo '<p style="margin:0 0 4px;font-size:0.9em;">' . esc_html__( 'Name Your Price', 'wc-pay-what-you-want' ) . '</p>';
		echo '<div style="display:flex;flex-direction:row-reverse;gap:4px;margin:4px 0;"><button type="button" style="padding:2px 8px;font-size:0.85em;">₪10</button><button type="button" style="padding:2px 8px;font-size:0.85em;">₪25</button></div>';
		echo '<input type="number" value="25" style="width:80px;text-align:right;direction:rtl;" readonly />';
		echo '</div></div>';

		echo '</div>';
		echo '</td></tr>';
	}

	/**
	 * Render the Currency Localisation preview panel (read-only).
	 */
	public static function renderCurrencyPreviewPanel(): void {
		$currency_code   = get_woocommerce_currency();
		$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
		$currency_pos    = get_option( 'woocommerce_currency_pos', 'left' );
		$decimal_sep     = wc_get_price_decimal_separator();
		$thousands_sep   = wc_get_price_thousand_separator();
		$decimals        = wc_get_price_decimals();
		$currencies      = get_woocommerce_currencies();
		$currency_name   = $currencies[ $currency_code ] ?? $currency_code;

		$examples = [
			__( 'Input field placeholder', 'wc-pay-what-you-want' )  => 10,
			__( 'Minimum price label', 'wc-pay-what-you-want' )      => 10,
			__( 'Maximum price label', 'wc-pay-what-you-want' )      => 100,
			__( 'Suggested price label', 'wc-pay-what-you-want' )    => 25,
			__( 'Preset button', 'wc-pay-what-you-want' )            => 15,
			__( 'Cart line total', 'wc-pay-what-you-want' )          => 29.99,
			__( 'Checkout summary', 'wc-pay-what-you-want' )         => 29.99,
			__( 'Order history record', 'wc-pay-what-you-want' )     => 29.99,
		];

		$pos_labels = [
			'left'        => __( 'Left (e.g. $25.00)', 'wc-pay-what-you-want' ),
			'right'       => __( 'Right (e.g. 25.00$)', 'wc-pay-what-you-want' ),
			'left_space'  => __( 'Left with space (e.g. $ 25.00)', 'wc-pay-what-you-want' ),
			'right_space' => __( 'Right with space (e.g. 25.00 $)', 'wc-pay-what-you-want' ),
		];

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label>' . esc_html__( 'Currency Localisation', 'wc-pay-what-you-want' ) . '</label>';
		echo '</th><td class="forminp">';

		// Currency settings summary.
		echo '<table class="widefat striped" style="max-width:500px;margin-bottom:1em;">';
		echo '<tbody>';
		$settings_rows = [
			__( 'Currency', 'wc-pay-what-you-want' )           => esc_html( $currency_name . ' (' . $currency_code . ')' ),
			__( 'Symbol', 'wc-pay-what-you-want' )             => '<code>' . esc_html( $currency_symbol ) . '</code>',
			__( 'Position', 'wc-pay-what-you-want' )           => esc_html( $pos_labels[ $currency_pos ] ?? $currency_pos ),
			__( 'Decimal separator', 'wc-pay-what-you-want' )  => '<code>' . esc_html( $decimal_sep ) . '</code>',
			__( 'Thousands separator', 'wc-pay-what-you-want' ) => '<code>' . ( '' === $thousands_sep ? __( '(none)', 'wc-pay-what-you-want' ) : esc_html( $thousands_sep ) ) . '</code>',
			__( 'Decimal places', 'wc-pay-what-you-want' )     => esc_html( (string) $decimals ),
		];
		foreach ( $settings_rows as $label => $value ) {
			echo '<tr><td style="width:180px;font-weight:600;">' . esc_html( $label ) . '</td>';
			echo '<td>' . wp_kses_post( $value ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		// Price preview table.
		echo '<h4 style="margin:0 0 0.5em;">' . esc_html__( 'Price display preview', 'wc-pay-what-you-want' ) . '</h4>';
		echo '<table class="widefat striped" style="max-width:500px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Surface', 'wc-pay-what-you-want' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'wc-pay-what-you-want' ) . '</th>';
		echo '<th>' . esc_html__( 'Formatted', 'wc-pay-what-you-want' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $examples as $label => $amount ) {
			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( (string) $amount ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $amount ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:0.5em;">' . esc_html__( 'All PWYW price outputs use wc_price() and automatically reflect your WooCommerce currency settings.', 'wc-pay-what-you-want' ) . '</p>';
		echo '</td></tr>';
	}
}
