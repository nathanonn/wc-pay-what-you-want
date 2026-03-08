<?php

namespace WcPwyw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate(): void {
		update_option( 'wcpwyw_enabled', 'no' );
		// All other options, product meta, order meta, and database tables are preserved.
		// Full data removal is handled only by the uninstall hook (P6 Hardening).
	}
}
