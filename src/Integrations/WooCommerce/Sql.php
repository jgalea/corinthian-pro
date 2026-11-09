<?php

namespace CorinthianPro\Integrations\WooCommerce;

/**
 * Boolean SQL for filter rules, built on a column expression. Every value goes through $wpdb->prepare().
 */
final class Sql {

	/**
	 * Operators per input kind, in display order.
	 *
	 * @param array<string,string> $options value => label, for select kinds.
	 * @return list<array<string,mixed>>
	 */
	public static function operators( string $kind, array $options = array() ): array {
		$empty = array(
			array(
				'op'    => 'empty',
				'label' => __( 'is empty', 'corinthian-pro' ),
				'input' => 'none',
			),
			array(
				'op'    => 'not_empty',
				'label' => __( 'is not empty', 'corinthian-pro' ),
				'input' => 'none',
			),
		);
		switch ( $kind ) {
			case 'number':
				return array_merge(
					array(
						array(
							'op'    => 'eq',
							'label' => '=',
							'input' => 'number',
						),
						array(
							'op'    => 'neq',
							'label' => '≠',
							'input' => 'number',
						),
						array(
							'op'    => 'gt',
							'label' => '>',
							'input' => 'number',
						),
						array(
							'op'    => 'gte',
							'label' => '≥',
							'input' => 'number',
						),
						array(
							'op'    => 'lt',
							'label' => '<',
							'input' => 'number',
						),
						array(
							'op'    => 'lte',
							'label' => '≤',
							'input' => 'number',
						),
						array(
							'op'    => 'between',
							'label' => __( 'between', 'corinthian-pro' ),
							'input' => 'number',
						),
					),
					$empty
				);
			case 'date':
				return array_merge(
					array(
						array(
							'op'    => 'before',
							'label' => __( 'before', 'corinthian-pro' ),
							'input' => 'date',
						),
						array(
							'op'    => 'after',
							'label' => __( 'after', 'corinthian-pro' ),
							'input' => 'date',
						),
						array(
							'op'    => 'within_days',
							'label' => __( 'in the last (days)', 'corinthian-pro' ),
							'input' => 'number',
						),
						array(
							'op'    => 'older_than_days',
							'label' => __( 'older than (days)', 'corinthian-pro' ),
							'input' => 'number',
						),
					),
					$empty
				);
			case 'select':
				return array(
					array(
						'op'      => 'in',
						'label'   => __( 'is any of', 'corinthian-pro' ),
						'input'   => 'select',
						'options' => $options,
					),
					array(
						'op'      => 'not_in',
						'label'   => __( 'is none of', 'corinthian-pro' ),
						'input'   => 'select',
						'options' => $options,
					),
				);
			case 'bool':
				return array(
					array(
						'op'      => 'eq',
						'label'   => __( 'is', 'corinthian-pro' ),
						'input'   => 'select',
						'options' => array(
							'1' => __( 'Yes', 'corinthian-pro' ),
							'0' => __( 'No', 'corinthian-pro' ),
						),
					),
				);
		}
		return array_merge(
			array(
				array(
					'op'    => 'contains',
					'label' => __( 'contains', 'corinthian-pro' ),
					'input' => 'text',
				),
				array(
					'op'    => 'not_contains',
					'label' => __( 'does not contain', 'corinthian-pro' ),
					'input' => 'text',
				),
				array(
					'op'    => 'eq',
					'label' => __( 'is', 'corinthian-pro' ),
					'input' => 'text',
				),
				array(
					'op'    => 'neq',
					'label' => __( 'is not', 'corinthian-pro' ),
					'input' => 'text',
				),
				array(
					'op'    => 'starts',
					'label' => __( 'starts with', 'corinthian-pro' ),
					'input' => 'text',
				),
			),
			$empty
		);
	}

	/**
	 * One condition for a column expression, or null when the op or value is invalid.
	 *
	 * @param bool $ts True when the column holds a unix timestamp rather than a UTC datetime.
	 */
	public static function condition( string $kind, string $col, string $op, mixed $value, bool $ts = false ): ?string {
		return match ( $kind ) {
			'number' => self::number( $col, $op, $value ),
			'date'   => self::date( $col, $op, $value, $ts ),
			'select' => self::in( $col, $op, $value ),
			'bool'   => self::flag( $col, $op, $value ),
			default  => self::text( $col, $op, $value ),
		};
	}

	public static function text( string $col, string $op, mixed $value ): ?string {
		global $wpdb;
		if ( 'empty' === $op ) {
			return "({$col} IS NULL OR {$col} = '')";
		}
		if ( 'not_empty' === $op ) {
			return "({$col} IS NOT NULL AND {$col} <> '')";
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$like = $wpdb->esc_like( $value );
		return match ( $op ) {
			'contains'     => $wpdb->prepare( "{$col} LIKE %s", '%' . $like . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'not_contains' => $wpdb->prepare( "({$col} IS NULL OR {$col} NOT LIKE %s)", '%' . $like . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'starts'       => $wpdb->prepare( "{$col} LIKE %s", $like . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'eq'           => $wpdb->prepare( "{$col} = %s", $value ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'neq'          => $wpdb->prepare( "({$col} IS NULL OR {$col} <> %s)", $value ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			default        => null,
		};
	}

	public static function number( string $col, string $op, mixed $value ): ?string {
		global $wpdb;
		if ( 'empty' === $op ) {
			return "{$col} IS NULL";
		}
		if ( 'not_empty' === $op ) {
			return "{$col} IS NOT NULL";
		}
		if ( 'between' === $op ) {
			if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
				return null;
			}
			return $wpdb->prepare( "{$col} BETWEEN %f AND %f", (float) min( $value ), (float) max( $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$sign = array(
			'eq'  => '=',
			'gt'  => '>',
			'gte' => '>=',
			'lt'  => '<',
			'lte' => '<=',
		);
		if ( 'neq' === $op ) {
			return $wpdb->prepare( "({$col} IS NULL OR {$col} <> %f)", (float) $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! isset( $sign[ $op ] ) ) {
			return null;
		}
		return $wpdb->prepare( "{$col} {$sign[ $op ]} %f", (float) $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Dates are read in the site's timezone. "before" is earlier than the start of that day,
	 * "after" is later than the end of it.
	 */
	public static function date( string $col, string $op, mixed $value, bool $ts ): ?string {
		global $wpdb;
		$fmt = static fn( int $t ) => $ts ? (string) $t : gmdate( 'Y-m-d H:i:s', $t );
		$arg = $ts ? '%d' : '%s';
		if ( 'empty' === $op ) {
			return $ts ? "({$col} IS NULL OR {$col} = 0)" : "({$col} IS NULL OR {$col} = '0000-00-00 00:00:00')";
		}
		if ( 'not_empty' === $op ) {
			return $ts ? "({$col} IS NOT NULL AND {$col} > 0)" : "({$col} IS NOT NULL AND {$col} <> '0000-00-00 00:00:00')";
		}
		$non_empty = $ts ? "{$col} > 0" : "{$col} <> '0000-00-00 00:00:00'";

		if ( 'within_days' === $op || 'older_than_days' === $op ) {
			if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
				return null;
			}
			$limit = $fmt( time() - (int) $value * DAY_IN_SECONDS );
			$bound = $ts ? (int) $limit : $limit;
			$now   = $ts ? time() : $fmt( time() );
			return 'within_days' === $op
				? $wpdb->prepare( "({$col} IS NOT NULL AND {$non_empty} AND {$col} >= {$arg} AND {$col} <= {$arg})", $bound, $now ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				: $wpdb->prepare( "({$col} IS NOT NULL AND {$non_empty} AND {$col} < {$arg})", $bound ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( 'before' !== $op && 'after' !== $op ) {
			return null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		try {
			$day = new \DateTimeImmutable( $value . ' 00:00:00', wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( 'before' === $op ) {
			$bound = $ts ? $day->getTimestamp() : $fmt( $day->getTimestamp() );
			return $wpdb->prepare( "({$col} IS NOT NULL AND {$non_empty} AND {$col} < {$arg})", $bound ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		$next  = $day->modify( '+1 day' );
		$bound = $ts ? $next->getTimestamp() : $fmt( $next->getTimestamp() );
		return $wpdb->prepare( "({$col} IS NOT NULL AND {$col} >= {$arg})", $bound ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	public static function in( string $col, string $op, mixed $value ): ?string {
		global $wpdb;
		$list = array_values( array_filter( array_map( 'strval', is_array( $value ) ? $value : array( $value ) ), static fn( $v ) => '' !== $v ) );
		if ( ! $list || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$holders = implode( ',', array_fill( 0, count( $list ), '%s' ) );
		if ( 'in' === $op ) {
			return $wpdb->prepare( "{$col} IN ({$holders})", $list ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		return $wpdb->prepare( "({$col} IS NULL OR {$col} NOT IN ({$holders}))", $list ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * A yes/no column stored as 'yes'/'no' or 1/0.
	 */
	public static function flag( string $col, string $op, mixed $value ): ?string {
		if ( 'eq' !== $op || ! in_array( (string) $value, array( '0', '1' ), true ) ) {
			return null;
		}
		$on = "{$col} IN ('1', 'yes')";
		return '1' === (string) $value ? "({$col} IS NOT NULL AND {$on})" : "({$col} IS NULL OR NOT ({$on}))";
	}

	/**
	 * Scalar subquery for one post meta value, so no join (and no duplicate rows) is needed.
	 * $cast: 'text', 'number' or 'ts' (unix timestamp, empty is NULL).
	 */
	public static function meta( string $id_col, string $key, string $cast = 'text' ): string {
		global $wpdb;
		$value = $wpdb->prepare( "(SELECT MAX(m.meta_value) FROM {$wpdb->postmeta} AS m WHERE m.post_id = {$id_col} AND m.meta_key = %s)", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return match ( $cast ) {
			'number' => "CAST(NULLIF({$value}, '') AS DECIMAL(30,6))",
			'ts'     => "CAST(NULLIF({$value}, '') AS UNSIGNED)",
			default  => $value,
		};
	}

	/**
	 * Whether the object has any of the given terms in a taxonomy (all terms when $slugs is empty).
	 *
	 * @param list<string> $slugs
	 */
	public static function has_term( string $id_col, string $taxonomy, array $slugs = array() ): string {
		global $wpdb;
		$sql = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->term_relationships} AS r INNER JOIN {$wpdb->term_taxonomy} AS x ON x.term_taxonomy_id = r.term_taxonomy_id INNER JOIN {$wpdb->terms} AS tm ON tm.term_id = x.term_id WHERE r.object_id = {$id_col} AND x.taxonomy = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$taxonomy
		);
		if ( $slugs ) {
			$holders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
			$sql    .= $wpdb->prepare( " AND tm.slug IN ({$holders})", $slugs ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		return $sql . ')';
	}
}
