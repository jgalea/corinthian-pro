<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\EventsCalendar\EventsCalendarIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Screens\ScreenRegistry;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

class EventsCalendarTest extends WP_UnitTestCase {

	private const KEYS = array(
		'start',
		'end',
		'all_day',
		'timezone',
		'venue',
		'organizers',
		'cost',
		'website',
		'featured',
		'venue_address',
		'venue_city',
		'venue_country',
		'venue_phone',
		'organizer_email',
		'organizer_phone',
	);

	private Registry $registry;
	private PostTypeScreen $screen;
	private PostTypeScreen $venues;
	private PostTypeScreen $organizers;

	/** @var array<string,int> events a..g */
	private array $n = array();
	/** @var array<string,int> venues v1..v3 */
	private array $v = array();
	/** @var array<string,int> organizers o1..o3 */
	private array $o = array();

	private int $admin = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! class_exists( 'Tribe__Events__Main', false ) ) {
			$file = WP_PLUGIN_DIR . '/the-events-calendar/the-events-calendar.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'The Events Calendar is not installed. Run: wp plugin install the-events-calendar' );
			}
			// A fresh site: tell the custom tables they need no migration, as TEC's own activation does.
			update_option( 'tec_ct1_migration_state', array( 'phase' => 'migration-not-required' ) );

			// WordPress has already run plugins_loaded and init. Run only TEC's callbacks for them, not every plugin's again.
			global $wp_filter;
			$saved = array();
			foreach ( array( 'plugins_loaded', 'init' ) as $hook ) {
				$saved[ $hook ]     = $wp_filter[ $hook ] ?? null;
				$wp_filter[ $hook ] = new \WP_Hook();
			}
			require_once $file;
			do_action( 'plugins_loaded' );
			do_action( 'init' );
			foreach ( $saved as $hook => $original ) {
				if ( null === $original ) {
					unset( $wp_filter[ $hook ] );
				} else {
					$wp_filter[ $hook ] = $original;
				}
			}
			( new \Tribe__Events__Capabilities() )->set_initial_caps( true );
		}
	}

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'UTC' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		// The events list is an admin screen; TEC leaves admin queries alone, so the filters below apply.
		set_current_screen( 'edit-tribe_events' );

		$this->registry = new Registry();
		EventsCalendarIntegration::add_types( $this->registry );
		$sets             = new SetRepository();
		$this->screen     = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'tribe_events' ) );
		$this->venues     = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'tribe_venue' ) );
		$this->organizers = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'tribe_organizer' ) );

		foreach ( array( 'v1', 'v2', 'v3' ) as $name ) {
			$this->v[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => 'tribe_venue',
					'post_title'  => 'Venue ' . $name,
					'post_status' => 'publish',
				)
			);
		}
		foreach ( array( 'o1', 'o2', 'o3' ) as $name ) {
			$this->o[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => 'tribe_organizer',
					'post_title'  => 'Organizer ' . $name,
					'post_status' => 'publish',
				)
			);
		}

		$now = time();
		$day = DAY_IN_SECONDS;
		$utc = static fn( int $ts ): string => gmdate( 'Y-m-d H:i:s', $ts );

		$this->n['a'] = $this->event(
			'A',
			'2031-03-10 10:00:00',
			'2031-03-10 11:00:00',
			'America/New_York',
			array(
				'cost'      => '10',
				'venue'     => $this->v['v1'],
				'organizer' => array( $this->o['o1'] ),
				'url'       => 'https://example.com/a',
				'featured'  => true,
			)
		);
		$this->n['b'] = $this->event(
			'B',
			'2031-03-12 09:00:00',
			'2031-03-12 10:30:00',
			'Europe/Paris',
			array(
				'cost'      => '25.5',
				'venue'     => $this->v['v2'],
				'organizer' => array( $this->o['o1'], $this->o['o2'] ),
			)
		);
		$this->n['c'] = $this->event( 'C', '2031-03-12 05:00:00', '2031-03-12 06:00:00', 'America/New_York' );
		$this->n['d'] = $this->event(
			'D',
			'2031-03-15 00:00:00',
			'2031-03-15 23:59:59',
			'UTC',
			array(
				'all_day' => true,
				'cost'    => '0',
			)
		);
		$this->n['e'] = $this->event( 'E', '2020-01-01 10:00:00', '2020-01-01 11:00:00', 'UTC', array( 'cost' => '100' ) );
		$this->n['f'] = $this->event( 'F', $utc( $now + 2 * $day ), $utc( $now + 2 * $day + 3 * HOUR_IN_SECONDS ), 'UTC', array( 'cost' => '5' ) );
		$this->n['g'] = $this->event( 'G', $utc( $now + 20 * $day ), $utc( $now + 20 * $day + 3 * HOUR_IN_SECONDS ), 'UTC' );
		$this->commit();

		update_post_meta( $this->v['v1'], '_VenueAddress', '1 Main Street' );
		update_post_meta( $this->v['v1'], '_VenueCity', 'Valletta' );
		update_post_meta( $this->v['v1'], '_VenueCountry', 'Malta' );
		update_post_meta( $this->v['v1'], '_VenuePhone', '+356 2100 0000' );
		update_post_meta( $this->o['o1'], '_OrganizerEmail', 'o1@example.com' );
		update_post_meta( $this->o['o1'], '_OrganizerPhone', '+356 2100 1111' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function event( string $title, string $start, string $end, string $timezone, array $args = array() ): int {
		$post = tribe_events()
			->set_args(
				array_merge(
					array(
						'title'      => 'Event ' . $title,
						'status'     => 'publish',
						'start_date' => $start,
						'end_date'   => $end,
						'timezone'   => $timezone,
					),
					$args
				)
			)
			->create();
		$this->assertNotFalse( $post, "event $title was created" );
		return $post->ID;
	}

	/** TEC copies date changes into its custom tables when the request ends; a test never ends one. */
	private function commit(): void {
		$watcher = tribe( \TEC\Events\Custom_Tables\V1\Updates\Meta_Watcher::class );
		foreach ( $this->n as $id ) {
			$watcher->push( $id );
		}
		tribe( \TEC\Events\Custom_Tables\V1\Updates\Controller::class )->commit_updates();
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'tec.' . $key );
		$this->assertNotNull( $type, "tec.$key is registered" );
		return $type;
	}

	private function cfg( string $key ): Config {
		return new Config( 'c1', 'tec.' . $key, 'x' );
	}

	private function render( string $key, int $id, ?PostTypeScreen $screen = null ): string {
		return $this->type( $key )->render( $id, $this->cfg( $key ), $screen ?? $this->screen );
	}

	private function meta( string $name, string $key ): string {
		wp_cache_flush();
		return (string) get_post_meta( $this->n[ $name ], $key, true );
	}

	/**
	 * @param array<int|string> $ids
	 * @return list<string>
	 */
	private function names( array $ids ): array {
		$flip = array_flip( $this->n );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $ids );
	}

	/**
	 * Runs the rule through a real WP_Query, as the list screen does.
	 *
	 * @return list<string>
	 */
	private function filtered( string $key, string $op, mixed $value ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Filterable::class, $type );
		$this->assertContains( $op, array_column( $type->filter_operators( $this->cfg( $key ), $this->screen ), 'op' ), "tec.$key offers $op" );
		$sql = $type->filter_condition( $this->cfg( $key ), $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "tec.$key $op was rejected" );

		$callback = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post_type'        => 'tribe_events',
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->posts );
	}

	/**
	 * @param list<string> $expected
	 */
	private function expect( array $expected, string $key, string $op, mixed $value ): void {
		$this->assertSame( $expected, $this->filtered( $key, $op, $value ), "tec.$key $op " . wp_json_encode( $value ) );
	}

	/**
	 * @return list<string>
	 */
	private function sorted( string $key, string $order ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Sortable::class, $type );
		$config   = $this->cfg( $key );
		$callback = function ( $clauses ) use ( $type, $config, $order ) {
			global $wpdb;
			$clauses            = $type->sort_clauses( $clauses, $config, $order, $this->screen );
			$clauses['orderby'] = trim( ( $clauses['orderby'] ?? '' ) . ", {$wpdb->posts}.ID {$order}", ', ' );
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post_type'        => 'tribe_events',
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'orderby'          => 'none',
				'fields'           => 'ids',
				'nopaging'         => true,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->posts );
	}

	private function save( string $key, string $event, mixed $value, string $mode = 'set' ): bool|WP_Error {
		$type = $this->type( $key );
		$this->assertInstanceOf( Editable::class, $type );
		return $type->save( $this->n[ $event ], $this->cfg( $key ), $this->screen, $value, $mode );
	}

	private function assertRejected( bool|WP_Error $result, string $message ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( 'corinthian_invalid', $result->get_error_code(), $message );
	}

	/**
	 * @return array<string,string> every date TEC keeps for an event, local and UTC
	 */
	private function dates( string $event ): array {
		return array(
			'start'     => $this->meta( $event, '_EventStartDate' ),
			'start_utc' => $this->meta( $event, '_EventStartDateUTC' ),
			'end'       => $this->meta( $event, '_EventEndDate' ),
			'end_utc'   => $this->meta( $event, '_EventEndDateUTC' ),
			'duration'  => $this->meta( $event, '_EventDuration' ),
		);
	}

	private function occurrence( string $event, string $column ): string {
		global $wpdb;
		$this->assertContains( $column, array( 'start_date_utc', 'end_date_utc' ) );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d LIMIT 1", $this->n[ $event ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_loader_registers_every_type_only_when_tec_is_active(): void {
		$this->assertCount( 15, self::KEYS );
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'tec.' . $key ), $key );
		}

		add_filter( 'corinthian_events_calendar_active', '__return_false' );
		$empty = new Registry();
		EventsCalendarIntegration::add_types( $empty );
		$this->assertNull( $empty->get( 'tec.start' ) );
		remove_filter( 'corinthian_events_calendar_active', '__return_false' );

		EventsCalendarIntegration::register();
		$this->assertNotFalse( has_action( 'corinthian_register_types', array( EventsCalendarIntegration::class, 'add_types' ) ) );
		$this->assertTrue( EventsCalendarIntegration::is_active() );
	}

	public function test_types_belong_to_the_right_screens(): void {
		$keys = static fn( array $types ): array => array_values( array_map( static fn( $t ) => $t->key(), array_filter( $types, static fn( $t ) => str_starts_with( $t->key(), 'tec.' ) ) ) );

		$this->assertCount( 9, $keys( $this->registry->for_screen( $this->screen ) ) );
		$this->assertSame( array( 'tec.venue_address', 'tec.venue_city', 'tec.venue_country', 'tec.venue_phone' ), $keys( $this->registry->for_screen( $this->venues ) ) );
		$this->assertSame( array( 'tec.organizer_email', 'tec.organizer_phone' ), $keys( $this->registry->for_screen( $this->organizers ) ) );
		$posts = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );
		$this->assertSame( array(), $keys( $this->registry->for_screen( $posts ) ) );

		$screens = new ScreenRegistry( $this->registry, new SetRepository() );
		$this->assertNotNull( $screens->get( 'post:tribe_events' ) );
		$this->assertNotNull( $screens->get( 'post:tribe_venue' ) );
		$this->assertNotNull( $screens->get( 'post:tribe_organizer' ) );
	}

	public function test_render_dates_in_the_events_own_timezone(): void {
		$this->assertSame( '2031-03-10 10:00', $this->render( 'start', $this->n['a'] ) );
		$this->assertSame( '2031-03-10 11:00', $this->render( 'end', $this->n['a'] ) );
		$this->assertSame( '2031-03-12 09:00', $this->render( 'start', $this->n['b'] ) );
		$this->assertSame( '2031-03-15', $this->render( 'start', $this->n['d'] ), 'all-day shows the date only' );
		$this->assertSame( '2031-03-15', $this->render( 'end', $this->n['d'] ) );
		$this->assertSame( 'America/New_York', $this->render( 'timezone', $this->n['a'] ) );
		$this->assertSame( 'Europe/Paris', $this->render( 'timezone', $this->n['b'] ) );
		$this->assertSame( '2031-03-10 10:00:00', $this->type( 'start' )->value( $this->n['a'], $this->cfg( 'start' ), $this->screen ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'start', self::factory()->post->create( array( 'post_type' => 'tribe_events' ) ) ), 'an event without dates shows a dash' );
	}

	public function test_render_flags_links_and_cost(): void {
		$this->assertStringContainsString( 'All day', $this->render( 'all_day', $this->n['d'] ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'all_day', $this->n['a'] ) );
		$this->assertStringContainsString( 'Featured', $this->render( 'featured', $this->n['a'] ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'featured', $this->n['b'] ) );

		$this->assertSame( 'Venue v1', $this->render( 'venue', $this->n['a'] ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'venue', $this->n['c'] ) );
		$this->assertSame( 'Organizer o1, Organizer o2', $this->render( 'organizers', $this->n['b'] ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'organizers', $this->n['c'] ) );

		$this->assertStringContainsString( '10', $this->render( 'cost', $this->n['a'] ) );
		$this->assertStringContainsString( '25', $this->render( 'cost', $this->n['b'] ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'cost', $this->n['c'] ) );

		$link = $this->render( 'website', $this->n['a'] );
		$this->assertStringContainsString( 'href="https://example.com/a"', $link );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $link );
		update_post_meta( $this->n['b'], '_EventURL', 'javascript:alert(1)' );
		$this->assertStringNotContainsString( '<a ', $this->render( 'website', $this->n['b'] ), 'only http(s) addresses become links' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'website', $this->n['c'] ) );
	}

	public function test_render_hides_a_password_protected_venue_from_users_who_cannot_see_it(): void {
		wp_update_post(
			array(
				'ID'            => $this->v['v1'],
				'post_password' => 'secret',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'venue', $this->n['a'] ) );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'Venue v1', $this->render( 'venue', $this->n['a'] ), 'control: an editor still sees it' );
	}

	public function test_render_venue_and_organizer_details(): void {
		$this->assertSame( '1 Main Street', $this->render( 'venue_address', $this->v['v1'], $this->venues ) );
		$this->assertSame( 'Valletta', $this->render( 'venue_city', $this->v['v1'], $this->venues ) );
		$this->assertSame( 'Malta', $this->render( 'venue_country', $this->v['v1'], $this->venues ) );
		$this->assertSame( '+356 2100 0000', $this->render( 'venue_phone', $this->v['v1'], $this->venues ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'venue_city', $this->v['v2'], $this->venues ) );

		$email = $this->render( 'organizer_email', $this->o['o1'], $this->organizers );
		$this->assertStringContainsString( 'href="mailto:o1@example.com"', $email );
		$this->assertSame( '+356 2100 1111', $this->render( 'organizer_phone', $this->o['o1'], $this->organizers ) );
		update_post_meta( $this->o['o2'], '_OrganizerEmail', '<script>x</script>' );
		$this->assertStringNotContainsString( '<script>', $this->render( 'organizer_email', $this->o['o2'], $this->organizers ) );
	}

	public function test_sort_start_and_end_order_by_the_utc_instant(): void {
		// Local clocks would put c (05:00 New York) before b (09:00 Paris); as instants b comes first.
		$asc = array( 'e', 'f', 'g', 'a', 'b', 'c', 'd' );
		$this->assertSame( $asc, $this->sorted( 'start', 'ASC' ) );
		$this->assertSame( array_reverse( $asc ), $this->sorted( 'start', 'DESC' ) );
		$this->assertSame( $asc, $this->sorted( 'end', 'ASC' ) );
		$this->assertSame( array_reverse( $asc ), $this->sorted( 'end', 'DESC' ) );
	}

	public function test_sort_cost_is_numeric_with_missing_costs_last(): void {
		$this->assertSame( array( 'd', 'f', 'a', 'b', 'e', 'c', 'g' ), $this->sorted( 'cost', 'ASC' ) );
		$this->assertSame( array( 'e', 'b', 'a', 'f', 'd', 'g', 'c' ), $this->sorted( 'cost', 'DESC' ) );
	}

	public function test_sort_is_offered_only_where_it_exists(): void {
		foreach ( array( 'start', 'end', 'cost' ) as $key ) {
			$this->assertInstanceOf( Sortable::class, $this->type( $key ) );
		}
		foreach ( array( 'all_day', 'timezone', 'venue', 'organizers', 'website', 'featured' ) as $key ) {
			$this->assertNotInstanceOf( Sortable::class, $this->type( $key ), $key );
		}
	}

	public function test_filter_start_before_after_and_within_days(): void {
		$this->expect( array( 'a', 'e', 'f', 'g' ), 'start', 'before', '2031-03-12' );
		$this->expect( array(), 'start', 'before', '2020-01-01' );
		$this->expect( array( 'b', 'c', 'd' ), 'start', 'after', '2031-03-10' );
		$this->expect( array( 'd' ), 'start', 'after', '2031-03-12' );
		$this->expect( array(), 'start', 'after', '2031-03-15' );
		$this->expect( array( 'f' ), 'start', 'within_days', '3' );
		$this->expect( array( 'f', 'g' ), 'start', 'within_days', 30 );
		$this->expect( array(), 'start', 'within_days', '1' );
	}

	public function test_filter_end_before_after_and_within_days(): void {
		$this->expect( array( 'a', 'e', 'f', 'g' ), 'end', 'before', '2031-03-12' );
		$this->expect( array(), 'end', 'before', '2020-01-01' );
		$this->expect( array( 'd' ), 'end', 'after', '2031-03-12' );
		$this->expect( array(), 'end', 'after', '2031-03-15' );
		$this->expect( array( 'f' ), 'end', 'within_days', '3' );
		$this->expect( array( 'f', 'g' ), 'end', 'within_days', '30' );
		$this->expect( array(), 'end', 'within_days', '1' );
	}

	public function test_date_filters_reject_bad_input(): void {
		foreach ( array( 'start', 'end' ) as $key ) {
			$type = $this->type( $key );
			foreach ( array(
				array( 'before', '2031-02-30' ),
				array( 'before', 'tomorrow' ),
				array( 'before', '' ),
				array( 'after', array( '2031-03-10' ) ),
				array( 'within_days', '-1' ),
				array( 'within_days', 'x' ),
				array( 'within_days', '99999' ),
				array( 'eq', '2031-03-10' ),
			) as [ $op, $value ] ) {
				$this->assertNull( $type->filter_condition( $this->cfg( $key ), $op, $value, $this->screen, 'clf0' ), "$key $op " . wp_json_encode( $value ) );
			}
		}
	}

	public function test_filter_all_day(): void {
		$this->expect( array( 'd' ), 'all_day', 'eq', '1' );
		$this->expect( array( 'a', 'b', 'c', 'e', 'f', 'g' ), 'all_day', 'eq', '0' );
		$this->assertNull( $this->type( 'all_day' )->filter_condition( $this->cfg( 'all_day' ), 'eq', 'maybe', $this->screen, 'clf0' ) );
		update_post_meta( $this->n['e'], '_EventAllDay', '1' );
		$this->expect( array( 'd', 'e' ), 'all_day', 'eq', '1' );
	}

	public function test_filter_venue(): void {
		$this->expect( array( 'a' ), 'venue', 'in', array( $this->v['v1'] ) );
		$this->expect( array( 'a', 'b' ), 'venue', 'in', array( $this->v['v1'], $this->v['v2'] ) );
		$this->expect( array( 'b', 'c', 'd', 'e', 'f', 'g' ), 'venue', 'not_in', array( $this->v['v1'] ) );
		$this->expect( array(), 'venue', 'in', array( $this->v['v3'] ) );
		$this->assertNull( $this->type( 'venue' )->filter_condition( $this->cfg( 'venue' ), 'in', array(), $this->screen, 'clf0' ) );
		$options = $this->type( 'venue' )->filter_operators( $this->cfg( 'venue' ), $this->screen )[0]['options'];
		$this->assertSame( 'Venue v1', $options[ (string) $this->v['v1'] ] );
	}

	public function test_filter_organizers(): void {
		$this->expect( array( 'a', 'b' ), 'organizers', 'in', array( $this->o['o1'] ) );
		$this->expect( array( 'b' ), 'organizers', 'in', array( $this->o['o2'] ) );
		$this->expect( array( 'c', 'd', 'e', 'f', 'g' ), 'organizers', 'not_in', array( $this->o['o1'] ) );
		$this->expect( array(), 'organizers', 'in', array( $this->o['o3'] ) );
	}

	public function test_filter_cost(): void {
		$this->expect( array( 'a' ), 'cost', 'eq', '10' );
		$this->expect( array( 'b', 'e' ), 'cost', 'gt', '20' );
		$this->expect( array( 'd', 'f' ), 'cost', 'lt', '10' );
		$this->expect( array( 'a', 'b', 'f' ), 'cost', 'between', array( 5, 30 ) );
		$this->expect( array( 'c', 'd', 'g' ), 'cost', 'empty', null );
		$this->expect( array(), 'cost', 'eq', '999' );
		$this->assertNull( $this->type( 'cost' )->filter_condition( $this->cfg( 'cost' ), 'gt', 'free', $this->screen, 'clf0' ) );
	}

	public function test_edit_start_round_trip_keeps_utc_and_duration_in_sync(): void {
		$type = $this->type( 'start' );
		$this->assertSame( '2031-03-10T10:00', $type->edit_value( $this->n['a'], $this->cfg( 'start' ), $this->screen ) );

		$this->assertTrue( $this->save( 'start', 'a', '2031-03-10T08:30' ) );
		$this->commit();
		$this->assertSame(
			array(
				'start'     => '2031-03-10 08:30:00',
				'start_utc' => '2031-03-10 12:30:00',
				'end'       => '2031-03-10 11:00:00',
				'end_utc'   => '2031-03-10 15:00:00',
				'duration'  => '9000',
			),
			$this->dates( 'a' )
		);
		$this->assertSame( '2031-03-10T08:30', $type->edit_value( $this->n['a'], $this->cfg( 'start' ), $this->screen ) );
		$this->assertSame( '2031-03-10 08:30', $this->render( 'start', $this->n['a'] ) );
		$this->assertSame( '2031-03-10 12:30:00', $this->occurrence( 'a', 'start_date_utc' ), "TEC's custom table follows" );

		$this->assertTrue( $this->save( 'start', 'b', '2031-03-12 07:00:00' ), 'a space and seconds are fine too' );
		$this->assertSame( '2031-03-12 06:00:00', $this->meta( 'b', '_EventStartDateUTC' ), 'Paris is one hour ahead of UTC' );
	}

	public function test_edit_end_round_trip_keeps_utc_and_duration_in_sync(): void {
		$this->assertTrue( $this->save( 'end', 'b', '2031-03-12T11:00' ) );
		$this->commit();
		$this->assertSame(
			array(
				'start'     => '2031-03-12 09:00:00',
				'start_utc' => '2031-03-12 08:00:00',
				'end'       => '2031-03-12 11:00:00',
				'end_utc'   => '2031-03-12 10:00:00',
				'duration'  => '7200',
			),
			$this->dates( 'b' )
		);
		$this->assertSame( '2031-03-12 10:00:00', $this->occurrence( 'b', 'end_date_utc' ) );
	}

	public function test_editing_dates_leaves_other_event_settings_alone(): void {
		update_post_meta( $this->n['a'], '_EventShowMap', '' );
		update_post_meta( $this->n['a'], '_EventShowMapLink', '' );
		$this->assertTrue( $this->save( 'start', 'a', '2031-03-10T09:00' ) );
		$this->assertSame( '', $this->meta( 'a', '_EventShowMap' ) );
		$this->assertSame( '', $this->meta( 'a', '_EventShowMapLink' ) );
		$this->assertSame( 'America/New_York', $this->meta( 'a', '_EventTimezone' ) );
		$this->assertSame( '1', $this->meta( 'a', '_tribe_featured' ) );
		$this->assertSame( (string) $this->v['v1'], $this->meta( 'a', '_EventVenueID' ) );
		$this->assertSame( 'Event A', get_post( $this->n['a'] )->post_title );
	}

	public function test_editing_an_all_day_event_snaps_to_whole_days(): void {
		$this->assertTrue( $this->save( 'start', 'd', '2031-03-14T15:00' ) );
		$this->assertSame( '2031-03-14 00:00:00', $this->meta( 'd', '_EventStartDate' ) );
		$this->assertSame( '2031-03-14 00:00:00', $this->meta( 'd', '_EventStartDateUTC' ) );
		$this->assertTrue( $this->save( 'end', 'd', '2031-03-16T08:00' ) );
		$this->assertSame( '2031-03-16 23:59:59', $this->meta( 'd', '_EventEndDate' ) );
		$this->assertSame( '2031-03-16 23:59:59', $this->meta( 'd', '_EventEndDateUTC' ) );
		$this->assertSame( 'yes', $this->meta( 'd', '_EventAllDay' ) );
	}

	public function test_edit_dates_rejects_invalid_input_and_changes_nothing(): void {
		$before = $this->dates( 'a' );
		foreach ( array(
			array( 'start', '' ),
			array( 'start', null ),
			array( 'start', 'garbage' ),
			array( 'start', '2031-02-30T10:00' ),
			array( 'start', '2031-03-10' ),
			array( 'start', '2031-03-10T25:00' ),
			array( 'start', '1969-12-31T10:00' ),
			array( 'start', array( '2031-03-10T10:00' ) ),
			array( 'start', '2031-03-10T12:00' ),
			array( 'end', '2031-03-10T09:00' ),
			array( 'end', 'soon' ),
		) as [ $key, $value ] ) {
			$this->assertRejected( $this->save( $key, 'a', $value ), "$key " . wp_json_encode( $value ) );
		}
		$this->assertSame( $before, $this->dates( 'a' ) );

		foreach ( array( 'clear', 'append', 'remove' ) as $mode ) {
			$this->assertWPError( $this->save( 'start', 'a', '2031-03-10T09:00', $mode ), $mode );
		}
		$this->assertSame( $before, $this->dates( 'a' ) );

		$post = self::factory()->post->create();
		$this->assertRejected( $this->type( 'start' )->save( $post, $this->cfg( 'start' ), $this->screen, '2031-03-10T09:00' ), 'a post is not an event' );
	}

	public function test_edit_all_day_round_trip(): void {
		$type = $this->type( 'all_day' );
		$this->assertSame( '0', $type->edit_value( $this->n['a'], $this->cfg( 'all_day' ), $this->screen ) );

		$this->assertTrue( $this->save( 'all_day', 'a', '1' ) );
		$this->commit();
		$this->assertSame( 'yes', $this->meta( 'a', '_EventAllDay' ) );
		$this->assertSame(
			array(
				'start'     => '2031-03-10 00:00:00',
				'start_utc' => '2031-03-10 04:00:00',
				'end'       => '2031-03-10 23:59:59',
				'end_utc'   => '2031-03-11 03:59:59',
				'duration'  => '86399',
			),
			$this->dates( 'a' )
		);
		$this->assertSame( '1', $type->edit_value( $this->n['a'], $this->cfg( 'all_day' ), $this->screen ) );
		$this->assertSame( '2031-03-10 04:00:00', $this->occurrence( 'a', 'start_date_utc' ) );

		$this->assertTrue( $this->save( 'all_day', 'a', '0' ) );
		$this->assertSame( '', $this->meta( 'a', '_EventAllDay' ) );
		$this->assertSame( '0', $type->edit_value( $this->n['a'], $this->cfg( 'all_day' ), $this->screen ) );
		$this->assertTrue( $this->save( 'all_day', 'a', false ), 'already off is a no-op' );
		$this->assertTrue( $this->save( 'all_day', 'a', true ) );
		$this->assertSame( 'yes', $this->meta( 'a', '_EventAllDay' ) );
	}

	public function test_edit_all_day_rejects_invalid_input(): void {
		foreach ( array( 'maybe', '', null, array( '1' ), 2 ) as $value ) {
			$this->assertRejected( $this->save( 'all_day', 'a', $value ), wp_json_encode( $value ) );
		}
		$this->assertSame( '', $this->meta( 'a', '_EventAllDay' ) );
		$this->assertWPError( $this->save( 'all_day', 'a', '1', 'clear' ) );
	}

	public function test_edit_venue_round_trip(): void {
		$type = $this->type( 'venue' );
		$this->assertSame( (string) $this->v['v1'], $type->edit_value( $this->n['a'], $this->cfg( 'venue' ), $this->screen ) );
		$this->assertSame( '', $type->edit_value( $this->n['c'], $this->cfg( 'venue' ), $this->screen ) );

		$this->assertTrue( $this->save( 'venue', 'a', (string) $this->v['v2'] ) );
		$this->assertSame( (string) $this->v['v2'], $this->meta( 'a', '_EventVenueID' ) );
		$this->assertSame( 'Venue v2', $this->render( 'venue', $this->n['a'] ) );
		$this->assertTrue( $this->save( 'venue', 'c', $this->v['v3'] ), 'an event with no venue gets one' );
		$this->assertSame( (string) $this->v['v3'], $this->meta( 'c', '_EventVenueID' ) );

		$this->assertTrue( $this->save( 'venue', 'a', '' ) );
		$this->assertSame( array(), get_post_meta( $this->n['a'], '_EventVenueID', false ) );
		$this->assertTrue( $this->save( 'venue', 'b', null, 'clear' ) );
		$this->assertSame( array(), get_post_meta( $this->n['b'], '_EventVenueID', false ) );
		$this->assertSame( (string) $this->v['v3'], $this->meta( 'c', '_EventVenueID' ), 'other events are untouched' );

		$edit = $type->edit_field( $this->cfg( 'venue' ), $this->screen );
		$this->assertSame( 'select', $edit['input'] );
		$this->assertSame( 'Venue v1', $edit['options'][ (string) $this->v['v1'] ] );
	}

	public function test_edit_venue_rejects_invalid_input(): void {
		$trashed = self::factory()->post->create(
			array(
				'post_type'   => 'tribe_venue',
				'post_status' => 'trash',
			)
		);
		foreach ( array( 'abc', '0', '-3', '999999', $this->o['o1'], $trashed, array( $this->v['v1'], $this->v['v2'] ), array( 'x' ), '1.5' ) as $value ) {
			$this->assertRejected( $this->save( 'venue', 'a', $value ), wp_json_encode( $value ) );
		}
		$this->assertSame( (string) $this->v['v1'], $this->meta( 'a', '_EventVenueID' ) );
		$this->assertWPError( $this->save( 'venue', 'a', (string) $this->v['v2'], 'append' ) );
	}

	public function test_edit_organizers_round_trip(): void {
		$type = $this->type( 'organizers' );
		$ids  = function ( string $event ): array {
			wp_cache_flush();
			$out = array_map( 'intval', get_post_meta( $this->n[ $event ], '_EventOrganizerID', false ) );
			sort( $out );
			return $out;
		};
		$edit = $type->edit_field( $this->cfg( 'organizers' ), $this->screen );
		$this->assertSame( 'multiselect', $edit['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $edit['bulk'] );
		$this->assertSame( array( (string) $this->o['o1'], (string) $this->o['o2'] ), $type->edit_value( $this->n['b'], $this->cfg( 'organizers' ), $this->screen ) );

		$this->assertTrue( $this->save( 'organizers', 'a', array( (string) $this->o['o2'], (string) $this->o['o3'] ) ) );
		$this->assertSame( array( $this->o['o2'], $this->o['o3'] ), $ids( 'a' ) );

		$this->assertTrue( $this->save( 'organizers', 'a', array( $this->o['o1'] ), 'append' ) );
		$this->assertSame( array( $this->o['o1'], $this->o['o2'], $this->o['o3'] ), $ids( 'a' ) );

		$this->assertTrue( $this->save( 'organizers', 'a', array( $this->o['o2'] ), 'remove' ) );
		$this->assertSame( array( $this->o['o1'], $this->o['o3'] ), $ids( 'a' ) );

		$this->assertTrue( $this->save( 'organizers', 'a', null, 'clear' ) );
		$this->assertSame( array(), $ids( 'a' ) );

		$this->assertTrue( $this->save( 'organizers', 'c', (string) $this->o['o3'] ), 'a single id is accepted' );
		$this->assertSame( array( $this->o['o3'] ), $ids( 'c' ) );
		$this->assertTrue( $this->save( 'organizers', 'c', array() ), 'an empty set clears' );
		$this->assertSame( array(), $ids( 'c' ) );
		$this->assertSame( array( $this->o['o1'], $this->o['o2'] ), $ids( 'b' ), 'other events are untouched' );
	}

	public function test_edit_organizers_rejects_invalid_input(): void {
		foreach ( array( array( 'x' ), array( $this->v['v1'] ), array( $this->o['o1'], 'nope' ), array( '999999' ), array( array( 1 ) ) ) as $value ) {
			$this->assertRejected( $this->save( 'organizers', 'a', $value ), wp_json_encode( $value ) );
		}
		$this->assertSame( array( (string) $this->o['o1'] ), array_map( 'strval', get_post_meta( $this->n['a'], '_EventOrganizerID', false ) ) );
		$this->assertWPError( $this->save( 'organizers', 'a', array( $this->o['o2'] ), 'toggle' ) );
	}

	public function test_a_user_without_the_events_capability_is_refused_on_every_editable_column(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$before = $this->dates( 'a' );

		$attempts = array(
			array( 'start', '2031-03-10T09:00' ),
			array( 'end', '2031-03-10T12:00' ),
			array( 'all_day', '1' ),
			array( 'venue', (string) $this->v['v2'] ),
			array( 'organizers', array( $this->o['o2'] ) ),
		);
		foreach ( $attempts as [ $key, $value ] ) {
			$result = $this->save( $key, 'a', $value );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $key );
		}
		$this->assertSame( $before, $this->dates( 'a' ) );
		$this->assertSame( '', $this->meta( 'a', '_EventAllDay' ) );
		$this->assertSame( (string) $this->v['v1'], $this->meta( 'a', '_EventVenueID' ) );
		$this->assertSame( (string) $this->o['o1'], $this->meta( 'a', '_EventOrganizerID' ) );

		wp_set_current_user( $this->admin );
		foreach ( $attempts as [ $key, $value ] ) {
			$this->assertTrue( $this->save( $key, 'a', $value ), "control: an editor may change $key" );
		}
	}

	public function test_an_author_may_edit_their_own_events_but_not_other_peoples(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'edit_tribe_events' ), 'authors hold the host capability, so only the event itself can refuse' );

		$result = $this->save( 'start', 'a', '2031-03-10T09:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'corinthian_protected', $result->get_error_code() );
		$this->assertSame( '2031-03-10 10:00:00', $this->meta( 'a', '_EventStartDate' ) );

		wp_update_post(
			array(
				'ID'          => $this->n['a'],
				'post_author' => $author,
			)
		);
		$this->assertTrue( $this->save( 'start', 'a', '2031-03-10T09:00' ), 'control: their own event' );
		$this->assertSame( '2031-03-10 09:00:00', $this->meta( 'a', '_EventStartDate' ) );
	}

	public function test_private_venue_is_neither_linkable_nor_listed_for_a_user_who_cannot_read_it(): void {
		$private = self::factory()->post->create(
			array(
				'post_type'   => 'tribe_venue',
				'post_status' => 'private',
				'post_title'  => 'Secret venue',
				'post_author' => $this->admin,
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( \CorinthianPro\Integrations\EventsCalendar\TecApi::linkable( 'tribe_venue', $private ), 'Control: the owner can link it.' );
		$this->assertArrayHasKey( (string) $private, \CorinthianPro\Integrations\EventsCalendar\TecApi::options( 'tribe_venue' ) );

		wp_set_current_user( $author );
		$this->assertFalse( \CorinthianPro\Integrations\EventsCalendar\TecApi::linkable( 'tribe_venue', $private ) );
		$this->assertArrayNotHasKey( (string) $private, \CorinthianPro\Integrations\EventsCalendar\TecApi::options( 'tribe_venue' ), 'Its title must not appear in the options.' );
	}
}
