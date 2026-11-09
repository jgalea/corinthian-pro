<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

/**
 * BuddyPress marks a spammer in the users table: user_status 1 on a single
 * site, the spam column on a network. bp_is_user_spammer() reads both.
 */
final class AccountStatusType extends BpType {

	public function key(): string {
		return 'bp.account_status';
	}

	public function label(): string {
		return __( 'Account status', 'corinthian-pro' );
	}

	public function component(): string {
		return 'members';
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return BpApi::is_spammer( $object_id ) ? 'spam' : 'active';
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		return 'spam' === $this->value( $object_id, $config, $screen )
			? '<strong>' . esc_html__( 'Spam', 'corinthian-pro' ) . '</strong>'
			: esc_html__( 'Active', 'corinthian-pro' );
	}
}
