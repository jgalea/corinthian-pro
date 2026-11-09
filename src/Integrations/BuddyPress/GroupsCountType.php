<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

final class GroupsCountType extends BpType {

	public function key(): string {
		return 'bp.groups_count';
	}

	public function label(): string {
		return __( 'Groups', 'corinthian-pro' );
	}

	public function component(): string {
		return 'groups';
	}

	/**
	 * BuddyPress leaves hidden groups out of the count for anyone but the member and moderators.
	 */
	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return BpApi::groups_count( $object_id );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		return esc_html( number_format_i18n( BpApi::groups_count( $object_id ) ) );
	}
}
