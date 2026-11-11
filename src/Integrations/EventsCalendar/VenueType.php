<?php

namespace CorinthianPro\Integrations\EventsCalendar;

defined( 'ABSPATH' ) || exit;

final class VenueType extends LinkedPostsType {

	public function key(): string {
		return 'tec.venue';
	}

	public function label(): string {
		return __( 'Event venue', 'corinthian-pro' );
	}

	protected function link_key(): string {
		return '_EventVenueID';
	}

	protected function linked_type(): string {
		return TecApi::VENUE;
	}

	protected function multiple(): bool {
		return false;
	}

	protected function modes(): array {
		return array( 'set', 'clear' );
	}

	protected function store( int $event_id, array $target, array $current ): bool {
		if ( $target ) {
			return TecApi::update_event( $event_id, array( 'venue' => $target[0] ) ) && $this->linked_ids( $event_id ) === $target;
		}
		// The ORM leaves the venue alone when asked for none, so the link row is dropped directly.
		delete_post_meta( $event_id, $this->link_key() );
		return true;
	}
}
