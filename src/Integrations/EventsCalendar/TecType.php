<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;
use WP_Error;

abstract class TecType extends AbstractType {

	public function group(): string {
		return __( 'The Events Calendar', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && TecApi::EVENT === $screen->subtype();
	}

	/**
	 * TEC saves event meta only for users with edit_tribe_events (Meta\Context),
	 * on top of the usual edit_post check for the event itself.
	 */
	protected function guard( int $event_id ): ?WP_Error {
		if ( TecApi::EVENT !== get_post_type( $event_id ) ) {
			return $this->invalid( __( 'This is not an event.', 'corinthian-pro' ) );
		}
		if ( ! current_user_can( 'edit_tribe_events' ) || ! current_user_can( 'edit_post', $event_id ) ) {
			return new WP_Error( 'corinthian_protected', __( 'You cannot change this event.', 'corinthian-pro' ) );
		}
		return null;
	}

	protected function invalid( string $message ): WP_Error {
		return new WP_Error( 'corinthian_invalid', $message );
	}

	protected function bad_mode(): WP_Error {
		return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that edit.', 'corinthian-pro' ) );
	}

	protected function failed(): WP_Error {
		return new WP_Error( 'corinthian_tec_failed', __( 'The Events Calendar could not save this change.', 'corinthian-pro' ) );
	}

	protected function meta( int $event_id, string $key ): string {
		$value = get_post_meta( $event_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	protected function is_all_day( int $event_id ): bool {
		return in_array( $this->meta( $event_id, '_EventAllDay' ), array( 'yes', '1' ), true );
	}

	/**
	 * Sort by $expr (with {a} standing for the join alias), events without a
	 * value last. $on narrows the join and must be a fixed string.
	 *
	 * @param array<string,string> $clauses
	 * @param 'ASC'|'DESC'         $order
	 * @return array<string,string>
	 */
	protected function sort_meta( array $clauses, Config $config, string $key, string $expr, string $order, string $on = '' ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$expr               = str_replace( '{a}', $alias, $expr );
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s{$on})", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		$clauses['orderby'] = "(MIN({$alias}.meta_value) IS NULL) ASC, {$expr} {$order}";
		return $clauses;
	}
}
