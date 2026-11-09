<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

/**
 * _yoast_wpseo_title: the title template as typed, with %%variables%% unexpanded.
 */
final class SeoTitleType extends TextType {

	public function key(): string {
		return 'yoast.seo_title';
	}

	public function label(): string {
		return __( 'SEO title', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'title';
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$template = $this->value( $object_id, $config, $screen );
		if ( ! is_string( $template ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$html     = '<span>' . esc_html( $template ) . '</span>';
		$post     = get_post( $object_id );
		$rendered = $post ? YoastIntegration::call( 'wpseo_replace_vars', $template, $post ) : null;
		if ( is_string( $rendered ) && $rendered !== $template ) {
			$html .= '<br><small class="corinthian-yoast-rendered">' . esc_html( $rendered ) . '</small>';
		}
		return $html;
	}
}
