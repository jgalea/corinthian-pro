<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Math keeps one post meta row per schema, named rank_math_schema_{Type}
 * (for example rank_math_schema_BlogPosting) and holding the JSON-LD array,
 * whose "@type" is the type again. Posts without such rows fall back to the
 * default schema of their post type in Rank Math's titles settings.
 */
final class SchemaTypeType extends RankMathType implements Filterable {

	private const PREFIX = 'rank_math_schema_';

	private const COMMON = array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'Recipe', 'Event', 'FAQPage', 'HowTo', 'VideoObject', 'JobPosting', 'Course', 'Person', 'Book', 'Service', 'SoftwareApplication' );

	public function key(): string {
		return 'rankmath.schema_type';
	}

	public function label(): string {
		return __( 'Schema type', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::PREFIX;
	}

	/**
	 * @return list<string>
	 */
	public static function types_of( int $object_id ): array {
		$types = array();
		foreach ( get_post_meta( $object_id ) as $key => $rows ) {
			if ( ! str_starts_with( (string) $key, self::PREFIX ) ) {
				continue;
			}
			foreach ( (array) $rows as $raw ) {
				$schema = maybe_unserialize( $raw );
				if ( ! is_array( $schema ) || empty( $schema['@type'] ) ) {
					continue;
				}
				foreach ( (array) $schema['@type'] as $type ) {
					if ( is_string( $type ) && '' !== $type ) {
						$types[] = $type;
					}
				}
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Names as Rank Math's own schema column shows them.
	 */
	public static function name( string $type ): string {
		return match ( $type ) {
			'BlogPosting', 'NewsArticle' => 'Article (' . $type . ')',
			'WooCommerceProduct'         => 'WooCommerce Product',
			'EDDProduct'                 => 'EDD Product',
			'VideoObject'                => 'Video',
			'JobPosting'                 => 'Job Posting',
			'SoftwareApplication'        => 'Software Application',
			'MusicGroup', 'MusicAlbum'   => 'Music',
			default                      => $type,
		};
	}

	/**
	 * The schema Rank Math applies when a post has none of its own: the post
	 * type default, which "article" resolves to the chosen article subtype.
	 */
	private function default_type( int $object_id ): ?string {
		if ( metadata_exists( 'post', $object_id, 'rank_math_rich_snippet' ) ) {
			return null;
		}
		$opts = RankMathIntegration::titles_settings();
		$type = get_post_type( $object_id );
		$for  = isset( $opts[ "pt_{$type}_default_rich_snippet" ] ) && is_string( $opts[ "pt_{$type}_default_rich_snippet" ] ) ? $opts[ "pt_{$type}_default_rich_snippet" ] : '';
		if ( 'article' === $for ) {
			$sub = $opts[ "pt_{$type}_default_article_type" ] ?? '';
			return is_string( $sub ) && '' !== $sub ? $sub : 'Article';
		}
		return '' === $for || 'off' === $for ? null : $for;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$types = self::types_of( $object_id );
		return $types ? $types : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$types = self::types_of( $object_id );
		if ( $types ) {
			return esc_html( implode( ', ', array_map( array( self::class, 'name' ), $types ) ) );
		}
		$default = $this->default_type( $object_id );
		if ( null === $default ) {
			return parent::render( $object_id, $config, $screen );
		}
		return '<em>' . esc_html( self::name( $default ) ) . '</em> <small>' . esc_html__( '(default)', 'corinthian-pro' ) . '</small>';
	}

	/**
	 * @return array<string,string>
	 */
	private function options(): array {
		global $wpdb;
		$found = wp_cache_get( 'schema_types', 'corinthian_rankmath' );
		if ( ! is_array( $found ) ) {
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 200", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( 'schema_types', $found, 'corinthian_rankmath', MINUTE_IN_SECONDS );
		}
		$types = self::COMMON;
		foreach ( $found as $meta_key ) {
			$type = substr( (string) $meta_key, strlen( self::PREFIX ) );
			if ( preg_match( '/^[A-Za-z0-9]+$/', $type ) ) {
				$types[] = $type;
			}
		}
		$out = array();
		foreach ( array_unique( $types ) as $type ) {
			$out[ $type ] = self::name( $type );
		}
		asort( $out );
		return $out;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( $this->options() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$types = array();
		foreach ( PostFilter::list_values( $value, false ) as $type ) {
			$type = (string) preg_replace( '/[^a-zA-Z0-9]/', '', (string) $type );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}
		if ( ! $types || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$any = array();
		foreach ( array_values( array_unique( $types ) ) as $i => $type ) {
			$any[] = PostFilter::meta_exists( "{$alias}_{$i}", self::PREFIX . $type );
		}
		$exists = '(' . implode( ' OR ', $any ) . ')';
		return PostFilter::wrap( 'in' === $op ? $exists : "NOT {$exists}" );
	}
}
