<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class YoastIntegration {

	public const META_PREFIX = '_yoast_wpseo_';

	public static function register(): void {
		Indexables::register();
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
		$active = defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
		return (bool) apply_filters( 'corinthian_yoast_active', $active );
	}

	/**
	 * Yoast shows its metabox on accessible post types whose "Show SEO settings" switch is on.
	 */
	public static function handles_post_type( string $post_type ): bool {
		$object = '' === $post_type ? null : get_post_type_object( $post_type );
		if ( ! $object || ! $object->public ) {
			return false;
		}
		if ( is_callable( array( 'WPSEO_Utils', 'is_metabox_active' ) ) ) {
			return (bool) call_user_func( array( 'WPSEO_Utils', 'is_metabox_active' ), $post_type, 'post_type' );
		}
		return true;
	}

	public static function option( string $name, mixed $fallback ): mixed {
		if ( ! is_callable( array( 'WPSEO_Options', 'get' ) ) ) {
			return $fallback;
		}
		return call_user_func( array( 'WPSEO_Options', 'get' ), $name, $fallback );
	}

	/**
	 * Yoast's metabox shows the advanced and schema tabs to users holding
	 * wpseo_edit_advanced_metadata (or wpseo_manage_options, which its capability
	 * helper counts as holding every Yoast capability), or to everyone when the
	 * "security: no advanced settings for authors" switch is off.
	 */
	public static function can_edit_advanced(): bool {
		if ( current_user_can( 'wpseo_manage_options' ) || current_user_can( 'wpseo_edit_advanced_metadata' ) ) {
			return true;
		}
		return false === self::option( 'disableadvanced_meta', true );
	}

	public static function call( string $function, mixed ...$args ): mixed {
		return is_callable( $function ) ? call_user_func_array( $function, $args ) : null;
	}

	public static function service( string $class_name ): ?object {
		$main = self::call( 'YoastSEO' );
		if ( ! is_object( $main ) || ! isset( $main->classes ) || ! is_object( $main->classes ) || ! is_callable( array( $main->classes, 'get' ) ) ) {
			return null;
		}
		$service = call_user_func( array( $main->classes, 'get' ), $class_name );
		return is_object( $service ) ? $service : null;
	}

	public static function indexable_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'yoast_indexable';
	}

	/**
	 * @return list<YoastType>
	 */
	public static function types(): array {
		return array(
			new SeoScoreType(),
			new ReadabilityScoreType(),
			new FocusKeyphraseType(),
			new SeoTitleType(),
			new MetaDescriptionType(),
			new NoindexType(),
			new NofollowType(),
			new CanonicalType(),
			new CornerstoneType(),
			new PrimaryCategoryType(),
			new SchemaPageType(),
			new SchemaArticleType(),
			new OutboundLinksType(),
			new InboundLinksType(),
		);
	}
}
