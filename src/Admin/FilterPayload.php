<?php

namespace CorinthianPro\Admin;

use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

final class FilterPayload {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'corinthian_table_payload', array( $this, 'extend' ), 10, 3 );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	public function extend( $data, Screen $screen, ColumnSet $set ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$data['hasFormatting'] = (bool) \CorinthianPro\Rest\FormattingController::rules( $set );
		$data['filter']        = array(
			'param'    => \CorinthianPro\Filters\FilterSet::QUERY_VAR,
			'current'  => \CorinthianPro\Filters\FilterSet::from_request()->to_array(),
			'columns'  => $this->filterable_columns( $screen, $set ),
			'segments' => \CorinthianPro\Rest\SegmentsController::for_user( $set, get_current_user_id() ),
			'canShare' => current_user_can( Plugin::capability() ),
		);
		return $data;
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
			$out[] = array(
				'id'        => $config->id,
				'label'     => '' !== $config->label ? $config->label : ( $native[ (string) $config->setting( 'key' ) ] ?? $type->label() ),
				'operators' => $type->filter_operators( $config, $screen ),
			);
		}
		return $out;
	}
}
