<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

trait FiltersBySpec {

	public function filter_operators( Config $config, Screen $screen ): array {
		$options = null !== $this->spec->options ? ( $this->spec->options )() : array();
		return Sql::operators( $this->spec->kind, $options );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
		}
		if ( null !== $this->spec->condition ) {
			return ( $this->spec->condition )( $op, $value, $screen, $alias );
		}
		if ( null === $this->spec->column ) {
			return null;
		}
		$sql   = ( $this->spec->column )( $screen, $alias );
		$where = Sql::condition( $this->spec->kind, $sql['col'], $op, $value, ! empty( $sql['ts'] ) );
		return null === $where ? null : array(
			'join'  => $sql['join'],
			'where' => $where,
		);
	}
}
