/**
 * WC Pay What You Want — Cart Page JavaScript
 *
 * Handles in-cart price editing: client-side validation, debounced AJAX
 * price update, loading states, inline error display, and subtotal refresh.
 *
 * Depends on: jQuery, wcpwywCartData (localized via wp_localize_script)
 */
(function ($) {
	'use strict';

	var WcPwywCart = {

		debounceTimers: {},

		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			// Event delegation: WooCommerce may re-render cart rows via its own
			// AJAX (e.g. after quantity update). Delegated listeners survive DOM replacement.
			$(document.body).on(
				'input',
				'.wcpwyw-cart-price-input',
				this.onInputChange.bind( this )
			);
			$(document.body).on(
				'blur',
				'.wcpwyw-cart-price-input',
				this.onInputBlur.bind( this )
			);
			$(document.body).on(
				'keydown',
				'.wcpwyw-cart-price-input',
				this.onKeyDown.bind( this )
			);
		},

		/**
		 * On every input event: start/restart a 300ms debounce for inline validation only.
		 * No AJAX on input — only on blur or Enter.
		 */
		onInputChange: function ( event ) {
			var $input = $( event.target );
			var key    = $input.data( 'wcpwyw-cart-key' );

			if ( this.debounceTimers[ key ] ) {
				clearTimeout( this.debounceTimers[ key ] );
			}

			this.debounceTimers[ key ] = setTimeout(
				function () {
					WcPwywCart.validateInput( $input );
				},
				300
			);
		},

		/**
		 * On blur: cancel pending debounce, validate, submit AJAX if valid.
		 */
		onInputBlur: function ( event ) {
			var $input = $( event.target );
			var key    = $input.data( 'wcpwyw-cart-key' );

			if ( this.debounceTimers[ key ] ) {
				clearTimeout( this.debounceTimers[ key ] );
				delete this.debounceTimers[ key ];
			}

			if ( this.validateInput( $input ) ) {
				this.submitPriceUpdate( $input );
			}
		},

		/**
		 * Enter key: trigger blur to fire the blur handler; prevent default form submit.
		 */
		onKeyDown: function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				$( event.target ).trigger( 'blur' );
			}
		},

		/**
		 * Client-side validation. Returns true if valid.
		 *
		 * @param {jQuery} $input
		 * @returns {boolean}
		 */
		validateInput: function ( $input ) {
			var val    = $input.val().trim();
			var min    = parseFloat( $input.data( 'wcpwyw-min' ) );
			var max    = parseFloat( $input.data( 'wcpwyw-max' ) );
			var $wrap  = $input.closest( '.wcpwyw-cart-price-wrap' );
			var $error = $wrap.find( '.wcpwyw-cart-error' );

			if ( val === '' || isNaN( parseFloat( val ) ) ) {
				this.showError(
					$input,
					$error,
					wcpwywCartData.i18n.invalidPrice
				);
				return false;
			}

			var price = parseFloat( val );

			if ( ! isNaN( min ) && price < min ) {
				this.showError(
					$input,
					$error,
					wcpwywCartData.i18n.belowMin.replace( '%s', this.formatPrice( min ) )
				);
				return false;
			}

			if ( ! isNaN( max ) && max > 0 && price > max ) {
				this.showError(
					$input,
					$error,
					wcpwywCartData.i18n.aboveMax.replace( '%s', this.formatPrice( max ) )
				);
				return false;
			}

			this.clearError( $input, $error );
			return true;
		},

		/**
		 * Show an inline error message.
		 *
		 * @param {jQuery} $input
		 * @param {jQuery} $error
		 * @param {string} message
		 */
		showError: function ( $input, $error, message ) {
			$input.addClass( 'wcpwyw-cart-price-input--error' );
			$error.text( message ).show();
		},

		/**
		 * Clear an inline error message.
		 *
		 * @param {jQuery} $input
		 * @param {jQuery} $error
		 */
		clearError: function ( $input, $error ) {
			$input.removeClass( 'wcpwyw-cart-price-input--error' );
			$error.text( '' ).hide();
		},

		/**
		 * Format a numeric price for display in error messages.
		 *
		 * @param {number} amount
		 * @returns {string}
		 */
		formatPrice: function ( amount ) {
			var decimals    = parseInt( wcpwywCartData.decimals, 10 ) || 2;
			var decSep      = wcpwywCartData.decimalSep  || '.';
			var thousandSep = wcpwywCartData.thousandSep || ',';
			var symbol      = wcpwywCartData.currencySymbol || '$';
			var pos         = wcpwywCartData.currencyPos || 'left';

			var fixed  = amount.toFixed( decimals );
			var parts  = fixed.split( '.' );
			var intPart = parts[0].replace(
				/\B(?=(\d{3})+(?!\d))/g,
				thousandSep
			);
			var formatted = intPart + ( decimals > 0 ? decSep + parts[1] : '' );

			switch ( pos ) {
				case 'left':
					return symbol + formatted;
				case 'right':
					return formatted + symbol;
				case 'left_space':
					return symbol + '\u00a0' + formatted;
				case 'right_space':
					return formatted + '\u00a0' + symbol;
				default:
					return symbol + formatted;
			}
		},

		/**
		 * Submit the price update via AJAX.
		 *
		 * @param {jQuery} $input
		 */
		submitPriceUpdate: function ( $input ) {
			var self      = this;
			var $wrap     = $input.closest( '.wcpwyw-cart-price-wrap' );
			var cartKey   = $input.data( 'wcpwyw-cart-key' );
			var nonce     = $input.data( 'wcpwyw-nonce' );
			var price     = $input.val().trim();
			var $subtotal = $input.closest( 'tr' ).find( '.product-subtotal' );

			// Set loading state.
			$wrap.addClass( 'wcpwyw-cart-loading' );
			$subtotal.addClass( 'wcpwyw-cart-loading' );

			$.ajax({
				url:    wcpwywCartData.ajaxUrl,
				method: 'POST',
				data:   {
					action:        'wcpwyw_update_cart_price',
					nonce:         nonce,
					cart_item_key: cartKey,
					price:         price,
				},
				success: function ( response ) {
					if ( response.success ) {
						// Update line subtotal cell.
						$subtotal.html( response.data.line_total );
						// Trigger WooCommerce cart fragments refresh (updates totals sidebar).
						$( document.body ).trigger( 'wc_update_cart' );
					} else {
						// Server validation rejected the price.
						var $error = $wrap.find( '.wcpwyw-cart-error' );
						self.showError(
							$input,
							$error,
							response.data.message
						);
						// Revert input to the last known-valid value.
						var lastValid = $input.data( 'wcpwyw-last-valid' );
						if ( lastValid !== undefined ) {
							$input.val( lastValid );
						}
					}
				},
				error: function () {
					var $error = $wrap.find( '.wcpwyw-cart-error' );
					self.showError( $input, $error, wcpwywCartData.i18n.serverError );
				},
				complete: function () {
					$wrap.removeClass( 'wcpwyw-cart-loading' );
					$subtotal.removeClass( 'wcpwyw-cart-loading' );
				},
			});

			// Store current value as last-valid so we can revert on server rejection.
			$input.data( 'wcpwyw-last-valid', price );
		},
	};

	$( function () {
		WcPwywCart.init();
	});

}( jQuery ));
