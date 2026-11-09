<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Registry;

final class WpraIntegration {

	public static function register(): void {
		add_action( 'corinthian_register_types', array( self::class, 'add_types' ) );
	}

	public static function add_types( Registry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		foreach ( self::types() as $type ) {
			$registry->add( $type );
		}
	}

	public static function is_active(): bool {
		$active = defined( 'WPRA_VERSION' ) && defined( 'WPRA_V5_USE_V4' ) && ! constant( 'WPRA_V5_USE_V4' );
		return (bool) apply_filters( 'corinthian_wpra_active', $active );
	}

	/**
	 * @return list<WpraType>
	 */
	public static function types(): array {
		return array(
			new SourceType(),
			new OriginalLinkType(),
			new ImportDateType(),
			new FeedAuthorType(),
			new SourceSiteType(),
			new MediaUrlType(),
		);
	}
}
