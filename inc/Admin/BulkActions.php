<?php

namespace WcPwyw\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BulkActions {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'bulk_actions-edit-product',           [ self::class, 'registerActions' ] );
		add_filter( 'handle_bulk_actions-edit-product',    [ self::class, 'handleActions' ], 10, 3 );
		add_filter( 'manage_edit-product_columns',         [ self::class, 'addColumn' ] );
		add_action( 'manage_product_posts_custom_column',  [ self::class, 'renderColumn' ], 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', [ self::class, 'registerSortableColumn' ] );
		add_action( 'admin_notices',                       [ self::class, 'adminNotice' ] );
	}

	public static function registerActions( array $actions ): array {
		$actions['wcpwyw_enable']  = __( 'Enable Pay What You Want', 'wc-pay-what-you-want' );
		$actions['wcpwyw_disable'] = __( 'Disable Pay What You Want', 'wc-pay-what-you-want' );
		return $actions;
	}

	public static function handleActions( string $redirect_to, string $action, array $post_ids ): string {
		if ( ! in_array( $action, [ 'wcpwyw_enable', 'wcpwyw_disable' ], true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return $redirect_to;
		}

		$new_value = ( 'wcpwyw_enable' === $action ) ? 'yes' : 'no';
		$changed   = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id     = (int) $post_id;
			$current_val = get_post_meta( $post_id, '_wcpwyw_enabled', true );

			// TC-402: skip already-correct products (don't double-count)
			if ( $current_val === $new_value ) {
				continue;
			}

			// TC-404: write only to parent post, not to variation children
			update_post_meta( $post_id, '_wcpwyw_enabled', $new_value );
			$changed++;
		}

		// Store result in a transient keyed to the current user (avoids multiuser collisions)
		set_transient( 'wcpwyw_bulk_notice_' . get_current_user_id(), [
			'action'  => $action,
			'changed' => $changed,
		], 30 );

		return add_query_arg( 'wcpwyw_bulk_done', '1', $redirect_to );
	}

	public static function addColumn( array $columns ): array {
		// Insert after the 'price' column if it exists
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'price' === $key ) {
				$new_columns['wcpwyw_status'] = __( 'PWYW', 'wc-pay-what-you-want' );
			}
		}
		// Fallback: if 'price' was not found, append at the end
		if ( ! isset( $new_columns['wcpwyw_status'] ) ) {
			$new_columns['wcpwyw_status'] = __( 'PWYW', 'wc-pay-what-you-want' );
		}
		return $new_columns;
	}

	public static function renderColumn( string $column, int $post_id ): void {
		if ( 'wcpwyw_status' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '<span class="wcpwyw-badge wcpwyw-badge-off">' . esc_html__( 'PWYW Off', 'wc-pay-what-you-want' ) . '</span>';
			return;
		}

		// TC-403: variable products show em-dash
		if ( $product->is_type( 'variable' ) ) {
			echo '<span class="wcpwyw-badge wcpwyw-badge-variable">&mdash;</span>';
			return;
		}

		$enabled = get_post_meta( $post_id, '_wcpwyw_enabled', true );
		if ( 'yes' === $enabled ) {
			echo '<span class="wcpwyw-badge wcpwyw-badge-on">' . esc_html__( 'PWYW On', 'wc-pay-what-you-want' ) . '</span>';
		} else {
			echo '<span class="wcpwyw-badge wcpwyw-badge-off">' . esc_html__( 'PWYW Off', 'wc-pay-what-you-want' ) . '</span>';
		}
	}

	public static function registerSortableColumn( array $columns ): array {
		$columns['wcpwyw_status'] = 'wcpwyw_status';
		return $columns;
	}

	public static function adminNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcpwyw_bulk_done'] ) ) {
			return;
		}

		$transient_key = 'wcpwyw_bulk_notice_' . get_current_user_id();
		$notice_data   = get_transient( $transient_key );

		if ( ! $notice_data ) {
			return;
		}

		delete_transient( $transient_key );

		$changed = (int) $notice_data['changed'];
		$action  = (string) $notice_data['action'];

		if ( 'wcpwyw_enable' === $action ) {
			/* translators: %d number of products */
			$message = sprintf(
				_n(
					'Pay What You Want has been enabled for %d product.',
					'Pay What You Want has been enabled for %d products.',
					$changed,
					'wc-pay-what-you-want'
				),
				$changed
			);
		} else {
			/* translators: %d number of products */
			$message = sprintf(
				_n(
					'Pay What You Want has been disabled for %d product.',
					'Pay What You Want has been disabled for %d products.',
					$changed,
					'wc-pay-what-you-want'
				),
				$changed
			);
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
