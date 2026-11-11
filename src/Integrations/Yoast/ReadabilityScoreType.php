<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

defined( 'ABSPATH' ) || exit;

/**
 * _yoast_wpseo_content_score: the readability score.
 */
final class ReadabilityScoreType extends ScoreType {

	public function key(): string {
		return 'yoast.readability_score';
	}

	public function label(): string {
		return __( 'Readability score', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'content_score';
	}
}
