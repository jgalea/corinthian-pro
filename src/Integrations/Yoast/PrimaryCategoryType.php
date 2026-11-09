<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostEditor;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * _yoast_wpseo_primary_{taxonomy}: the term id of the primary term. Yoast keeps
 * one per hierarchical taxonomy and mirrors it into wp_yoast_primary_term when a
 * post is saved, so a write here rebuilds that row itself.
 */
final class PrimaryCategoryType extends YoastType implements Filterable, Editable {

	public function key(): string {
		return 'yoast.primary_category';
	}

	public function label(): string {
		return __( 'Primary category', 'corinthian-pro' );
	}

	private function taxonomy( Config $config ): string {
		$taxonomy = sanitize_key( (string) $config->setting( 'taxonomy', 'category' ) );
		return '' !== $taxonomy && taxonomy_exists( $taxonomy ) ? $taxonomy : 'category';
	}

	protected function meta_name( Config $config ): string {
		return 'primary_' . $this->taxonomy( $config );
	}

	public function settings_schema( Screen $screen ): array {
		$options = array();
		foreach ( get_taxonomies( array( 'hierarchical' => true ), 'objects' ) as $taxonomy ) {
			$options[ $taxonomy->name ] = $taxonomy->labels->singular_name;
		}
		return array(
			array(
				'name'    => 'taxonomy',
				'type'    => 'select',
				'label'   => __( 'Taxonomy', 'corinthian-pro' ),
				'default' => 'category',
				'options' => $options,
			),
		);
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$stored = parent::value( $object_id, $config, $screen );
		return is_numeric( $stored ) && (int) $stored > 0 ? (int) $stored : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$id = $this->value( $object_id, $config, $screen );
		if ( null === $id ) {
			return parent::render( $object_id, $config, $screen );
		}
		$term = get_term( $id, $this->taxonomy( $config ) );
		return $term instanceof \WP_Term ? esc_html( $term->name ) : esc_html( '#' . $id );
	}

	/**
	 * @return array<string,string>
	 */
	private function term_options( Config $config ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy( $config ),
				'hide_empty' => false,
				'number'     => PostEditor::TERM_LIMIT,
				'orderby'    => 'name',
				'fields'     => 'id=>name',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $id => $name ) {
			$out[ (string) $id ] = (string) $name;
		}
		return $out;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'      => 'eq',
				'label'   => __( 'is', 'corinthian-pro' ),
				'input'   => 'select',
				'options' => $this->term_options( $config ),
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$ids = PostFilter::list_values( $value, true );
		if ( 'eq' !== $op || 1 !== count( $ids ) ) {
			return null;
		}
		global $wpdb;
		return PostFilter::wrap( PostFilter::meta_exists( $alias, $this->meta_key( $config ), $wpdb->prepare( "{$alias}.meta_value = %s", (string) $ids[0] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => 'select',
			'options' => $this->term_options( $config ),
			'bulk'    => $this->modes(),
		);
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return $this->invalid( __( 'Choose a category.', 'corinthian-pro' ) );
		}
		$term_id = absint( $value );
		if ( $term_id < 1 || ! has_term( $term_id, $this->taxonomy( $config ), $object_id ) ) {
			return $this->invalid( __( 'The primary category must be one the post is already in.', 'corinthian-pro' ) );
		}
		return (string) $term_id;
	}

	protected function after_write( int $object_id, Config $config ): void {
		$builder = YoastIntegration::service( 'Yoast\WP\SEO\Builders\Primary_Term_Builder' );
		if ( $builder && is_callable( array( $builder, 'build' ) ) ) {
			call_user_func( array( $builder, 'build' ), $object_id );
		}
	}
}
