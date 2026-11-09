<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Screens\Screen;

/**
 * rank_math_title: the title template as typed, with %variables% unexpanded.
 */
final class SeoTitleType extends RankMathType implements Filterable, Editable {

	public const META_KEY = 'rank_math_title';

	public function key(): string {
		return 'rankmath.seo_title';
	}

	public function label(): string {
		return __( 'SEO title', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	/**
	 * The title as Rank Math would output it, or null when its replacer isn't
	 * loaded (it only exists inside a running Rank Math).
	 */
	public static function rendered( string $template, int $object_id ): ?string {
		$post = get_post( $object_id );
		if ( ! $post || ! is_callable( array( 'RankMath\Helper', 'replace_vars' ) ) ) {
			return null;
		}
		$out = call_user_func( array( 'RankMath\Helper', 'replace_vars' ), $template, $post );
		return is_string( $out ) ? $out : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$template = parent::value( $object_id, $config, $screen );
		if ( ! is_string( $template ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$html     = '<span>' . esc_html( $template ) . '</span>';
		$rendered = self::rendered( $template, $object_id );
		if ( null !== $rendered && $rendered !== $template ) {
			$html .= '<br><small class="corinthian-rm-rendered">' . esc_html( $rendered ) . '</small>';
		}
		return $html;
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
			'input' => 'text',
			'bulk'  => $this->modes(),
		);
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		$text = $this->plain( $value, true );
		if ( $text instanceof \WP_Error ) {
			return $text;
		}
		return '' === $text ? null : $text;
	}
}
