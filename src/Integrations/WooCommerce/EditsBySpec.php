<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

trait EditsBySpec {

	public function edit_field( Config $config, Screen $screen ): array {
		$edit  = $this->spec->edit;
		$field = array( 'input' => null === $edit ? 'text' : $edit->input );
		if ( $edit && null !== $edit->options ) {
			$field['options'] = ( $edit->options )();
		}
		if ( $edit && $edit->bulk ) {
			$field['bulk'] = array_values( array_unique( array_merge( array( 'set' ), $edit->bulk ) ) );
		}
		return $field;
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$object = $this->object( $object_id );
		return null === $object || null === $this->spec->edit ? null : ( $this->spec->edit->value )( $object );
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		$edit = $this->spec->edit;
		if ( null === $edit ) {
			return new WP_Error( 'corinthian_not_editable', __( 'This column cannot be edited.', 'corinthian-pro' ) );
		}
		if ( 'set' !== $mode && ! in_array( $mode, $edit->bulk, true ) ) {
			return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that edit.', 'corinthian-pro' ) );
		}
		if ( ! Woo::can_edit( $this->spec->scope, $object_id ) ) {
			return new WP_Error( 'corinthian_protected', __( 'You cannot change this item.', 'corinthian-pro' ) );
		}
		$object = $this->object( $object_id );
		if ( null === $object ) {
			return new WP_Error( 'corinthian_not_found', __( 'Item not found.', 'corinthian-pro' ) );
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			return new WP_Error( 'corinthian_invalid', __( 'Invalid value.', 'corinthian-pro' ) );
		}
		try {
			$error = ( $edit->apply )( $object, $value, $mode );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
			if ( ! $edit->saves ) {
				$object->save();
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'corinthian_invalid', wp_strip_all_tags( $e->getMessage() ) );
		}
		return true;
	}
}
