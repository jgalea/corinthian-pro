<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * When WPRA imported the item. It writes an ISO 8601 string (2031-03-10T09:00:00+00:00)
 * from PHP's clock, which WordPress keeps on UTC, so the first 19 characters sort and
 * compare as UTC wall-clock time.
 */
final class ImportDateType extends MetaTextType {

	private const FORMAT = 'Y-m-d\TH:i:s';

	public function key(): string {
		return 'wpra.imported';
	}

	public function label(): string {
		return __( 'Import date', 'corinthian-pro' );
	}

	protected function meta_key(): string {
		return WpraApi::IMPORT_DATE;
	}

	protected function sort_expression(): string {
		return 'MAX(LEFT({a}.meta_value, 19))';
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$value = $this->value( $object_id, $config, $screen );
		$ts    = is_string( $value ) ? strtotime( $value ) : false;
		if ( false === $ts ) {
			return parent::render( $object_id, $config, $screen );
		}
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::date_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$col = "LEFT({$alias}.meta_value, 19)";

		if ( 'within_days' === $op || 'older_than_days' === $op ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value > 36500 ) {
				return null;
			}
			$edge = gmdate( self::FORMAT, time() - (int) $value * DAY_IN_SECONDS );
			if ( 'older_than_days' === $op ) {
				return $this->exists( $alias, $wpdb->prepare( "{$col} < %s", $edge ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $this->exists( $alias, $wpdb->prepare( "{$col} >= %s AND {$col} <= %s", $edge, gmdate( self::FORMAT ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ( 'before' !== $op && 'after' !== $op ) || ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! wp_checkdate( (int) $m[2], (int) $m[3], (int) $m[1], $value ) ) {
			return null;
		}
		if ( 'before' === $op ) {
			$edge = get_gmt_from_date( $value . ' 00:00:00', self::FORMAT );
			return $this->exists( $alias, $wpdb->prepare( "{$col} < %s", $edge ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$next = gmdate( 'Y-m-d', (int) strtotime( $value . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$edge = get_gmt_from_date( $next . ' 00:00:00', self::FORMAT );
		return $this->exists( $alias, $wpdb->prepare( "{$col} >= %s", $edge ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array{join:string,where:string}|null
	 */
	private function exists( string $alias, string $extra ): ?array {
		return PostFilter::wrap( PostFilter::meta_exists( $alias, $this->meta_key(), $extra ) );
	}
}
