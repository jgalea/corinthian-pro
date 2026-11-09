<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Screens\Screen;

/**
 * A member's last activity, stored by BuddyPress as a GMT time in its activity
 * table (component "members", type "last_activity"), one row per member.
 * Dates the user picks are days on the site's calendar, so they are converted to GMT.
 */
final class LastActivityType extends BpType implements Sortable, Filterable {

	public function key(): string {
		return 'bp.last_activity';
	}

	public function label(): string {
		return __( 'Last activity', 'corinthian-pro' );
	}

	public function component(): string {
		return 'members';
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$time = BpApi::last_activity( $object_id );
		return '' === $time ? null : $time;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$time = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $time ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		return esc_html( get_date_from_gmt( $time, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$table              = BpApi::last_activity_table();
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$table} AS {$alias} ON ({$alias}.user_id = {$wpdb->users}.ID AND {$alias}.component = %s AND {$alias}.type = 'last_activity')", BpApi::last_activity_component() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['groupby'] = "{$wpdb->users}.ID";
		$clauses['orderby'] = "(MAX({$alias}.date_recorded) IS NULL) ASC, MAX({$alias}.date_recorded) {$order}";
		return $clauses;
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
				'label' => __( 'in the last (days)', 'corinthian-pro' ),
				'input' => 'number',
			),
			array(
				'op'    => 'older_than_days',
				'label' => __( 'older than (days)', 'corinthian-pro' ),
				'input' => 'number',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		if ( 'within_days' === $op || 'older_than_days' === $op ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value > 36500 ) {
				return null;
			}
			$edge = gmdate( 'Y-m-d H:i:s', time() - (int) $value * DAY_IN_SECONDS );
			$cond = 'within_days' === $op
				? $wpdb->prepare( "{$alias}.date_recorded >= %s AND {$alias}.date_recorded <= %s", $edge, gmdate( 'Y-m-d H:i:s' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "{$alias}.date_recorded < %s", $edge ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( ( 'before' === $op || 'after' === $op ) && is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && wp_checkdate( (int) $m[2], (int) $m[3], (int) $m[1], $value ) ) {
			if ( 'before' === $op ) {
				$cond = $wpdb->prepare( "{$alias}.date_recorded < %s", get_gmt_from_date( $value . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$next = gmdate( 'Y-m-d', (int) strtotime( $value . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
				$cond = $wpdb->prepare( "{$alias}.date_recorded >= %s", get_gmt_from_date( $next . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		} else {
			return null;
		}

		$table = BpApi::last_activity_table();
		$where = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$table} AS {$alias} WHERE {$alias}.user_id = {$wpdb->users}.ID AND {$alias}.component = %s AND {$alias}.type = 'last_activity' AND ", BpApi::last_activity_component() ) . $cond . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'join'  => '',
			'where' => $where,
		);
	}
}
