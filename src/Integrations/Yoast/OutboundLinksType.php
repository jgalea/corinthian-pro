<?php

namespace CorinthianPro\Integrations\Yoast;

defined( 'ABSPATH' ) || exit;

final class OutboundLinksType extends LinkCountType {

	public function key(): string {
		return 'yoast.internal_links_out';
	}

	public function label(): string {
		return __( 'Internal links out', 'corinthian-pro' );
	}

	protected function column(): string {
		return 'link_count';
	}
}
