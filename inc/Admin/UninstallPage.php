<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UninstallPage {

	private const PAGE_SLUG = 'wcpwyw-uninstall';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Only register hooks in the admin.
		if ( ! is_admin() ) {
			return;
		}

		// Intercept the standard "delete-plugin" action.
		add_action( 'admin_action_delete-plugin', [ self::class, 'interceptDelete' ] );

		// Register the hidden confirmation page.
		add_action( 'admin_menu', [ self::class, 'registerPage' ] );

		// Fix page title and menu highlighting for hidden page (PHP 8.x compat).
		add_action( 'current_screen', [ self::class, 'ensurePageTitle' ] );

		// Show post-uninstall notice on plugins page.
		add_action( 'admin_notices', [ self::class, 'showPostUninstallNotice' ] );
	}

	/**
	 * Intercept the WP "delete-plugin" action and redirect to our confirmation page
	 * when the plugin being deleted is our own.
	 */
	public static function interceptDelete(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plugin = isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : '';

		if ( WCPWYW_BASENAME !== $plugin ) {
			return;
		}

		// Redirect to our confirmation page.
		$redirect = admin_url( 'admin.php?page=wcpwyw-uninstall&plugin=' . rawurlencode( $plugin ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Register the hidden admin page for the uninstall confirmation.
	 * Uses empty string (not null) for parent slug — PHP 8.x compat.
	 */
	public static function registerPage(): void {
		add_submenu_page(
			'',                                        // Hidden — empty parent (not null, for PHP 8.x).
			__( 'Uninstall PWYW', 'wc-pay-what-you-want' ),
			__( 'Uninstall PWYW', 'wc-pay-what-you-want' ),
			'delete_plugins',
			self::PAGE_SLUG,
			[ self::class, 'renderPage' ]
		);
	}

	/**
	 * Fix page title and menu parent for hidden page.
	 *
	 * Prevents strip_tags(null) deprecation in admin-header.php and
	 * ensures get_admin_page_parent() in menu-header.php resolves correctly.
	 */
	public static function ensurePageTitle(): void {
		$screen = get_current_screen();
		if ( $screen && 'admin_page_' . self::PAGE_SLUG === $screen->id ) {
			global $title, $_wp_real_parent_file;
			if ( null === $title ) {
				$title = __( 'Uninstall PWYW', 'wc-pay-what-you-want' );
			}
			$_wp_real_parent_file[''] = 'plugins.php';
		}
	}

	/**
	 * Render the uninstall confirmation page.
	 */
	public static function renderPage(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete plugins.', 'wc-pay-what-you-want' ) );
		}

		// Handle form submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['_wcpwyw_uninstall_action'] ) ) {
			self::handleFormPost();
			return;
		}

		// Load live data counts.
		$counts = self::getLiveCounts();

		// Get the plugin slug to pass through.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : WCPWYW_BASENAME;

		wp_enqueue_style( 'wcpwyw-admin-uninstall', WCPWYW_URL . 'assets/css/admin-uninstall.css', [], WCPWYW_VERSION );
		wp_enqueue_script( 'wcpwyw-admin-uninstall', WCPWYW_URL . 'assets/js/admin-uninstall.js', [], WCPWYW_VERSION, true );

		?>
		<div class="wrap wcpwyw_root wcpwyw_admin-layout wcpwyw-uninstall-page">
			<h1><?php esc_html_e( 'Uninstall WC Pay What You Want', 'wc-pay-what-you-want' ); ?></h1>

			<div class="wcpwyw-uninstall__inventory">
				<h2><?php esc_html_e( 'Data currently stored by this plugin', 'wc-pay-what-you-want' ); ?></h2>
				<table class="widefat striped" style="max-width:480px;">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Plugin settings', 'wc-pay-what-you-want' ); ?></td>
							<td><?php echo esc_html( sprintf( _n( '%d option', '%d options', $counts['settings'], 'wc-pay-what-you-want' ), $counts['settings'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'PWYW-enabled products', 'wc-pay-what-you-want' ); ?></td>
							<td><?php echo esc_html( sprintf( _n( '%d product', '%d products', $counts['products'], 'wc-pay-what-you-want' ), $counts['products'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Analytics rows', 'wc-pay-what-you-want' ); ?></td>
							<td><?php echo esc_html( sprintf( _n( '%d row', '%d rows', $counts['analytics'], 'wc-pay-what-you-want' ), $counts['analytics'] ) ); ?></td>
						</tr>
						<tr class="wcpwyw-uninstall__order-meta-item" style="display:none;">
							<td><?php esc_html_e( 'Orders with PWYW data', 'wc-pay-what-you-want' ); ?></td>
							<td><?php echo esc_html( sprintf( _n( '%d order', '%d orders', $counts['orders'], 'wc-pay-what-you-want' ), $counts['orders'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<form method="post" action="" class="wcpwyw-uninstall__form">
				<?php wp_nonce_field( 'wcpwyw_uninstall', '_wcpwyw_nonce' ); ?>
				<input type="hidden" name="_wcpwyw_uninstall_action" value="confirm">
				<input type="hidden" name="_wcpwyw_plugin" value="<?php echo esc_attr( $plugin ); ?>">

				<div class="wcpwyw-uninstall__options">
					<h2><?php esc_html_e( 'What should happen to your data?', 'wc-pay-what-you-want' ); ?></h2>

					<div class="wcpwyw-uninstall__notice-info notice notice-info inline">
						<p><?php esc_html_e( 'Your PWYW settings, product meta, analytics history, and order records will be preserved in the database. You can re-activate the plugin at any time and your data will still be there.', 'wc-pay-what-you-want' ); ?></p>
					</div>

					<div class="wcpwyw-uninstall__notice-warning notice notice-warning inline" style="display:none;">
						<p><strong><?php esc_html_e( 'Warning:', 'wc-pay-what-you-want' ); ?></strong>
						<?php esc_html_e( 'The selected data will be permanently deleted. This action cannot be undone.', 'wc-pay-what-you-want' ); ?></p>
					</div>

					<label class="wcpwyw-uninstall__radio-label">
						<input type="radio" name="wcpwyw_uninstall_choice" value="keep" checked>
						<span class="wcpwyw-uninstall__radio-text">
							<strong><?php esc_html_e( 'Keep all data', 'wc-pay-what-you-want' ); ?></strong><br>
							<span class="description"><?php esc_html_e( 'Remove the plugin only. All settings, product meta, analytics, and order history remain in the database.', 'wc-pay-what-you-want' ); ?></span>
						</span>
					</label>

					<label class="wcpwyw-uninstall__radio-label">
						<input type="radio" name="wcpwyw_uninstall_choice" value="delete">
						<span class="wcpwyw-uninstall__radio-text">
							<strong><?php esc_html_e( 'Delete plugin data', 'wc-pay-what-you-want' ); ?></strong><br>
							<span class="description"><?php esc_html_e( 'Remove plugin and delete: settings, product meta, and analytics table.', 'wc-pay-what-you-want' ); ?></span>
						</span>
					</label>

					<div class="wcpwyw-uninstall__delete-panel" style="display:none;">
						<label class="wcpwyw-uninstall__checkbox-label">
							<input type="checkbox" name="wcpwyw_delete_order_meta" value="1">
							<span><?php esc_html_e( 'Also delete order meta (PWYW price history in orders)', 'wc-pay-what-you-want' ); ?></span>
						</label>

						<div class="wcpwyw-uninstall__affected-list">
							<p><strong><?php esc_html_e( 'Data that will be deleted:', 'wc-pay-what-you-want' ); ?></strong></p>
							<ul>
								<li><?php esc_html_e( 'All wcpwyw_* option entries', 'wc-pay-what-you-want' ); ?></li>
								<li><?php esc_html_e( 'All _wcpwyw_* product meta keys', 'wc-pay-what-you-want' ); ?></li>
								<li><?php esc_html_e( 'wcpwyw_analytics database table', 'wc-pay-what-you-want' ); ?></li>
								<li class="wcpwyw-uninstall__order-meta-line" style="display:none;"><?php esc_html_e( 'All _wcpwyw_* order item meta', 'wc-pay-what-you-want' ); ?></li>
							</ul>
						</div>
					</div>
				</div>

				<div class="wcpwyw-uninstall__actions">
					<button type="submit" id="wcpwyw-uninstall-btn" class="button button-primary">
						<?php esc_html_e( 'Uninstall — keep all data', 'wc-pay-what-you-want' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Cancel', 'wc-pay-what-you-want' ); ?>
					</a>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the form POST from the confirmation page.
	 */
	private static function handleFormPost(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete plugins.', 'wc-pay-what-you-want' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['_wcpwyw_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wcpwyw_uninstall' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-pay-what-you-want' ) );
		}

		$choice            = isset( $_POST['wcpwyw_uninstall_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpwyw_uninstall_choice'] ) ) : 'keep';
		$delete_order_meta = ! empty( $_POST['wcpwyw_delete_order_meta'] );
		$plugin            = isset( $_POST['_wcpwyw_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcpwyw_plugin'] ) ) : WCPWYW_BASENAME;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $choice, [ 'keep', 'delete' ], true ) ) {
			$choice = 'keep';
		}

		// Store choice in a transient so uninstall.php can read it.
		set_transient(
			'wcpwyw_uninstall_choice',
			[
				'action'            => $choice,
				'delete_order_meta' => $delete_order_meta,
			],
			60
		);

		// Store the result message for the post-uninstall notice.
		if ( 'keep' === $choice ) {
			set_transient(
				'wcpwyw_uninstall_result',
				__( 'WC Pay What You Want has been uninstalled. Data kept: settings, product meta, analytics, and order history.', 'wc-pay-what-you-want' ),
				60
			);
		} elseif ( $delete_order_meta ) {
			set_transient(
				'wcpwyw_uninstall_result',
				__( 'WC Pay What You Want has been uninstalled. Data deleted: all PWYW data including order history.', 'wc-pay-what-you-want' ),
				60
			);
		} else {
			set_transient(
				'wcpwyw_uninstall_result',
				__( 'WC Pay What You Want has been uninstalled. Data deleted: settings and product meta. Order history preserved.', 'wc-pay-what-you-want' ),
				60
			);
		}

		// Trigger the actual WordPress plugin deletion by redirecting to the standard WP delete URL.
		$nonce_delete = wp_create_nonce( 'delete-plugin_' . $plugin );
		$delete_url   = admin_url( 'plugins.php?action=delete-selected&checked[]=' . rawurlencode( $plugin ) . '&_wpnonce=' . $nonce_delete );

		wp_safe_redirect( $delete_url );
		exit;
	}

	/**
	 * Show the post-uninstall notice transient if present.
	 */
	public static function showPostUninstallNotice(): void {
		$message = get_transient( 'wcpwyw_uninstall_result' );
		if ( false === $message ) {
			return;
		}
		delete_transient( 'wcpwyw_uninstall_result' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Get live data counts for the inventory table.
	 *
	 * @return array{settings: int, products: int, orders: int, analytics: int}
	 */
	private static function getLiveCounts(): array {
		global $wpdb;

		$settings_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcpwyw_%'"
		);
		$has_wc_settings = (bool) get_option( 'woocommerce_wcpwyw_settings' );
		if ( $has_wc_settings ) {
			$settings_count++;
		}

		$products_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_wcpwyw_enabled',
				'yes'
			)
		);

		$orders_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s",
				'_wcpwyw_enabled'
			)
		);

		$analytics_table  = $wpdb->prefix . 'wcpwyw_analytics';
		$analytics_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $analytics_table ) ) === $analytics_table;
		$analytics_count  = $analytics_exists
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$analytics_table}`" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: 0;

		return [
			'settings'  => $settings_count,
			'products'  => $products_count,
			'orders'    => $orders_count,
			'analytics' => $analytics_count,
		];
	}
}
