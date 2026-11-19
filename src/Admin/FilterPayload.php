<?php

namespace CorinthianPro\Admin;

use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;
use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\ViewState;
use CorinthianPro\Rest\SegmentsController;

defined( 'ABSPATH' ) || exit;

final class FilterPayload {

	public const BODY_CLASS = 'corinthian-has-filter';

	private bool $bar = false;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'corinthian_table_payload', array( $this, 'extend' ), 10, 3 );
		add_filter( 'disable_months_dropdown', array( $this, 'hide_native' ) );
		add_filter( 'disable_categories_dropdown', array( $this, 'hide_native' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * The filter bar replaces core's own filter row, so core's dropdowns step aside on screens that show it.
	 *
	 * @param mixed $disabled
	 * @return mixed
	 */
	public function hide_native( $disabled ) {
		return $this->bar ? true : $disabled;
	}

	public function body_class( string $classes ): string {
		return $this->bar ? trim( $classes . ' ' . self::BODY_CLASS ) : $classes;
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	public function extend( $data, Screen $screen, ColumnSet $set ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$current = FilterSet::from_request()->to_array();
		if ( ! empty( $current['rules'] ) ) {
			add_filter(
				'admin_body_class',
				static fn( $classes ) => is_string( $classes ) ? $classes . ' corinthian-has-filters' : $classes
			);
		}
		$data['hasFormatting'] = (bool) \CorinthianPro\Rest\FormattingController::rules( $set );
		$columns               = $this->filterable_columns( $screen, $set );
		$this->bar             = (bool) $columns;
		$data['filter']        = array(
			'param'         => FilterSet::QUERY_VAR,
			'current'       => $current,
			'view'          => ViewState::from_request( $screen ),
			'statusParam'   => ViewState::status_param( $screen ),
			'segmentParam'  => ViewState::SEGMENT_VAR,
			'noSegment'     => ViewState::NO_SEGMENT,
			'activeSegment' => ViewState::active_segment(),
			'columns'       => $columns,
			'dropped'       => $this->dropped( $screen, $set ),
			'segments'      => SegmentsController::for_user( $set, get_current_user_id() ),
			'defaults'      => SegmentsController::defaults( $set, get_current_user_id() ),
			'canShare'      => current_user_can( Plugin::capability() ),
		);
		return $data;
	}

	/**
	 * Rules in the request's filter that can't apply, with why, so a stale or hand-edited link says what it lost.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function dropped( Screen $screen, ColumnSet $set ): array {
		$raw = isset( $_GET[ FilterSet::QUERY_VAR ] ) ? wp_unslash( (string) $_GET[ FilterSet::QUERY_VAR ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only list filtering, parsed rule by rule below.
		if ( '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array( array( 'reason' => 'unreadable' ) );
		}
		$out   = array();
		$rules = is_array( $data['rules'] ?? null ) ? array_values( $data['rules'] ) : array();
		foreach ( $rules as $i => $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['column'] ?? null ) || ! is_string( $rule['op'] ?? null ) || '' === $rule['column'] || '' === $rule['op'] ) {
				$out[] = array(
					'rule'   => is_array( $rule ) ? array_intersect_key( $rule, array_flip( array( 'column', 'op' ) ) ) : null,
					'reason' => 'malformed',
				);
				continue;
			}
			$clean = FilterSet::from( array( 'rules' => array( $rule ) ) )->rules[0];
			$why   = $i >= FilterSet::MAX_RULES ? 'too_many_rules' : $this->rule_problem( $clean, $screen, $set );
			if ( $why ) {
				$out[] = array(
					'rule'   => $clean,
					'reason' => $why,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array{column:string,op:string,value:mixed} $rule
	 */
	private function rule_problem( array $rule, Screen $screen, ColumnSet $set ): string {
		$config = $set->column( $rule['column'] );
		$type   = $config ? $this->plugin->registry->get( $config->type ) : null;
		if ( ! $config || ! $type instanceof \Corinthian\Columns\Filterable || false === $config->setting( 'filterable', true ) ) {
			return 'unknown_column';
		}
		$operators = $type->filter_operators( $config, $screen );
		if ( ! $operators ) {
			return 'unknown_column';
		}
		if ( ! in_array( $rule['op'], array_column( $operators, 'op' ), true ) ) {
			return 'unsupported_op';
		}
		$sql = $type->filter_condition( $config, $rule['op'], $rule['value'], $screen, 'clfd' );
		return ! $sql || '' === trim( $sql['where'] ) ? 'invalid_value' : '';
	}

	/**
	 * Filterable columns of a set, with their operators, for the filter bar.
	 *
	 * @return list<array{id:string,label:string,operators:list<array<string,mixed>>}>
	 */
	private function filterable_columns( Screen $screen, \Corinthian\Sets\ColumnSet $set ): array {
		$out    = array();
		$native = $screen->native_columns();
		foreach ( $set->columns as $config ) {
			$type = $this->plugin->registry->get( $config->type );
			if ( ! $type instanceof \Corinthian\Columns\Filterable || false === $config->setting( 'filterable', true ) ) {
				continue;
			}
			$operators = $type->filter_operators( $config, $screen );
			if ( ! $operators ) {
				continue;
			}
			$out[] = array(
				'id'        => $config->id,
				'label'     => '' !== $config->label ? $config->label : ( $native[ (string) $config->setting( 'key' ) ] ?? $type->label() ),
				'operators' => $operators,
			);
		}
		return $out;
	}
}
