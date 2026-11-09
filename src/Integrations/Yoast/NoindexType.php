<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use WP_Error;

/**
 * _yoast_wpseo_meta-robots-noindex: '1' noindex, '2' index. No row (Yoast's
 * '0') means the post type default from Yoast's search appearance settings.
 */
final class NoindexType extends YoastType implements Sortable, Filterable, Editable {

	private const STORED = array(
		'noindex' => '1',
		'index'   => '2',
	);

	public function key(): string {
		return 'yoast.noindex';
	}

	public function label(): string {
		return __( 'Noindex', 'corinthian-pro' );
	}

	protected function meta_name( Config $config ): string {
		return 'meta-robots-noindex';
	}

	protected function advanced(): bool {
		return true;
	}

	/**
	 * @return array<string,string>
	 */
	private function options(): array {
		return array(
			'default' => __( 'Default', 'corinthian-pro' ),
			'index'   => __( 'Index', 'corinthian-pro' ),
			'noindex' => __( 'Noindex', 'corinthian-pro' ),
		);
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$state = array_search( (string) parent::value( $object_id, $config, $screen ), self::STORED, true );
		return false === $state ? null : $state;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$state = $this->value( $object_id, $config, $screen );
		if ( 'noindex' === $state ) {
			return '<strong>' . esc_html__( 'Noindex', 'corinthian-pro' ) . '</strong>';
		}
		return 'index' === $state ? esc_html__( 'Index', 'corinthian-pro' ) : '<span aria-hidden="true">&#8212;</span>';
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_by( $clauses, $config, $this->meta_key( $config ), "MAX({a}.meta_value = '1')", $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( $this->options() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$wanted = array_values( array_intersect( PostFilter::list_values( $value, false ), array_keys( $this->options() ) ) );
		if ( ! $wanted || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		$key = $this->meta_key( $config );
		$any = array();
		foreach ( $wanted as $i => $state ) {
			$any[] = 'default' === $state
				? 'NOT ' . PostFilter::meta_exists( "{$alias}_{$i}", $key, "{$alias}_{$i}.meta_value IN ('1','2')" )
				: PostFilter::meta_exists( "{$alias}_{$i}", $key, "{$alias}_{$i}.meta_value = '" . self::STORED[ $state ] . "'" );
		}
		$sql = '(' . implode( ' OR ', $any ) . ')';
		return PostFilter::wrap( 'in' === $op ? $sql : "NOT {$sql}" );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		return array(
			'input'   => 'select',
			'options' => $this->options(),
			'bulk'    => $this->modes(),
		);
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$state = $this->value( $object_id, $config, $screen );
		return is_string( $state ) ? $state : 'default';
	}

	protected function prepare( mixed $value, string $mode, int $object_id, Config $config ): string|WP_Error|null {
		if ( ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$state = strtolower( trim( (string) $value ) );
		if ( 'default' === $state || '' === $state ) {
			return null;
		}
		return self::STORED[ $state ] ?? $this->invalid( __( 'Choose default, index or noindex.', 'corinthian-pro' ) );
	}
}
