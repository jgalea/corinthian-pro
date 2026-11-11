<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

trait SortsBySpec {

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		if ( null === $this->spec->column ) {
			return $clauses;
		}
		$sql  = ( $this->spec->column )( $screen, $this->alias( $config ) );
		$expr = $sql['col'];

		$clauses['join'] = trim( ( $clauses['join'] ?? '' ) . ' ' . $sql['join'] );
		// Rows without a value always sort last.
		$empty              = in_array( $this->spec->kind, array( 'number', 'date' ), true ) ? "{$expr} IS NULL" : "({$expr} IS NULL OR {$expr} = '')";
		$clauses['orderby'] = "{$empty} ASC, {$expr} {$order}";
		return $clauses;
	}
}
