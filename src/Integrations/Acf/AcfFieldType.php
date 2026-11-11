<?php

namespace CorinthianPro\Integrations\Acf;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Any top-level ACF field on a post, user or term list. Unsortable kinds
 * (relations, repeaters, multi-value choices) leave the query unchanged.
 */
final class AcfFieldType extends AbstractType implements Sortable, Filterable, Editable, \Corinthian\Columns\ConditionallySortable {

	private const OBJECTS = array( 'post', 'user', 'term' );

	public function key(): string {
		return 'acf.field';
	}

	public function label(): string {
		return __( 'ACF field', 'corinthian-pro' );
	}

	public function group(): string {
		return __( 'Custom fields', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return in_array( $screen->object_type(), self::OBJECTS, true );
	}

	public function settings_schema( Screen $screen ): array {
		$options = array();
		foreach ( AcfApi::fields_for( $screen ) as list( $group, $field ) ) {
			$options[ (string) $field['key'] ] = $group . ': ' . $field['label'];
		}
		return array(
			array(
				'name'    => 'field_key',
				'type'    => 'select',
				'label'   => __( 'Field', 'corinthian-pro' ),
				'default' => '',
				'options' => $options,
			),
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function field( Config $config ): ?array {
		return AcfApi::field( (string) $config->setting( 'field_key', '' ) );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config );
		if ( ! $field || ! $this->supports( $screen ) ) {
			return null;
		}
		$object = $screen->object_type();
		$name   = (string) $field['name'];
		$family = FieldKind::family( $field );

		if ( 'group' === $family ) {
			return AcfApi::get( (string) $field['key'], AcfApi::ref( $screen, $object_id ) );
		}
		if ( ! metadata_exists( $object, $object_id, $name ) ) {
			return null;
		}
		if ( in_array( $family, array( 'list', 'gallery' ), true ) ) {
			// Row counts and gallery ids are read straight from the meta row; loading every row is costly.
			return get_metadata( $object, $object_id, $name, true );
		}
		return AcfApi::get( (string) $field['key'], AcfApi::ref( $screen, $object_id ) );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$field = $this->field( $config );
		if ( ! $field ) {
			return parent::render( $object_id, $config, $screen );
		}
		return FieldRenderer::render( $field, $this->value( $object_id, $config, $screen ) );
	}

	public function sorts( Config $config, Screen $screen ): bool {
		$field = $this->field( $config );
		return $field && FieldKind::sortable( $field );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		$field = $this->field( $config );
		if ( ! $field ) {
			return $clauses;
		}
		return FieldQuery::sort( $clauses, $field, $this->alias( $config ), $order, $screen );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		$field = $this->field( $config );
		return $field ? FieldQuery::operators( $field ) : array();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$field = $this->field( $config );
		return $field ? FieldQuery::condition( $field, $op, $value, $alias, $screen ) : null;
	}

	public function edit_field( Config $config, Screen $screen ): array {
		$field = $this->field( $config );
		if ( ! $field || ! FieldKind::editable( $field ) ) {
			return array(
				'input'    => 'text',
				'readonly' => true,
				'bulk'     => array(),
			);
		}
		return FieldEditor::shape( $field );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config );
		if ( ! $field || ! FieldKind::editable( $field ) ) {
			return null;
		}
		return FieldEditor::current( $field, $this->value( $object_id, $config, $screen ) );
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|\WP_Error {
		$field = $this->field( $config );
		if ( ! $field ) {
			return new \WP_Error( 'corinthian_no_key', __( 'This column has no field set.', 'corinthian-pro' ) );
		}
		if ( ! FieldKind::editable( $field ) || ! $this->supports( $screen ) ) {
			return new \WP_Error( 'corinthian_readonly', __( 'This field cannot be edited here.', 'corinthian-pro' ) );
		}
		$shape = FieldEditor::shape( $field );
		if ( ! in_array( $mode, (array) $shape['bulk'], true ) ) {
			return new \WP_Error( 'corinthian_bad_mode', __( 'This column does not support that bulk edit.', 'corinthian-pro' ) );
		}

		$object = $screen->object_type();
		$name   = (string) $field['name'];
		$verb   = 'clear' === $mode ? 'delete' : ( metadata_exists( $object, $object_id, $name ) ? 'edit' : 'add' );
		if ( ! \Corinthian\Columns\MetaCaps::can( "{$verb}_{$object}_meta", $object_id, $name ) ) {
			return new \WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
		}

		$multiple = FieldKind::multiple( $field );
		$current  = $this->value( $object_id, $config, $screen );

		switch ( $mode ) {
			case 'clear':
				if ( ! empty( $field['required'] ) ) {
					return new \WP_Error( 'corinthian_invalid', __( 'This field is required.', 'corinthian-pro' ) );
				}
				$new = $multiple ? array() : '';
				break;
			case 'append':
			case 'remove':
				$change = FieldEditor::normalize( $field, $value );
				if ( is_wp_error( $change ) ) {
					return $change;
				}
				$have = array_map( 'strval', FieldKind::as_list( $current ) );
				$list = 'append' === $mode
					? array_merge( $have, (array) $change )
					: array_diff( $have, array_map( 'strval', (array) $change ) );
				$new  = FieldEditor::normalize( $field, array_values( array_unique( $list ) ) );
				break;
			default:
				$new = FieldEditor::normalize( $field, $value );
		}
		if ( is_wp_error( $new ) ) {
			return $new;
		}

		// update_field() returns false when nothing changed, so success is not read from it.
		AcfApi::update( (string) $field['key'], $new, AcfApi::ref( $screen, $object_id ) );
		return true;
	}
}
