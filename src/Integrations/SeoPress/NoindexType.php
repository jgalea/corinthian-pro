<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;

/**
 * _seopress_robots_index: 'yes' means noindex.
 */
final class NoindexType extends FlagType {

	public function key(): string {
		return 'seopress.noindex';
	}

	public function label(): string {
		return __( 'Noindex', 'corinthian-pro' );
	}

	protected function meta_key( Config $config, string $post_type ): string {
		return '_seopress_robots_index';
	}

	protected function on_label(): string {
		return __( 'Noindex', 'corinthian-pro' );
	}
}
