<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

final class WebsiteType extends TecType {

	public function key(): string {
		return 'tec.website';
	}

	public function label(): string {
		return __( 'Event website', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$url = $this->meta( $object_id, '_EventURL' );
		return '' === $url ? null : $url;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$url  = $this->value( $object_id, $config, $screen );
		$safe = is_string( $url ) ? esc_url( $url, array( 'http', 'https' ) ) : '';
		if ( '' === $safe ) {
			return parent::render( $object_id, $config, $screen );
		}
		return '<a href="' . esc_url( $safe ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $url ) . '</a>';
	}
}
