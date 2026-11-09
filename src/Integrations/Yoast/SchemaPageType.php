<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

/**
 * _yoast_wpseo_schema_page_type: one of Schema_Types::PAGE_TYPES.
 */
final class SchemaPageType extends SchemaType {

	public function key(): string {
		return 'yoast.schema_page_type';
	}

	public function label(): string {
		return __( 'Schema page type', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'schema_page_type';
	}

	protected function default_option(): string {
		return 'schema-page-type-';
	}

	protected function types(): array {
		return self::from_constant( 'Yoast\WP\SEO\Config\Schema_Types::PAGE_TYPES' );
	}

	protected function applies_to( string $post_type ): bool {
		return true;
	}
}
