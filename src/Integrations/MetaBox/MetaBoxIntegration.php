<?php

namespace CorinthianPro\Integrations\MetaBox;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class MetaBoxIntegration {

	public static function register(): void {
		add_action(
			'corinthian_register_types',
			static function ( Registry $registry ): void {
				if ( MetaBoxApi::available() ) {
					$registry->add( new MetaBoxFieldType() );
				}
			}
		);
	}
}
