<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

final class TimezoneType extends TecType {

	public function key(): string {
		return 'tec.timezone';
	}

	public function label(): string {
		return __( 'Event timezone', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$zone = TecApi::timezone( $object_id );
		return '' === $zone ? null : $zone;
	}
}
