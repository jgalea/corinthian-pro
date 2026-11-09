<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\BuddyPress\BuddyPressIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_UnitTestCase;
use WP_User_Query;

class BuddyPressTest extends WP_UnitTestCase {

	private const KEYS = array( 'last_activity', 'xprofile', 'member_type', 'groups_count', 'friends_count', 'account_status' );

	private Registry $registry;
	private UserScreen $screen;

	/** @var array<string,int> users u1..u5 */
	private array $u = array();
	/** @var array<string,int> xprofile fields */
	private array $f = array();

	private int $admin  = 0;
	private int $editor = 0;
	private int $viewer = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! function_exists( 'buddypress' ) ) {
			$file = WP_PLUGIN_DIR . '/buddypress/bp-loader.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'BuddyPress is not installed. Run: wp plugin install buddypress' );
			}
			$components = array(
				'members'  => 1,
				'xprofile' => 1,
				'friends'  => 1,
				'groups'   => 1,
				'activity' => 1,
				'settings' => 1,
			);
			update_option( 'bp-active-components', $components );

			// WordPress has already run plugins_loaded and init. Run only BuddyPress's callbacks for them, not every plugin's again.
			global $wp_filter;
			$saved = array();
			foreach ( array( 'plugins_loaded', 'init' ) as $hook ) {
				$saved[ $hook ]     = $wp_filter[ $hook ] ?? null;
				$wp_filter[ $hook ] = new \WP_Hook();
			}
			require_once $file;
			do_action( 'plugins_loaded' );
			bp_setup_theme();
			do_action( 'init' );
			foreach ( $saved as $hook => $original ) {
				if ( null === $original ) {
					unset( $wp_filter[ $hook ] );
				} else {
					$wp_filter[ $hook ] = $original;
				}
			}

			// The way BuddyPress's own installer creates the tables of the active components.
			require_once WP_PLUGIN_DIR . '/buddypress/bp-core/admin/bp-core-admin-schema.php';
			bp_core_install( $components );
			bp_add_caps();
			// The install seeds the default field under a different name than BuddyPress looks it up by.
			$GLOBALS['wpdb']->update( buddypress()->profile->table_name_fields, array( 'name' => bp_xprofile_fullname_field_name() ), array( 'id' => 1 ) );
			wp_cache_flush();
		}
		foreach ( array( 'student', 'teacher', 'alumni' ) as $type ) {
			if ( ! bp_get_member_type_object( $type ) ) {
				bp_register_member_type(
					$type,
					array(
						'labels' => array(
							'name'          => ucfirst( $type ) . 's',
							'singular_name' => ucfirst( $type ),
						),
					)
				);
			}
		}
	}

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'Europe/Malta' );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		// Can open the Users screen and edit members, but is no BuddyPress moderator.
		add_role(
			'cln_member_editor',
			'Member editor',
			array(
				'read'       => true,
				'list_users' => true,
				'edit_users' => true,
			)
		);
		add_role(
			'cln_member_viewer',
			'Member viewer',
			array(
				'read'       => true,
				'list_users' => true,
			)
		);
		$this->editor = self::factory()->user->create( array( 'role' => 'cln_member_editor' ) );
		$this->viewer = self::factory()->user->create( array( 'role' => 'cln_member_viewer' ) );

		$this->registry = new Registry();
		BuddyPressIntegration::add_types( $this->registry );
		$this->screen = new UserScreen( $this->registry, new SetRepository() );

		foreach ( array( 'u1', 'u2', 'u3', 'u4', 'u5' ) as $name ) {
			$this->u[ $name ] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}

		foreach ( $this->u as $name => $id ) {
			xprofile_set_field_data( 1, $id, 'Member ' . $name );
		}

		$now = time();
		$day = DAY_IN_SECONDS;
		bp_update_user_last_activity( $this->u['u1'], '2031-03-10 23:30:00' );
		bp_update_user_last_activity( $this->u['u2'], '2031-03-12 08:00:00' );
		bp_update_user_last_activity( $this->u['u4'], gmdate( 'Y-m-d H:i:s', $now - 2 * $day ) );
		bp_update_user_last_activity( $this->u['u5'], gmdate( 'Y-m-d H:i:s', $now - 40 * $day ) );
		global $wpdb;
		$wpdb->delete(
			buddypress()->members->table_name_last_activity,
			array(
				'user_id' => $this->u['u3'],
				'type'    => 'last_activity',
			)
		);
		wp_cache_flush();

		$this->f['text']   = $this->field( 'Nickname', 'textbox' );
		$this->f['area']   = $this->field( 'Bio', 'textarea' );
		$this->f['number'] = $this->field( 'Age', 'number' );
		$this->f['date']   = $this->field( 'Birthday', 'datebox' );
		$this->f['url']    = $this->field( 'Site', 'url' );
		$this->f['pledge'] = $this->field( 'Pledge', 'textbox', true );
		$this->f['choice'] = $this->field( 'Level', 'selectbox', false, array( 'Alpha', 'Beta', 'Gamma' ) );
		$this->f['multi']  = $this->field( 'Interests', 'checkbox', false, array( 'Music', 'Sport', 'Art', 'Dance' ) );

		$this->set_data( 'text', 'u1', 'Ann' );
		$this->set_data( 'text', 'u2', 'Bob' );
		$this->set_data( 'text', 'u3', 'Annabel' );
		$this->set_data( 'number', 'u1', '30' );
		$this->set_data( 'number', 'u2', '45' );
		$this->set_data( 'number', 'u3', '20' );
		$this->set_data( 'date', 'u1', '1990-05-10 00:00:00' );
		$this->set_data( 'date', 'u2', '1985-12-01 00:00:00' );
		$this->set_data( 'date', 'u3', '2000-01-15 00:00:00' );
		$this->set_data( 'choice', 'u1', 'Alpha' );
		$this->set_data( 'choice', 'u2', 'Beta' );
		$this->set_data( 'choice', 'u3', 'Alpha' );
		$this->set_data( 'multi', 'u1', array( 'Music', 'Sport' ) );
		$this->set_data( 'multi', 'u2', array( 'Art' ) );
		$this->set_data( 'multi', 'u3', array( 'Music' ) );
		$this->set_data( 'url', 'u1', 'https://example.com/ann' );
		$this->set_data( 'pledge', 'u1', 'yes' );

		bp_set_member_type( $this->u['u1'], 'student' );
		bp_set_member_type( $this->u['u2'], array( 'teacher', 'student' ) );
	}

	/**
	 * @param list<string> $options
	 */
	private function field( string $name, string $type, bool $required = false, array $options = array() ): int {
		$id = xprofile_insert_field(
			array(
				'field_group_id' => 1,
				'name'           => $name,
				'type'           => $type,
				'is_required'    => $required,
			)
		);
		$this->assertNotEmpty( $id, "field $name was created" );
		foreach ( $options as $i => $option ) {
			xprofile_insert_field(
				array(
					'field_group_id' => 1,
					'parent_id'      => $id,
					'type'           => 'option',
					'name'           => $option,
					'option_order'   => $i + 1,
				)
			);
		}
		return (int) $id;
	}

	/**
	 * @param string|list<string> $value
	 */
	private function set_data( string $field, string $user, string|array $value ): void {
		$this->assertTrue( (bool) xprofile_set_field_data( $this->f[ $field ], $this->u[ $user ], $value ), "$field for $user" );
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'bp.' . $key );
		$this->assertNotNull( $type, "bp.$key is registered" );
		return $type;
	}

	private function cfg( string $key, ?string $field = null ): Config {
		return new Config( 'c1', 'bp.' . $key, 'x', null, 'px', null === $field ? array() : array( 'field_id' => (string) $this->f[ $field ] ) );
	}

	private function render( string $key, string $user, ?string $field = null ): string {
		return $this->type( $key )->render( $this->u[ $user ], $this->cfg( $key, $field ), $this->screen );
	}

	/**
	 * @param array<int|string> $ids
	 * @return list<string>
	 */
	private function names( array $ids ): array {
		$flip = array_flip( $this->u );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $ids );
	}

	/**
	 * Runs the rule through a real WP_User_Query, the way the Users screen applies it.
	 *
	 * @return list<string>
	 */
	private function filtered( string $key, string $op, mixed $value, ?string $field = null ): array {
		$type   = $this->type( $key );
		$config = $this->cfg( $key, $field );
		$this->assertInstanceOf( Filterable::class, $type );
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $this->screen ), 'op' ), "bp.$key offers $op" );
		$sql = $type->filter_condition( $config, $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "bp.$key $op was rejected" );

		$callback = static function ( $query ) use ( $sql ) {
			$query->query_from  .= ' ' . $sql['join'];
			$query->query_where .= ' AND ' . $sql['where'];
		};
		add_action( 'pre_user_query', $callback );
		$query = new WP_User_Query(
			array(
				'include' => array_values( $this->u ),
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		remove_action( 'pre_user_query', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->get_results() );
	}

	/**
	 * @param list<string> $expected
	 */
	private function expect( array $expected, string $key, string $op, mixed $value, ?string $field = null ): void {
		$this->assertSame( $expected, $this->filtered( $key, $op, $value, $field ), "bp.$key $op " . wp_json_encode( $value ) );
	}

	/**
	 * @return list<string>
	 */
	private function sorted( string $key, string $order ): array {
		$type   = $this->type( $key );
		$config = $this->cfg( $key );
		$this->assertInstanceOf( Sortable::class, $type );
		$callback = function ( $query ) use ( $type, $config, $order ) {
			global $wpdb;
			$clauses              = $type->sort_clauses(
				array(
					'join'    => '',
					'where'   => '',
					'orderby' => '',
					'groupby' => '',
				),
				$config,
				$order,
				$this->screen
			);
			$query->query_from   .= ' ' . $clauses['join'];
			$orderby              = trim( $clauses['orderby'] . ", {$wpdb->users}.ID {$order}", ', ' );
			$groupby              = '' !== $clauses['groupby'] ? 'GROUP BY ' . $clauses['groupby'] . ' ' : '';
			$query->query_orderby = $groupby . 'ORDER BY ' . $orderby;
		};
		add_action( 'pre_user_query', $callback );
		$query = new WP_User_Query(
			array(
				'include' => array_values( $this->u ),
				'fields'  => 'ID',
			)
		);
		remove_action( 'pre_user_query', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->get_results() );
	}

	private function save( string $key, string $user, mixed $value, string $mode = 'set', ?string $field = null ): bool|WP_Error {
		$type = $this->type( $key );
		$this->assertInstanceOf( Editable::class, $type );
		return $type->save( $this->u[ $user ], $this->cfg( $key, $field ), $this->screen, $value, $mode );
	}

	private function assertRejected( bool|WP_Error $result, string $message ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( 'corinthian_invalid', $result->get_error_code(), $message );
	}

	/**
	 * What BuddyPress itself returns for a field, read fresh.
	 *
	 * @return string|list<string>|false
	 */
	private function stored( string $field, string $user ): string|array|false {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM ' . buddypress()->profile->table_name_data . ' WHERE field_id = %d AND user_id = %d', $this->f[ $field ], $this->u[ $user ] ) );
		return null === $raw ? false : maybe_unserialize( $raw );
	}

	/**
	 * @return list<string>
	 */
	private function types_of( string $user ): array {
		wp_cache_flush();
		$types = bp_get_member_type( $this->u[ $user ], false );
		$types = is_array( $types ) ? $types : array();
		sort( $types );
		return $types;
	}

	public function test_loader_registers_each_type_only_for_an_active_component(): void {
		$this->assertCount( 6, self::KEYS );
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'bp.' . $key ), $key );
		}

		$off     = static fn( $active, $component ) => in_array( $component, array( 'groups', 'xprofile' ), true ) ? false : $active;
		add_filter( 'bp_is_active', $off, 10, 2 );
		$partial = new Registry();
		BuddyPressIntegration::add_types( $partial );
		remove_filter( 'bp_is_active', $off, 10 );
		$this->assertNull( $partial->get( 'bp.groups_count' ) );
		$this->assertNull( $partial->get( 'bp.xprofile' ) );
		$this->assertNotNull( $partial->get( 'bp.friends_count' ) );
		$this->assertNotNull( $partial->get( 'bp.last_activity' ) );

		add_filter( 'corinthian_buddypress_active', '__return_false' );
		$none = new Registry();
		BuddyPressIntegration::add_types( $none );
		remove_filter( 'corinthian_buddypress_active', '__return_false' );
		$this->assertNull( $none->get( 'bp.last_activity' ) );

		BuddyPressIntegration::register();
		$this->assertNotFalse( has_action( 'corinthian_register_types', array( BuddyPressIntegration::class, 'add_types' ) ) );
		$this->assertTrue( BuddyPressIntegration::is_active() );
	}

	public function test_types_belong_to_the_users_screen_only(): void {
		$keys  = static fn( array $types ): array => array_values( array_map( static fn( $t ) => $t->key(), array_filter( $types, static fn( $t ) => str_starts_with( $t->key(), 'bp.' ) ) ) );
		$this->assertCount( 6, $keys( $this->registry->for_screen( $this->screen ) ) );
		$posts = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );
		$this->assertSame( array(), $keys( $this->registry->for_screen( $posts ) ) );
	}

	public function test_render_last_activity_in_the_site_timezone(): void {
		$this->assertSame( '2031-03-11 00:30', $this->render( 'last_activity', 'u1' ), '23:30 GMT is half past midnight in Malta' );
		$this->assertSame( '2031-03-12 09:00', $this->render( 'last_activity', 'u2' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'last_activity', 'u3' ) );
		$this->assertSame( '2031-03-12 08:00:00', $this->type( 'last_activity' )->value( $this->u['u2'], $this->cfg( 'last_activity' ), $this->screen ) );
	}

	public function test_sort_last_activity_with_members_who_never_were_active_last(): void {
		$this->assertSame( array( 'u5', 'u4', 'u1', 'u2', 'u3' ), $this->sorted( 'last_activity', 'ASC' ) );
		$this->assertSame( array( 'u2', 'u1', 'u4', 'u5', 'u3' ), $this->sorted( 'last_activity', 'DESC' ) );
	}

	public function test_filter_last_activity_by_date(): void {
		// Malta is an hour ahead of GMT in March, so 23:30 GMT on the 10th is already the 11th there.
		$this->expect( array( 'u4', 'u5' ), 'last_activity', 'before', '2031-03-11' );
		$this->expect( array(), 'last_activity', 'before', '2000-01-01' );
		$this->expect( array( 'u1', 'u2' ), 'last_activity', 'after', '2031-03-10' );
		$this->expect( array( 'u2' ), 'last_activity', 'after', '2031-03-11' );
		$this->expect( array(), 'last_activity', 'after', '2031-03-12' );
		$this->expect( array( 'u4' ), 'last_activity', 'within_days', '3' );
		$this->expect( array( 'u4', 'u5' ), 'last_activity', 'within_days', 100 );
		$this->expect( array(), 'last_activity', 'within_days', '1' );
		$this->expect( array( 'u5' ), 'last_activity', 'older_than_days', '10' );
		$this->expect( array(), 'last_activity', 'older_than_days', '1000' );
	}

	public function test_last_activity_filter_rejects_bad_input(): void {
		$type = $this->type( 'last_activity' );
		foreach ( array(
			array( 'before', '2031-02-30' ),
			array( 'before', 'soon' ),
			array( 'after', array( '2031-03-10' ) ),
			array( 'within_days', '-1' ),
			array( 'within_days', 'x' ),
			array( 'older_than_days', '99999' ),
			array( 'eq', '2031-03-10' ),
		) as [ $op, $value ] ) {
			$this->assertNull( $type->filter_condition( $this->cfg( 'last_activity' ), $op, $value, $this->screen, 'clf0' ), "$op " . wp_json_encode( $value ) );
		}
	}

	public function test_xprofile_settings_list_the_editable_fields(): void {
		$schema = $this->type( 'xprofile' )->settings_schema( $this->screen );
		$this->assertSame( 'field_id', $schema[0]['name'] );
		$this->assertSame( 'select', $schema[0]['type'] );
		$this->assertArrayHasKey( (string) $this->f['text'], $schema[0]['options'] );
		$this->assertArrayHasKey( (string) $this->f['multi'], $schema[0]['options'] );
		$this->assertStringContainsString( 'Nickname', $schema[0]['options'][ (string) $this->f['text'] ] );
	}

	public function test_render_xprofile_values_by_field_type(): void {
		$this->assertSame( 'Ann', $this->render( 'xprofile', 'u1', 'text' ) );
		$this->assertSame( '30', $this->render( 'xprofile', 'u1', 'number' ) );
		$this->assertSame( '1990-05-10', $this->render( 'xprofile', 'u1', 'date' ) );
		$this->assertSame( 'Alpha', $this->render( 'xprofile', 'u1', 'choice' ) );
		$this->assertSame( 'Music, Sport', $this->render( 'xprofile', 'u1', 'multi' ) );
		$this->assertStringContainsString( 'href="https://example.com/ann"', $this->render( 'xprofile', 'u1', 'url' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'xprofile', 'u4', 'text' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'xprofile', 'u4', 'multi' ) );

		$this->set_data( 'text', 'u4', '<b>x</b> & "y"' );
		$this->assertStringNotContainsString( '<b>', $this->render( 'xprofile', 'u4', 'text' ) );

		$unset = new Config( 'c1', 'bp.xprofile', 'x' );
		$this->assertStringContainsString( '&#8212;', $this->type( 'xprofile' )->render( $this->u['u1'], $unset, $this->screen ), 'no field chosen yet' );
		$gone = new Config( 'c1', 'bp.xprofile', 'x', null, 'px', array( 'field_id' => '999999' ) );
		$this->assertStringContainsString( '&#8212;', $this->type( 'xprofile' )->render( $this->u['u1'], $gone, $this->screen ), 'field deleted' );
	}

	public function test_xprofile_respects_the_members_visibility_setting(): void {
		xprofile_set_field_visibility_level( $this->f['text'], $this->u['u1'], 'adminsonly' );
		$this->assertSame( 'Ann', $this->render( 'xprofile', 'u1', 'text' ), 'a moderator sees it' );
		$this->assertSame( 'Ann', $this->type( 'xprofile' )->value( $this->u['u1'], $this->cfg( 'xprofile', 'text' ), $this->screen ) );

		wp_set_current_user( $this->viewer );
		$this->assertStringContainsString( '&#8212;', $this->render( 'xprofile', 'u1', 'text' ), 'a plain list_users role does not' );
		$this->assertNull( $this->type( 'xprofile' )->value( $this->u['u1'], $this->cfg( 'xprofile', 'text' ), $this->screen ) );
		$this->assertSame( 'Bob', $this->render( 'xprofile', 'u2', 'text' ), 'control: a public field is shown' );
	}

	public function test_xprofile_filter_text(): void {
		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'contains', 'ann', 'text' );
		$this->expect( array( 'u2' ), 'xprofile', 'eq', 'Bob', 'text' );
		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'starts', 'Ann', 'text' );
		$this->expect( array( 'u2', 'u4', 'u5' ), 'xprofile', 'not_contains', 'ann', 'text' );
		$this->expect( array( 'u2', 'u3', 'u4', 'u5' ), 'xprofile', 'neq', 'Ann', 'text' );
		$this->expect( array( 'u4', 'u5' ), 'xprofile', 'empty', null, 'text' );
		$this->expect( array( 'u1', 'u2', 'u3' ), 'xprofile', 'not_empty', null, 'text' );
		$this->expect( array(), 'xprofile', 'eq', 'Nobody', 'text' );
		$this->assertNull( $this->type( 'xprofile' )->filter_condition( $this->cfg( 'xprofile', 'text' ), 'gt', '1', $this->screen, 'clf0' ) );
	}

	public function test_xprofile_filter_number(): void {
		$this->expect( array( 'u1' ), 'xprofile', 'eq', '30', 'number' );
		$this->expect( array( 'u1', 'u2' ), 'xprofile', 'gt', '25', 'number' );
		$this->expect( array( 'u3' ), 'xprofile', 'lt', '25', 'number' );
		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'between', array( 20, 30 ), 'number' );
		$this->expect( array( 'u2', 'u3', 'u4', 'u5' ), 'xprofile', 'neq', '30', 'number' );
		$this->expect( array( 'u4', 'u5' ), 'xprofile', 'empty', null, 'number' );
		$this->expect( array(), 'xprofile', 'gt', '99', 'number' );
		$this->assertNull( $this->type( 'xprofile' )->filter_condition( $this->cfg( 'xprofile', 'number' ), 'gt', 'old', $this->screen, 'clf0' ) );
	}

	public function test_xprofile_filter_date(): void {
		$this->expect( array( 'u2' ), 'xprofile', 'before', '1986-01-01', 'date' );
		$this->expect( array( 'u3' ), 'xprofile', 'after', '1990-05-10', 'date' );
		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'after', '1989-12-31', 'date' );
		$this->expect( array(), 'xprofile', 'before', '1900-01-01', 'date' );
		$this->assertNull( $this->type( 'xprofile' )->filter_condition( $this->cfg( 'xprofile', 'date' ), 'before', '1990-02-30', $this->screen, 'clf0' ) );
	}

	public function test_xprofile_filter_choice_and_multi(): void {
		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'in', array( 'Alpha' ), 'choice' );
		$this->expect( array( 'u2' ), 'xprofile', 'in', array( 'Beta' ), 'choice' );
		$this->expect( array( 'u2', 'u4', 'u5' ), 'xprofile', 'not_in', array( 'Alpha' ), 'choice' );
		$this->expect( array(), 'xprofile', 'in', array( 'Gamma' ), 'choice' );
		$this->assertNull( $this->type( 'xprofile' )->filter_condition( $this->cfg( 'xprofile', 'choice' ), 'in', array( 'Delta' ), $this->screen, 'clf0' ), 'not an option' );

		$this->expect( array( 'u1', 'u3' ), 'xprofile', 'in', array( 'Music' ), 'multi' );
		$this->expect( array( 'u1', 'u2' ), 'xprofile', 'in', array( 'Sport', 'Art' ), 'multi' );
		$this->expect( array( 'u2', 'u4', 'u5' ), 'xprofile', 'not_in', array( 'Music' ), 'multi' );
		$this->expect( array(), 'xprofile', 'in', array( 'Dance' ), 'multi' );
	}

	public function test_xprofile_filter_is_closed_to_members_who_cannot_see_every_value(): void {
		wp_set_current_user( $this->editor );
		$type = $this->type( 'xprofile' );
		$this->assertSame( array(), $type->filter_operators( $this->cfg( 'xprofile', 'text' ), $this->screen ) );
		$this->assertNull( $type->filter_condition( $this->cfg( 'xprofile', 'text' ), 'contains', 'ann', $this->screen, 'clf0' ) );
		wp_set_current_user( $this->admin );
		$this->assertNotEmpty( $type->filter_operators( $this->cfg( 'xprofile', 'text' ), $this->screen ), 'control: a moderator has it' );
	}

	public function test_edit_xprofile_text_fields_round_trip_through_buddypress(): void {
		$this->assertSame( 'text', $this->type( 'xprofile' )->edit_field( $this->cfg( 'xprofile', 'text' ), $this->screen )['input'] );
		$this->assertSame( 'Ann', $this->type( 'xprofile' )->edit_value( $this->u['u1'], $this->cfg( 'xprofile', 'text' ), $this->screen ) );

		$this->assertTrue( $this->save( 'xprofile', 'u1', '  Zed  ', 'set', 'text' ) );
		$this->assertSame( 'Zed', $this->stored( 'text', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', 'Zed', 'set', 'text' ), 'saving the same value again is fine' );
		$this->assertTrue( $this->save( 'xprofile', 'u1', null, 'clear', 'text' ) );
		$this->assertEmpty( $this->stored( 'text', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u4', '', 'set', 'text' ), 'an empty value on an empty field is fine' );

		$this->assertSame( 'textarea', $this->type( 'xprofile' )->edit_field( $this->cfg( 'xprofile', 'area' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'xprofile', 'u1', "Line one\nLine two", 'set', 'area' ) );
		$this->assertSame( "Line one\nLine two", $this->stored( 'area', 'u1' ) );

		$this->assertTrue( $this->save( 'xprofile', 'u1', 'https://example.org/new', 'set', 'url' ) );
		$this->assertSame( 'https://example.org/new', $this->stored( 'url', 'u1' ) );

		$this->assertTrue( $this->save( 'xprofile', 'u4', '42', 'set', 'number' ) );
		$this->assertSame( '42', $this->stored( 'number', 'u4' ) );
		$this->assertSame( '42', $this->type( 'xprofile' )->edit_value( $this->u['u4'], $this->cfg( 'xprofile', 'number' ), $this->screen ) );

		$this->assertSame( 'date', $this->type( 'xprofile' )->edit_field( $this->cfg( 'xprofile', 'date' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'xprofile', 'u4', '2001-02-03', 'set', 'date' ) );
		$this->assertSame( '2001-02-03 00:00:00', $this->stored( 'date', 'u4' ) );
		$this->assertSame( '2001-02-03', $this->type( 'xprofile' )->edit_value( $this->u['u4'], $this->cfg( 'xprofile', 'date' ), $this->screen ) );
	}

	public function test_edit_xprofile_choice_fields_round_trip_through_buddypress(): void {
		$edit = $this->type( 'xprofile' )->edit_field( $this->cfg( 'xprofile', 'choice' ), $this->screen );
		$this->assertSame( 'select', $edit['input'] );
		$this->assertSame( array( 'Alpha', 'Beta', 'Gamma' ), array_keys( $edit['options'] ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', 'Gamma', 'set', 'choice' ) );
		$this->assertSame( 'Gamma', $this->stored( 'choice', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', null, 'clear', 'choice' ) );
		$this->assertEmpty( $this->stored( 'choice', 'u1' ) );

		$multi = $this->type( 'xprofile' )->edit_field( $this->cfg( 'xprofile', 'multi' ), $this->screen );
		$this->assertSame( 'multiselect', $multi['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $multi['bulk'] );
		$this->assertSame( array( 'Music', 'Sport' ), $this->type( 'xprofile' )->edit_value( $this->u['u1'], $this->cfg( 'xprofile', 'multi' ), $this->screen ) );

		$this->assertTrue( $this->save( 'xprofile', 'u1', array( 'Art', 'Dance' ), 'set', 'multi' ) );
		$this->assertSame( array( 'Art', 'Dance' ), $this->stored( 'multi', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', array( 'Music' ), 'append', 'multi' ) );
		$this->assertSame( array( 'Art', 'Dance', 'Music' ), $this->stored( 'multi', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', array( 'Art' ), 'remove', 'multi' ) );
		$this->assertSame( array( 'Dance', 'Music' ), $this->stored( 'multi', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', null, 'clear', 'multi' ) );
		$this->assertEmpty( $this->stored( 'multi', 'u1' ) );
		$this->assertSame( array( 'Art' ), $this->stored( 'multi', 'u2' ), 'other members are untouched' );
	}

	public function test_edit_xprofile_rejects_invalid_input_and_changes_nothing(): void {
		foreach ( array(
			array( 'number', 'abc' ),
			array( 'number', array( '1' ) ),
			array( 'date', '2001-02-30' ),
			array( 'date', '03/02/2001' ),
			array( 'date', '2001-02-03 10:00:00' ),
			array( 'choice', 'Delta' ),
			array( 'choice', array( 'Alpha' ) ),
			array( 'multi', array( 'Nope' ) ),
			array( 'multi', array( 'Music', array( 'x' ) ) ),
			array( 'url', 'javascript:alert(1)' ),
			array( 'url', 'example.com' ),
			array( 'text', array( 'x' ) ),
		) as [ $field, $value ] ) {
			$this->assertRejected( $this->save( 'xprofile', 'u1', $value, 'set', $field ), "$field " . wp_json_encode( $value ) );
		}
		$this->assertSame( 'Ann', $this->stored( 'text', 'u1' ) );
		$this->assertSame( '30', $this->stored( 'number', 'u1' ) );
		$this->assertSame( '1990-05-10 00:00:00', $this->stored( 'date', 'u1' ) );
		$this->assertSame( 'Alpha', $this->stored( 'choice', 'u1' ) );
		$this->assertSame( array( 'Music', 'Sport' ), $this->stored( 'multi', 'u1' ) );

		$this->assertRejected( $this->save( 'xprofile', 'u1', '', 'set', 'pledge' ), 'a required field cannot be emptied' );
		$this->assertRejected( $this->save( 'xprofile', 'u1', null, 'clear', 'pledge' ), 'nor cleared' );
		$this->assertSame( 'yes', $this->stored( 'pledge', 'u1' ) );
		$this->assertTrue( $this->save( 'xprofile', 'u1', 'agreed', 'set', 'pledge' ), 'control: a required field can change' );

		foreach ( array( 'append', 'remove', 'toggle' ) as $mode ) {
			$this->assertWPError( $this->save( 'xprofile', 'u1', 'x', $mode, 'text' ), $mode );
		}
		$this->assertSame( 'Ann', $this->stored( 'text', 'u1' ) );

		$none = new Config( 'c1', 'bp.xprofile', 'x', null, 'px', array( 'field_id' => '999999' ) );
		$this->assertRejected( $this->type( 'xprofile' )->save( $this->u['u1'], $none, $this->screen, 'x' ), 'no such field' );
		$this->assertRejected( $this->type( 'xprofile' )->save( 99999999, $this->cfg( 'xprofile', 'text' ), $this->screen, 'x' ), 'no such member' );
	}

	public function test_render_member_type_and_filter(): void {
		$this->assertSame( 'Student', $this->render( 'member_type', 'u1' ) );
		$this->assertSame( 'Student, Teacher', $this->render( 'member_type', 'u2' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'member_type', 'u3' ) );

		$this->expect( array( 'u1', 'u2' ), 'member_type', 'in', array( 'student' ) );
		$this->expect( array( 'u2' ), 'member_type', 'in', array( 'teacher' ) );
		$this->expect( array( 'u3', 'u4', 'u5' ), 'member_type', 'not_in', array( 'student' ) );
		$this->expect( array( 'u1', 'u3', 'u4', 'u5' ), 'member_type', 'not_in', array( 'teacher' ) );
		$this->expect( array(), 'member_type', 'in', array( 'alumni' ) );
		$type = $this->type( 'member_type' );
		$this->assertNull( $type->filter_condition( $this->cfg( 'member_type' ), 'in', array( 'ghost' ), $this->screen, 'clf0' ), 'not a registered type' );
		$this->assertSame( 'Student', $type->filter_operators( $this->cfg( 'member_type' ), $this->screen )[0]['options']['student'] );
	}

	public function test_edit_member_type_round_trip_through_buddypress(): void {
		$type = $this->type( 'member_type' );
		$this->assertSame( array( 'student' ), $type->edit_value( $this->u['u1'], $this->cfg( 'member_type' ), $this->screen ) );

		$this->assertTrue( $this->save( 'member_type', 'u1', array( 'teacher' ) ) );
		$this->assertSame( array( 'teacher' ), $this->types_of( 'u1' ) );
		$this->assertTrue( $this->save( 'member_type', 'u1', array( 'student' ), 'append' ) );
		$this->assertSame( array( 'student', 'teacher' ), $this->types_of( 'u1' ) );
		$this->assertTrue( $this->save( 'member_type', 'u1', array( 'teacher' ), 'remove' ) );
		$this->assertSame( array( 'student' ), $this->types_of( 'u1' ) );
		$this->assertTrue( $this->save( 'member_type', 'u1', 'alumni' ), 'a single value is accepted' );
		$this->assertSame( array( 'alumni' ), $this->types_of( 'u1' ) );
		$this->assertTrue( $this->save( 'member_type', 'u1', null, 'clear' ) );
		$this->assertSame( array(), $this->types_of( 'u1' ) );
		$this->assertTrue( $this->save( 'member_type', 'u3', array( 'student' ) ), 'a member with no type gets one' );
		$this->assertSame( array( 'student' ), $this->types_of( 'u3' ) );
		$this->assertSame( array( 'student', 'teacher' ), $this->types_of( 'u2' ), 'other members are untouched' );

		$edit = $type->edit_field( $this->cfg( 'member_type' ), $this->screen );
		$this->assertSame( 'multiselect', $edit['input'] );
		$this->assertSame( 'Teacher', $edit['options']['teacher'] );
	}

	public function test_edit_member_type_rejects_invalid_input(): void {
		foreach ( array( array( 'ghost' ), array( 'student', 'ghost' ), array( array( 'student' ) ), array( '1' ) ) as $value ) {
			$this->assertRejected( $this->save( 'member_type', 'u1', $value ), wp_json_encode( $value ) );
		}
		$this->assertSame( array( 'student' ), $this->types_of( 'u1' ) );
		$this->assertWPError( $this->save( 'member_type', 'u1', array( 'teacher' ), 'toggle' ) );
		$this->assertRejected( $this->type( 'member_type' )->save( 99999999, $this->cfg( 'member_type' ), $this->screen, array( 'student' ) ), 'no such member' );
	}

	public function test_a_member_without_the_buddypress_rule_is_refused_on_every_editable_column(): void {
		$attempts = array(
			array( 'xprofile', 'Zed', 'text' ),
			array( 'member_type', array( 'teacher' ), null ),
		);
		foreach ( array(
			'subscriber' => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			'viewer'     => $this->viewer,
		) as $label => $user ) {
			wp_set_current_user( $user );
			foreach ( $attempts as [ $key, $value, $field ] ) {
				$result = $this->save( $key, 'u1', $value, 'set', $field );
				$this->assertWPError( $result, "$label $key" );
				$this->assertSame( 'corinthian_protected', $result->get_error_code(), "$label $key" );
			}
		}
		$this->assertSame( 'Ann', $this->stored( 'text', 'u1' ) );
		$this->assertSame( array( 'student' ), $this->types_of( 'u1' ) );

		foreach ( array( $this->editor, $this->admin ) as $user ) {
			wp_set_current_user( $user );
			foreach ( $attempts as [ $key, $value, $field ] ) {
				$this->assertTrue( $this->save( $key, 'u1', $value, 'set', $field ), "control: user $user may change $key" );
			}
		}
		$this->assertSame( 'Zed', $this->stored( 'text', 'u1' ) );
		$this->assertSame( array( 'teacher' ), $this->types_of( 'u1' ) );
	}

	public function test_render_group_and_friend_counts_and_account_status(): void {
		$g1 = groups_create_group(
			array(
				'creator_id' => $this->u['u1'],
				'name'       => 'Group one',
				'status'     => 'public',
			)
		);
		groups_create_group(
			array(
				'creator_id' => $this->u['u1'],
				'name'       => 'Group two',
				'status'     => 'public',
			)
		);
		groups_create_group(
			array(
				'creator_id' => $this->u['u3'],
				'name'       => 'Secret group',
				'status'     => 'hidden',
			)
		);
		groups_join_group( $g1, $this->u['u2'] );
		wp_cache_flush();

		$this->assertSame( '2', $this->render( 'groups_count', 'u1' ) );
		$this->assertSame( '1', $this->render( 'groups_count', 'u2' ) );
		$this->assertSame( '0', $this->render( 'groups_count', 'u4' ) );
		$this->assertSame( '1', $this->render( 'groups_count', 'u3' ), 'a moderator sees hidden groups' );
		wp_set_current_user( $this->viewer );
		wp_cache_flush();
		$this->assertSame( '0', $this->render( 'groups_count', 'u3' ), 'BuddyPress leaves a hidden group out for anyone but the member or a moderator' );
		wp_set_current_user( $this->admin );

		friends_add_friend( $this->u['u1'], $this->u['u2'], true );
		friends_add_friend( $this->u['u1'], $this->u['u3'], false );
		wp_cache_flush();
		$this->assertSame( '1', $this->render( 'friends_count', 'u1' ), 'a pending request does not count' );
		$this->assertSame( '1', $this->render( 'friends_count', 'u2' ) );
		$this->assertSame( '0', $this->render( 'friends_count', 'u3' ) );

		bp_core_process_spammer_status( $this->u['u4'], 'spam' );
		$this->assertStringContainsString( 'Spam', $this->render( 'account_status', 'u4' ) );
		$this->assertSame( 'Active', $this->render( 'account_status', 'u1' ) );
		bp_core_process_spammer_status( $this->u['u4'], 'ham' );
		$this->assertSame( 'Active', $this->render( 'account_status', 'u4' ) );
	}

	public function test_render_only_columns_offer_no_sort_filter_or_edit(): void {
		foreach ( array( 'groups_count', 'friends_count', 'account_status' ) as $key ) {
			$type = $this->type( $key );
			$this->assertNotInstanceOf( Sortable::class, $type, $key );
			$this->assertNotInstanceOf( Filterable::class, $type, $key );
			$this->assertNotInstanceOf( Editable::class, $type, $key );
		}
	}
}
