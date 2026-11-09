<?php

namespace CorinthianPro\Integrations\BuddyPress;

/**
 * The only place that calls BuddyPress. Calls go through call_user_func so
 * the plugin analyses cleanly without BuddyPress's stubs.
 */
final class BpApi {

	public const OPTION_CAP = 200;

	/**
	 * @param string|array{0:mixed,1:string} $callable
	 */
	private static function call( string|array $callable, mixed ...$args ): mixed {
		return \call_user_func_array( $callable, $args );
	}

	public static function component_active( string $component ): bool {
		return function_exists( 'bp_is_active' ) && (bool) self::call( 'bp_is_active', $component );
	}

	/**
	 * Capability check as BuddyPress's own admin does it for member changes.
	 */
	public static function can_manage_members(): bool {
		return function_exists( 'bp_current_user_can' ) && ( (bool) self::call( 'bp_current_user_can', 'edit_users' ) || (bool) self::call( 'bp_current_user_can', 'bp_moderate' ) );
	}

	public static function is_moderator(): bool {
		return function_exists( 'bp_current_user_can' ) && (bool) self::call( 'bp_current_user_can', 'bp_moderate' );
	}

	public static function last_activity_table(): string {
		$bp = self::call( 'buddypress' );
		return (string) $bp->members->table_name_last_activity;
	}

	public static function last_activity_component(): string {
		$bp = self::call( 'buddypress' );
		return (string) $bp->members->id;
	}

	public static function xprofile_table(): string {
		$bp = self::call( 'buddypress' );
		return (string) $bp->profile->table_name_data;
	}

	/**
	 * Time of the user's last activity as a GMT 'Y-m-d H:i:s' string, or ''.
	 */
	public static function last_activity( int $user_id ): string {
		$time = self::call( 'bp_get_user_last_activity', $user_id );
		return is_string( $time ) ? $time : '';
	}

	public static function groups_count( int $user_id ): int {
		return (int) self::call( 'groups_total_groups_for_user', $user_id );
	}

	public static function friends_count( int $user_id ): int {
		return (int) self::call( 'friends_get_total_friend_count', $user_id );
	}

	public static function is_spammer( int $user_id ): bool {
		return (bool) self::call( 'bp_is_user_spammer', $user_id );
	}

	/**
	 * @return array<string,string> member type name => singular label
	 */
	public static function member_types(): array {
		$types = self::call( 'bp_get_member_types', array(), 'objects' );
		$out   = array();
		foreach ( is_array( $types ) ? $types : array() as $name => $type ) {
			$vars                  = self::props( $type );
			$labels                = is_array( $vars['labels'] ?? null ) ? $vars['labels'] : array();
			$label                 = is_string( $labels['singular_name'] ?? null ) ? $labels['singular_name'] : '';
			$out[ (string) $name ] = '' !== $label ? $label : (string) $name;
		}
		return $out;
	}

	/**
	 * Public properties of a BuddyPress object, which has no stubs here.
	 *
	 * @return array<string,mixed>
	 */
	private static function props( mixed $object ): array {
		return is_object( $object ) ? get_object_vars( $object ) : array();
	}

	/**
	 * @return list<string>
	 */
	public static function member_type_names( int $user_id ): array {
		$types = self::call( 'bp_get_member_type', $user_id, false );
		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array();
	}

	/**
	 * @param string|list<string> $types '' removes every type
	 */
	public static function set_member_types( int $user_id, string|array $types, bool $append ): bool {
		$result = self::call( 'bp_set_member_type', $user_id, $types, $append );
		return false !== $result && ! is_wp_error( $result );
	}

	public static function remove_member_type( int $user_id, string $type ): void {
		self::call( 'bp_remove_member_type', $user_id, $type );
	}

	public static function member_type_taxonomy(): string {
		return (string) self::call( 'bp_get_member_type_tax_name' );
	}

	/**
	 * Database prefix of the site BuddyPress keeps member type terms on.
	 */
	public static function member_type_prefix(): string {
		global $wpdb;
		$site = (int) self::call( 'bp_get_taxonomy_term_site_id', self::member_type_taxonomy() );
		return $wpdb->get_blog_prefix( $site );
	}

	/**
	 * @return array{id:int,name:string,type:string,required:bool,options:list<string>}|null
	 */
	public static function field( int $field_id ): ?array {
		if ( $field_id <= 0 || ! function_exists( 'xprofile_get_field' ) ) {
			return null;
		}
		$field = self::call( 'xprofile_get_field', $field_id, null, false );
		$vars  = self::props( $field );
		if ( empty( $vars['id'] ) || ! empty( $vars['parent_id'] ) ) {
			return null;
		}
		$options = array();
		$kids    = self::call( array( $field, 'get_children' ) );
		foreach ( is_array( $kids ) ? $kids : array() as $kid ) {
			$kid_vars = self::props( $kid );
			if ( isset( $kid_vars['name'] ) && is_scalar( $kid_vars['name'] ) ) {
				$options[] = (string) $kid_vars['name'];
			}
		}
		return array(
			'id'       => (int) $vars['id'],
			'name'     => (string) ( is_scalar( $vars['name'] ?? null ) ? $vars['name'] : '' ),
			'type'     => (string) ( is_scalar( $vars['type'] ?? null ) ? $vars['type'] : '' ),
			'required' => ! empty( $vars['is_required'] ),
			'options'  => $options,
		);
	}

	/**
	 * Every top-level profile field as [ id, label, type ].
	 *
	 * @return list<array{0:int,1:string,2:string}>
	 */
	public static function fields(): array {
		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return array();
		}
		$groups = self::call( 'bp_xprofile_get_groups', array( 'fetch_fields' => true ) );
		$out    = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$group_vars = self::props( $group );
			foreach ( is_array( $group_vars['fields'] ?? null ) ? $group_vars['fields'] : array() as $field ) {
				$vars = self::props( $field );
				if ( ! empty( $vars['id'] ) ) {
					$out[] = array( (int) $vars['id'], (string) ( $group_vars['name'] ?? '' ) . ': ' . (string) ( $vars['name'] ?? '' ), (string) ( $vars['type'] ?? '' ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Raw stored value: a string, or a list of strings for multi-value fields. Null when empty.
	 *
	 * @return string|list<string>|null
	 */
	public static function field_data( int $field_id, int $user_id ): string|array|null {
		global $wpdb;
		$table = self::xprofile_table();
		// The raw stored value: the xprofile_get_field_data() filters turn URLs into links and reformat dates.
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE field_id = %d AND user_id = %d", $field_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is BuddyPress's own table name.
		$data = null === $raw ? '' : maybe_unserialize( $raw );
		if ( is_array( $data ) ) {
			$list = array_values( array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $data ), static fn( $v ) => '' !== $v ) );
			return $list ? $list : null;
		}
		return is_scalar( $data ) && '' !== (string) $data ? (string) $data : null;
	}

	/**
	 * Whether the current user may not see this field's value on that profile.
	 */
	public static function field_hidden( int $field_id, int $user_id ): bool {
		$hidden = self::call( 'bp_xprofile_get_hidden_fields_for_user', $user_id, get_current_user_id() );
		return is_array( $hidden ) && in_array( $field_id, array_map( 'intval', $hidden ), true );
	}

	/**
	 * @param string|list<string> $value
	 */
	public static function set_field_data( int $field_id, int $user_id, string|array $value, bool $required ): bool {
		return (bool) self::call( 'xprofile_set_field_data', $field_id, $user_id, $value, $required );
	}
}
