<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A schema type chosen per post. No row means the post type default from
 * Yoast's content type settings applies.
 */
abstract class SchemaType extends YoastType implements Sortable, Filterable, Editable {

	/**
	 * @return array<string,string> type => label, from Yoast's own list.
	 */
	abstract protected function types(): array;

	abstract protected function applies_to( string $post_type ): bool;

	/**
	 * Yoast option holding the post type default, without the post type suffix.
	 */
	abstract protected function default_option(): string;

	protected function advanced(): bool {
		return true;
	}

	public function supports( Screen $screen ): bool {
		return parent::supports( $screen ) && $this->applies_to( $screen->subtype() );
	}

	/**
	 * @return array<string,string>
	 */
	protected static function from_constant( string $name ): array {
		$list = defined( $name ) ? constant( $name ) : array();
		$out  = array();
		foreach ( is_array( $list ) ? array_keys( $list ) : array() as $type ) {
			$out[ (string) $type ] = (string) $type;
		}
		return $out;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$type = $this->value( $object_id, $config, $screen );
		if ( is_string( $type ) ) {
			return esc_html( $type );
		}
		$default = YoastIntegration::option( $this->default_option() . get_post_type( $object_id ), '' );
		if ( ! is_string( $default ) || '' === $default ) {
			return parent::render( $object_id, $config, $screen );
		}
		return '<em>' . esc_html( $default ) . '</em> <small>' . esc_html__( '(default)', 'corinthian-pro' ) . '</small>';
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, $this->meta_key( $config ), 'MAX({a}.meta_value)', $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( array( 'default' => __( 'Default', 'corinthian-pro' ) ) + $this->types() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$allowed = array_keys( $this->types() );
		$wanted  = array_values( array_intersect( PostFilter::list_values( $value, false ), array_merge( array( 'default' ), $allowed ) ) );
		if ( ! $wanted || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$key = $this->meta_key( $config );
		$any = array();
		foreach ( $wanted as $i => $type ) {
			$any[] = 'default' === $type
				? 'NOT ' . PostFilter::meta_exists( "{$alias}_{$i}", $key, "{$alias}_{$i}.meta_value <> ''" )
				: PostFilter::meta_exists( "{$alias}_{$i}", $key, $wpdb->prepare( "{$alias}_{$i}.meta_value = %s", (string) $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$sql = '(' . implode( ' OR ', $any ) . ')';
		return PostFilter::wrap( 'in' === $op ? $sql : "NOT {$sql}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => 'select',
			'options' => array( 'default' => __( 'Default', 'corinthian-pro' ) ) + $this->types(),
			'bulk'    => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$type = $this->value( $object_id, $config, $screen );
		return is_string( $type ) ? $type : 'default';
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		if ( ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$type = trim( (string) $value );
		if ( 'default' === strtolower( $type ) || '' === $type ) {
			return null;
		}
		if ( ! $this->applies_to( (string) get_post_type( $object_id ) ) || ! isset( $this->types()[ $type ] ) ) {
			return $this->invalid( __( 'Choose one of the schema types Yoast offers for this content type.', 'corinthian-pro' ) );
		}
		return $type;
	}
}
