<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

/**
 * _yoast_wpseo_linkdex: the SEO score for the focus keyphrase.
 */
final class SeoScoreType extends ScoreType {

	public function key(): string {
		return 'yoast.seo_score';
	}

	public function label(): string {
		return __( 'SEO score', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'linkdex';
	}
}
