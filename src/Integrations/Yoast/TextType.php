<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\RankMath\MetaText;
use Corinthian\Screens\Screen;

/**
 * A free-text Yoast field: contains / is empty filters, alphabetical sort and a text editor.
 */
abstract class TextType extends YoastType implements Sortable, Filterable, Editable {

	protected function single_line(): bool {
		return true;
	}

	protected function input(): string {
		return 'text';
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, $this->meta_key( $config ), 'MAX({a}.meta_value)', $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return array(
			array(
				'op'    => 'contains',
				'label' => __( 'contains', 'corinthian-pro' ),
				'input' => 'text',
			),
			array(
				'op'    => 'empty',
				'label' => __( 'is empty', 'corinthian-pro' ),
				'input' => 'none',
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		return MetaText::condition( $alias, $this->meta_key( $config ), $op, $value );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input' => $this->input(),
			'bulk'  => $this->modes(),
		);
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|\WP_Error|null {
		$text = $this->plain( $value, $this->single_line() );
		if ( $text instanceof \WP_Error ) {
			return $text;
		}
		return '' === $text ? null : $text;
	}
}
