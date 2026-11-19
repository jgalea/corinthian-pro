<?php

namespace CorinthianPro\Filters;

use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of a list view a segment keeps besides its rules: sort, search
 * and status. A null field means the segment doesn't set it, which is how
 * segments saved before these fields existed behave.
 */
final class ViewState {

	public const SEGMENT_VAR = 'corinthian_segment';
	public const NO_SEGMENT  = 'none';
	public const FIELDS      = array( 'orderby', 'order', 'search', 'status' );

	/**
	 * Query args a bare visit may carry: they pick the screen, not what it shows.
	 */
	public const NEUTRAL_ARGS = array( 'post_type', 'taxonomy', 'page', 'mode' );

	public static function status_param( Screen $screen ): string {
		return match ( $screen->object_type() ) {
			'post'    => 'post_status',
			'comment' => 'comment_status',
			'user'    => 'role',
			default   => '',
		};
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{orderby:?string,order:?string,search:?string,status:?string}
	 */
	public static function clean( array $data ): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			$value         = $data[ $field ] ?? null;
			$out[ $field ] = null === $value || ! is_scalar( $value ) ? null : self::clean_field( $field, (string) $value );
		}
		return $out;
	}

	private static function clean_field( string $field, string $value ): string {
		switch ( $field ) {
			case 'order':
				$value = strtolower( $value );
				return in_array( $value, array( 'asc', 'desc' ), true ) ? $value : '';
			case 'status':
				$value = substr( sanitize_key( $value ), 0, 50 );
				return 'all' === $value ? '' : $value;
			case 'search':
				return mb_substr( sanitize_text_field( $value ), 0, 200 );
			default:
				return substr( sanitize_text_field( $value ), 0, 100 );
		}
	}

	/**
	 * Sort, search and status of the current request.
	 *
	 * @return array{orderby:string,order:string,search:string,status:string}
	 */
	public static function from_request( Screen $screen ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only list state, cleaned by clean().
		$param = self::status_param( $screen );
		$view  = self::clean(
			array(
				'orderby' => wp_unslash( $_GET['orderby'] ?? '' ),
				'order'   => wp_unslash( $_GET['order'] ?? '' ),
				'search'  => wp_unslash( $_GET['s'] ?? '' ),
				'status'  => '' !== $param ? wp_unslash( $_GET[ $param ] ?? '' ) : '',
			)
		);
		// phpcs:enable
		if ( '' === $view['orderby'] ) {
			$view['order'] = '';
		}
		return array_map( 'strval', $view );
	}

	public static function active_segment(): string {
		return isset( $_GET[ self::SEGMENT_VAR ] ) && is_string( $_GET[ self::SEGMENT_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::SEGMENT_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
	}

	/**
	 * Whether the request names nothing about what the list shows, so a
	 * default segment may fill it in.
	 */
	public static function is_bare_request(): bool {
		return array() === array_diff( array_keys( $_GET ), self::NEUTRAL_ARGS ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the keys are read.
	}

	/**
	 * Query args that apply a segment: its rules, the view fields it sets, and its id.
	 *
	 * @param array<string,mixed> $segment Segment as SegmentsController::shape() returns it.
	 * @return array<string,string>
	 */
	public static function segment_args( Screen $screen, array $segment ): array {
		$args   = array();
		$filter = FilterSet::from( (array) ( $segment['filter'] ?? array() ) );
		if ( ! $filter->is_empty() ) {
			$args[ FilterSet::QUERY_VAR ] = (string) wp_json_encode( $filter->to_array() );
		}
		$keys = array(
			'orderby' => 'orderby',
			'order'   => 'order',
			'search'  => 's',
			'status'  => self::status_param( $screen ),
		);
		foreach ( self::clean( $segment ) as $field => $value ) {
			if ( null !== $value && '' !== $value && '' !== $keys[ $field ] ) {
				$args[ $keys[ $field ] ] = $value;
			}
		}
		$args[ self::SEGMENT_VAR ] = (string) ( $segment['id'] ?? '' );
		return $args;
	}
}
