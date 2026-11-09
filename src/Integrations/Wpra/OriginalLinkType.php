<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

final class OriginalLinkType extends MetaTextType {

	public function key(): string {
		return 'wpra.link';
	}

	public function label(): string {
		return __( 'Original article link', 'corinthian-pro' );
	}

	protected function meta_key(): string {
		return WpraApi::URL;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$url = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $url ) || '' === esc_url( $url ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$shown = (string) preg_replace( '#^https?://(www\.)?#i', '', $url );
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( wp_html_excerpt( $shown, 70, '…' ) ) );
	}
}
