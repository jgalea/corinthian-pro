<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

final class FeaturedType extends TecType {

	public function key(): string {
		return 'tec.featured';
	}

	public function label(): string {
		return __( 'Featured event', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return '' !== $this->meta( $object_id, '_tribe_featured' );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		if ( true !== $this->value( $object_id, $config, $screen ) ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}
		return '<strong>' . esc_html__( 'Featured', 'corinthian-pro' ) . '</strong>';
	}
}
