<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class SeoPressIntegration {

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
		$active = defined( 'SEOPRESS_VERSION' );
		return (bool) apply_filters( 'corinthian_seopress_active', $active );
	}

	/**
	 * SEOPress shows its metabox on the post types it lists, which the
	 * seopress_metaboxe_seo filter can narrow.
	 */
	public static function handles_post_type( string $post_type ): bool {
		$object = '' === $post_type ? null : get_post_type_object( $post_type );
		if ( ! $object || ! $object->public ) {
			return false;
		}
		$service = self::call( 'seopress_get_service', 'WordPressData' );
		if ( ! is_object( $service ) || ! is_callable( array( $service, 'getPostTypes' ) ) ) {
			return true;
		}
		$types = apply_filters( 'seopress_metaboxe_seo', call_user_func( array( $service, 'getPostTypes' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SEOPress's own filter.
		return is_array( $types ) && isset( $types[ $post_type ] );
	}

	/**
	 * SEOPress's Advanced > Security setting can block a role from the SEO
	 * metabox ('GLOBAL') or from the content analysis ('CONTENT_ANALYSIS').
	 * Super admins are never blocked.
	 */
	public static function role_blocked( string $area ): bool {
		return true === self::call( 'seopress_metabox_role_is_blocked', $area );
	}

	public static function call( string $function, mixed ...$args ): mixed {
		return is_callable( $function ) ? call_user_func_array( $function, $args ) : null;
	}

	/**
	 * @return list<SeoPressType>
	 */
	public static function types(): array {
		return array(
			new TitleType(),
			new MetaDescriptionType(),
			new TargetKeywordsType(),
			new NoindexType(),
			new NofollowType(),
			new CanonicalType(),
			new PrimaryCategoryType(),
		);
	}
}
