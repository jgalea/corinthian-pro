<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * Post meta written by Yoast SEO. The post meta rows (_yoast_wpseo_*) are what
 * Yoast's editor writes and what its own list filters query; the
 * wp_yoast_indexable row is derived from them. Writes therefore go through
 * WPSEO_Meta, and Yoast's own meta watcher rebuilds the indexable when the
 * request ends.
 */
abstract class YoastType extends AbstractType {

	/**
	 * Meta name without the _yoast_wpseo_ prefix, as WPSEO_Meta::set_value() takes it.
	 */
	abstract protected function meta_name( Config $config ): string;

	public function group(): string {
		return __( 'Yoast SEO', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && YoastIntegration::handles_post_type( $screen->subtype() );
	}

	protected function meta_key( Config $config ): string {
		return YoastIntegration::META_PREFIX . $this->meta_name( $config );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = get_post_meta( $object_id, $this->meta_key( $config ), true );
		return '' === $value ? null : $value;
	}

	/**
	 * @return list<string>
	 */
	protected function modes(): array {
		return array( 'set', 'clear' );
	}

	/**
	 * Turns submitted input into the value Yoast stores. Null removes the meta row.
	 */
	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		return new WP_Error( 'corinthian_not_editable', __( 'This column cannot be edited.', 'corinthian-pro' ) );
	}

	/**
	 * Yoast puts noindex, nofollow, canonical and schema behind its advanced tab.
	 */
	protected function advanced(): bool {
		return false;
	}

	/**
	 * A Yoast feature switch (a wpseo option) that has to be on for the field to exist in its editor.
	 */
	protected function feature(): ?string {
		return null;
	}

	protected function after_write( int $object_id, Config $config ): void {
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return is_scalar( $value ) ? (string) $value : null;
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( ! in_array( $mode, $this->modes(), true ) ) {
			return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that edit.', 'corinthian-pro' ) );
		}
		// Yoast's keys start with an underscore, so WordPress refuses edit_post_meta for
		// everyone. Its own editor, REST auth callbacks and bulk editor check edit_post.
		if ( ! current_user_can( 'edit_post', $object_id ) ) {
			return $this->refused();
		}
		if ( $this->advanced() && ! YoastIntegration::can_edit_advanced() ) {
			return $this->refused();
		}
		$feature = $this->feature();
		if ( null !== $feature && true !== YoastIntegration::option( $feature, true ) ) {
			return new WP_Error( 'corinthian_disabled', __( 'Yoast SEO has this feature switched off.', 'corinthian-pro' ) );
		}

		$name = $this->meta_name( $config );
		if ( 'clear' === $mode ) {
			$this->forget( $name, $object_id );
			$this->after_write( $object_id, $config );
			return true;
		}
		$new = $this->prepare( $value, $mode, $object_id, $config );
		if ( $new instanceof WP_Error ) {
			return $new;
		}
		if ( null === $new ) {
			$this->forget( $name, $object_id );
		} else {
			$this->store( $name, $new, $object_id );
		}
		$this->after_write( $object_id, $config );
		return true;
	}

	private function store( string $name, string $value, int $object_id ): void {
		if ( is_callable( array( 'WPSEO_Meta', 'set_value' ) ) ) {
			call_user_func( array( 'WPSEO_Meta', 'set_value' ), $name, $value, $object_id );
			return;
		}
		update_post_meta( $object_id, YoastIntegration::META_PREFIX . $name, wp_slash( $value ) );
	}

	private function forget( string $name, int $object_id ): void {
		if ( is_callable( array( 'WPSEO_Meta', 'delete' ) ) ) {
			call_user_func( array( 'WPSEO_Meta', 'delete' ), $name, $object_id );
			return;
		}
		delete_post_meta( $object_id, YoastIntegration::META_PREFIX . $name );
	}

	protected function refused(): WP_Error {
		return new WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
	}

	protected function invalid( string $message ): WP_Error {
		return new WP_Error( 'corinthian_invalid', $message );
	}

	/**
	 * Plain text the way Yoast stores titles and descriptions: no markup, and
	 * %%variables%% left alone.
	 */
	protected function plain( mixed $value, bool $single_line ): string|WP_Error {
		if ( ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$raw   = (string) $value;
		$clean = trim( wp_strip_all_tags( $raw ) );
		if ( $single_line ) {
			$clean = trim( (string) preg_replace( '/\s+/', ' ', $clean ) );
		}
		if ( '' === $clean && '' !== trim( $raw ) ) {
			return $this->invalid( __( 'Enter text without markup.', 'corinthian-pro' ) );
		}
		return $clean;
	}

	protected function join( string $alias, string $key ): string {
		global $wpdb;
		return $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s)", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sort by $expr (with {a} standing for the join alias), posts without a value last.
	 *
	 * @param array<string,string> $clauses
	 * @param 'ASC'|'DESC'         $order
	 * @return array<string,string>
	 */
	protected function sort_by( array $clauses, Config $config, string $key, string $expr, string $order ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$expr               = str_replace( '{a}', $alias, $expr );
		$clauses['join']   .= $this->join( $alias, $key );
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		$clauses['orderby'] = "(MAX({$alias}.meta_value) IS NULL OR MAX({$alias}.meta_value) = '') ASC, {$expr} {$order}";
		return $clauses;
	}
}
