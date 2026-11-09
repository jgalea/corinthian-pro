<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * rank_math_canonical_url: an absolute URL, run through esc_url_raw by Rank Math.
 */
final class CanonicalUrlType extends RankMathType implements Filterable, Editable {

	public const META_KEY = 'rank_math_canonical_url';

	public function key(): string {
		return 'rankmath.canonical_url';
	}

	public function label(): string {
		return __( 'Canonical URL', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$url = parent::value( $object_id, $config, $screen );
		if ( ! is_string( $url ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'    => 'empty',
				'label' => __( 'is empty', 'corinthian-pro' ),
				'input' => 'none',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		return 'empty' === $op ? PostFilter::wrap( 'NOT ' . PostFilter::meta_exists( $alias, self::META_KEY, "{$alias}.meta_value <> ''" ) ) : null;
	}

	public function edit_field( Config $config, Screen $screen ): array {
		// The table editor has no URL input; the value is validated as one on save.
		return array(
			'input' => 'text',
			'bulk'  => $this->modes(),
		);
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		if ( ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return null;
		}
		$url   = esc_url_raw( $raw, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! preg_match( '#^https?://#i', $raw ) ) {
			return $this->invalid( __( 'Enter a full URL starting with http:// or https://.', 'corinthian-pro' ) );
		}
		return $url;
	}

	protected function rank_math_cap(): string {
		return 'onpage_advanced';
	}
}
