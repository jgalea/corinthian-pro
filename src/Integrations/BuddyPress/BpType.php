<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\AbstractType;
use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

abstract class BpType extends AbstractType {

	/** The BuddyPress component the column needs; the type is not registered without it. */
	abstract public function component(): string;

	public function group(): string {
		return __( 'BuddyPress', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'user' === $screen->object_type();
	}

	/**
	 * BuddyPress's admin changes a member only for users with edit_users or
	 * bp_moderate; the user must also be editable by WordPress's own rule.
	 */
	protected function guard( int $user_id ): ?WP_Error {
		if ( ! get_userdata( $user_id ) ) {
			return $this->invalid( __( 'This user does not exist.', 'corinthian-pro' ) );
		}
		if ( ! BpApi::can_manage_members() || ! current_user_can( 'edit_user', $user_id ) ) {
			return new WP_Error( 'corinthian_protected', __( 'You cannot change this member.', 'corinthian-pro' ) );
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
		return new WP_Error( 'corinthian_bp_failed', __( 'BuddyPress could not save this change.', 'corinthian-pro' ) );
	}
}
