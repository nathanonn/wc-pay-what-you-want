/* WC Pay What You Want — Uninstall Confirmation Page */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var radios        = document.querySelectorAll( 'input[name="wcpwyw_uninstall_choice"]' );
		var deletePanel   = document.querySelector( '.wcpwyw-uninstall__delete-panel' );
		var noticeInfo    = document.querySelector( '.wcpwyw-uninstall__notice-info' );
		var noticeWarning = document.querySelector( '.wcpwyw-uninstall__notice-warning' );
		var submitBtn     = document.getElementById( 'wcpwyw-uninstall-btn' );
		var orderCheckbox = document.querySelector( 'input[name="wcpwyw_delete_order_meta"]' );
		var orderMetaItem = document.querySelector( '.wcpwyw-uninstall__order-meta-item' );
		var orderMetaLine = document.querySelector( '.wcpwyw-uninstall__order-meta-line' );

		if ( ! radios.length || ! deletePanel || ! submitBtn ) {
			return;
		}

		function updateRadioState() {
			var selected = document.querySelector( 'input[name="wcpwyw_uninstall_choice"]:checked' );
			if ( ! selected ) {
				return;
			}

			if ( 'delete' === selected.value ) {
				deletePanel.style.display   = 'block';
				if ( noticeInfo )    { noticeInfo.style.display    = 'none'; }
				if ( noticeWarning ) { noticeWarning.style.display = 'block'; }
				updateSubmitLabel();
			} else {
				deletePanel.style.display   = 'none';
				if ( noticeInfo )    { noticeInfo.style.display    = 'block'; }
				if ( noticeWarning ) { noticeWarning.style.display = 'none'; }
				submitBtn.textContent = submitBtn.getAttribute( 'data-label-keep' ) || 'Uninstall \u2014 keep all data';
			}
		}

		function updateSubmitLabel() {
			if ( orderCheckbox && orderCheckbox.checked ) {
				submitBtn.textContent = submitBtn.getAttribute( 'data-label-delete-all' ) || 'Uninstall \u2014 delete all PWYW data';
			} else {
				submitBtn.textContent = submitBtn.getAttribute( 'data-label-delete' ) || 'Uninstall \u2014 delete plugin data';
			}
		}

		// Store initial labels as data attributes so they survive re-reads.
		submitBtn.setAttribute( 'data-label-keep',       submitBtn.textContent.trim() );
		submitBtn.setAttribute( 'data-label-delete',     'Uninstall \u2014 delete plugin data' );
		submitBtn.setAttribute( 'data-label-delete-all', 'Uninstall \u2014 delete all PWYW data' );

		// Radio change handler.
		for ( var i = 0; i < radios.length; i++ ) {
			radios[ i ].addEventListener( 'change', updateRadioState );
		}

		// Checkbox change handler.
		if ( orderCheckbox ) {
			orderCheckbox.addEventListener( 'change', function () {
				if ( orderMetaItem ) {
					orderMetaItem.style.display = orderCheckbox.checked ? '' : 'none';
				}
				if ( orderMetaLine ) {
					orderMetaLine.style.display = orderCheckbox.checked ? '' : 'none';
				}
				updateSubmitLabel();
			} );
		}

		// Run on load to set initial state.
		updateRadioState();
	} );
}() );
