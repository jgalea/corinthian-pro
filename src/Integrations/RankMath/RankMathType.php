<?php

namespace CorinthianPro\Integrations\RankMath;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;
use WP_Error;

abstract class RankMathType extends AbstractType {

	abstract protected function meta_key( Config $config ): string;

	public function group(): string {
		return __( 'Rank Math', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && RankMathIntegration::handles_post_type( $screen->subtype() );
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
	 * Turns submitted input into the value Rank Math stores. Null removes the
	 * meta row. $existing is the stored value, or null when there is none.
	 */
	protected function prepare( mixed $value, string $mode, mixed $existing, int $object_id, Config $config ): mixed {
		return new WP_Error( 'corinthian_not_editable', __( 'This column cannot be edited.', 'corinthian-pro' ) );
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->value( $object_id, $config, $screen );
		return is_scalar( $value ) ? (string) $value : null;
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		$key = $this->meta_key( $config );
		if ( ! in_array( $mode, $this->modes(), true ) ) {
			return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that edit.', 'corinthian-pro' ) );
		}
		if ( ! self::has_rank_math_cap( $this->rank_math_cap() ) ) {
			return $this->refused();
		}
		$exists = metadata_exists( 'post', $object_id, $key );

		if ( 'clear' === $mode ) {
			if ( ! current_user_can( 'delete_post_meta', $object_id, $key ) ) {
				return $this->refused();
			}
			delete_post_meta( $object_id, $key );
			return true;
		}

		if ( ! current_user_can( $exists ? 'edit_post_meta' : 'add_post_meta', $object_id, $key ) ) {
			return $this->refused();
		}
		$new = $this->prepare( $value, $mode, $exists ? get_post_meta( $object_id, $key, true ) : null, $object_id, $config );
		if ( $new instanceof WP_Error ) {
			return $new;
		}
		if ( null === $new ) {
			if ( $exists ) {
				if ( ! current_user_can( 'delete_post_meta', $object_id, $key ) ) {
					return $this->refused();
				}
				delete_post_meta( $object_id, $key );
			}
			return true;
		}
		update_post_meta( $object_id, $key, wp_slash( $new ) );
		return true;
	}

	/**
	 * Rank Math's role manager capability this column's edits need, without the
	 * "rank_math_" prefix, matching Rank Math's own Helper::has_cap().
	 */
	protected function rank_math_cap(): string {
		return 'onpage_general';
	}

	/**
	 * Rank Math gates its SEO fields behind its own capabilities. Mirror that so
	 * Corinthian never lets a role edit what Rank Math's editor would hide. When
	 * no role holds the capability (role manager never set up), fall back to the
	 * post and meta checks alone.
	 */
	public static function has_rank_math_cap( string $cap ): bool {
		$full = 'rank_math_' . str_replace( '-', '_', $cap );
		if ( current_user_can( $full ) ) {
			return true;
		}
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( $full ) ) {
				return false;
			}
		}
		return true;
	}

	protected function refused(): WP_Error {
		return new WP_Error( 'corinthian_protected', __( 'You cannot change this field.', 'corinthian-pro' ) );
	}

	protected function invalid( string $message ): WP_Error {
		return new WP_Error( 'corinthian_invalid', $message );
	}

	/**
	 * Plain text the way Rank Math stores titles and descriptions: no markup,
	 * and % variables left alone (sanitize_text_field would eat "%category%").
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
	 * Sort by $expr (with {a} standing for the join alias), posts without a
	 * value last.
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
