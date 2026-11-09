<?php

namespace CorinthianPro\Integrations\EventsCalendar;

final class OrganizersType extends LinkedPostsType {

	public function key(): string {
		return 'tec.organizers';
	}

	public function label(): string {
		return __( 'Event organizers', 'corinthian-pro' );
	}

	protected function link_key(): string {
		return '_EventOrganizerID';
	}

	protected function linked_type(): string {
		return TecApi::ORGANIZER;
	}

	protected function multiple(): bool {
		return true;
	}

	protected function modes(): array {
		return array( 'set', 'clear', 'append', 'remove' );
	}

	protected function store( int $event_id, array $target, array $current ): bool {
		if ( ! TecApi::update_event( $event_id, array( 'organizer' => $target ) ) ) {
			return false;
		}
		$saved = $this->linked_ids( $event_id );
		sort( $saved );
		sort( $target );
		return $saved === $target;
	}
}
