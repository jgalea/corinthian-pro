<?php

namespace CorinthianPro\Rest;

use Corinthian\Columns\ColumnType;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Screens\Screen;

final class TypeDescriptionListener {

	public function register(): void {
		add_filter( 'corinthian_type_description', array( $this, 'describe' ), 10, 3 );
	}

	/**
	 * @param mixed $description
	 * @return mixed
	 */
	public function describe( $description, ColumnType $type, Screen $screen ) {
		if ( ! is_array( $description ) ) {
			return $description;
		}
		$description['filterable'] = $type instanceof Filterable;
		$description['editable']   = $type instanceof Editable;
		$description['exportable'] = true;
		if ( $type instanceof Filterable ) {
			// Operators can vary with a column's settings. filter_operators holds the ones for default
			// settings; filter_operators_by_setting holds the ones that differ per select option.
			$default                         = $type->filter_operators( Config::from_array( array( 'id' => 'x', 'type' => $type->key() ) ), $screen );
			$description['filter_operators'] = $default;
			$variants                        = array();
			foreach ( (array) ( $description['settings'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || 'select' !== ( $field['type'] ?? '' ) || empty( $field['name'] ) || ! is_array( $field['options'] ?? null ) ) {
					continue;
				}
				foreach ( array_keys( $field['options'] ) as $option ) {
					$config = Config::from_array(
						array(
							'id'       => 'x',
							'type'     => $type->key(),
							'settings' => array( $field['name'] => (string) $option ),
						)
					);
					$ops    = $type->filter_operators( $config, $screen );
					if ( $ops !== $default ) {
						$variants[ (string) $field['name'] ][ (string) $option ] = $ops;
					}
				}
			}
			if ( $variants ) {
				$description['filter_operators_by_setting'] = $variants;
			}
		}
		return $description;
	}
}
