/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {

		// -----------------------------------------------------------------------
		// Product Panel: Enable PWYW toggle (TC-206)
		// Hides/shows .wcpwyw-product-fields when #_wcpwyw_enabled changes
		// -----------------------------------------------------------------------
		var $enableCheckbox = $( '#_wcpwyw_enabled' );
		var $productFields  = $( '.wcpwyw-product-fields' );
		var $disabledNotice = $( '.wcpwyw-disabled-notice' );

		$enableCheckbox.on( 'change', function () {
			if ( $( this ).is( ':checked' ) ) {
				$productFields.show();
				$disabledNotice.hide();
			} else {
				$productFields.hide();
				$disabledNotice.show();
			}
		} );

		// -----------------------------------------------------------------------
		// Product Panel: Helper text switching (TC-203 / TC-204)
		// When a price override field gains/loses a value, switch hint between
		// "Using global default" and "Override active"
		// -----------------------------------------------------------------------
		$( '.wcpwyw-price-override-field' ).on( 'input change', function () {
			var $hint    = $( this ).closest( '.form-field' ).find( '.wcpwyw-field-hint' );
			var hasValue = '' !== $.trim( $( this ).val() );
			$hint.toggleClass( 'wcpwyw-override-active', hasValue )
				 .toggleClass( 'wcpwyw-using-global', ! hasValue );
			if ( hasValue ) {
				$hint.text( $hint.data( 'override-text' ) || $hint.text() );
			} else {
				$hint.text( $hint.data( 'global-text' ) || $hint.text() );
			}
		} );

		// -----------------------------------------------------------------------
		// Settings Tab: Email notification threshold progressive disclosure (TC-511)
		// Show/hide threshold row based on its own enable checkbox
		// -----------------------------------------------------------------------
		function toggleEmailThreshold( $checkbox, $thresholdRow ) {
			if ( $checkbox.is( ':checked' ) ) {
				$thresholdRow.show();
			} else {
				$thresholdRow.hide();
			}
		}

		var $belowToggle    = $( '#wcpwyw_email_below_enabled' );
		var $belowThreshold = $( '.wcpwyw-email-below-threshold' ).closest( 'tr' );
		var $aboveToggle    = $( '#wcpwyw_email_above_enabled' );
		var $aboveThreshold = $( '.wcpwyw-email-above-threshold' ).closest( 'tr' );

		toggleEmailThreshold( $belowToggle, $belowThreshold );
		toggleEmailThreshold( $aboveToggle, $aboveThreshold );

		$belowToggle.on( 'change', function () {
			toggleEmailThreshold( $( this ), $belowThreshold );
		} );
		$aboveToggle.on( 'change', function () {
			toggleEmailThreshold( $( this ), $aboveThreshold );
		} );

		// -----------------------------------------------------------------------
		// Settings Tab: Master toggle banner (TC-106)
		// Show/hide the "PWYW is currently disabled" banner when #wcpwyw_enabled
		// checkbox changes (the banner is rendered server-side; JS keeps it in sync)
		// -----------------------------------------------------------------------
		var $masterToggle   = $( '#wcpwyw_enabled' );
		var $disabledBanner = $( '.wcpwyw-disabled-banner' );

		$masterToggle.on( 'change', function () {
			if ( $( this ).is( ':checked' ) ) {
				$disabledBanner.hide();
			} else {
				$disabledBanner.show();
			}
		} );

		// -----------------------------------------------------------------------
		// Settings Tab: Live preset button preview (wireframe Section 3)
		// Renders mock buttons under the Global Preset Amounts field
		// -----------------------------------------------------------------------
		var $presetInput   = $( '.wcpwyw-preset-input' );
		var $presetPreview = $( '.wcpwyw-preset-preview' );

		if ( $presetInput.length && $presetPreview.length ) {
			function updatePresetPreview() {
				var raw   = $presetInput.val().trim();
				var parts = raw ? raw.split( ',' ) : [];
				var html  = '';
				$.each( parts, function ( i, part ) {
					var num = parseFloat( $.trim( part ) );
					if ( ! isNaN( num ) && num > 0 ) {
						html += '<span class="wcpwyw-preview-btn">$' + num.toFixed( 2 ) + '</span> ';
					}
				} );
				$presetPreview.html( html || '<em>(no presets)</em>' );
			}
			$presetInput.on( 'input change', updatePresetPreview );
			updatePresetPreview();
		}

		// -----------------------------------------------------------------------
		// Variation Panel: Radio-driven show/hide (TC-301 / TC-302 / TC-303 / TC-304)
		// For each variation, listen to changes on _wcpwyw_variation_mode[N] radio
		// -----------------------------------------------------------------------
		function handleVariationModeChange( $radio ) {
			var $panel          = $radio.closest( '.wcpwyw-variation-panel' );
			var mode            = $radio.val();
			var $overrideFields = $panel.find( '.wcpwyw-variation-override-fields' );
			var $inheritInfo    = $panel.find( '.wcpwyw-inherit-info' );
			var $disableInfo    = $panel.find( '.wcpwyw-disable-info' );

			$overrideFields.toggle( 'enable' === mode );
			$inheritInfo.toggle( 'inherit' === mode );
			$disableInfo.toggle( 'disable' === mode );
		}

		// Delegate for dynamically-loaded variations (AJAX loaded by WooCommerce)
		$( document ).on( 'change', 'input[name^="_wcpwyw_variation_mode"]', function () {
			handleVariationModeChange( $( this ) );
		} );

		// Run on page load for any variation panels already in the DOM
		$( 'input[name^="_wcpwyw_variation_mode"]:checked' ).each( function () {
			handleVariationModeChange( $( this ) );
		} );

		// -----------------------------------------------------------------------
		// Settings Tab: Security section — cap value field show/hide (TC-612 / P6)
		// Show/hide the "Absolute maximum price cap" row based on the cap checkbox
		// -----------------------------------------------------------------------
		function updateCapFieldVisibility() {
			var $capToggle = $( '#wcpwyw_price_cap_enabled' );
			var $capValue  = $( '#wcpwyw_price_cap_value' ).closest( 'tr' );
			if ( $capToggle.is( ':checked' ) ) {
				$capValue.show();
			} else {
				$capValue.hide();
			}
		}
		updateCapFieldVisibility();
		$( '#wcpwyw_price_cap_enabled' ).on( 'change', updateCapFieldVisibility );

	} );

} )( jQuery );
