<?php

namespace CorinthianPro\Filters;

use Corinthian\Columns\Filterable;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * A group of smart-filter rules: { match: "all"|"any", rules: [ { column, op, value } ] }.
 *
 * Stored and passed around as plain JSON so segments, URLs and AI-built
 * filters all share one shape.
 */
final class FilterSet {

	public const QUERY_VAR = 'corinthian_filter';
	public const MAX_RULES = 20;

	/**
	 * @param list<array{column:string,op:string,value:mixed}> $rules
	 */
	public function __construct(
		public readonly string $match,
		public readonly array $rules,
	) {}

	public static function empty(): self {
		return new self( 'all', array() );
	}

	/**
	 * @param array<string,mixed>|string|null $data
	 */
	public static function from( array|string|null $data ): self {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) ) {
			return self::empty();
		}
		$rules = array();
		foreach ( array_slice( (array) ( $data['rules'] ?? array() ), 0, self::MAX_RULES ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['column'] ) || empty( $rule['op'] ) ) {
				continue;
			}
			$rules[] = array(
				'column' => sanitize_key( (string) $rule['column'] ),
				'op'     => sanitize_key( (string) $rule['op'] ),
				'value'  => self::clean_value( $rule['value'] ?? null ),
			);
		}
		return new self( 'any' === ( $data['match'] ?? 'all' ) ? 'any' : 'all', $rules );
	}

	/**
	 * Read the filter from the current request, if any.
	 */
	public static function from_request(): self {
		// The value is JSON that from() parses and validates rule by rule, so sanitize_text_field() would corrupt it.
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only list filtering, same as core's own list filters.
		return '' === $raw ? self::empty() : self::from( $raw );
	}

	public function is_empty(): bool {
		return array() === $this->rules;
	}

	/**
	 * @return array{match:string,rules:list<array{column:string,op:string,value:mixed}>}
	 */
	public function to_array(): array {
		return array(
			'match' => $this->match,
			'rules' => $this->rules,
		);
	}

	/**
	 * Build the SQL for this filter on a screen's active set.
	 *
	 * @return array{join:string,where:string} where is "" or a full boolean expression.
	 */
	public function compile( Screen $screen, ?\Corinthian\Sets\ColumnSet $set = null ): array {
		$set = $set ?? $screen->active_set();
		if ( ! $set || $this->is_empty() ) {
			return array(
				'join'  => '',
				'where' => '',
			);
		}
		$joins = array();
		$conds = array();
		foreach ( $this->rules as $i => $rule ) {
			$config = $set->column( $rule['column'] );
			$type   = $config ? $screen->registry()->get( $config->type ) : null;
			if ( ! $config || ! $type instanceof Filterable || false === $config->setting( 'filterable', true ) ) {
				continue;
			}
			$allowed = array_column( $type->filter_operators( $config, $screen ), 'op' );
			if ( ! in_array( $rule['op'], $allowed, true ) ) {
				continue;
			}
			$sql = $type->filter_condition( $config, $rule['op'], $rule['value'], $screen, 'clf' . $i );
			if ( ! $sql || '' === trim( $sql['where'] ) ) {
				continue;
			}
			if ( '' !== trim( $sql['join'] ) ) {
				$joins[] = $sql['join'];
			}
			$conds[] = '(' . $sql['where'] . ')';
		}
		return array(
			'join'  => implode( ' ', $joins ),
			'where' => $conds ? '(' . implode( 'any' === $this->match ? ' OR ' : ' AND ', $conds ) . ')' : '',
		);
	}

	private static function clean_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_values( array_map( static fn( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '', array_slice( $value, 0, 100 ) ) );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return null === $value ? null : sanitize_text_field( (string) $value );
	}
}
