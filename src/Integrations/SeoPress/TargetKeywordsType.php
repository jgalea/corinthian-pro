<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * _seopress_analysis_target_kw: target keywords joined with commas. The
 * content analysis security area governs it, not the SEO metabox one.
 */
final class TargetKeywordsType extends TextType {

	public function key(): string {
		return 'seopress.target_keywords';
	}

	public function label(): string {
		return __( 'Target keywords', 'corinthian-pro' );
	}

	protected function meta_key( Config $config, string $post_type ): string {
		return '_seopress_analysis_target_kw';
	}

	protected function area(): string {
		return 'CONTENT_ANALYSIS';
	}

	/**
	 * @return list<string>
	 */
	private static function split( string $keywords ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ), static fn( $word ) => '' !== $word ) );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$value = $this->value( $object_id, $config, $screen );
		$words = is_string( $value ) ? self::split( $value ) : array();
		return $words ? esc_html( implode( ', ', $words ) ) : parent::render( $object_id, $config, $screen );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return is_string( $value ) ? implode( ', ', self::split( $value ) ) : null;
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|\WP_Error|null {
		$text = $this->plain( $value, true );
		if ( $text instanceof \WP_Error ) {
			return $text;
		}
		$seen  = array();
		$words = array();
		foreach ( self::split( $text ) as $word ) {
			if ( ! isset( $seen[ strtolower( $word ) ] ) ) {
				$seen[ strtolower( $word ) ] = true;
				$words[]                     = $word;
			}
		}
		return $words ? implode( ',', $words ) : null;
	}
}
