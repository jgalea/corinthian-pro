<?php

namespace CorinthianPro\Integrations\Yoast;

final class InboundLinksType extends LinkCountType {

	public function key(): string {
		return 'yoast.internal_links_in';
	}

	public function label(): string {
		return __( 'Internal links in', 'corinthian-pro' );
	}

	protected function column(): string {
		return 'incoming_link_count';
	}
}
