<?php

namespace CorinthianPro\Integrations\MetaBox;

use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that calls Meta Box. Calls go through call_user_func so the
 * plugin analyses cleanly without Meta Box's function stubs.
 */
final class MetaBoxApi {

	private static function call( string $name, mixed ...$args ): mixed {
		return \call_user_func_array( $name, $args );
	}

	public static function available(): bool {
		return function_exists( 'rwmb_get_field_settings' ) && function_exists( 'rwmb_meta' ) && function_exists( 'rwmb_set_meta' );
	}

	/**
	 * @return array{object_type:string,type:string}|null
	 */
	private static function args( Screen $screen ): ?array {
		return match ( $screen->object_type() ) {
			'post' => array(
				'object_type' => 'post',
				'type'        => $screen->subtype(),
			),
			'term' => array(
				'object_type' => 'term',
				'type'        => $screen->subtype(),
			),
			'user' => array(
				'object_type' => 'user',
				'type'        => 'user',
			),
			default => null,
		};
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function field( string $id, Screen $screen ): ?array {
		$args = self::args( $screen );
		if ( '' === $id || null === $args || ! self::available() ) {
			return null;
		}
		$field = self::call( 'rwmb_get_field_settings', $id, $args );
		return is_array( $field ) && ! empty( $field['id'] ) ? $field : null;
	}

	/**
	 * Storable fields of the meta boxes that apply to the screen, as a list of [ meta box title, field ].
	 *
	 * @return list<array{0:string,1:array<string,mixed>}>
	 */
	public static function fields_for( Screen $screen ): array {
		$args = self::args( $screen );
		if ( null === $args || ! function_exists( 'rwmb_get_object_fields' ) || ! function_exists( 'rwmb_get_registry' ) ) {
			return array();
		}
		$fields = self::call( 'rwmb_get_object_fields', $args['type'], $args['object_type'] );
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$titles = array();
		$boxes  = self::call( 'rwmb_get_registry', 'meta_box' );
		$all    = is_object( $boxes ) && method_exists( $boxes, 'get_by' ) ? $boxes->get_by( array( 'object_type' => $args['object_type'] ) ) : array();
		foreach ( is_array( $all ) ? $all : array() as $box ) {
			if ( ! is_object( $box ) ) {
				continue;
			}
			foreach ( (array) $box->fields as $field ) {
				if ( is_array( $field ) && isset( $field['id'] ) ) {
					$titles[ (string) $field['id'] ] = (string) $box->title;
				}
			}
		}

		$out = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && FieldKind::storable( $field ) ) {
				$out[] = array( $titles[ (string) $field['id'] ] ?? '', $field );
			}
		}
		return $out;
	}

	/**
	 * How the field's values are stored: "meta" (the object's own meta table, so SQL can reach it),
	 * "custom" (another storage such as a custom table, reached through Meta Box only) or "none".
	 *
	 * @param array<string,mixed> $field
	 */
	public static function mode( array $field, Screen $screen ): string {
		if ( isset( $field['save_field'] ) && ! $field['save_field'] ) {
			return 'none';
		}
		$storage  = $field['storage'] ?? null;
		$expected = match ( $screen->object_type() ) {
			'post'  => 'RWMB_Post_Storage',
			'term'  => 'RWMB_Term_Storage',
			'user'  => 'RWMB_User_Storage',
			default => '',
		};
		return is_object( $storage ) && get_debug_type( $storage ) === $expected ? 'meta' : 'custom';
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function get( array $field, Screen $screen, int $object_id ): mixed {
		$args = self::args( $screen );
		return null === $args ? null : self::call( 'rwmb_meta', (string) $field['id'], $args, $object_id );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function set( array $field, Screen $screen, int $object_id, mixed $value ): void {
		$args = self::args( $screen );
		if ( null !== $args ) {
			self::call( 'rwmb_set_meta', $object_id, (string) $field['id'], $value, $args );
		}
	}
}
