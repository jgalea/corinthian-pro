<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * Venues and organizers linked to an event through _EventVenueID and
 * _EventOrganizerID, one meta row per linked post.
 */
abstract class LinkedPostsType extends TecType implements Filterable, Editable {

	abstract protected function link_key(): string;

	abstract protected function linked_type(): string;

	abstract protected function multiple(): bool;

	/**
	 * @return list<string>
	 */
	abstract protected function modes(): array;

	/**
	 * @return list<int>
	 */
	protected function linked_ids( int $event_id ): array {
		$ids = array();
		foreach ( get_post_meta( $event_id, $this->link_key(), false ) as $id ) {
			if ( is_scalar( $id ) && absint( $id ) > 0 ) {
				$ids[] = absint( $id );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$titles = array();
		foreach ( $this->linked_ids( $object_id ) as $id ) {
			if ( TecApi::visible( $id ) ) {
				$title    = get_the_title( $id );
				$titles[] = '' !== $title ? $title : '#' . $id;
			}
		}
		return $titles ? $titles : null;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( TecApi::options( $this->linked_type() ) );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		return PostFilter::meta_ids( $this->link_key(), static fn() => array() )->condition( $config, $op, $value, $screen, $alias );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => $this->multiple() ? 'multiselect' : 'select',
			'options' => TecApi::options( $this->linked_type() ),
			'bulk'    => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$ids = array_map( 'strval', $this->linked_ids( $object_id ) );
		return $this->multiple() ? $ids : ( $ids[0] ?? '' );
	}

	/**
	 * Turns the submitted value into a clean list of linkable post ids.
	 *
	 * @return list<int>|WP_Error
	 */
	protected function ids_from( mixed $value ): array|WP_Error {
		$items = null === $value || '' === $value ? array() : ( is_array( $value ) ? $value : array( $value ) );
		$ids   = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
			}
			if ( '' === (string) $item ) {
				continue;
			}
			if ( ! ctype_digit( (string) $item ) || ! TecApi::linkable( $this->linked_type(), (int) $item ) ) {
				return $this->invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
			}
			$ids[] = (int) $item;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $this->multiple() && count( $ids ) > 1 ) {
			return $this->invalid( __( 'Choose one option.', 'corinthian-pro' ) );
		}
		return $ids;
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( ! in_array( $mode, $this->modes(), true ) ) {
			return $this->bad_mode();
		}
		$refused = $this->guard( $object_id );
		if ( $refused ) {
			return $refused;
		}
		$ids = 'clear' === $mode ? array() : $this->ids_from( $value );
		if ( $ids instanceof WP_Error ) {
			return $ids;
		}
		$current = $this->linked_ids( $object_id );
		$target  = match ( $mode ) {
			'append' => array_values( array_unique( array_merge( $current, $ids ) ) ),
			'remove' => array_values( array_diff( $current, $ids ) ),
			default  => $ids,
		};
		if ( $target === $current ) {
			return true;
		}
		return $this->store( $object_id, $target, $current ) ? true : $this->failed();
	}

	/**
	 * @param list<int> $target
	 * @param list<int> $current
	 */
	abstract protected function store( int $event_id, array $target, array $current ): bool;
}
