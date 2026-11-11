<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * _yoast_wpseo_metadesc: the description template as typed, with %%variables%% unexpanded.
 */
final class MetaDescriptionType extends TextType {

	public function key(): string {
		return 'yoast.meta_description';
	}

	public function label(): string {
		return __( 'Meta description', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'metadesc';
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
