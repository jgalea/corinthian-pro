<?php

namespace CorinthianPro\Sets;

use Corinthian\Sets\ColumnSet;

defined( 'ABSPATH' ) || exit;

final class PhpExport {

	/**
	 * PHP source that registers the given set, for pasting into a theme or plugin.
	 */
	public static function to_php( ColumnSet $set ): string {
		$data = array(
			'key'      => self::key_for( $set ),
			'screen'   => $set->screen,
			'title'    => $set->title,
			'columns'  => array_map( static fn( $c ) => $c->to_array(), $set->columns ),
			'settings' => array_diff_key( $set->settings, array_flip( array( 'readonly', 'code_key', 'imported_from' ) ) ),
		);
		// var_export output is valid PHP for these plain arrays; there are no objects in a set.
		return "add_action( 'corinthian_register_types', function () {\n\tcorinthian_register_set( " . var_export( $data, true ) . " );\n} );\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}

	private static function key_for( ColumnSet $set ): string {
		$key = sanitize_key( (string) preg_replace( '/[\s-]+/', '_', $set->title ) );
		return '' !== $key ? $key : 'set_' . $set->id;
	}
}
