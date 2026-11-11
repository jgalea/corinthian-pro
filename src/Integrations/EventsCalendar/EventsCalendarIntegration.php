<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class EventsCalendarIntegration {

	public static function register(): void {
		add_action( 'corinthian_register_types', array( self::class, 'add_types' ) );
	}

	public static function add_types( Registry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		foreach ( self::types() as $type ) {
			$registry->add( $type );
		}
	}

	public static function is_active(): bool {
		$active = class_exists( 'Tribe__Events__Main', false ) && function_exists( 'tribe_events' );
		return (bool) apply_filters( 'corinthian_events_calendar_active', $active );
	}

	/**
	 * @return list<TecType>
	 */
	public static function types(): array {
		return array(
			new StartType(),
			new EndType(),
			new AllDayType(),
			new TimezoneType(),
			new VenueType(),
			new OrganizersType(),
			new CostType(),
			new WebsiteType(),
			new FeaturedType(),
			new LinkedFieldType( 'tec.venue_address', __( 'Venue address', 'corinthian-pro' ), TecApi::VENUE, '_VenueAddress' ),
			new LinkedFieldType( 'tec.venue_city', __( 'Venue city', 'corinthian-pro' ), TecApi::VENUE, '_VenueCity' ),
			new LinkedFieldType( 'tec.venue_country', __( 'Venue country', 'corinthian-pro' ), TecApi::VENUE, '_VenueCountry' ),
			new LinkedFieldType( 'tec.venue_phone', __( 'Venue phone', 'corinthian-pro' ), TecApi::VENUE, '_VenuePhone' ),
			new LinkedFieldType( 'tec.organizer_email', __( 'Organizer email', 'corinthian-pro' ), TecApi::ORGANIZER, '_OrganizerEmail', true ),
			new LinkedFieldType( 'tec.organizer_phone', __( 'Organizer phone', 'corinthian-pro' ), TecApi::ORGANIZER, '_OrganizerPhone' ),
		);
	}
}
