<?php

namespace CorinthianPro\Integrations\MetaBox;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\ConditionallySortable;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Any Meta Box field on a post, term or user list. Fields stored outside the
 * object's own meta table (a custom table) render and save through Meta Box
 * but can't be sorted or filtered in SQL. Unsortable kinds (lists,
 * relations, media) leave the query unchanged.
 */
final class MetaBoxFieldType extends AbstractType implements Sortable, Filterable, Editable, ConditionallySortable {

	private const OBJECTS = array( 'post', 'user', 'term' );

	public function key(): string {
		return 'metabox.field';
	}

	public function label(): string {
		return __( 'Meta Box field', 'corinthian-pro' );
	}

	public function group(): string {
		return __( 'Custom fields', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return in_array( $screen->object_type(), self::OBJECTS, true );
	}

	public function settings_schema( Screen $screen ): array {
		$options = array();
		foreach ( MetaBoxApi::fields_for( $screen ) as list( $group, $field ) ) {
			$label                            = (string) ( $field['name'] ?? '' );
			$options[ (string) $field['id'] ] = ( '' !== $group ? $group . ': ' : '' ) . ( '' !== $label ? $label : (string) $field['id'] );
		}
		return array(
			array(
				'name'    => 'field_id',
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
	private function field( Config $config, Screen $screen ): ?array {
		$field = $this->supports( $screen ) ? MetaBoxApi::field( (string) $config->setting( 'field_id', '' ), $screen ) : null;
		// Password fields are unavailable everywhere: view, sort, filter, export and edit.
		return $field && ! FieldKind::secret( $field ) ? $field : null;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config, $screen );
		if ( ! $field || ! FieldKind::storable( $field ) ) {
			return null;
		}
		return MetaBoxApi::get( $field, $screen, $object_id );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$field = $this->field( $config, $screen );
		if ( ! $field ) {
			return parent::render( $object_id, $config, $screen );
		}
		return FieldRenderer::render( $field, $this->value( $object_id, $config, $screen ) );
	}

	public function sorts( Config $config, Screen $screen ): bool {
		$field = $this->field( $config, $screen );
		return $field && FieldKind::sortable( $field, MetaBoxApi::mode( $field, $screen ) );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		$field = $this->field( $config, $screen );
		if ( ! $field ) {
			return $clauses;
		}
		return FieldQuery::sort( $clauses, $field, MetaBoxApi::mode( $field, $screen ), $this->alias( $config ), $order, $screen );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		$field = $this->field( $config, $screen );
		return $field ? FieldQuery::operators( $field, MetaBoxApi::mode( $field, $screen ) ) : array();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$field = $this->field( $config, $screen );
		return $field ? FieldQuery::condition( $field, MetaBoxApi::mode( $field, $screen ), $op, $value, $alias, $screen ) : null;
	}

	public function edit_field( Config $config, Screen $screen ): array {
		$field = $this->field( $config, $screen );
		if ( ! $field || ! FieldKind::editable( $field, MetaBoxApi::mode( $field, $screen ) ) ) {
			return array(
				'input'    => 'text',
				'readonly' => true,
				'bulk'     => array(),
			);
		}
		return FieldEditor::shape( $field );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config, $screen );
		if ( ! $field || ! FieldKind::editable( $field, MetaBoxApi::mode( $field, $screen ) ) ) {
			return null;
		}
		return FieldEditor::current( $field, $this->value( $object_id, $config, $screen ) );
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|\WP_Error {
		if ( ! $this->supports( $screen ) ) {
			return new \WP_Error( 'corinthian_readonly', __( 'This field cannot be edited here.', 'corinthian-pro' ) );
		}
		$field = $this->field( $config, $screen );
		if ( ! $field ) {
			return new \WP_Error( 'corinthian_no_key', __( 'This column has no field set.', 'corinthian-pro' ) );
		}
		$storage = MetaBoxApi::mode( $field, $screen );
		if ( ! FieldKind::editable( $field, $storage ) ) {
			return new \WP_Error( 'corinthian_readonly', __( 'This field cannot be edited here.', 'corinthian-pro' ) );
		}
		$shape = FieldEditor::shape( $field );
		if ( ! in_array( $mode, (array) $shape['bulk'], true ) ) {
			return new \WP_Error( 'corinthian_bad_mode', __( 'This column does not support that bulk edit.', 'corinthian-pro' ) );
		}

		$object = $screen->object_type();
		if ( ! $this->may_edit_object( $object, $object_id ) ) {
			return new \WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
		}
		$name = (string) $field['id'];
		if ( 'meta' === $storage ) {
			$verb = 'clear' === $mode ? 'delete' : ( metadata_exists( $object, $object_id, $name ) ? 'edit' : 'add' );
			if ( ! \Corinthian\Columns\MetaCaps::can( "{$verb}_{$object}_meta", $object_id, $name ) ) {
				return new \WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
			}
		}

		$multiple = FieldKind::multiple( $field );
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
				$have = FieldEditor::current( $field, $this->value( $object_id, $config, $screen ) );
				$have = array_map( 'strval', is_array( $have ) ? $have : array() );
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

		MetaBoxApi::set( $field, $screen, $object_id, $new );
		return true;
	}

	private function may_edit_object( string $object, int $object_id ): bool {
		return match ( $object ) {
			'post'  => current_user_can( 'edit_post', $object_id ),
			'user'  => current_user_can( 'edit_user', $object_id ),
			'term'  => current_user_can( 'edit_term', $object_id ),
			default => false,
		};
	}
}
