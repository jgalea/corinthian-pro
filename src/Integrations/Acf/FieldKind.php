<?php

namespace CorinthianPro\Integrations\Acf;

/**
 * What a field type can do. Every per-feature decision (render, sort, filter,
 * edit) starts from family() so the rules live in one table.
 */
final class FieldKind {

	private const FAMILIES = array(
		'text'             => 'text',
		'textarea'         => 'text',
		'email'            => 'text',
		'url'              => 'text',
		'password'         => 'text',
		'wysiwyg'          => 'text',
		'oembed'           => 'text',
		'color_picker'     => 'text',
		'number'           => 'number',
		'range'            => 'number',
		'date_picker'      => 'date',
		'date_time_picker' => 'datetime',
		'time_picker'      => 'time',
		'true_false'       => 'bool',
		'select'           => 'choice',
		'radio'            => 'choice',
		'button_group'     => 'choice',
		'checkbox'         => 'choice',
		'image'            => 'media',
		'file'             => 'media',
		'gallery'          => 'gallery',
		'link'             => 'link',
		'post_object'      => 'relation',
		'relationship'     => 'relation',
		'page_link'        => 'relation',
		'taxonomy'         => 'relation',
		'user'             => 'relation',
		'repeater'         => 'list',
		'flexible_content' => 'list',
		'group'            => 'group',
	);

	private const NO_STORAGE = array( 'tab', 'message', 'accordion', 'clone', 'separator' );

	/**
	 * @param array<string,mixed> $field
	 */
	public static function type( array $field ): string {
		return (string) ( $field['type'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function storable( array $field ): bool {
		return ! in_array( self::type( $field ), self::NO_STORAGE, true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function family( array $field ): string {
		return self::FAMILIES[ self::type( $field ) ] ?? 'text';
	}

	/**
	 * Whether the value is stored as a serialized array.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function multiple( array $field ): bool {
		switch ( self::type( $field ) ) {
			case 'checkbox':
			case 'relationship':
			case 'gallery':
			case 'flexible_content':
				return true;
			case 'select':
			case 'post_object':
			case 'page_link':
			case 'user':
				return ! empty( $field['multiple'] );
			case 'taxonomy':
				return in_array( $field['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true );
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function sortable( array $field ): bool {
		if ( self::multiple( $field ) ) {
			return false;
		}
		return in_array( self::family( $field ), array( 'text', 'number', 'date', 'datetime', 'time', 'bool', 'choice' ), true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function editable( array $field ): bool {
		return ! in_array( self::type( $field ), array( 'repeater', 'flexible_content', 'group', 'gallery', 'oembed', 'wysiwyg', 'link', 'password' ), true )
			&& self::storable( $field );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	public static function choices( array $field ): array {
		$out = array();
		foreach ( is_array( $field['choices'] ?? null ) ? $field['choices'] : array() as $value => $label ) {
			$out[ (string) $value ] = (string) $label;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function allows_custom( array $field ): bool {
		return ! empty( $field['allow_custom'] ) || ! empty( $field['other_choice'] );
	}

	/**
	 * @return list<string>
	 */
	public static function as_list( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		$list = is_array( $value ) ? $value : array( $value );
		$out  = array();
		foreach ( $list as $item ) {
			if ( $item instanceof \WP_Post ) {
				$out[] = (string) $item->ID;
			} elseif ( $item instanceof \WP_Term ) {
				$out[] = (string) $item->term_id;
			} elseif ( $item instanceof \WP_User ) {
				$out[] = (string) $item->ID;
			} elseif ( is_scalar( $item ) && '' !== (string) $item ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}
}
