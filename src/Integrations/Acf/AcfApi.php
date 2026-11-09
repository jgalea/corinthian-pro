<?php

namespace CorinthianPro\Integrations\Acf;

use Corinthian\Screens\Screen;

/**
 * The only place that calls ACF. Calls go through call_user_func so the
 * plugin analyses cleanly without ACF's function stubs.
 */
final class AcfApi {

	private static function call( string $name, mixed ...$args ): mixed {
		return \call_user_func_array( $name, $args );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function field( string $key ): ?array {
		if ( '' === $key || ! function_exists( 'acf_get_field' ) ) {
			return null;
		}
		$field = self::call( 'acf_get_field', $key );
		return is_array( $field ) && ! empty( $field['name'] ) ? $field : null;
	}

	/**
	 * Top-level storable fields of the groups that apply to the screen, as a list of [ group title, field ].
	 *
	 * @return list<array{0:string,1:array<string,mixed>}>
	 */
	public static function fields_for( Screen $screen ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}
		$queries = match ( $screen->object_type() ) {
			'post' => array( array( 'post_type' => $screen->subtype() ) ),
			'user' => array(
				array( 'user_form' => 'edit' ),
				array(
					'user_form' => 'edit',
					'user_role' => 'all',
				),
			),
			'term' => array( array( 'taxonomy' => $screen->subtype() ) ),
			default => array(),
		};

		$groups = array();
		foreach ( $queries as $args ) {
			$found = self::call( 'acf_get_field_groups', $args );
			foreach ( is_array( $found ) ? $found : array() as $group ) {
				if ( is_array( $group ) && isset( $group['key'] ) ) {
					$groups[ (string) $group['key'] ] = $group;
				}
			}
		}

		$out = array();
		foreach ( $groups as $group ) {
			$fields = self::call( 'acf_get_fields', $group['key'] );
			foreach ( is_array( $fields ) ? $fields : array() as $field ) {
				if ( is_array( $field ) && ! empty( $field['name'] ) && FieldKind::storable( $field ) ) {
					$out[] = array( (string) ( $group['title'] ?? '' ), $field );
				}
			}
		}
		return $out;
	}

	public static function get( string $key, string $ref ): mixed {
		return self::call( 'get_field', $key, $ref, false );
	}

	public static function update( string $key, mixed $value, string $ref ): bool {
		return (bool) self::call( 'update_field', $key, $value, $ref );
	}

	public static function ref( Screen $screen, int $id ): string {
		return match ( $screen->object_type() ) {
			'user' => 'user_' . $id,
			'term' => 'term_' . $id,
			default => (string) $id,
		};
	}
}
