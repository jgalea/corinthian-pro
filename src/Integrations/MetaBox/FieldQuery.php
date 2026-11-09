<?php

namespace CorinthianPro\Integrations\MetaBox;

use Corinthian\Screens\Screen;

/**
 * SQL for sorting and filtering on a Meta Box field's stored value. Values
 * are read from the meta table that matches the screen's object type, keyed
 * by the field id. Filters use EXISTS subqueries because list fields keep one
 * meta row per item and a join would repeat the object.
 */
final class FieldQuery {

	/**
	 * @return array{table:string,id:string,outer:string}|null
	 */
	private static function meta( Screen $screen ): ?array {
		global $wpdb;
		return match ( $screen->object_type() ) {
			'post' => array(
				'table' => $wpdb->postmeta,
				'id'    => 'post_id',
				'outer' => "{$wpdb->posts}.ID",
			),
			'user' => array(
				'table' => $wpdb->usermeta,
				'id'    => 'user_id',
				'outer' => "{$wpdb->users}.ID",
			),
			'term' => array(
				'table' => $wpdb->termmeta,
				'id'    => 'term_id',
				'outer' => 't.term_id',
			),
			default => null,
		};
	}

	private static function exists( Screen $screen, string $alias, string $name, string $where, bool $negate ): ?string {
		global $wpdb;
		$meta = self::meta( $screen );
		if ( ! $meta ) {
			return null;
		}
		$sql = $wpdb->prepare( "SELECT 1 FROM {$meta['table']} AS {$alias} WHERE {$alias}.{$meta['id']} = {$meta['outer']} AND {$alias}.meta_key = %s", $name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $where ) {
			$sql .= ' AND ' . $where;
		}
		return ( $negate ? 'NOT EXISTS (' : 'EXISTS (' ) . $sql . ')';
	}

	/**
	 * @param array<string,string> $clauses
	 * @param array<string,mixed>  $field
	 * @param 'ASC'|'DESC'         $order
	 * @return array<string,string>
	 */
	public static function sort( array $clauses, array $field, string $mode, string $alias, string $order, Screen $screen ): array {
		global $wpdb;
		$meta = self::meta( $screen );
		if ( ! $meta || ! FieldKind::sortable( $field, $mode ) ) {
			return $clauses;
		}
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$meta['table']} AS {$alias} ON ({$alias}.{$meta['id']} = {$meta['outer']} AND {$alias}.meta_key = %s)", (string) $field['id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['groupby'] = $meta['outer'];

		$spec = FieldKind::date_spec( $field );
		$expr = match ( FieldKind::family( $field ) ) {
			'number' => "MAX(CAST({$alias}.meta_value AS DECIMAL(30,6)))",
			'bool'   => "MAX(CAST({$alias}.meta_value AS UNSIGNED))",
			'date', 'datetime' => $spec['timestamp'] ? "MAX(CAST({$alias}.meta_value AS SIGNED))" : "MAX({$alias}.meta_value)",
			default  => "MAX({$alias}.meta_value)",
		};
		// Empty values always sort last; they share one NULL key so the tie-break decides their order.
		$empty              = "(MAX({$alias}.meta_value) IS NULL OR MAX({$alias}.meta_value) = '')";
		$clauses['orderby'] = "{$empty} ASC, CASE WHEN {$empty} THEN NULL ELSE {$expr} END {$order}";
		return $clauses;
	}

	/**
	 * @param array<int|string,string> $options
	 * @return array<string,mixed>
	 */
	private static function op( string $op, string $label, string $input, array $options = array() ): array {
		$out = array(
			'op'    => $op,
			'label' => $label,
			'input' => $input,
		);
		if ( $options ) {
			$out['options'] = $options;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return list<array<string,mixed>>
	 */
	public static function operators( array $field, string $mode ): array {
		if ( ! FieldKind::filterable( $field, $mode ) ) {
			return array();
		}
		$empty = array(
			self::op( 'empty', __( 'is empty', 'corinthian-pro' ), 'none' ),
			self::op( 'not_empty', __( 'is not empty', 'corinthian-pro' ), 'none' ),
		);
		if ( FieldKind::cloned( $field ) ) {
			return $empty;
		}
		$family = FieldKind::family( $field );
		switch ( $family ) {
			case 'text':
				return array_merge(
					array(
						self::op( 'contains', __( 'contains', 'corinthian-pro' ), 'text' ),
						self::op( 'not_contains', __( 'does not contain', 'corinthian-pro' ), 'text' ),
						self::op( 'eq', __( 'is', 'corinthian-pro' ), 'text' ),
						self::op( 'neq', __( 'is not', 'corinthian-pro' ), 'text' ),
						self::op( 'starts', __( 'starts with', 'corinthian-pro' ), 'text' ),
						self::op( 'ends', __( 'ends with', 'corinthian-pro' ), 'text' ),
					),
					$empty
				);
			case 'number':
				return array_merge(
					array(
						self::op( 'eq', '=', 'number' ),
						self::op( 'neq', '!=', 'number' ),
						self::op( 'gt', '>', 'number' ),
						self::op( 'gte', '>=', 'number' ),
						self::op( 'lt', '<', 'number' ),
						self::op( 'lte', '<=', 'number' ),
						self::op( 'between', __( 'between', 'corinthian-pro' ), 'number' ),
					),
					$empty
				);
			case 'date':
			case 'datetime':
				if ( ! FieldKind::date_orderable( $field ) ) {
					return $empty;
				}
				return array_merge(
					array(
						self::op( 'eq', __( 'on', 'corinthian-pro' ), 'date' ),
						self::op( 'before', __( 'before', 'corinthian-pro' ), 'date' ),
						self::op( 'after', __( 'after', 'corinthian-pro' ), 'date' ),
						self::op( 'between', __( 'between', 'corinthian-pro' ), 'date' ),
						self::op( 'within_days', __( 'in the last (days)', 'corinthian-pro' ), 'number' ),
						self::op( 'older_than_days', __( 'older than (days)', 'corinthian-pro' ), 'number' ),
					),
					$empty
				);
			case 'time':
				if ( ! FieldKind::date_orderable( $field ) ) {
					return $empty;
				}
				return array_merge(
					array(
						self::op( 'before', __( 'before', 'corinthian-pro' ), 'text' ),
						self::op( 'after', __( 'after', 'corinthian-pro' ), 'text' ),
					),
					$empty
				);
			case 'choice':
				$options = FieldKind::choices( $field );
				return array_merge(
					array(
						self::op( 'in', __( 'is any of', 'corinthian-pro' ), 'select', $options ),
						self::op( 'not_in', __( 'is none of', 'corinthian-pro' ), 'select', $options ),
					),
					$empty
				);
			case 'bool':
				return array(
					self::op(
						'eq',
						__( 'is', 'corinthian-pro' ),
						'select',
						array(
							'1' => __( 'Yes', 'corinthian-pro' ),
							'0' => __( 'No', 'corinthian-pro' ),
						)
					),
				);
			case 'relation':
				return array_merge(
					array(
						self::op( 'contains', __( 'includes ID', 'corinthian-pro' ), 'number' ),
						self::op( 'not_contains', __( 'does not include ID', 'corinthian-pro' ), 'number' ),
					),
					$empty
				);
		}
		return $empty;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array{join:string,where:string}|null
	 */
	public static function condition( array $field, string $mode, string $op, mixed $value, string $alias, Screen $screen ): ?array {
		if ( ! in_array( $op, array_column( self::operators( $field, $mode ), 'op' ), true ) ) {
			return null;
		}
		$col  = "{$alias}.meta_value";
		$name = (string) $field['id'];
		$v    = is_array( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : '' );

		if ( 'empty' === $op || 'not_empty' === $op ) {
			$sql = self::exists( $screen, $alias, $name, "{$col} <> ''", 'empty' === $op );
			return null === $sql ? null : array(
				'join'  => '',
				'where' => $sql,
			);
		}

		$rule = self::typed( $field, FieldKind::family( $field ), $op, $v, $col );
		if ( null === $rule ) {
			return null;
		}
		$sql = self::exists( $screen, $alias, $name, $rule[0], $rule[1] );
		return null === $sql ? null : array(
			'join'  => '',
			'where' => $sql,
		);
	}

	/**
	 * A row-level predicate plus whether the rule means "no row matches it".
	 *
	 * @param array<string,mixed> $field
	 * @param array<mixed>|string $v
	 * @return array{0:string,1:bool}|null
	 */
	private static function typed( array $field, string $family, string $op, array|string $v, string $col ): ?array {
		global $wpdb;
		switch ( $family ) {
			case 'text':
				if ( ! is_string( $v ) || '' === $v ) {
					return null;
				}
				$like = $wpdb->esc_like( $v );
				return match ( $op ) {
					'contains'     => array( $wpdb->prepare( "{$col} LIKE %s", '%' . $like . '%' ), false ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'not_contains' => array( $wpdb->prepare( "{$col} LIKE %s", '%' . $like . '%' ), true ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'eq'           => array( $wpdb->prepare( "{$col} = %s", $v ), false ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'neq'          => array( $wpdb->prepare( "{$col} = %s", $v ), true ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'starts'       => array( $wpdb->prepare( "{$col} LIKE %s", $like . '%' ), false ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'ends'         => array( $wpdb->prepare( "{$col} LIKE %s", '%' . $like ), false ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					default        => null,
				};

			case 'number':
				$num = "CAST({$col} AS DECIMAL(30,6))";
				if ( 'between' === $op ) {
					if ( ! is_array( $v ) || 2 !== count( $v ) || ! is_numeric( $v[0] ?? null ) || ! is_numeric( $v[1] ?? null ) ) {
						return null;
					}
					return array( $wpdb->prepare( "({$col} <> '' AND {$num} BETWEEN %f AND %f)", (float) min( $v ), (float) max( $v ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				if ( ! is_string( $v ) || ! is_numeric( $v ) ) {
					return null;
				}
				$symbol = array(
					'eq'  => '=',
					'neq' => '=',
					'gt'  => '>',
					'gte' => '>=',
					'lt'  => '<',
					'lte' => '<=',
				)[ $op ] ?? null;
				return null === $symbol ? null : array( $wpdb->prepare( "({$col} <> '' AND {$num} {$symbol} %f)", (float) $v ), 'neq' === $op ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			case 'date':
			case 'datetime':
			case 'time':
				return self::date_rule( $field, $family, $op, $v, $col );

			case 'bool':
				if ( 'eq' !== $op || ! in_array( $v, array( '0', '1' ), true ) ) {
					return null;
				}
				return array( "{$col} = '1'", '0' === $v );

			case 'choice':
				if ( ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
					return null;
				}
				$wanted = array_values( array_intersect( array_unique( array_filter( array_map( 'strval', (array) $v ), static fn( $x ) => '' !== $x ) ), array_map( 'strval', array_keys( FieldKind::choices( $field ) ) ) ) );
				if ( ! $wanted ) {
					return null;
				}
				$list = $wpdb->prepare( implode( ',', array_fill( 0, count( $wanted ), '%s' ) ), $wanted ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare
				return array( "{$col} IN ({$list})", 'not_in' === $op );

			case 'relation':
				if ( ! is_string( $v ) || ! ctype_digit( $v ) || '0' === $v ) {
					return null;
				}
				return array( $wpdb->prepare( "FIND_IN_SET(%s, {$col}) > 0", $v ), 'not_contains' === $op ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $field
	 * @param array<mixed>|string $v
	 * @return array{0:string,1:bool}|null
	 */
	private static function date_rule( array $field, string $family, string $op, array|string $v, string $col ): ?array {
		global $wpdb;
		if ( ! FieldKind::date_orderable( $field ) ) {
			return null;
		}
		$spec = FieldKind::date_spec( $field );
		$utc  = new \DateTimeZone( 'UTC' );

		if ( 'time' === $family ) {
			if ( ! is_string( $v ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v ) ) {
				return null;
			}
			$time = \DateTimeImmutable::createFromFormat( '!H:i:s', 5 === strlen( $v ) ? $v . ':00' : $v, $utc );
			if ( ! $time || ! in_array( $op, array( 'before', 'after' ), true ) ) {
				return null;
			}
			$symbol = 'before' === $op ? '<' : '>';
			return array( $wpdb->prepare( "({$col} <> '' AND {$col} {$symbol} %s)", $time->format( $spec['store'] ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$bound = static function ( string $day, bool $end ) use ( $spec, $utc ) {
			$dt = new \DateTimeImmutable( $day . ( $end ? ' 23:59:59' : ' 00:00:00' ), $utc );
			return $spec['timestamp'] ? $dt->getTimestamp() : $dt->format( $spec['store'] );
		};
		$valid = static function ( mixed $day ): bool {
			return is_string( $day ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		};
		$expr = $spec['timestamp'] ? "CAST({$col} AS SIGNED)" : $col;
		$mark = $spec['timestamp'] ? '%d' : '%s';
		$ago  = static fn( int $days ): string => wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$guard = "{$col} <> ''";

		switch ( $op ) {
			case 'eq':
			case 'before':
			case 'after':
				if ( ! $valid( $v ) ) {
					return null;
				}
				$day = (string) $v;
				if ( 'eq' === $op ) {
					return array( $wpdb->prepare( "({$guard} AND {$expr} >= {$mark} AND {$expr} <= {$mark})", $bound( $day, false ), $bound( $day, true ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
				}
				return 'before' === $op
					? array( $wpdb->prepare( "({$guard} AND {$expr} < {$mark})", $bound( $day, false ) ), false ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
					: array( $wpdb->prepare( "({$guard} AND {$expr} > {$mark})", $bound( $day, true ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
			case 'between':
				if ( ! is_array( $v ) || 2 !== count( $v ) || ! $valid( $v[0] ?? null ) || ! $valid( $v[1] ?? null ) ) {
					return null;
				}
				$days = array( (string) $v[0], (string) $v[1] );
				sort( $days );
				return array( $wpdb->prepare( "({$guard} AND {$expr} >= {$mark} AND {$expr} <= {$mark})", $bound( $days[0], false ), $bound( $days[1], true ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
			case 'within_days':
				if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
					return null;
				}
				return array( $wpdb->prepare( "({$guard} AND {$expr} >= {$mark} AND {$expr} <= {$mark})", $bound( $ago( (int) $v ), false ), $bound( $ago( 0 ), true ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
			case 'older_than_days':
				if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
					return null;
				}
				return array( $wpdb->prepare( "({$guard} AND {$expr} < {$mark})", $bound( $ago( (int) $v ), false ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mark is a literal %d or %s chosen above.
		}
		return null;
	}
}
