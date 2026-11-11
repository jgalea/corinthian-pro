<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use DateTimeImmutable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * An event's start or end. TEC stores the wall-clock time in the event's own
 * timezone (_EventStartDate) and the same instant in UTC (_EventStartDateUTC).
 * Before/after work on the displayed local date; "in the next N days" and the
 * sort work on the UTC instant, so events in different timezones order correctly.
 */
abstract class DateTimeType extends TecType implements Sortable, Filterable, Editable {

	private const FORMATS = array( 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s' );

	/** @return 'Start'|'End' */
	abstract protected function edge(): string;

	private function local_key(): string {
		return '_Event' . $this->edge() . 'Date';
	}

	private function utc_key(): string {
		return '_Event' . $this->edge() . 'DateUTC';
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->meta( $object_id, $this->local_key() );
		return '' === $value ? null : $value;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$local = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $local ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$format = (string) get_option( 'date_format' );
		if ( ! $this->is_all_day( $object_id ) ) {
			$format .= ' ' . get_option( 'time_format' );
		}
		return esc_html( (string) mysql2date( $format, $local ) );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_meta( $clauses, $config, $this->utc_key(), 'MIN({a}.meta_value)', $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'    => 'before',
				'label' => __( 'before', 'corinthian-pro' ),
				'input' => 'date',
			),
			array(
				'op'    => 'after',
				'label' => __( 'after', 'corinthian-pro' ),
				'input' => 'date',
			),
			array(
				'op'    => 'within_days',
				'label' => __( 'in the next (days)', 'corinthian-pro' ),
				'input' => 'number',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		if ( 'within_days' === $op ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value > 36500 ) {
				return null;
			}
			$extra = $wpdb->prepare( "{$alias}.meta_value >= %s AND {$alias}.meta_value <= %s", gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + (int) $value * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return PostFilter::wrap( PostFilter::meta_exists( $alias, $this->utc_key(), $extra ) );
		}
		if ( ( 'before' !== $op && 'after' !== $op ) || ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! wp_checkdate( (int) $m[2], (int) $m[3], (int) $m[1], $value ) ) {
			return null;
		}
		if ( 'before' === $op ) {
			$extra = $wpdb->prepare( "{$alias}.meta_value < %s", $value . ' 00:00:00' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$next  = gmdate( 'Y-m-d', (int) strtotime( $value . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
			$extra = $wpdb->prepare( "{$alias}.meta_value >= %s", $next . ' 00:00:00' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return PostFilter::wrap( PostFilter::meta_exists( $alias, $this->local_key(), $extra ) );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => 'datetime',
			'bulk'  => array( 'set' ),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$local = $this->value( $object_id, $config, $screen );
		$dt    = is_string( $local ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $local ) : false;
		return $dt ? $dt->format( 'Y-m-d\TH:i' ) : '';
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( 'set' !== $mode ) {
			return $this->bad_mode();
		}
		$refused = $this->guard( $object_id );
		if ( $refused ) {
			return $refused;
		}
		$new = $this->parse( $value );
		if ( $new instanceof WP_Error ) {
			return $new;
		}
		$start = $this->meta( $object_id, '_EventStartDate' );
		$end   = $this->meta( $object_id, '_EventEndDate' );
		if ( '' === $start || '' === $end ) {
			return $this->invalid( __( 'This event has no dates to change.', 'corinthian-pro' ) );
		}

		if ( 'Start' === $this->edge() ) {
			$start = $this->is_all_day( $object_id ) ? TecApi::day_start( substr( $new, 0, 10 ) ) : $new;
		} else {
			$end = $this->is_all_day( $object_id ) ? TecApi::day_end( substr( $new, 0, 10 ) ) : $new;
		}
		// TEC drops a date change that ends before it starts without saying so.
		if ( $end < $start ) {
			return $this->invalid( 'Start' === $this->edge() ? __( 'The start cannot be after the end.', 'corinthian-pro' ) : __( 'The end cannot be before the start.', 'corinthian-pro' ) );
		}

		// Both dates go in together so TEC recalculates the duration.
		$saved = TecApi::update_event(
			$object_id,
			array(
				'start_date' => $start,
				'end_date'   => $end,
			)
		);
		if ( ! $saved || $this->meta( $object_id, '_EventStartDate' ) !== $start || $this->meta( $object_id, '_EventEndDate' ) !== $end ) {
			return $this->failed();
		}
		return true;
	}

	private function parse( mixed $value ): string|WP_Error {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return $this->invalid( __( 'Enter a date and time.', 'corinthian-pro' ) );
		}
		$raw = trim( (string) $value );
		foreach ( self::FORMATS as $format ) {
			$dt = DateTimeImmutable::createFromFormat( '!' . $format, $raw );
			if ( $dt && $dt->format( $format ) === $raw && (int) $dt->format( 'Y' ) >= 1970 ) {
				return $dt->format( 'Y-m-d H:i:s' );
			}
		}
		return $this->invalid( __( 'Enter a date and time.', 'corinthian-pro' ) );
	}
}
