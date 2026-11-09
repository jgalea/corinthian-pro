<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;

/**
 * _yoast_wpseo_focuskw: the single focus keyphrase Yoast's free editor stores.
 */
final class FocusKeyphraseType extends TextType {

	public function key(): string {
		return 'yoast.focus_keyphrase';
	}

	public function label(): string {
		return __( 'Focus keyphrase', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'focuskw';
	}

	protected function feature(): ?string {
		return 'keyword_analysis_active';
	}
}
