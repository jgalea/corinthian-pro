<?php

namespace CorinthianPro\Integrations\Wpra;

final class FeedAuthorType extends MetaTextType {

	public function key(): string {
		return 'wpra.author';
	}

	public function label(): string {
		return __( 'Feed author', 'corinthian-pro' );
	}

	protected function meta_key(): string {
		return WpraApi::AUTHOR_NAME;
	}
}
