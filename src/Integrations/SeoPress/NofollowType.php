<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;

/**
 * _seopress_robots_follow: 'yes' means nofollow.
 */
final class NofollowType extends FlagType {

	public function key(): string {
		return 'seopress.nofollow';
	}

	public function label(): string {
		return __( 'Nofollow', 'corinthian-pro' );
	}

	protected function meta_key( Config $config, string $post_type ): string {
		return '_seopress_robots_follow';
	}

	protected function on_label(): string {
		return __( 'Nofollow', 'corinthian-pro' );
	}
}
