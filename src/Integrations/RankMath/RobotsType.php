<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * rank_math_robots: a serialized list such as array( 'index', 'nofollow' ).
 * Exactly one of index and noindex is always present when Rank Math writes it.
 * No row means the site-wide default from Rank Math's settings applies.
 */
final class RobotsType extends RankMathType implements Filterable, Editable {

	public const META_KEY = 'rank_math_robots';

	private const ORDER = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	public function key(): string {
		return 'rankmath.robots';
	}

	public function label(): string {
		return __( 'Robots', 'corinthian-pro' );
	}

	protected function meta_key( Config $config ): string {
		return self::META_KEY;
	}

	protected function modes(): array {
		return array( 'set', 'clear', 'append', 'remove' );
	}

	/**
	 * @return array<string,string>
	 */
	private function options(): array {
		return array(
			'noindex'      => 'noindex',
			'nofollow'     => 'nofollow',
			'noarchive'    => 'noarchive',
			'noimageindex' => 'noimageindex',
			'nosnippet'    => 'nosnippet',
		);
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$stored = get_post_meta( $object_id, self::META_KEY, true );
		$list   = is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
		return $list ? $list : null;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( $this->options() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$wanted = array_values( array_intersect( PostFilter::list_values( $value, false ), array_keys( $this->options() ) ) );
		if ( ! $wanted || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$likes = array();
		foreach ( $wanted as $flag ) {
			// Serialized strings are quoted, so '"index"' never matches '"noindex"'.
			$likes[] = $wpdb->prepare( "{$alias}.meta_value LIKE %s", '%"' . $wpdb->esc_like( (string) $flag ) . '"%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$exists = PostFilter::meta_exists( $alias, self::META_KEY, '(' . implode( ' OR ', $likes ) . ')' );
		return PostFilter::wrap( 'in' === $op ? $exists : "NOT {$exists}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => 'multiselect',
			'options' => $this->options(),
			'bulk'    => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return array_values( array_intersect( is_array( $value ) ? $value : array(), array_keys( $this->options() ) ) );
	}

	/**
	 * @return list<string>|\WP_Error
	 */
	private function parse( mixed $value ): array|\WP_Error {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$flags = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
			}
			$item = trim( strtolower( $item ) );
			if ( '' === $item || 'index' === $item ) {
				continue;
			}
			if ( ! isset( $this->options()[ $item ] ) ) {
				/* translators: %s: robots directive */
				return $this->invalid( sprintf( __( '"%s" is not a robots setting.', 'corinthian-pro' ), $item ) );
			}
			$flags[] = $item;
		}
		return $flags;
	}

	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		$flags = $this->parse( $value );
		if ( $flags instanceof \WP_Error ) {
			return $flags;
		}
		$current = is_array( $existing ) ? array_values( array_filter( $existing, 'is_string' ) ) : array();

		if ( 'append' === $mode ) {
			$flags = array_merge( $current, $flags );
		} elseif ( 'remove' === $mode ) {
			if ( ! $current ) {
				return null;
			}
			$flags = array_diff( $current, $flags );
		}
		$flags = array_diff( array_unique( $flags ), array( 'index' ) );
		if ( ! in_array( 'noindex', $flags, true ) ) {
			$flags[] = 'index';
		}
		return array_values( array_intersect( self::ORDER, $flags ) );
	}

	protected function rank_math_cap(): string {
		return 'onpage_advanced';
	}
}
