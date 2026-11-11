<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

final class FriendsCountType extends BpType {

	public function key(): string {
		return 'bp.friends_count';
	}

	public function label(): string {
		return __( 'Friends', 'corinthian-pro' );
	}

	public function component(): string {
		return 'friends';
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return BpApi::friends_count( $object_id );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		return esc_html( number_format_i18n( BpApi::friends_count( $object_id ) ) );
	}
}
