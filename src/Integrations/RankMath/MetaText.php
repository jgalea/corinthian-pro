<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Types\PostFilter;

/**
 * "contains" and "is empty" on a text meta value, as an EXISTS subquery so a
 * post with duplicate rows still counts once.
 */
final class MetaText {

	/**
	 * @return array{join:string,where:string}|null
	 */
	public static function condition( string $alias, string $key, string $op, mixed $value ): ?array {
		if ( 'empty' === $op ) {
			return PostFilter::wrap( 'NOT ' . PostFilter::meta_exists( $alias, $key, "{$alias}.meta_value <> ''" ) );
		}
		if ( 'contains' !== $op ) {
			return null;
		}
		$like = PostFilter::text_where( "{$alias}.meta_value", 'contains', $value );
		return null === $like ? null : PostFilter::wrap( PostFilter::meta_exists( $alias, $key, $like ) );
	}
}
