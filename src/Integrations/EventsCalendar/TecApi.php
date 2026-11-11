<?php

namespace CorinthianPro\Integrations\EventsCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that calls The Events Calendar. Calls go through
 * call_user_func so the plugin analyses cleanly without TEC's stubs.
 */
final class TecApi {

	public const EVENT     = 'tribe_events';
	public const VENUE     = 'tribe_venue';
	public const ORGANIZER = 'tribe_organizer';

	public const OPTION_CAP = 200;

	/**
	 * @param string|array{0:string,1:string} $callable
	 */
	private static function call( string|array $callable, mixed ...$args ): mixed {
		return \call_user_func_array( $callable, $args );
	}

	/**
	 * Saves through TEC's ORM, which keeps the UTC copies, the duration and the
	 * custom tables in step. The map flags are passed back unchanged because the
	 * ORM otherwise turns them on for events that had them off.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function update_event( int $event_id, array $args ): bool {
		if ( ! function_exists( 'tribe_events' ) ) {
			return false;
		}
		$args += array(
			'show_map'      => ! empty( get_post_meta( $event_id, '_EventShowMap', true ) ),
			'show_map_link' => ! empty( get_post_meta( $event_id, '_EventShowMapLink', true ) ),
		);
		try {
			$repository = self::call( 'tribe_events' );
			$result     = $repository->where( 'id', $event_id )->set_args( $args )->save();
		} catch ( \Throwable $e ) {
			return false;
		}
		wp_cache_delete( $event_id, 'post_meta' );
		return is_array( $result ) && true === ( $result[ $event_id ] ?? false );
	}

	public static function timezone( int $event_id ): string {
		$zone = self::call( array( 'Tribe__Events__Timezones', 'get_event_timezone_string' ), $event_id );
		return is_string( $zone ) ? $zone : '';
	}

	public static function day_start( string $date ): string {
		$out = self::call( 'tribe_beginning_of_day', $date );
		return is_string( $out ) ? $out : $date . ' 00:00:00';
	}

	public static function day_end( string $date ): string {
		$out = self::call( 'tribe_end_of_day', $date );
		return is_string( $out ) ? $out : $date . ' 23:59:59';
	}

	public static function cost( int $event_id ): string {
		$out = self::call( 'tribe_get_cost', $event_id, true );
		return is_string( $out ) ? wp_strip_all_tags( $out ) : '';
	}

	/**
	 * TEC's own check for whether a venue or organizer may be linked to an event.
	 */
	public static function linkable( string $post_type, int $id ): bool {
		if ( $id <= 0 || get_post_type( $id ) !== $post_type || 'trash' === get_post_status( $id ) || ! self::readable( $id ) ) {
			return false;
		}
		$linked = self::call( array( 'Tribe__Events__Linked_Posts', 'instance' ) );
		return (bool) $linked->is_valid_linked_post( $post_type, $id );
	}

	/**
	 * Whether the current user may see a linked post's details. TEC's own check
	 * only looks at the password cookie, so someone who can edit the post is let through.
	 */
	public static function visible( int $id ): bool {
		if ( ! self::readable( $id ) ) {
			return false;
		}
		if ( current_user_can( 'edit_post', $id ) ) {
			return true;
		}
		$linked = self::call( array( 'Tribe__Events__Linked_Posts', 'instance' ) );
		return (bool) $linked->is_linked_post_visible( $id );
	}

	/**
	 * @return array<string,string> post id => title
	 */
	public static function options( string $post_type ): array {
		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => self::OPTION_CAP,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			if ( ! self::visible( $post->ID ) ) {
				continue;
			}
			$title                     = get_the_title( $post );
			$out[ (string) $post->ID ] = '' !== $title ? $title : '#' . $post->ID;
		}
		return $out;
	}

	/**
	 * Same rule as the field plugins: a linked venue or organizer must be one
	 * the user can read, published unless they can edit it. Linking or listing
	 * an item reveals that it exists and shows its title.
	 */
	private static function readable( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'read_post', $id ) ) {
			return false;
		}
		return 'publish' === $post->post_status || current_user_can( 'edit_post', $id );
	}
}
