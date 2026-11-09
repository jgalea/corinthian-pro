<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * rank_math_pillar_content: the string 'on', or 'off' after Rank Math's own toggle turns it off.
 */
final class PillarContentType extends RankMathType implements Sortable, Filterable, Editable {

	public const META_KEY = 'rank_math_pillar_content';

	public function key(): string {
		return 'rankmath.pillar_content';
	}

	public function label(): string {
		return __( 'Pillar content', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$stored = parent::value( $object_id, $config, $screen );
		return null === $stored ? null : 'on' === $stored;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		if ( true !== $this->value( $object_id, $config, $screen ) ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}
		return '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span> ' . esc_html__( 'Pillar', 'corinthian-pro' );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, self::META_KEY, "MAX({a}.meta_value = 'on')", $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::yes_no_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$flag = PostFilter::flag( $value );
		if ( 'eq' !== $op || null === $flag ) {
			return null;
		}
		$on = PostFilter::meta_exists( $alias, self::META_KEY, "{$alias}.meta_value = 'on'" );
		return PostFilter::wrap( $flag ? $on : "NOT {$on}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => 'toggle',
			'bulk'  => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		return true === $this->value( $object_id, $config, $screen ) ? '1' : '0';
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		$word = is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '' );
		if ( in_array( $word, array( '1', 'on', 'true', 'yes' ), true ) ) {
			return 'on';
		}
		if ( in_array( $word, array( '0', 'off', 'false', 'no' ), true ) ) {
			return 'off';
		}
		return $this->invalid( __( 'Choose on or off.', 'corinthian-pro' ) );
	}
}
