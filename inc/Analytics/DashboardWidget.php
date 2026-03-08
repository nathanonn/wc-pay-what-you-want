<?php

namespace WcPwyw\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardWidget {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'wp_dashboard_setup', [ self::class, 'registerWidget' ] );
		add_action( 'wp_ajax_wcpwyw_widget_data',        [ self::class, 'handleAjaxData' ] );
		add_action( 'wp_ajax_wcpwyw_save_widget_period', [ self::class, 'saveWidgetPeriod' ] );
	}

	public static function registerWidget(): void {
		wp_add_dashboard_widget(
			'wcpwyw_overview_widget',
			__( 'Pay What You Want — Overview', 'wc-pay-what-you-want' ),
			[ self::class, 'renderWidget' ]
		);
	}

	public static function renderWidget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$user_id      = get_current_user_id();
		$saved_period = get_user_meta( $user_id, 'wcpwyw_widget_period', true );
		$active_period = in_array( $saved_period, [ '7', '30', '90', 'all' ], true )
			? $saved_period
			: '30';

		$nonce = wp_create_nonce( 'wcpwyw_widget_nonce' );

		?>
		<div
			class="wcpwyw-widget"
			data-wcpwyw-period="<?php echo esc_attr( $active_period ); ?>"
			data-wcpwyw-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-wcpwyw-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		>
			<div class="wcpwyw-widget__tabs" role="tablist">
				<?php
				$periods = [
					'7'   => __( '7 days',   'wc-pay-what-you-want' ),
					'30'  => __( '30 days',  'wc-pay-what-you-want' ),
					'90'  => __( '90 days',  'wc-pay-what-you-want' ),
					'all' => __( 'All time', 'wc-pay-what-you-want' ),
				];
				foreach ( $periods as $key => $label ) {
					$key          = (string) $key; // PHP coerces integer-like keys; cast back for strict comparison.
					$active_class = ( $key === $active_period ) ? ' wcpwyw-widget__tab--active' : '';
					printf(
						'<button class="wcpwyw-widget__tab%s" data-period="%s" role="tab" aria-selected="%s">%s</button>',
						esc_attr( $active_class ),
						esc_attr( $key ),
						( $key === $active_period ) ? 'true' : 'false',
						esc_html( $label )
					);
				}
				?>
			</div>
			<div class="wcpwyw-widget__body">
				<!-- Initial skeleton shown while the first AJAX call completes -->
				<div class="wcpwyw-widget__skeleton wcpwyw-widget__skeleton--visible">
					<div class="wcpwyw-widget__skeleton-cards">
						<div class="wcpwyw-widget__skeleton-card"></div>
						<div class="wcpwyw-widget__skeleton-card"></div>
						<div class="wcpwyw-widget__skeleton-card"></div>
					</div>
					<div class="wcpwyw-widget__skeleton-row"></div>
					<div class="wcpwyw-widget__skeleton-row wcpwyw-widget__skeleton-row--short"></div>
					<div class="wcpwyw-widget__skeleton-row"></div>
					<div class="wcpwyw-widget__skeleton-row wcpwyw-widget__skeleton-row--short"></div>
				</div>
				<!-- Populated by AJAX -->
				<div class="wcpwyw-widget__content" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	public static function saveWidgetPeriod(): void {
		check_ajax_referer( 'wcpwyw_widget_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$period = sanitize_text_field( $_POST['period'] ?? '' );
		if ( ! in_array( $period, [ '7', '30', '90', 'all' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid period' ], 400 );
		}

		update_user_meta( get_current_user_id(), 'wcpwyw_widget_period', $period );
		wp_send_json_success();
	}

	public static function handleAjaxData(): void {
		check_ajax_referer( 'wcpwyw_widget_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$period = sanitize_text_field( $_POST['period'] ?? '30' );
		if ( ! in_array( $period, [ '7', '30', '90', 'all' ], true ) ) {
			$period = '30';
		}

		try {
			$data = self::queryMetrics( $period );
			wp_send_json_success( $data );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
	}

	private static function queryMetrics( string $period ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wcpwyw_analytics';

		// Build date filter.
		if ( 'all' === $period ) {
			$date_where = '';
			$date_args  = [];
		} else {
			$days       = (int) $period;
			$date_where = 'WHERE created_at >= %s';
			$date_args  = [ gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) ) ];
		}

		// Headline metrics.
		$headline_sql = $date_where
			? $wpdb->prepare(
				"SELECT SUM(customer_price) AS revenue, COUNT(*) AS orders, AVG(customer_price) AS avg_price, AVG((customer_price - suggested_price) / NULLIF(suggested_price, 0)) AS avg_deviation FROM {$table} {$date_where}",
				...$date_args
			)
			: "SELECT SUM(customer_price) AS revenue, COUNT(*) AS orders, AVG(customer_price) AS avg_price, AVG((customer_price - suggested_price) / NULLIF(suggested_price, 0)) AS avg_deviation FROM {$table}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$headline = $wpdb->get_row( $headline_sql );

		$total_orders        = (int) ( $headline->orders ?? 0 );
		$total_revenue       = (float) ( $headline->revenue ?? 0.0 );
		$avg_price           = ( $total_orders > 0 ) ? (float) $headline->avg_price : null;
		$avg_deviation_ratio = ( $total_orders > 0 ) ? (float) $headline->avg_deviation : null;

		// Price distribution — four tiers relative to suggested_price.
		$where_prefix = $date_where ? str_replace( 'WHERE ', '', $date_where ) . ' AND ' : '';

		$distribution = [];
		foreach (
			[
				'below_zero' => 'customer_price = 0',
				'below_sugg' => 'customer_price > 0 AND customer_price < suggested_price AND suggested_price > 0',
				'at_sugg'    => 'ABS(customer_price - suggested_price) < 0.01',
				'above_sugg' => 'customer_price > suggested_price AND ABS(customer_price - suggested_price) >= 0.01',
			] as $tier => $condition
		) {
			$base_condition = $where_prefix ? "WHERE {$where_prefix}{$condition}" : "WHERE {$condition}";

			$sql = $date_args
				? $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} {$base_condition}",
					...$date_args
				)
				: "SELECT COUNT(*) FROM {$table} WHERE {$condition}";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$distribution[ $tier ] = (int) $wpdb->get_var( $sql );
		}

		// Top 5 products by revenue.
		$top5_sql = $date_where
			? $wpdb->prepare(
				"SELECT product_id, SUM(customer_price) AS revenue, COUNT(*) AS orders, AVG(customer_price) AS avg_price
				 FROM {$table}
				 {$date_where}
				 GROUP BY product_id
				 ORDER BY revenue DESC
				 LIMIT 5",
				...$date_args
			)
			: "SELECT product_id, SUM(customer_price) AS revenue, COUNT(*) AS orders, AVG(customer_price) AS avg_price
			   FROM {$table}
			   GROUP BY product_id
			   ORDER BY revenue DESC
			   LIMIT 5";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$top5_rows = $wpdb->get_results( $top5_sql );

		$top5 = [];
		foreach ( $top5_rows as $row ) {
			$product_id   = (int) $row->product_id;
			$product      = wc_get_product( $product_id );
			$product_name = $product
				? $product->get_name()
				/* translators: %d: product ID */
				: sprintf( __( 'Product #%d', 'wc-pay-what-you-want' ), $product_id );
			$edit_url = get_edit_post_link( $product_id, 'raw' );

			$top5[] = [
				'product_id'   => $product_id,
				'product_name' => $product_name,
				'edit_url'     => $edit_url,
				'revenue'      => (float) $row->revenue,
				'orders'       => (int) $row->orders,
				'avg_price'    => (float) $row->avg_price,
			];
		}

		// Period labels.
		$period_labels = [
			'7'   => __( 'Last 7 days',  'wc-pay-what-you-want' ),
			'30'  => __( 'Last 30 days', 'wc-pay-what-you-want' ),
			'90'  => __( 'Last 90 days', 'wc-pay-what-you-want' ),
			'all' => __( 'All time',     'wc-pay-what-you-want' ),
		];

		$empty_labels = [
			'7'   => __( 'No PWYW orders in the last 7 days.',  'wc-pay-what-you-want' ),
			'30'  => __( 'No PWYW orders in the last 30 days.', 'wc-pay-what-you-want' ),
			'90'  => __( 'No PWYW orders in the last 90 days.', 'wc-pay-what-you-want' ),
			'all' => __( 'No PWYW orders yet.',                 'wc-pay-what-you-want' ),
		];

		return [
			'period'            => $period,
			'period_label'      => $period_labels[ $period ],
			'empty_message'     => $empty_labels[ $period ],
			'total_orders'      => $total_orders,
			'total_revenue'     => $total_revenue,
			'avg_price'         => $avg_price,
			'avg_deviation_pct' => ( null !== $avg_deviation_ratio ) ? round( $avg_deviation_ratio * 100, 1 ) : null,
			'distribution'      => $distribution,
			'dist_total'        => $total_orders,
			'top5'              => $top5,
			'products_url'      => admin_url( 'edit.php?post_type=product' ),
			'currency_symbol'   => get_woocommerce_currency_symbol(),
			'decimals'          => wc_get_price_decimals(),
		];
	}
}
