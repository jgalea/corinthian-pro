<?php

namespace CorinthianPro\Admin;

use Corinthian\Columns\Editable;
use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

final class EditPayload {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'corinthian_table_payload', array( $this, 'extend' ), 10, 3 );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	public function extend( $data, Screen $screen, ColumnSet $set ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$data['editable'] = $this->editable_columns( $screen, $set );
		$data['quickAdd'] = $this->quick_add( $screen, $set );
		return $data;
	}

	/**
	 * Columns of a set that can be edited from the list table.
	 *
	 * @return list<array{id:string,tableKey:string,field:array<string,mixed>}>
	 */
	private function editable_columns( Screen $screen, ColumnSet $set ): array {
		$out = array();
		foreach ( $set->columns as $config ) {
			$type = $this->plugin->registry->get( $config->type );
			if ( ! $type instanceof Editable || false === $config->setting( 'editable', true ) ) {
				continue;
			}
			$out[] = array(
				'id'       => $config->id,
				'tableKey' => $config->table_key(),
				'field'    => $type->edit_field( $config, $screen ),
			);
		}
		return $out;
	}

	/**
	 * Quick add creates a draft through core's posts REST route, so only post
	 * types exposed there qualify, and core applies its own permission checks.
	 *
	 * @return array{restBase:string,namespace:string,label:string}|null
	 */
	private function quick_add( Screen $screen, ColumnSet $set ): ?array {
		if ( 'post' !== $screen->object_type() || 'attachment' === $screen->subtype() || false === ( $set->settings['quick_add'] ?? true ) ) {
			return null;
		}
		$type = get_post_type_object( $screen->subtype() );
		if ( ! $type || ! $type->show_in_rest || ! current_user_can( $type->cap->create_posts ) ) {
			return null;
		}
		return array(
			'restBase'  => (string) ( $type->rest_base ?: $type->name ),
			'namespace' => (string) ( $type->rest_namespace ?: 'wp/v2' ),
			'label'     => (string) $type->labels->add_new_item,
		);
	}
}
