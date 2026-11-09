<?php

namespace CorinthianPro\Integrations\Acf;

use Corinthian\Columns\Registry;

final class AcfIntegration {

	public static function register(): void {
		add_action(
			'corinthian_register_types',
			static function ( Registry $registry ): void {
				if ( function_exists( 'acf_get_field' ) ) {
					$registry->add( new AcfFieldType() );
				}
			}
		);
	}
}
