<?php

namespace CorinthianPro\Integrations\EventsCalendar;

defined( 'ABSPATH' ) || exit;

final class EndType extends DateTimeType {

	public function key(): string {
		return 'tec.end';
	}

	public function label(): string {
		return __( 'Event end', 'corinthian-pro' );
	}

	protected function edge(): string {
		return 'End';
	}
}
