<?php
/**
 * Plugin Name:       WC Pay What You Want
 * Plugin URI:        https://github.com/nathanonn/wc-pay-what-you-want
 * Description:       Lets customers set their own price for WooCommerce products within admin-defined boundaries.
 * Version:           1.0.7
 * Author:            Nathan Onn
 * Author URI:        https://www.nathanonn.com
 * Requires at least: 6.0
 * Tested up to: 	  6.9.1
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-pay-what-you-want
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WCPWYW_VERSION',  '1.0.7' );
define( 'WCPWYW_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WCPWYW_URL',      plugin_dir_url( __FILE__ ) );
define( 'WCPWYW_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader.
if ( file_exists( WCPWYW_DIR . 'vendor/autoload.php' ) ) {
	require_once WCPWYW_DIR . 'vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Activation / Deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, [ 'WcPwyw\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WcPwyw\\Deactivator', 'deactivate' ] );

// ---------------------------------------------------------------------------
// Global security wrapper functions (available regardless of WooCommerce status)
// ---------------------------------------------------------------------------

function wcpwyw_create_nonce( string $action ): string {
	return \WcPwyw\Security::create_nonce( $action );
}

function wcpwyw_verify_nonce( string $nonce, string $action ): bool {
	return \WcPwyw\Security::verify_nonce( $nonce, $action );
}

function wcpwyw_sanitize_price( mixed $value ): float|false {
	return \WcPwyw\Security::sanitize_price( $value );
}

function wcpwyw_sanitize_text_field( mixed $value ): string {
	return \WcPwyw\Security::sanitize_text_field( $value );
}

function wcpwyw_current_user_can( string $capability = 'manage_woocommerce' ): bool {
	return \WcPwyw\Security::current_user_can( $capability );
}

// ---------------------------------------------------------------------------
// Bootstrap on plugins_loaded
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'wcpwyw_init' );

function wcpwyw_init(): void {
	// Load text domain for translations (P6 i18n).
	load_plugin_textdomain(
		'wc-pay-what-you-want',
		false,
		dirname( WCPWYW_BASENAME ) . '/languages'
	);

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcpwyw_woocommerce_missing_notice' );
		return;
	}

	if ( is_admin() ) {
		\WcPwyw\Admin\UninstallPage::init();
	}

	\WcPwyw\Admin\Loader::init();
	\WcPwyw\Frontend\Loader::init();
	\WcPwyw\Analytics\DashboardWidget::init();
	\WcPwyw\Analytics\AlertMailer::init();
}

function wcpwyw_woocommerce_missing_notice(): void {
	if ( ! current_user_can( 'install_plugins' ) ) {
		return;
	}

	$install_url = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );

	echo '<div class="notice notice-error">';
	echo '<p>';
	echo '<strong>' . esc_html__( 'WC Pay What You Want — Missing Dependency', 'wc-pay-what-you-want' ) . '</strong>';
	echo '</p>';
	echo '<p>';
	echo esc_html__( 'WC Pay What You Want requires WooCommerce to be installed and active. Please install and activate WooCommerce, then re-activate this plugin.', 'wc-pay-what-you-want' );
	echo '</p>';
	echo '<p>';
	echo '<a href="' . esc_url( $install_url ) . '" class="button button-primary">';
	echo esc_html__( 'Install WooCommerce', 'wc-pay-what-you-want' );
	echo '</a>';
	echo '</p>';
	echo '</div>';
}
