<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * Post meta written by SEOPress (_seopress_*). SEOPress keeps these as plain
 * post meta and drops writes from roles its Security settings block, so save()
 * refuses those roles up front instead of reporting a write that never lands.
 */
abstract class SeoPressType extends AbstractType {

	abstract protected function meta_key( Config $config, string $post_type ): string;

	public function group(): string {
		return __( 'SEOPress', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && SeoPressIntegration::handles_post_type( $screen->subtype() );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = get_post_meta( $object_id, $this->meta_key( $config, (string) get_post_type( $object_id ) ), true );
		return '' === $value ? null : $value;
	}

	/**
	 * @return list<string>
	 */
	protected function modes(): array {
		return array( 'set', 'clear' );
	}

	/**
	 * SEOPress restriction area that governs this field.
	 */
	protected function area(): string {
		return 'GLOBAL';
	}

	/**
	 * Turns submitted input into the value SEOPress stores. Null removes the meta row.
	 */
	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		return new WP_Error( 'corinthian_not_editable', __( 'This column cannot be edited.', 'corinthian-pro' ) );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return is_scalar( $value ) ? (string) $value : null;
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( ! in_array( $mode, $this->modes(), true ) ) {
			return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that edit.', 'corinthian-pro' ) );
		}
		// The keys start with an underscore, so WordPress refuses edit_post_meta for
		// everyone. SEOPress's own save routines and REST auth check edit_post.
		if ( ! current_user_can( 'edit_post', $object_id ) || SeoPressIntegration::role_blocked( $this->area() ) ) {
			return $this->refused();
		}

		$key = $this->meta_key( $config, (string) get_post_type( $object_id ) );
		if ( 'clear' === $mode ) {
			delete_post_meta( $object_id, $key );
			return true;
		}
		$new = $this->prepare( $value, $mode, $object_id, $config );
		if ( $new instanceof WP_Error ) {
			return $new;
		}
		if ( null === $new ) {
			delete_post_meta( $object_id, $key );
		} else {
			update_post_meta( $object_id, $key, wp_slash( $new ) );
		}
		return true;
	}

	protected function refused(): WP_Error {
		return new WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
	}

	protected function invalid( string $message ): WP_Error {
		return new WP_Error( 'corinthian_invalid', $message );
	}

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
