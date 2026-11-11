<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Registry;

defined( 'ABSPATH' ) || exit;

final class BuddyPressIntegration {

	public static function register(): void {
		add_action( 'corinthian_register_types', array( self::class, 'add_types' ) );
	}

	public static function add_types( Registry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		foreach ( self::types() as $type ) {
			if ( BpApi::component_active( $type->component() ) ) {
				$registry->add( $type );
			}
		}
	}

	public static function is_active(): bool {
		$active = function_exists( 'buddypress' ) && function_exists( 'bp_is_active' );
		return (bool) apply_filters( 'corinthian_buddypress_active', $active );
	}

	/**
	 * @return list<BpType>
	 */
	public static function types(): array {
		return array(
			new LastActivityType(),
			new XProfileType(),
			new MemberTypeType(),
			new GroupsCountType(),
			new FriendsCountType(),
			new AccountStatusType(),
		);
	}
}
