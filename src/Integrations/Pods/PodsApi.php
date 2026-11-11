<?php

namespace CorinthianPro\Integrations\Pods;

use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that calls Pods. Calls go through call_user_func so the
 * plugin analyses cleanly without Pods' function stubs.
 */
final class PodsApi {

	private static function call( string $name, mixed ...$args ): mixed {
		return \call_user_func_array( $name, $args );
	}

	public static function available(): bool {
		return function_exists( 'pods' ) && function_exists( 'pods_api' );
	}

	public static function pod_name( Screen $screen ): string {
		return match ( $screen->object_type() ) {
			'post', 'term' => $screen->subtype(),
			'user'         => 'user',
			default        => '',
		};
	}

	private static function pod_type( Screen $screen ): string {
		return match ( $screen->object_type() ) {
			'post'  => 'post_type',
			'term'  => 'taxonomy',
			'user'  => 'user',
			default => '',
		};
	}

	/**
	 * The pod behind the screen's objects: its label, storage ("meta" keeps values in the object's own
	 * meta table, "table" in a pod table) and its fields as plain arrays keyed by field name.
	 *
	 * @return array{label:string,storage:string,fields:array<string,array<string,mixed>>}|null
	 */
	public static function pod( Screen $screen ): ?array {
		$name = self::pod_name( $screen );
		if ( '' === $name || ! self::available() ) {
			return null;
		}
		$api = self::call( 'pods_api' );
		if ( ! is_object( $api ) || ! method_exists( $api, 'load_pod' ) ) {
			return null;
		}
		$pod = $api->load_pod( array( 'name' => $name ) );
		if ( ! $pod instanceof \ArrayAccess || ! method_exists( $pod, 'get_fields' ) || self::pod_type( $screen ) !== (string) $pod['type'] ) {
			return null;
		}
		$fields = array();
		foreach ( (array) $pod->get_fields() as $field ) {
			$args = is_object( $field ) && method_exists( $field, 'get_args' ) ? $field->get_args() : null;
			if ( is_array( $args ) && ! empty( $args['name'] ) ) {
				$fields[ (string) $args['name'] ] = $args;
			}
		}
		$storage = (string) $pod['storage'];
		return array(
			'label'   => (string) $pod['label'],
			'storage' => '' !== $storage ? $storage : 'meta',
			'fields'  => $fields,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function field( string $name, Screen $screen ): ?array {
		$pod   = '' !== $name ? self::pod( $screen ) : null;
		$field = $pod['fields'][ $name ] ?? null;
		return $field && FieldKind::storable( $field ) ? $field : null;
	}

	public static function storage( Screen $screen ): string {
		$pod = self::pod( $screen );
		return $pod ? $pod['storage'] : 'none';
	}

	/**
	 * Storable fields of the screen's pod, as a list of [ pod label, field ].
	 *
	 * @return list<array{0:string,1:array<string,mixed>}>
	 */
	public static function fields_for( Screen $screen ): array {
		$pod = self::pod( $screen );
		if ( ! $pod ) {
			return array();
		}
		$out = array();
		foreach ( $pod['fields'] as $field ) {
			if ( FieldKind::storable( $field ) ) {
				$out[] = array( $pod['label'], $field );
			}
		}
		return $out;
	}

	private static function item( Screen $screen, int $id ): ?object {
		$pods = self::call( 'pods', self::pod_name( $screen ), $id );
		return is_object( $pods ) && method_exists( $pods, 'valid' ) && $pods->valid() ? $pods : null;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function get( array $field, Screen $screen, int $id ): mixed {
		$pods = self::item( $screen, $id );
		if ( ! $pods || ! method_exists( $pods, 'field' ) ) {
			return null;
		}
		$name = (string) $field['name'];
		return FieldKind::reads_ids( $field )
			? $pods->field(
				array(
					'name'   => $name,
					'output' => 'ids',
				)
			)
			: $pods->field( $name );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function set( array $field, Screen $screen, int $id, mixed $value ): bool {
		$pods = self::item( $screen, $id );
		if ( ! $pods || ! method_exists( $pods, 'save' ) ) {
			return false;
		}
		$saved = $pods->save( (string) $field['name'], $value, $id );
		return ! is_wp_error( $saved ) && is_numeric( $saved ) && (int) $saved > 0;
	}

	/**
	 * Pods' own field permission (admin only, role and capability limits).
	 *
	 * @param array<string,mixed> $field
	 */
	public static function permitted( array $field ): bool {
		return ! function_exists( 'pods_permission' ) || (bool) self::call( 'pods_permission', $field );
	}
}
