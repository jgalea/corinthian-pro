<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * rank_math_seo_score: an integer 0-100 that Rank Math's editor writes on save.
 */
final class SeoScoreType extends RankMathType implements Sortable, Filterable {

	public const META_KEY = 'rank_math_seo_score';

	public function key(): string {
		return 'rankmath.seo_score';
	}

	public function label(): string {
		return __( 'SEO score', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = parent::value( $object_id, $config, $screen );
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Same bands as Rank Math's own list column: 81+ great, 51-80 good, 0-50 bad.
	 *
	 * @return array{0:string,1:string,2:string} slug, label, background colour
	 */
	public static function level( int $score ): array {
		if ( $score > 80 ) {
			return array( 'good', __( 'Good', 'corinthian-pro' ), '#00a32a' );
		}
		if ( $score > 50 ) {
			return array( 'ok', __( 'OK', 'corinthian-pro' ), '#b26200' );
		}
		return array( 'poor', __( 'Poor', 'corinthian-pro' ), '#d63638' );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$score = $this->value( $object_id, $config, $screen );
		if ( null === $score ) {
			return parent::render( $object_id, $config, $screen );
		}
		[ $slug, $label, $colour ] = self::level( $score );
		return sprintf(
			'<span class="corinthian-rm-score corinthian-rm-score--%1$s"><span style="display:inline-block;min-width:2.2em;padding:0 6px;border-radius:3px;background:%2$s;color:#fff;font-weight:600;text-align:center">%3$d</span> <span>%4$s</span></span>',
			esc_attr( $slug ),
			esc_attr( $colour ),
			$score,
			esc_html( $label )
		);
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, self::META_KEY, 'MAX(CAST({a}.meta_value AS DECIMAL(10,2)))', $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'    => 'gt',
				'label' => '>',
				'input' => 'number',
			),
			array(
				'op'    => 'lt',
				'label' => '<',
				'input' => 'number',
			),
			array(
				'op'    => 'between',
				'label' => __( 'between', 'corinthian-pro' ),
				'input' => 'number',
			),
			array(
				'op'    => 'empty',
				'label' => __( 'is empty', 'corinthian-pro' ),
				'input' => 'none',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		if ( 'empty' === $op ) {
			return PostFilter::wrap( 'NOT ' . PostFilter::meta_exists( $alias, self::META_KEY, "{$alias}.meta_value <> ''" ) );
		}
		if ( ! in_array( $op, array( 'gt', 'lt', 'between' ), true ) ) {
			return null;
		}
		$where = PostFilter::number_where( "CAST({$alias}.meta_value AS DECIMAL(10,2))", $op, $value );
		return null === $where ? null : PostFilter::wrap( PostFilter::meta_exists( $alias, self::META_KEY, "{$alias}.meta_value <> '' AND {$where}" ) );
	}
}
