<?php

namespace CorinthianPro\Integrations\Pods;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class PodsIntegration {

	public static function register(): void {
		add_action(
			'corinthian_register_types',
			static function ( Registry $registry ): void {
				if ( PodsApi::available() ) {
					$registry->add( new PodsFieldType() );
				}
			}
		);
	}
}
