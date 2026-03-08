/* global jQuery, wcpwywAnalytics */
( function ( $ ) {
	'use strict';

	function renderWidget( $widget, data ) {
		var isEmpty  = data.total_orders === 0;
		var currency = data.currency_symbol;
		var decimals = data.decimals;

		function formatCurrency( amount ) {
			return currency + parseFloat( amount ).toFixed( decimals );
		}

		var html = '';

		// Metric cards
		html += '<div class="wcpwyw-widget__cards">';

		html += '<div class="wcpwyw-widget__card">';
		html += '<div class="wcpwyw-widget__card-label">' + wcpwywAnalytics.i18n.totalRevenue + '</div>';
		html += '<div class="wcpwyw-widget__card-value">' + formatCurrency( data.total_revenue ) + '</div>';
		html += '<div class="wcpwyw-widget__card-period">' + data.period_label + '</div>';
		html += '</div>';

		html += '<div class="wcpwyw-widget__card">';
		html += '<div class="wcpwyw-widget__card-label">' + wcpwywAnalytics.i18n.totalOrders + '</div>';
		html += '<div class="wcpwyw-widget__card-value">' + data.total_orders + '</div>';
		html += '<div class="wcpwyw-widget__card-period">' + data.period_label + '</div>';
		html += '</div>';

		var avgPriceValue = data.avg_price !== null ? formatCurrency( data.avg_price ) : '&mdash;';
		html += '<div class="wcpwyw-widget__card">';
		html += '<div class="wcpwyw-widget__card-label">' + wcpwywAnalytics.i18n.avgPrice + '</div>';
		html += '<div class="wcpwyw-widget__card-value">' + avgPriceValue + '</div>';
		html += '<div class="wcpwyw-widget__card-period">' + data.period_label + '</div>';
		html += '</div>';

		html += '</div>'; // .wcpwyw-widget__cards

		if ( isEmpty ) {
			html += '<div class="wcpwyw-widget__empty">';
			html += '<p>' + data.empty_message + '</p>';
			html += '<p>' + wcpwywAnalytics.i18n.enableHint + '</p>';
			html += '<a href="' + data.products_url + '" class="button button-secondary">' + wcpwywAnalytics.i18n.viewProducts + '</a>';
			html += '</div>';
		} else {
			// Avg vs Suggested
			if ( data.avg_deviation_pct !== null ) {
				var devPct   = data.avg_deviation_pct;
				var devLabel = devPct >= 0
					? wcpwywAnalytics.i18n.avgAbove.replace( '%s', '+' + devPct + '%' )
					: wcpwywAnalytics.i18n.avgBelow.replace( '%s', devPct + '%' );
				var devClass = devPct >= 0 ? 'wcpwyw-widget__deviation--above' : 'wcpwyw-widget__deviation--below';
				html += '<div class="wcpwyw-widget__avg-deviation ' + devClass + '">' + devLabel + '</div>';
			}

			// Distribution
			if ( data.dist_total > 0 ) {
				var dist  = data.distribution;
				var tiers = [
					{ key: 'below_zero', label: wcpwywAnalytics.i18n.distBelowMin },
					{ key: 'below_sugg', label: wcpwywAnalytics.i18n.distBelowSugg },
					{ key: 'at_sugg',    label: wcpwywAnalytics.i18n.distAtSugg },
					{ key: 'above_sugg', label: wcpwywAnalytics.i18n.distAboveSugg },
				];
				html += '<div class="wcpwyw-widget__distribution"><h4>' + wcpwywAnalytics.i18n.distribution + '</h4>';
				$.each( tiers, function ( i, tier ) {
					var count = dist[ tier.key ] || 0;
					if ( tier.key === 'below_zero' && count === 0 ) {
						return; // hide "Below min" row when no zero-price orders
					}
					var pct  = data.dist_total > 0 ? Math.round( ( count / data.dist_total ) * 100 ) : 0;
					var fill = Math.min( pct, 100 );
					html += '<div class="wcpwyw-widget__dist-row">';
					html += '<span class="wcpwyw-widget__dist-label">' + tier.label + '</span>';
					html += '<div class="wcpwyw-widget__dist-bar-wrap"><div class="wcpwyw-widget__dist-bar" style="width:' + fill + '%"></div></div>';
					html += '<span class="wcpwyw-widget__dist-pct">' + pct + '%</span>';
					html += '<span class="wcpwyw-widget__dist-count">(' + count + ')</span>';
					html += '</div>';
				} );
				html += '</div>'; // .wcpwyw-widget__distribution
			}

			// Top 5 products
			if ( data.top5 && data.top5.length > 0 ) {
				html += '<div class="wcpwyw-widget__top5"><h4>' + wcpwywAnalytics.i18n.top5Title + '</h4>';
				html += '<table class="wcpwyw-widget__top5-table"><thead><tr>';
				html += '<th>#</th>';
				html += '<th>' + wcpwywAnalytics.i18n.col_product + '</th>';
				html += '<th>' + wcpwywAnalytics.i18n.col_revenue + '</th>';
				html += '<th>' + wcpwywAnalytics.i18n.col_orders + '</th>';
				html += '<th>' + wcpwywAnalytics.i18n.col_avg + '</th>';
				html += '</tr></thead><tbody>';
				$.each( data.top5, function ( i, row ) {
					html += '<tr>';
					html += '<td>' + ( i + 1 ) + '</td>';
					html += '<td><a href="' + row.edit_url + '">' + row.product_name + '</a></td>';
					html += '<td>' + formatCurrency( row.revenue ) + '</td>';
					html += '<td>' + row.orders + '</td>';
					html += '<td>' + formatCurrency( row.avg_price ) + '</td>';
					html += '</tr>';
				} );
				html += '</tbody></table></div>';
			}
		}

		$widget.find( '.wcpwyw-widget__content' ).html( html ).show();
		$widget.find( '.wcpwyw-widget__skeleton' ).removeClass( 'wcpwyw-widget__skeleton--visible' );
		$widget.find( '.wcpwyw-widget__error' ).hide();
	}

	function loadWidgetData( $widget, period ) {
		var $skeleton = $widget.find( '.wcpwyw-widget__skeleton' );
		var $content  = $widget.find( '.wcpwyw-widget__content' );
		var $error    = $widget.find( '.wcpwyw-widget__error' );
		var $tabs     = $widget.find( '.wcpwyw-widget__tab' );

		$skeleton.addClass( 'wcpwyw-widget__skeleton--visible' );
		$content.hide();
		$error.hide();
		$tabs.prop( 'disabled', true );

		$.post(
			$widget.data( 'wcpwyw-ajax-url' ),
			{
				action: 'wcpwyw_widget_data',
				nonce:  $widget.data( 'wcpwyw-nonce' ),
				period: period,
			},
			function ( response ) {
				$tabs.prop( 'disabled', false );
				$skeleton.removeClass( 'wcpwyw-widget__skeleton--visible' );
				if ( response.success ) {
					renderWidget( $widget, response.data );
					// Persist period selection.
					$.post( $widget.data( 'wcpwyw-ajax-url' ), {
						action: 'wcpwyw_save_widget_period',
						nonce:  $widget.data( 'wcpwyw-nonce' ),
						period: period,
					} );
				} else {
					$error.show();
					$content.hide();
				}
			}
		).fail( function () {
			$tabs.prop( 'disabled', false );
			$skeleton.removeClass( 'wcpwyw-widget__skeleton--visible' );
			$error.show();
			$content.hide();
		} );
	}

	$( function () {
		var $widget = $( '#wcpwyw_overview_widget .wcpwyw-widget' );
		if ( ! $widget.length ) {
			return;
		}

		// Add error state HTML.
		$widget.find( '.wcpwyw-widget__body' ).append(
			'<div class="wcpwyw-widget__error" style="display:none;">' +
			'<p>' + wcpwywAnalytics.i18n.errorMsg + '</p>' +
			'<button class="button wcpwyw-widget__retry">' + wcpwywAnalytics.i18n.retry + '</button>' +
			'</div>'
		);

		var activePeriod = $widget.data( 'wcpwyw-period' ) || '30';

		// Set the initial active tab state (the PHP sets the class, but we also
		// ensure aria-selected is correct and the class is on the right tab).
		$widget.find( '.wcpwyw-widget__tab' ).removeClass( 'wcpwyw-widget__tab--active' ).attr( 'aria-selected', 'false' );
		$widget.find( '.wcpwyw-widget__tab[data-period="' + activePeriod + '"]' )
			.addClass( 'wcpwyw-widget__tab--active' )
			.attr( 'aria-selected', 'true' );

		$widget.on( 'click', '.wcpwyw-widget__tab', function () {
			var $tab   = $( this );
			var period = $tab.data( 'period' );
			$widget.find( '.wcpwyw-widget__tab' ).removeClass( 'wcpwyw-widget__tab--active' ).attr( 'aria-selected', 'false' );
			$tab.addClass( 'wcpwyw-widget__tab--active' ).attr( 'aria-selected', 'true' );
			loadWidgetData( $widget, period );
		} );

		$widget.on( 'click', '.wcpwyw-widget__retry', function () {
			var period = $widget.find( '.wcpwyw-widget__tab--active' ).data( 'period' ) || '30';
			loadWidgetData( $widget, period );
		} );

		loadWidgetData( $widget, activePeriod );
	} );

} )( jQuery );
