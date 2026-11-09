<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

/**
 * _yoast_wpseo_is_cornerstone: '1' for cornerstone content.
 */
final class CornerstoneType extends FlagType {

	public function key(): string {
		return 'yoast.cornerstone';
	}

	public function label(): string {
		return __( 'Cornerstone content', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'is_cornerstone';
	}

	protected function on_label(): string {
		return __( 'Cornerstone', 'corinthian-pro' );
	}

	protected function feature(): ?string {
		return 'enable_cornerstone_content';
	}
}
