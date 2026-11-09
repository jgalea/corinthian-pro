<?php

namespace CorinthianPro\Abilities;

use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\Matcher;
use Corinthian\Plugin;
use CorinthianPro\Rest\SegmentsController;
use CorinthianPro\Rest\EditController;
use WP_Error;
use WP_REST_Request;

/**
 * Column sets, smart filters, segments and bulk edits as WordPress abilities,
 * so AI agents and other tools can use them with the user's own permissions.
 */
final class Abilities {

	public const CATEGORY  = 'corinthian';
	public const MAX_FIND  = 500;

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'categories' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'abilities' ) );
	}

	public static function categories(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Admin columns', 'corinthian-pro' ),
				'description' => __( 'Find, filter and bulk edit content through the column sets on admin list screens.', 'corinthian-pro' ),
			)
		);
	}

	public static function abilities(): void {
		$filter_schema = array(
			'type'       => 'object',
			'properties' => array(
				'match' => array(
					'type' => 'string',
					'enum' => array( 'all', 'any' ),
				),
				'rules' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'column' => array( 'type' => 'string' ),
							'op'     => array( 'type' => 'string' ),
							'value'  => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'null' ) ),
						),
						'required'   => array( 'column', 'op' ),
					),
				),
			),
		);

		wp_register_ability(
			'corinthian/list-sets',
			array(
				'label'               => __( 'List column sets', 'corinthian-pro' ),
				'description'         => __( 'Column sets the current user can see, with each column and the filter operators it supports.', 'corinthian-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'screen' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => array( self::class, 'list_sets' ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'corinthian/find',
			array(
				'label'               => __( 'Find items with a smart filter', 'corinthian-pro' ),
				'description'         => __( 'Ids of items on a list screen that match a filter built from a set\'s columns. Returns only items the user may read.', 'corinthian-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'screen' => array( 'type' => 'string' ),
						'set'    => array( 'type' => 'integer' ),
						'filter' => $filter_schema,
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_FIND,
						),
					),
					'required'   => array( 'screen', 'set', 'filter' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ids'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'truncated' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( self::class, 'find' ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'corinthian/list-segments',
			array(
				'label'               => __( 'List saved segments', 'corinthian-pro' ),
				'description'         => __( 'Saved filters for a column set, shared and personal.', 'corinthian-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'set' => array( 'type' => 'integer' ) ),
					'required'   => array( 'set' ),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => array( self::class, 'list_segments' ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'corinthian/bulk-edit',
			array(
				'label'               => __( 'Bulk edit a column', 'corinthian-pro' ),
				'description'         => __( 'Set, clear, append or remove a value in one editable column for a list of item ids. Each item is checked against the user\'s permissions.', 'corinthian-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'screen' => array( 'type' => 'string' ),
						'set'    => array( 'type' => 'integer' ),
						'column' => array( 'type' => 'string' ),
						'mode'   => array(
							'type' => 'string',
							'enum' => array( 'set', 'clear', 'append', 'remove' ),
						),
						'value'  => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'null' ) ),
						'ids'    => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => self::MAX_FIND,
						),
					),
					'required'   => array( 'screen', 'set', 'column', 'mode', 'ids' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'bulk_edit' ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return list<array<string,mixed>>
	 */
	public static function list_sets( $input = array() ): array {
		$plugin = Plugin::instance();
		$user   = wp_get_current_user();
		$only   = (string) ( $input['screen'] ?? '' );
		$out    = array();
		foreach ( $plugin->screens->all() as $screen ) {
			if ( ( '' !== $only && $screen->key() !== $only ) || ! $screen->user_can_list() ) {
				continue;
			}
			foreach ( $plugin->sets->for_screen( $screen->key() ) as $set ) {
				if ( ! $set->visible_to( $user ) ) {
					continue;
				}
				$columns = array();
				foreach ( $set->columns as $config ) {
					$type    = $plugin->registry->get( $config->type );
					$columns[] = array(
						'id'        => $config->id,
						'label'     => '' !== $config->label ? $config->label : ( $screen->native_columns()[ (string) $config->setting( 'key' ) ] ?? $config->id ),
						'type'      => $config->type,
						'operators' => $type instanceof \Corinthian\Columns\Filterable ? array_column( $type->filter_operators( $config, $screen ), 'op' ) : array(),
						'editable'  => $type instanceof \Corinthian\Columns\Editable && false !== $config->setting( 'editable', true ),
					);
				}
				$out[] = array(
					'id'      => $set->id,
					'screen'  => $screen->key(),
					'title'   => $set->title,
					'columns' => $columns,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	public static function find( $input = array() ): array|WP_Error {
		$plugin = Plugin::instance();
		$screen = $plugin->screens->get( (string) ( $input['screen'] ?? '' ) );
		$set    = $plugin->sets->find( (int) ( $input['set'] ?? 0 ) );
		if ( ! $screen || ! $set || $set->screen !== $screen->key() || ! $set->visible_to( wp_get_current_user() ) || ! $screen->user_can_list() ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		$filter = FilterSet::from( is_array( $input['filter'] ?? null ) ? $input['filter'] : array() );
		if ( $filter->is_empty() ) {
			return new WP_Error( 'corinthian_empty_filter', __( 'Add at least one rule.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		$limit = max( 1, min( self::MAX_FIND, (int) ( $input['limit'] ?? self::MAX_FIND ) ) );
		// Fetch one extra to know whether the result was cut off.
		$ids = array_values( array_filter( Matcher::ids( $screen, $set, $filter, null, $limit + 1 ), array( $screen, 'user_can_read' ) ) );
		return array(
			'ids'       => array_slice( $ids, 0, $limit ),
			'truncated' => count( $ids ) > $limit,
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public static function list_segments( $input = array() ): array|WP_Error {
		$set = Plugin::instance()->sets->find( (int) ( $input['set'] ?? 0 ) );
		if ( ! $set || ! $set->visible_to( wp_get_current_user() ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		return SegmentsController::for_user( $set, get_current_user_id() );
	}

	/**
	 * Runs through the same controller as the list-table bulk editor, so every
	 * permission check applies.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	public static function bulk_edit( $input = array() ): array|WP_Error {
		$ids     = array_slice( array_values( array_filter( array_map( 'absint', (array) ( $input['ids'] ?? array() ) ) ) ), 0, self::MAX_FIND );
		$done    = 0;
		$failed  = array();
		$offset  = 0;
		$control = new EditController( Plugin::instance() );
		do {
			$request = new WP_REST_Request( 'POST', '/corinthian/v1/bulk-edit' );
			foreach ( array( 'screen', 'set', 'column', 'mode', 'value' ) as $key ) {
				$request->set_param( $key, $input[ $key ] ?? null );
			}
			$request->set_param( 'ids', $ids );
			$request->set_param( 'offset', $offset );
			$response = $control->bulk( $request );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$data   = $response->get_data();
			$done  += (int) $data['done'];
			$failed = array_merge( $failed, (array) $data['failed'] );
			$offset = $data['next_offset'];
		} while ( null !== $offset );

		return array(
			'done'   => $done,
			'failed' => $failed,
		);
	}
}
