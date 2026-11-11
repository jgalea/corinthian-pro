<?php

namespace CorinthianPro\Integrations\SeoPress;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A SEOPress checkbox stored as 'yes' when ticked. Unticked deletes the row.
 */
abstract class FlagType extends SeoPressType implements Sortable, Filterable, Editable {

	abstract protected function on_label(): string;

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return 'yes' === parent::value( $object_id, $config, $screen );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		if ( true !== $this->value( $object_id, $config, $screen ) ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}
		return '<strong>' . esc_html( $this->on_label() ) . '</strong>';
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, $this->meta_key( $config, $screen->subtype() ), "MAX({a}.meta_value = 'yes')", $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::yes_no_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$flag = PostFilter::flag( $value );
		if ( 'eq' !== $op || null === $flag ) {
			return null;
		}
		$on = PostFilter::meta_exists( $alias, $this->meta_key( $config, $screen->subtype() ), "{$alias}.meta_value = 'yes'" );
		return PostFilter::wrap( $flag ? $on : "NOT {$on}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => 'toggle',
			'bulk'  => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		return true === $this->value( $object_id, $config, $screen ) ? '1' : '0';
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		$word = is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '' );
		if ( in_array( $word, array( '1', 'on', 'true', 'yes' ), true ) ) {
			return 'yes';
		}
		if ( in_array( $word, array( '0', 'off', 'false', 'no' ), true ) ) {
			return null;
		}
		return $this->invalid( __( 'Choose on or off.', 'corinthian-pro' ) );
	}
}
