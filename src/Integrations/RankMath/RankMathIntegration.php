<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Registry;

final class RankMathIntegration {

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
		$active = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
		return (bool) apply_filters( 'corinthian_rankmath_active', $active );
	}

	/**
	 * Rank Math keeps a per-type "add meta box" switch in its titles option; types
	 * it has no switch for count as handled when they are public.
	 */
	public static function handles_post_type( string $post_type ): bool {
		$object = '' === $post_type ? null : get_post_type_object( $post_type );
		if ( ! $object || ! $object->public ) {
			return false;
		}
		$flag = "pt_{$post_type}_add_meta_box";
		$opts = self::titles_settings();
		if ( isset( $opts[ $flag ] ) ) {
			return 'on' === $opts[ $flag ];
		}
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function titles_settings(): array {
		$opts = get_option( 'rank-math-options-titles' );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * @return list<RankMathType>
	 */
	public static function types(): array {
		return array(
			new SeoScoreType(),
			new FocusKeywordType(),
			new SeoTitleType(),
			new MetaDescriptionType(),
			new RobotsType(),
			new CanonicalUrlType(),
			new PillarContentType(),
			new PrimaryCategoryType(),
			new SchemaTypeType(),
		);
	}
}
