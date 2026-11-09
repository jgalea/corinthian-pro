<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

/**
 * _yoast_wpseo_schema_article_type: one of Schema_Types::ARTICLE_TYPES, which
 * Yoast narrows with the wpseo_schema_article_types filter. Only content types
 * with author support have one (Yoast's Article_Helper).
 */
final class SchemaArticleType extends SchemaType {

	public function key(): string {
		return 'yoast.schema_article_type';
	}

	public function label(): string {
		return __( 'Schema article type', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'schema_article_type';
	}

	protected function default_option(): string {
		return 'schema-article-type-';
	}

	protected function types(): array {
		$all = self::from_constant( 'Yoast\WP\SEO\Config\Schema_Types::ARTICLE_TYPES' );
		/** This filter is documented in Yoast SEO, inc/class-wpseo-meta.php. */
		$allowed = apply_filters( 'wpseo_schema_article_types', $all ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter.
		return is_array( $allowed ) ? array_intersect_key( $all, $allowed ) : $all;
	}

	protected function applies_to( string $post_type ): bool {
		return post_type_supports( $post_type, 'author' );
	}
}
