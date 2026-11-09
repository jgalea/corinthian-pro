<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * _EventAllDay is "yes" for all-day events and absent otherwise.
 */
final class AllDayType extends TecType implements Filterable, Editable {

	public function key(): string {
		return 'tec.all_day';
	}

	public function label(): string {
		return __( 'All-day event', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return $this->is_all_day( $object_id );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		if ( ! $this->is_all_day( $object_id ) ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}
		return '<strong>' . esc_html__( 'All day', 'corinthian-pro' ) . '</strong>';
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::yes_no_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$flag = PostFilter::flag( $value );
		if ( 'eq' !== $op || null === $flag ) {
			return null;
		}
		$on = PostFilter::meta_exists( $alias, '_EventAllDay', "{$alias}.meta_value IN ('yes','1')" );
		return PostFilter::wrap( $flag ? $on : "NOT {$on}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => 'toggle',
			'bulk'  => array( 'set' ),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		return $this->is_all_day( $object_id ) ? '1' : '0';
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( 'set' !== $mode ) {
			return $this->bad_mode();
		}
		$refused = $this->guard( $object_id );
		if ( $refused ) {
			return $refused;
		}
		$word = is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '' );
		if ( in_array( $word, array( '1', 'on', 'true', 'yes' ), true ) ) {
			$on = true;
		} elseif ( in_array( $word, array( '0', 'off', 'false', 'no' ), true ) ) {
			$on = false;
		} else {
			return $this->invalid( __( 'Choose on or off.', 'corinthian-pro' ) );
		}
		if ( $on === $this->is_all_day( $object_id ) ) {
			return true;
		}

		if ( ! $on ) {
			// The ORM ignores all_day=false, so TEC's own legacy API removes the flag the same way.
			delete_post_meta( $object_id, '_EventAllDay' );
			return true;
		}

		$start = $this->meta( $object_id, '_EventStartDate' );
		$end   = $this->meta( $object_id, '_EventEndDate' );
		if ( '' === $start || '' === $end ) {
			return $this->invalid( __( 'This event has no dates to change.', 'corinthian-pro' ) );
		}
		$saved = TecApi::update_event(
			$object_id,
			array(
				'all_day'    => true,
				'start_date' => TecApi::day_start( substr( $start, 0, 10 ) ),
				'end_date'   => TecApi::day_end( substr( $end, 0, 10 ) ),
			)
		);
		return $saved && $this->is_all_day( $object_id ) ? true : $this->failed();
	}
}
