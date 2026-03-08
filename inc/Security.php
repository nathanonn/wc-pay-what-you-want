<?php

namespace WcPwyw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Security {

	/**
	 * Create a nonce for the given action.
	 */
	public static function create_nonce( string $action ): string {
		return wp_create_nonce( $action );
	}

	/**
	 * Verify a nonce for the given action.
	 * Returns true if valid (value 1 or 2), false otherwise.
	 */
	public static function verify_nonce( string $nonce, string $action ): bool {
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Sanitize a price value.
	 * Returns a float >= 0, or false if the input is non-numeric or contains HTML.
	 *
	 * @return float|false
	 */
	public static function sanitize_price( mixed $value ): float|false {
		// Strip any HTML first — if HTML was present, the value is invalid.
		$stripped = wp_strip_all_tags( (string) $value );

		if ( $stripped !== (string) $value ) {
			// HTML was present — reject the input.
			return false;
		}

		$stripped = trim( $stripped );

		if ( ! is_numeric( $stripped ) ) {
			return false;
		}

		$float = (float) $stripped;

		if ( $float < 0.0 ) {
			return false; // Negative prices are invalid.
		}

		return $float;
	}

	/**
	 * Sanitize a plain text field (strips HTML tags, normalises whitespace).
	 */
	public static function sanitize_text_field( mixed $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Check whether the current user has the given WooCommerce/WP capability.
	 * Defaults to 'manage_woocommerce'.
	 */
	public static function current_user_can( string $capability = 'manage_woocommerce' ): bool {
		return (bool) current_user_can( $capability );
	}
}
