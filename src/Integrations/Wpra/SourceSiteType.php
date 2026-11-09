<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

final class SourceSiteType extends MetaTextType {

	public function key(): string {
		return 'wpra.source_site';
	}

	public function label(): string {
		return __( 'Source website', 'corinthian-pro' );
	}

	protected function meta_key(): string {
		return WpraApi::SOURCE_URL;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$url = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $url ) || '' === esc_url( $url ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( is_string( $host ) ? $host : $url ) );
	}
}
