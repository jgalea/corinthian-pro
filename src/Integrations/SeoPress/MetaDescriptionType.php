<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

/**
 * _seopress_titles_desc: the description template as typed.
 */
final class MetaDescriptionType extends TextType {

	public function key(): string {
		return 'seopress.meta_description';
	}

	public function label(): string {
		return __( 'Meta description', 'corinthian-pro' );
	}

	protected function meta_key( Config $config, string $post_type ): string {
		return '_seopress_titles_desc';
	}

	protected function single_line(): bool {
		return false;
	}

	protected function input(): string {
		return 'textarea';
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$text = $this->value( $object_id, $config, $screen );
		return is_string( $text ) ? esc_html( wp_trim_words( $text, 30 ) ) : parent::render( $object_id, $config, $screen );
	}
}
