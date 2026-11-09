<?php

namespace CorinthianPro\Integrations\Pods;

/**
 * What a Pods field type can do. Every per-feature decision (render, sort,
 * filter, edit) starts from family() so the rules live in one table.
 */
final class FieldKind {

	private const FAMILIES = array(
		'text'      => 'text',
		'website'   => 'text',
		'phone'     => 'text',
		'email'     => 'text',
		'password'  => 'text',
		'code'      => 'text',
		'paragraph' => 'text',
		'wysiwyg'   => 'text',
		'color'     => 'text',
		'oembed'    => 'text',
		'slug'      => 'text',
		'number'    => 'number',
		'currency'  => 'number',
		'date'      => 'date',
		'datetime'  => 'datetime',
		'time'      => 'time',
		'boolean'   => 'bool',
		'file'      => 'media',
	);

	private const NO_VALUE = array( 'heading', 'html' );

	private const RELATIONS = array( 'post_type', 'taxonomy', 'user' );

	/** Values Pods writes for "no value" in text and date columns. */
	public const EMPTY_VALUES = array( '', '0000-00-00', '0000-00-00 00:00:00' );

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
		return ! empty( $field['name'] ) && ! in_array( self::type( $field ), self::NO_VALUE, true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function repeatable( array $field ): bool {
		return ! empty( $field['repeatable'] );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function pick_object( array $field ): string {
		return (string) ( $field['pick_object'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function family( array $field ): string {
		if ( self::repeatable( $field ) ) {
			return 'other';
		}
		$type = self::type( $field );
		if ( 'pick' === $type ) {
			$object = self::pick_object( $field );
			return match ( true ) {
				'' === $object || 'custom-simple' === $object => 'choice',
				in_array( $object, self::RELATIONS, true )    => 'relation',
				'media' === $object                           => 'media',
				default                                       => 'other',
			};
		}
		return self::FAMILIES[ $type ] ?? 'other';
	}

	/**
	 * Whether the value is a list (Pods saves one meta row per item).
	 *
	 * @param array<string,mixed> $field
	 */
	public static function multiple( array $field ): bool {
		return match ( self::type( $field ) ) {
			'pick'  => 'multi' === ( $field['pick_format_type'] ?? '' ),
			'file'  => 'multi' === ( $field['file_format_type'] ?? '' ),
			default => false,
		};
	}

	/**
	 * Relations and files come back from Pods as whole rows unless ids are asked for.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function reads_ids( array $field ): bool {
		return in_array( self::family( $field ), array( 'relation', 'media' ), true );
	}

	/**
	 * Fields holding secrets are never read, sorted, filtered or exported.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function secret( array $field ): bool {
		return 'password' === self::type( $field );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function filterable( array $field, string $mode ): bool {
		return ! self::secret( $field ) && 'meta' === $mode && in_array( self::family( $field ), array( 'text', 'number', 'date', 'datetime', 'time', 'bool', 'choice', 'relation', 'media', 'other' ), true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function sortable( array $field, string $mode ): bool {
		return ! self::secret( $field )
			&& 'meta' === $mode
			&& ! self::multiple( $field )
			&& in_array( self::family( $field ), array( 'text', 'number', 'date', 'datetime', 'time', 'bool', 'choice' ), true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function editable( array $field, string $mode ): bool {
		if ( 'meta' !== $mode || ! self::storable( $field ) ) {
			return false;
		}
		if ( in_array( self::type( $field ), array( 'password', 'code', 'wysiwyg', 'oembed', 'slug' ), true ) ) {
			return false;
		}
		return in_array( self::family( $field ), array( 'text', 'number', 'choice', 'bool', 'date', 'datetime', 'time', 'media', 'relation' ), true );
	}

	/**
	 * Options of a custom list, from Pods' "value|label" lines.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	public static function choices( array $field ): array {
		$custom = $field['pick_custom'] ?? '';
		$out    = array();
		if ( is_array( $custom ) ) {
			foreach ( $custom as $key => $label ) {
				if ( is_scalar( $label ) ) {
					$out[ (string) $key ] = (string) $label;
				}
			}
			return $out;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $custom ) ?: array() as $line ) {
			$parts = explode( '|', $line, 2 );
			$value = trim( $parts[0] );
			if ( '' !== $value ) {
				$out[ $value ] = isset( $parts[1] ) ? trim( $parts[1] ) : $value;
			}
		}
		return $out;
	}

	/**
	 * Flattens whatever Pods returns for a value (scalars, lists, rows) into ids or plain strings.
	 *
	 * @return list<string>
	 */
	public static function as_list( mixed $value ): array {
		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}
		if ( is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['term_id'] ) || isset( $value['pod_item_id'] ) ) ) {
			$value = array( $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			if ( $item instanceof \WP_Term ) {
				$out[] = (string) $item->term_id;
			} elseif ( $item instanceof \WP_Post || $item instanceof \WP_User ) {
				$out[] = (string) $item->ID;
			} elseif ( is_array( $item ) && isset( $item['pod_item_id'] ) && is_scalar( $item['pod_item_id'] ) ) {
				$out[] = (string) $item['pod_item_id'];
			} elseif ( is_array( $item ) && isset( $item['term_id'] ) && is_scalar( $item['term_id'] ) ) {
				$out[] = (string) $item['term_id'];
			} elseif ( is_array( $item ) && isset( $item['ID'] ) && is_scalar( $item['ID'] ) ) {
				$out[] = (string) $item['ID'];
			} elseif ( is_scalar( $item ) && '' !== (string) $item ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}
}
