/* global wcpwywData */
jQuery( function ( $ ) {
	'use strict';

	// Bail if data not available.
	if ( typeof wcpwywData === 'undefined' ) {
		return;
	}

	var WcPwywFrontend = {
		config: wcpwywData.isVariable ? null : wcpwywData.simpleConfig,
		debounceTimer: null,

		init: function () {
			if ( ! wcpwywData.isVariable && ! this.config ) {
				return;
			}

			// Ensure the PWYW hidden fields are inside form.cart so they are submitted.
			this.injectFieldsIntoForm();

			// For simple products, run initial state.
			if ( ! wcpwywData.isVariable && this.config && this.config.pwyw_enabled ) {
				this.syncPresetState();
				this.validate();
			}

			this.bindEvents();

			// Handle variable products.
			if ( wcpwywData.isVariable ) {
				this.bindVariationEvents();
			}
		},

		/**
		 * Move (or copy) the PWYW hidden inputs into form.cart so they are
		 * included in the add-to-cart POST when the theme renders the form
		 * outside the #wcpwyw-section container (e.g. block-based themes).
		 */
		injectFieldsIntoForm: function () {
			var $form = $( 'form.cart' );
			if ( ! $form.length ) {
				return;
			}

			// Names of hidden fields that must travel with the form.
			var fieldNames = [ 'wcpwyw_price', 'wcpwyw_product_id', 'wcpwyw_variation_id', 'wcpwyw_nonce' ];

			$.each( fieldNames, function ( i, name ) {
				// Skip if already inside the form.
				if ( $form.find( '[name="' + name + '"]' ).length ) {
					return;
				}

				// Find the original field (may be outside the form).
				var $original = $( '[name="' + name + '"]' ).first();
				if ( ! $original.length ) {
					return;
				}

				// Clone fields into the form as hidden inputs; keep originals in place for the visible UI.
				var $clone = $original.clone();
				$clone.attr( 'id', '' ); // avoid duplicate IDs
				$clone.attr( 'type', 'hidden' ); // ensure clone is hidden regardless of original type
				$form.append( $clone );

				// For the price input: keep the form clone's value in sync with the original.
				if ( name === 'wcpwyw_price' ) {
					$original.on( 'input change', function () {
						$form.find( '[name="wcpwyw_price"]' ).not( $original ).val( $( this ).val() );
					} );
				}
			} );
		},

		bindEvents: function () {
			var self = this;

			// Preset button clicks.
			$( document ).on( 'click', '#wcpwyw-section .wcpwyw-preset-btn', function () {
				if ( $( this ).hasClass( 'wcpwyw-preset-btn--custom' ) ) {
					// Force Custom active regardless of current input value.
					$( '#wcpwyw-section .wcpwyw-preset-btn:not(.wcpwyw-preset-btn--custom)' ).removeClass( 'wcpwyw-preset-btn--active' );
					$( '#wcpwyw-section .wcpwyw-preset-btn--custom' ).addClass( 'wcpwyw-preset-btn--active' );
					$( '#wcpwyw-price-input' ).focus();
				} else {
					var val = $( this ).data( 'wcpwyw-value' );
					$( '#wcpwyw-price-input' ).val( val ).trigger( 'input' );
					self.syncPresetState();
					self.validate();
				}
			} );

			// Live input validation (debounced).
			$( document ).on( 'input change', '#wcpwyw-price-input', function () {
				clearTimeout( self.debounceTimer );
				self.debounceTimer = setTimeout( function () {
					self.syncPresetState();
					self.validate();
				}, 300 );
			} );

			// Immediate validation on blur — also dismiss the last-price note once
			// the customer has manually entered a different value.
			$( document ).on( 'blur', '#wcpwyw-price-input', function () {
				clearTimeout( self.debounceTimer );
				self.syncPresetState();
				self.validate();
				self.dismissLastPriceNote( $( this ) );
			} );
		},

		syncPresetState: function () {
			if ( ! this.config ) {
				return;
			}

			var inputVal = parseFloat( $( '#wcpwyw-price-input' ).val() );
			var decimals = this.config.decimals || 2;
			var foundMatch = false;

			$( '#wcpwyw-section .wcpwyw-preset-btn:not(.wcpwyw-preset-btn--custom)' ).each( function () {
				var presetVal = parseFloat( $( this ).data( 'wcpwyw-value' ) );
				if ( ! isNaN( inputVal ) && Math.abs( inputVal - presetVal ) < ( 1 / Math.pow( 10, decimals + 1 ) ) ) {
					$( this ).addClass( 'wcpwyw-preset-btn--active' );
					foundMatch = true;
				} else {
					$( this ).removeClass( 'wcpwyw-preset-btn--active' );
				}
			} );

			if ( foundMatch ) {
				$( '#wcpwyw-section .wcpwyw-preset-btn--custom' ).removeClass( 'wcpwyw-preset-btn--active' );
			} else {
				$( '#wcpwyw-section .wcpwyw-preset-btn--custom' ).addClass( 'wcpwyw-preset-btn--active' );
			}
		},

		validate: function () {
			if ( ! this.config ) {
				return;
			}

			var rawVal = $( '#wcpwyw-price-input' ).val();
			var val = parseFloat( rawVal );
			var config = this.config;
			var i18n = wcpwywData.i18n;

			// Empty or non-numeric.
			if ( rawVal === '' || isNaN( val ) ) {
				this.showError( i18n.errorInvalid );
				this.disableCart();
				return;
			}

			// Round to configured decimals and write back to input if value changed.
			var decimals = config.decimals !== undefined ? config.decimals : ( parseInt( wcpwywData.priceDecimals, 10 ) || 2 );

			// JPY and other zero-decimal currencies: reject decimal input (TC-617).
			if ( decimals === 0 && val !== Math.floor( val ) ) {
				this.showError( i18n.errorInvalid );
				this.disableCart();
				return;
			}
			var factor = Math.pow( 10, decimals );
			val = Math.round( val * factor ) / factor;
			var rounded = val.toFixed( decimals );
			if ( parseFloat( rawVal ) !== val ) {
				$( '#wcpwyw-price-input' ).val( rounded ).trigger( 'change' );
			}

			// Zero price check.
			if ( ! config.allow_zero && val === 0 ) {
				this.showError( ( i18n.errorBelowMin || '' ).replace( '{amount}', config.formatted_min ) );
				this.disableCart();
				return;
			}

			// Below minimum.
			if ( val < config.min_price ) {
				this.showError( ( i18n.errorBelowMin || '' ).replace( '{amount}', config.formatted_min ) );
				this.disableCart();
				return;
			}

			// Above maximum.
			if ( val > config.max_price ) {
				this.showError( ( i18n.errorAboveMax || '' ).replace( '{amount}', config.formatted_max ) );
				this.disableCart();
				return;
			}

			// Valid.
			this.clearError();
			this.enableCart();
		},

		showError: function ( msg ) {
			$( '#wcpwyw-section .wcpwyw-input-wrap' ).addClass( 'wcpwyw-input-wrap--error' );
			$( '#wcpwyw-error' ).html( msg ).show();
		},

		clearError: function () {
			$( '#wcpwyw-section .wcpwyw-input-wrap' ).removeClass( 'wcpwyw-input-wrap--error' );
			$( '#wcpwyw-error' ).html( '' ).hide();
		},

		/**
		 * Hide the last-price pre-fill note once the customer manually changes
		 * the input value away from the pre-filled last-paid price.
		 *
		 * @param {jQuery} $input The price input element.
		 */
		dismissLastPriceNote: function ( $input ) {
			var $note = $( '#wcpwyw-section .wcpwyw-last-price-note' );
			if ( ! $note.length ) {
				return;
			}
			// Only dismiss if the customer has changed the value from the original last-paid price.
			var lastPrice = parseFloat( $input.data( 'wcpwyw-last-price' ) );
			var currentVal = parseFloat( $input.val() );
			if ( isNaN( lastPrice ) ) {
				return;
			}
			if ( currentVal !== lastPrice ) {
				$note.hide();
				$input.removeAttr( 'data-wcpwyw-has-history' );
			}
		},

		disableCart: function () {
			$( '.single_add_to_cart_button' )
				.attr( 'disabled', 'disabled' )
				.addClass( 'wcpwyw-add-to-cart--disabled' );
		},

		enableCart: function () {
			$( '.single_add_to_cart_button' )
				.removeAttr( 'disabled' )
				.removeClass( 'wcpwyw-add-to-cart--disabled' );
		},

		bindVariationEvents: function () {
			var self = this;
			var $form = $( 'form.variations_form' );

			if ( ! $form.length ) {
				return;
			}

			// Show loading state when attribute dropdown changes (before AJAX resolves).
			$form.on( 'woocommerce_variation_select_change', function () {
				$( '#wcpwyw-section' ).addClass( 'wcpwyw-section--loading' );
				self.disableCart();
			} );

			// Variation found — update section with variation config.
			$form.on( 'found_variation', function ( event, variation ) {
				$( '#wcpwyw-section' ).removeClass( 'wcpwyw-section--loading' );

				var variationId = variation.variation_id;

				// Prefer wcpwyw data embedded in the variation JSON (via woocommerce_available_variation filter),
				// fall back to the localised variationsConfig map for backward compatibility.
				var pwywData = variation.wcpwyw || null;
				var config = ( pwywData && pwywData.enabled )
					? pwywData
					: ( wcpwywData.variationsConfig[ variationId ] || null );

				// Determine whether PWYW is enabled for this specific variation.
				var pwywEnabled = pwywData ? !! pwywData.enabled : ( config && !! config.pwyw_enabled );

				if ( ! pwywEnabled ) {
					// PWYW disabled for this variation — hide PWYW section, restore standard cart flow.
					$( '#wcpwyw-section' ).hide();
					self.config = null;
					self.enableCart();
					return;
				}

				// Update local config reference.
				self.config = config;

				// Show the section.
				$( '#wcpwyw-section' ).show();

				// Rebuild preset buttons.
				var $presetsContainer = $( '#wcpwyw-section .wcpwyw-presets' );
				$presetsContainer.empty();

				var decimals = config.decimals || 2;
				var currencySymbol = config.currency_symbol || '';

				$.each( config.preset_buttons, function ( i, presetValue ) {
					var formatted = parseFloat( presetValue ).toFixed( decimals );
					var $btn = $( '<button>' )
						.attr( 'type', 'button' )
						.addClass( 'wcpwyw-preset-btn' )
						.attr( 'data-wcpwyw-value', formatted )
						.text( currencySymbol + formatted );
					$presetsContainer.append( $btn );
				} );

				// Add Custom button.
				var $customBtn = $( '<button>' )
					.attr( 'type', 'button' )
					.addClass( 'wcpwyw-preset-btn wcpwyw-preset-btn--custom' )
					.text( wcpwywData.i18n ? wcpwywData.i18n.customLabel || 'Custom' : 'Custom' );
				$presetsContainer.append( $customBtn );

				// Update input attributes.
				var $input = $( '#wcpwyw-price-input' );
				var step = ( 1 / Math.pow( 10, decimals ) ).toFixed( decimals );
				$input
					.attr( 'min', parseFloat( config.min_price ).toFixed( decimals ) )
					.attr( 'max', parseFloat( config.max_price ).toFixed( decimals ) )
					.attr( 'step', step )
					.val( parseFloat( config.suggested_price ).toFixed( decimals ) )
					.trigger( 'input' );

				// Update boundary label — use .html() so that any HTML entities in formatted prices render correctly.
				$( '#wcpwyw-boundary' ).html(
					'Pay between ' + config.formatted_min + ' and ' + config.formatted_max
				);

				// Update suggested price label — use .html() for same reason.
				$( '#wcpwyw-section .wcpwyw-suggested' ).html( 'Suggested: ' + config.formatted_suggested );

				// Update price input label heading for this variation.
				if ( config.label_input ) {
					$( '#wcpwyw-section .wcpwyw-heading' ).text( config.label_input );
				}

				// Update hidden variation ID field.
				$( 'input[name="wcpwyw_variation_id"]' ).val( variationId );

				// Sync state and validate.
				self.syncPresetState();
				self.validate();
			} );

			// Variation reset — hide section.
			$form.on( 'reset_data', function () {
				$( '#wcpwyw-section' )
					.removeClass( 'wcpwyw-section--loading' )
					.hide();
				self.enableCart();
				self.clearError();
			} );
		}
	};

	WcPwywFrontend.init();
} );
