<?php

namespace CorinthianPro\Integrations\Acf;

use Corinthian\Screens\Screen;

/**
 * SQL for sorting and filtering on an ACF field's stored value. Values are
 * read from the meta table that matches the screen's object type, keyed by
 * the field name.
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

	private static function join( Screen $screen, string $alias, string $name ): ?string {
		global $wpdb;
		$meta = self::meta( $screen );
		if ( ! $meta ) {
			return null;
		}
		return $wpdb->prepare( " LEFT JOIN {$meta['table']} AS {$alias} ON ({$alias}.{$meta['id']} = {$meta['outer']} AND {$alias}.meta_key = %s)", $name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string,string> $clauses
	 * @param array<string,mixed>  $field
	 * @param 'ASC'|'DESC'         $order
	 * @return array<string,string>
	 */
	public static function sort( array $clauses, array $field, string $alias, string $order, Screen $screen ): array {
		$meta = self::meta( $screen );
		$join = self::join( $screen, $alias, (string) $field['name'] );
		if ( ! $meta || null === $join || ! FieldKind::sortable( $field ) ) {
			return $clauses;
		}

		$clauses['join']   .= $join;
		$clauses['groupby'] = $meta['outer'];

		$expr = match ( FieldKind::family( $field ) ) {
			'number' => "MAX(CAST({$alias}.meta_value AS DECIMAL(30,6)))",
			'bool'   => "MAX(CAST({$alias}.meta_value AS UNSIGNED))",
			default  => "MAX({$alias}.meta_value)",
		};
		// Empty values always sort last; they share one NULL key so the tie-break decides their order.
		$empty              = "(MAX({$alias}.meta_value) IS NULL OR MAX({$alias}.meta_value) = '')";
		$clauses['orderby'] = "{$empty} ASC, CASE WHEN {$empty} THEN NULL ELSE {$expr} END {$order}";
		return $clauses;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return list<array<string,mixed>>
	 */
	public static function operators( array $field ): array {
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
		switch ( FieldKind::family( $field ) ) {
			case 'text':
				return array_merge(
					array(
						array(
							'op'    => 'contains',
							'label' => __( 'contains', 'corinthian-pro' ),
							'input' => 'text',
						),
						array(
							'op'    => 'eq',
							'label' => __( 'is', 'corinthian-pro' ),
							'input' => 'text',
						),
					),
					$empty
				);
			case 'number':
				return array_merge(
					array(
						array(
							'op'    => 'eq',
							'label' => '=',
							'input' => 'number',
						),
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
					),
					$empty
				);
			case 'date':
			case 'datetime':
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
					),
					$empty
				);
			case 'time':
				return array_merge(
					array(
						array(
							'op'    => 'before',
							'label' => __( 'before', 'corinthian-pro' ),
							'input' => 'text',
						),
						array(
							'op'    => 'after',
							'label' => __( 'after', 'corinthian-pro' ),
							'input' => 'text',
						),
					),
					$empty
				);
			case 'choice':
				$options = FieldKind::choices( $field );
				return array_merge(
					array(
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
					),
					$empty
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
			case 'relation':
				return array_merge(
					array(
						array(
							'op'    => 'contains',
							'label' => __( 'includes ID', 'corinthian-pro' ),
							'input' => 'number',
						),
					),
					$empty
				);
			case 'media':
			case 'gallery':
			case 'link':
				return $empty;
			case 'list':
				return $empty;
		}
		return array();
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array{join:string,where:string}|null
	 */
	public static function condition( array $field, string $op, mixed $value, string $alias, Screen $screen ): ?array {
		$join = self::join( $screen, $alias, (string) $field['name'] );
		if ( null === $join || ! self::operators( $field ) ) {
			return null;
		}
		$col    = "{$alias}.meta_value";
		$family = FieldKind::family( $field );
		$v      = is_array( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : '' );

		$empties = array( '' );
		if ( FieldKind::multiple( $field ) ) {
			$empties[] = 'a:0:{}';
		}
		if ( 'list' === $family ) {
			$empties[] = '0';
		}
		$in_empty = "'" . implode( "','", $empties ) . "'";

		$where = match ( $op ) {
			'empty'     => "({$col} IS NULL OR {$col} IN ({$in_empty}))",
			'not_empty' => "({$col} IS NOT NULL AND {$col} NOT IN ({$in_empty}))",
			default     => self::typed( $field, $family, $op, $v, $col ),
		};
		return null === $where ? null : array(
			'join'  => $join,
			'where' => $where,
		);
	}

	/**
	 * @param array<string,mixed>  $field
	 * @param array<mixed>|string  $v
	 */
	private static function typed( array $field, string $family, string $op, array|string $v, string $col ): ?string {
		global $wpdb;
		switch ( $family ) {
			case 'text':
				if ( ! is_string( $v ) || '' === $v ) {
					return null;
				}
				return match ( $op ) {
					'contains' => $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $v ) . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'eq'       => $wpdb->prepare( "{$col} = %s", $v ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					default    => null,
				};

			case 'number':
				$num = "CAST({$col} AS DECIMAL(30,6))";
				if ( 'between' === $op ) {
					if ( ! is_array( $v ) || 2 !== count( $v ) || ! is_numeric( $v[0] ) || ! is_numeric( $v[1] ) ) {
						return null;
					}
					return $wpdb->prepare( "({$col} <> '' AND {$num} BETWEEN %f AND %f)", (float) min( $v ), (float) max( $v ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				if ( ! is_string( $v ) || ! is_numeric( $v ) ) {
					return null;
				}
				return match ( $op ) {
					'eq'    => $wpdb->prepare( "({$col} <> '' AND {$num} = %f)", (float) $v ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'gt'    => $wpdb->prepare( "({$col} <> '' AND {$num} > %f)", (float) $v ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'lt'    => $wpdb->prepare( "({$col} <> '' AND {$num} < %f)", (float) $v ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					default => null,
				};

			case 'date':
			case 'datetime':
				return self::date_condition( 'datetime' === $family, $op, $v, $col );

			case 'time':
				if ( ! is_string( $v ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v ) ) {
					return null;
				}
				$time = 5 === strlen( $v ) ? $v . ':00' : $v;
				return match ( $op ) {
					'before' => $wpdb->prepare( "({$col} <> '' AND {$col} < %s)", $time ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'after'  => $wpdb->prepare( "{$col} > %s", $time ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					default  => null,
				};

			case 'bool':
				if ( 'eq' !== $op || ! in_array( $v, array( '0', '1' ), true ) ) {
					return null;
				}
				return '1' === $v ? "({$col} = '1')" : "({$col} IS NULL OR {$col} IN ('', '0'))";

			case 'choice':
				return self::choice_condition( $field, $op, $v, $col );

			case 'relation':
				if ( 'contains' !== $op || ! is_string( $v ) || ! ctype_digit( $v ) || '0' === $v ) {
					return null;
				}
				$sql = $wpdb->prepare( "({$col} = %s OR {$col} LIKE %s", $v, '%' . $wpdb->esc_like( '"' . $v . '"' ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 'taxonomy' === FieldKind::type( $field ) ) {
					// Term ids are serialized as integers: i:0;i:12;
					$sql .= $wpdb->prepare( " OR {$col} REGEXP %s", '^a:[0-9]+:[{](i:[0-9]+;i:[0-9]+;)*i:[0-9]+;i:' . $v . ';' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				return $sql . ')';
		}
		return null;
	}

	/**
	 * @param array<mixed>|string $v
	 */
	private static function date_condition( bool $with_time, string $op, array|string $v, string $col ): ?string {
		global $wpdb;
		$fmt    = $with_time ? 'Y-m-d' : 'Ymd';
		$start  = static fn( string $day ): string => $with_time ? $day . ' 00:00:00' : $day;
		$end    = static fn( string $day ): string => $with_time ? $day . ' 23:59:59' : $day;
		$day    = null;
		$within = null;

		if ( 'within_days' === $op ) {
			if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
				return null;
			}
			$within = (int) $v;
		} else {
			if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return null;
			}
			$day = $m[1] . $m[2] . $m[3];
			$day = $with_time ? "{$m[1]}-{$m[2]}-{$m[3]}" : $day;
		}

		switch ( $op ) {
			case 'before':
				return $wpdb->prepare( "({$col} <> '' AND {$col} < %s)", $start( (string) $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'after':
				return $wpdb->prepare( "{$col} > %s", $end( (string) $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'within_days':
				$from = wp_date( $fmt, time() - (int) $within * DAY_IN_SECONDS );
				$to   = wp_date( $fmt );
				return $wpdb->prepare( "({$col} >= %s AND {$col} <= %s)", $start( (string) $from ), $end( (string) $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $field
	 * @param array<mixed>|string $v
	 */
	private static function choice_condition( array $field, string $op, array|string $v, string $col ): ?string {
		global $wpdb;
		if ( ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$choices = array_map( 'strval', array_keys( FieldKind::choices( $field ) ) );
		$wanted  = array_values( array_unique( array_filter( array_map( 'strval', (array) $v ), static fn( $x ) => '' !== $x ) ) );
		$wanted  = array_values( array_intersect( $wanted, $choices ) );
		if ( ! $wanted ) {
			return null;
		}

		if ( FieldKind::multiple( $field ) ) {
			$likes = array();
			foreach ( $wanted as $item ) {
				$likes[] = $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( '"' . $item . '"' ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$any = '(' . implode( ' OR ', $likes ) . ')';
			return 'in' === $op ? $any : "({$col} IS NULL OR NOT {$any})";
		}

		$list = $wpdb->prepare( implode( ',', array_fill( 0, count( $wanted ), '%s' ) ), $wanted ); // phpcs:ignore WordPress.DB.PreparedSQL.UnfinishedPrepare
		return 'in' === $op ? "{$col} IN ({$list})" : "({$col} IS NULL OR {$col} NOT IN ({$list}))";
	}
}
