<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * _yoast_wpseo_canonical: an absolute URL.
 */
final class CanonicalType extends TextType {

	public function key(): string {
		return 'yoast.canonical';
	}

	public function label(): string {
		return __( 'Canonical URL', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'canonical';
	}

	protected function advanced(): bool {
		return true;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$url = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $url ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|\WP_Error|null {
		if ( ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return null;
		}
		$url   = esc_url_raw( $raw, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! preg_match( '#^https?://#i', $raw ) ) {
			return $this->invalid( __( 'Enter a full URL starting with http:// or https://.', 'corinthian-pro' ) );
		}
		return $url;
	}
}
