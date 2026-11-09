<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Screens\Screen;

/**
 * rank_math_focus_keyword: one comma-separated string, the first keyword is the primary.
 */
final class FocusKeywordType extends RankMathType implements Sortable, Filterable, Editable {

	public const META_KEY = 'rank_math_focus_keyword';

	public function key(): string {
		return 'rankmath.focus_keyword';
	}

	public function label(): string {
		return __( 'Focus keywords', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	/**
	 * @return list<string>
	 */
	public static function split( string $stored ): array {
		$out = array();
		foreach ( explode( ',', $stored ) as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' !== $keyword ) {
				$out[] = $keyword;
			}
		}
		return $out;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = parent::value( $object_id, $config, $screen );
		return is_string( $value ) && array() !== self::split( $value ) ? implode( ', ', self::split( $value ) ) : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$stored = parent::value( $object_id, $config, $screen );
		$words  = is_string( $stored ) ? self::split( $stored ) : array();
		if ( ! $words ) {
			return parent::render( $object_id, $config, $screen );
		}
		$primary = array_shift( $words );
		$html    = '<strong>' . esc_html( $primary ) . '</strong>';
		if ( $words ) {
			$html .= ', ' . esc_html( implode( ', ', $words ) );
		}
		return $html;
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, self::META_KEY, "TRIM(SUBSTRING_INDEX(MAX({a}.meta_value), ',', 1))", $order );
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

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return is_string( $value ) ? $value : null;
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		$text = $this->plain( $value, true );
		if ( $text instanceof \WP_Error ) {
			return $text;
		}
		$words = array();
		foreach ( self::split( $text ) as $word ) {
			if ( ! in_array( strtolower( $word ), array_map( 'strtolower', $words ), true ) ) {
				$words[] = $word;
			}
		}
		if ( ! $words ) {
			return null;
		}
		$max = (int) apply_filters( 'rank_math/focus_keyword/maxtags', 5 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own filter.
		if ( count( $words ) > $max ) {
			/* translators: %d: maximum number of focus keywords */
			return $this->invalid( sprintf( __( 'Use at most %d focus keywords.', 'corinthian-pro' ), $max ) );
		}
		return implode( ',', $words );
	}
}
