<?php

namespace CorinthianPro\Integrations\MetaBox;

/**
 * What a Meta Box field type can do. Every per-feature decision (render, sort,
 * filter, edit) starts from family() so the rules live in one table.
 */
final class FieldKind {

	private const FAMILIES = array(
		'text'              => 'text',
		'hidden'            => 'text',
		'textarea'          => 'text',
		'email'             => 'text',
		'url'               => 'text',
		'password'          => 'text',
		'file_input'        => 'text',
		'wysiwyg'           => 'text',
		'color'             => 'text',
		'oembed'            => 'text',
		'number'            => 'number',
		'range'             => 'number',
		'select'            => 'choice',
		'select_advanced'   => 'choice',
		'radio'             => 'choice',
		'button_group'      => 'choice',
		'checkbox_list'     => 'choice',
		'image_select'      => 'choice',
		'checkbox'          => 'bool',
		'switch'            => 'bool',
		'date'              => 'date',
		'datetime'          => 'datetime',
		'time'              => 'time',
		'image'             => 'media',
		'image_advanced'    => 'media',
		'image_upload'      => 'media',
		'plupload_image'    => 'media',
		'single_image'      => 'media',
		'file'              => 'media',
		'file_advanced'     => 'media',
		'file_upload'       => 'media',
		'video'             => 'media',
		'post'              => 'relation',
		'user'              => 'relation',
		'taxonomy_advanced' => 'relation',
		'taxonomy'          => 'term_rel',
	);

	private const NO_VALUE = array( 'heading', 'divider', 'custom_html', 'button' );

	private const IMAGES = array( 'image', 'image_advanced', 'image_upload', 'plupload_image', 'single_image' );

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
		return ! empty( $field['id'] ) && ! in_array( self::type( $field ), self::NO_VALUE, true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function family( array $field ): string {
		return self::FAMILIES[ self::type( $field ) ] ?? 'other';
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function cloned( array $field ): bool {
		return ! empty( $field['clone'] );
	}

	/**
	 * Whether the value is a list (Meta Box saves one meta row per item).
	 *
	 * @param array<string,mixed> $field
	 */
	public static function multiple( array $field ): bool {
		return ! empty( $field['multiple'] ) && ! self::cloned( $field );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function is_image( array $field ): bool {
		return in_array( self::type( $field ), self::IMAGES, true );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array{timestamp:bool,input:string,store:string}
	 */
	public static function date_spec( array $field ): array {
		$input = (string) ( $field['php_format'] ?? '' );
		if ( '' === $input ) {
			$input = match ( self::family( $field ) ) {
				'datetime' => 'Y-m-d H:i',
				'time'     => 'H:i',
				default    => 'Y-m-d',
			};
		}
		$save = (string) ( $field['save_format'] ?? '' );
		return array(
			'timestamp' => ! empty( $field['timestamp'] ),
			'input'     => $input,
			'store'     => '' !== $save ? $save : $input,
		);
	}

	/**
	 * Whether stored dates compare correctly as plain strings or numbers in SQL.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function date_orderable( array $field ): bool {
		$family = self::family( $field );
		if ( ! in_array( $family, array( 'date', 'datetime', 'time' ), true ) ) {
			return false;
		}
		$spec = self::date_spec( $field );
		if ( $spec['timestamp'] ) {
			return 'time' !== $family;
		}
		$pattern = 'time' === $family ? '/^H:i(:s)?$/' : '/^Y-m-d(( |\\\\T)H:i(:s)?)?$/';
		return 1 === preg_match( $pattern, $spec['store'] );
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
		return ! self::secret( $field ) && 'meta' === $mode && 'term_rel' !== self::family( $field );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function sortable( array $field, string $mode ): bool {
		if ( self::secret( $field ) || 'meta' !== $mode || self::cloned( $field ) || self::multiple( $field ) ) {
			return false;
		}
		return match ( self::family( $field ) ) {
			'text', 'number', 'bool', 'choice' => true,
			'date', 'datetime', 'time'         => self::date_orderable( $field ),
			default                            => false,
		};
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function editable( array $field, string $mode ): bool {
		if ( 'none' === $mode || self::cloned( $field ) || ! self::storable( $field ) ) {
			return false;
		}
		$attributes = (array) ( $field['attributes'] ?? array() );
		if ( ! empty( $field['readonly'] ) || ! empty( $field['disabled'] ) || ! empty( $attributes['readonly'] ) || ! empty( $attributes['disabled'] ) ) {
			return false;
		}
		if ( in_array( self::type( $field ), array( 'password', 'wysiwyg', 'oembed' ), true ) ) {
			return false;
		}
		return in_array( self::family( $field ), array( 'text', 'number', 'choice', 'bool', 'date', 'datetime', 'time', 'media', 'relation' ), true );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	public static function choices( array $field ): array {
		$out = array();
		foreach ( is_array( $field['options'] ?? null ) ? $field['options'] : array() as $key => $option ) {
			if ( is_array( $option ) ) {
				$value         = (string) ( $option['value'] ?? $key );
				$out[ $value ] = (string) ( $option['label'] ?? $value );
			} elseif ( is_scalar( $option ) ) {
				$out[ (string) $key ] = (string) $option;
			}
		}
		return $out;
	}

	/**
	 * Flattens whatever Meta Box returns for a value (scalars, lists, media info keyed by id, term objects) into ids or plain strings.
	 *
	 * @return list<string>
	 */
	public static function as_list( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['term_id'] ) ) ) {
			$value = array( $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			if ( $item instanceof \WP_Term ) {
				$out[] = (string) $item->term_id;
			} elseif ( $item instanceof \WP_Post || $item instanceof \WP_User ) {
				$out[] = (string) $item->ID;
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

	/**
	 * Ids of a relation or media value; taxonomy_advanced saves them comma separated.
	 *
	 * @return list<string>
	 */
	public static function ids( mixed $value ): array {
		$out = array();
		foreach ( self::as_list( $value ) as $item ) {
			foreach ( explode( ',', $item ) as $part ) {
				if ( '' !== trim( $part ) ) {
					$out[] = trim( $part );
				}
			}
		}
		return $out;
	}
}
