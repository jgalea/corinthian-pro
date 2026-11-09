<?php

namespace CorinthianPro\Integrations\Pods;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\ConditionallySortable;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Screens\Screen;

/**
 * Any Pods field on a post, term or user list. Pods that keep their values in
 * their own table (advanced pods) render through Pods but can't be sorted,
 * filtered or edited here; meta-based pods can. Unsortable kinds (lists,
 * relations, files) leave the query unchanged.
 */
final class PodsFieldType extends AbstractType implements Sortable, Filterable, Editable, ConditionallySortable {

	private const OBJECTS = array( 'post', 'user', 'term' );

	public function key(): string {
		return 'pods.field';
	}

	public function label(): string {
		return __( 'Pods field', 'corinthian-pro' );
	}

	public function group(): string {
		return __( 'Custom fields', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return in_array( $screen->object_type(), self::OBJECTS, true );
	}

	public function settings_schema( Screen $screen ): array {
		$options = array();
		foreach ( PodsApi::fields_for( $screen ) as list( $group, $field ) ) {
			$name             = (string) $field['name'];
			$label            = (string) ( $field['label'] ?? '' );
			$options[ $name ] = ( '' !== $group ? $group . ': ' : '' ) . ( '' !== $label ? $label : $name );
		}
		return array(
			array(
				'name'    => 'field',
				'type'    => 'select',
				'label'   => __( 'Field', 'corinthian-pro' ),
				'default' => '',
				'options' => $options,
			),
		);
	}

	/**
	 * The field and how its pod stores it.
	 *
	 * @return array{0:array<string,mixed>,1:string}|null
	 */
	private function resolve( Config $config, Screen $screen ): ?array {
		$name = (string) $config->setting( 'field', '' );
		if ( '' === $name || ! $this->supports( $screen ) ) {
			return null;
		}
		$pod   = PodsApi::pod( $screen );
		$field = $pod['fields'][ $name ] ?? null;
		if ( ! $pod || ! $field || ! FieldKind::storable( $field ) ) {
			return null;
		}
		// Password fields and fields Pods restricts from this user (admin only,
		// role or capability limits) are unavailable everywhere: view, sort,
		// filter, export and edit.
		if ( FieldKind::secret( $field ) || ! PodsApi::permitted( $field ) ) {
			return null;
		}
		return array( $field, $pod['storage'] );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$found = $this->resolve( $config, $screen );
		return $found ? PodsApi::get( $found[0], $screen, $object_id ) : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$found = $this->resolve( $config, $screen );
		if ( ! $found ) {
			return parent::render( $object_id, $config, $screen );
		}
		return FieldRenderer::render( $found[0], PodsApi::get( $found[0], $screen, $object_id ) );
	}

	public function sorts( Config $config, Screen $screen ): bool {
		$found = $this->resolve( $config, $screen );
		return $found && FieldKind::sortable( $found[0], $found[1] );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		$found = $this->resolve( $config, $screen );
		return $found ? FieldQuery::sort( $clauses, $found[0], $found[1], $this->alias( $config ), $order, $screen ) : $clauses;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		$found = $this->resolve( $config, $screen );
		return $found ? FieldQuery::operators( $found[0], $found[1] ) : array();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$found = $this->resolve( $config, $screen );
		return $found ? FieldQuery::condition( $found[0], $found[1], $op, $value, $alias, $screen ) : null;
	}

	public function edit_field( Config $config, Screen $screen ): array {
		$found = $this->resolve( $config, $screen );
		if ( ! $found || ! FieldKind::editable( $found[0], $found[1] ) ) {
			return array(
				'input'    => 'text',
				'readonly' => true,
				'bulk'     => array(),
			);
		}
		return FieldEditor::shape( $found[0] );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$found = $this->resolve( $config, $screen );
		if ( ! $found || ! FieldKind::editable( $found[0], $found[1] ) ) {
			return null;
		}
		return FieldEditor::current( $found[0], PodsApi::get( $found[0], $screen, $object_id ) );
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|\WP_Error {
		if ( ! $this->supports( $screen ) ) {
			return new \WP_Error( 'corinthian_readonly', __( 'This field cannot be edited here.', 'corinthian-pro' ) );
		}
		$found = $this->resolve( $config, $screen );
		if ( ! $found ) {
			return new \WP_Error( 'corinthian_no_key', __( 'This field is not available.', 'corinthian-pro' ) );
		}
		list( $field, $storage ) = $found;
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
		$name = (string) $field['name'];
		$verb = 'clear' === $mode ? 'delete' : ( metadata_exists( $object, $object_id, $name ) ? 'edit' : 'add' );
		if ( ! \Corinthian\Columns\MetaCaps::can( "{$verb}_{$object}_meta", $object_id, $name ) ) {
			return new \WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
		}

		$multiple = FieldKind::multiple( $field );
		switch ( $mode ) {
			case 'clear':
				if ( FieldEditor::required( $field ) ) {
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
				$have = FieldEditor::current( $field, PodsApi::get( $field, $screen, $object_id ) );
				$have = array_map( 'strval', is_array( $have ) ? $have : array() );
				$list = 'append' === $mode
					? array_merge( $have, array_map( 'strval', (array) $change ) )
					: array_diff( $have, array_map( 'strval', (array) $change ) );
				$new  = FieldEditor::normalize( $field, array_values( array_unique( $list ) ) );
				break;
			default:
				$new = FieldEditor::normalize( $field, $value );
		}
		if ( is_wp_error( $new ) ) {
			return $new;
		}

		if ( ! PodsApi::set( $field, $screen, $object_id, $new ) ) {
			return new \WP_Error( 'corinthian_failed', __( 'Pods could not save this value.', 'corinthian-pro' ) );
		}
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
