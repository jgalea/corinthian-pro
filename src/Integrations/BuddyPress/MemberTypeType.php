<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * Member types are terms of BuddyPress's member type taxonomy attached to the
 * user id, named after the type.
 */
final class MemberTypeType extends BpType implements Filterable, Editable {

	private const MODES = array( 'set', 'clear', 'append', 'remove' );

	public function key(): string {
		return 'bp.member_type';
	}

	public function label(): string {
		return __( 'Member type', 'corinthian-pro' );
	}

	public function component(): string {
		return 'members';
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$labels = BpApi::member_types();
		$names  = array();
		foreach ( BpApi::member_type_names( $object_id ) as $name ) {
			$names[] = $labels[ $name ] ?? $name;
		}
		return $names ? $names : null;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( BpApi::member_types() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$names = array_values( array_intersect( PostFilter::list_values( $value, false ), array_map( 'strval', array_keys( BpApi::member_types() ) ) ) );
		if ( ! $names || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$prefix = BpApi::member_type_prefix();
		$marks  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$exists = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$prefix}term_relationships AS {$alias}_r INNER JOIN {$prefix}term_taxonomy AS {$alias}_x ON {$alias}_x.term_taxonomy_id = {$alias}_r.term_taxonomy_id INNER JOIN {$prefix}terms AS {$alias}_t ON {$alias}_t.term_id = {$alias}_x.term_id WHERE {$alias}_r.object_id = {$wpdb->users}.ID AND {$alias}_x.taxonomy = %s AND {$alias}_t.name IN ({$marks}))", array_merge( array( BpApi::member_type_taxonomy() ), $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array(
			'join'  => '',
			'where' => 'in' === $op ? $exists : "NOT {$exists}",
		);
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => 'multiselect',
			'options' => BpApi::member_types(),
			'bulk'    => self::MODES,
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		return BpApi::member_type_names( $object_id );
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return $this->bad_mode();
		}
		$refused = $this->guard( $object_id );
		if ( $refused ) {
			return $refused;
		}
		$types = 'clear' === $mode ? array() : $this->types_from( $value );
		if ( $types instanceof WP_Error ) {
			return $types;
		}

		$current = BpApi::member_type_names( $object_id );
		$target  = match ( $mode ) {
			'append' => array_values( array_unique( array_merge( $current, $types ) ) ),
			'remove' => array_values( array_diff( $current, $types ) ),
			default  => $types,
		};
		if ( $target === $current ) {
			return true;
		}

		if ( 'remove' === $mode ) {
			foreach ( $types as $type ) {
				BpApi::remove_member_type( $object_id, $type );
			}
		} elseif ( ! BpApi::set_member_types( $object_id, $target ? $target : '', 'append' === $mode ) ) {
			return $this->failed();
		}

		$saved = BpApi::member_type_names( $object_id );
		sort( $saved );
		sort( $target );
		return $saved === $target ? true : $this->failed();
	}

	/**
	 * @return list<string>|WP_Error
	 */
	private function types_from( mixed $value ): array|WP_Error {
		$items      = null === $value || '' === $value ? array() : ( is_array( $value ) ? $value : array( $value ) );
		$registered = array_map( 'strval', array_keys( BpApi::member_types() ) );
		$out        = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
			}
			if ( '' === (string) $item ) {
				continue;
			}
			if ( ! in_array( (string) $item, $registered, true ) ) {
				return $this->invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
			}
			$out[] = (string) $item;
		}
		return array_values( array_unique( $out ) );
	}
}
