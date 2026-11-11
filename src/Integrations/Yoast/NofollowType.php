<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

defined( 'ABSPATH' ) || exit;

/**
 * _yoast_wpseo_meta-robots-nofollow: '1' for nofollow; follow is the default and is not stored.
 */
final class NofollowType extends FlagType {

	public function key(): string {
		return 'yoast.nofollow';
	}

	public function label(): string {
		return __( 'Nofollow', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'meta-robots-nofollow';
	}

	protected function on_label(): string {
		return __( 'Nofollow', 'corinthian-pro' );
	}

	protected function advanced(): bool {
		return true;
	}
}
