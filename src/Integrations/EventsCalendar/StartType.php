<?php

namespace CorinthianPro\Integrations\EventsCalendar;

defined( 'ABSPATH' ) || exit;

final class StartType extends DateTimeType {

	public function key(): string {
		return 'tec.start';
	}

	public function label(): string {
		return __( 'Event start', 'corinthian-pro' );
	}

	protected function edge(): string {
		return 'Start';
	}
}
