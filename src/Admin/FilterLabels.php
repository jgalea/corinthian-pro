<?php

namespace CorinthianPro\Admin;

use Corinthian\Columns\FilterOptions;
use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

defined( 'ABSPATH' ) || exit;

/**
 * Searchable operators ship only their first values. This adds the labels of
 * values the current filter uses, so chips and pickers show names, not ids.
 */
final class FilterLabels {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'corinthian_table_payload', array( $this, 'extend' ), 20, 3 );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	public function extend( $data, Screen $screen, ColumnSet $set ) {
		if ( ! is_array( $data ) || ! is_array( $data['filter']['columns'] ?? null ) || ! is_array( $data['filter']['current']['rules'] ?? null ) ) {
			return $data;
		}
		$picked = array();
		foreach ( $data['filter']['current']['rules'] as $rule ) {
			if ( is_array( $rule ) && is_array( $rule['value'] ?? null ) && in_array( $rule['op'] ?? '', array( 'in', 'not_in' ), true ) ) {
				foreach ( $rule['value'] as $value ) {
					$picked[ (string) $rule['column'] ][] = (string) $value;
				}
			}
		}
		foreach ( $data['filter']['columns'] as $i => $column ) {
			$values = array_values( array_unique( $picked[ $column['id'] ] ?? array() ) );
			$config = $values ? $set->column( $column['id'] ) : null;
			$type   = $config ? $this->plugin->registry->get( $config->type ) : null;
			if ( ! $config || ! $type instanceof FilterOptions ) {
				continue;
			}
			$labels = null;
			foreach ( (array) ( $column['operators'] ?? array() ) as $j => $operator ) {
				if ( empty( $operator['search'] ) || ! in_array( $operator['op'] ?? '', array( 'in', 'not_in' ), true ) ) {
					continue;
				}
				$known = array_map( 'strval', array_keys( (array) ( $operator['options'] ?? array() ) ) );
				if ( ! array_diff( $values, $known ) ) {
					continue;
				}
				$labels ??= $type->filter_labels( $config, $screen, $values );
				$data['filter']['columns'][ $i ]['operators'][ $j ]['options'] = (array) ( $operator['options'] ?? array() ) + $labels;
			}
		}
		return $data;
	}
}
