<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\AbstractType;
use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

/**
 * A WooCommerce column driven by a Spec. Sorting, filtering and editing come from
 * the traits the concrete classes use, so a column only advertises what it can do.
 */
abstract class Column extends AbstractType {

	public function __construct( protected Spec $spec ) {}

	public function key(): string {
		return $this->spec->key;
	}

	public function label(): string {
		return $this->spec->label;
	}

	public function group(): string {
		return match ( $this->spec->scope ) {
			'order'  => __( 'WooCommerce orders', 'corinthian-pro' ),
			'coupon' => __( 'WooCommerce coupons', 'corinthian-pro' ),
			default  => __( 'WooCommerce products', 'corinthian-pro' ),
		};
	}

	public function supports( Screen $screen ): bool {
		return Woo::active() && Woo::scope_of( $screen ) === $this->spec->scope;
	}

	protected function object( int $id ): mixed {
		return match ( $this->spec->scope ) {
			'order'  => Woo::order( $id ),
			'coupon' => Woo::coupon( $id ),
			default  => Woo::product( $id ),
		};
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$object = $this->object( $object_id );
		return null === $object ? null : ( $this->spec->value )( $object );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$object = $this->object( $object_id );
		if ( null === $object ) {
			return Woo::dash();
		}
		$value = ( $this->spec->value )( $object );
		if ( null === $value || '' === $value ) {
			return Woo::dash();
		}
		return null !== $this->spec->html ? ( $this->spec->html )( $value, $object ) : parent::render( $object_id, $config, $screen );
	}
}
