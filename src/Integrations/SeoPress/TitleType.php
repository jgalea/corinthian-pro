<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;

defined( 'ABSPATH' ) || exit;

/**
 * _seopress_titles_title: the title template as typed, with %%variables%% unexpanded.
 */
final class TitleType extends TextType {

	public function key(): string {
		return 'seopress.title';
	}

	public function label(): string {
		return __( 'SEO title', 'corinthian-pro' );
	}

	protected function meta_key( Config $config, string $post_type ): string {
		return '_seopress_titles_title';
	}
}
