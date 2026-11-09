<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Screens\Screen;

/**
 * rank_math_description: plain text, %variables% unexpanded.
 */
final class MetaDescriptionType extends RankMathType implements Filterable, Editable {

	public const META_KEY = 'rank_math_description';

	public function key(): string {
		return 'rankmath.meta_description';
	}

	public function label(): string {
		return __( 'Meta description', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$text = parent::value( $object_id, $config, $screen );
		return is_string( $text ) ? esc_html( wp_trim_words( $text, 30 ) ) : parent::render( $object_id, $config, $screen );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'    => 'contains',
				'label' => __( 'contains', 'corinthian-pro' ),
				'input' => 'text',
			),
			array(
				'op'    => 'empty',
				'label' => __( 'is empty', 'corinthian-pro' ),
				'input' => 'none',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		return MetaText::condition( $alias, self::META_KEY, $op, $value );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => 'textarea',
			'bulk'  => $this->modes(),
		);
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		$text = $this->plain( $value, false );
		if ( $text instanceof \WP_Error ) {
			return $text;
		}
		return '' === $text ? null : $text;
	}
}
