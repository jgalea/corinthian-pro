<?php

namespace CorinthianPro\Rest;

use Corinthian\Columns\Filterable;
use Corinthian\Columns\FilterOptions;
use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Search and page the values a filter rule can take on one column, or look up
 * labels for values picked earlier. The column type supplies the values
 * through FilterOptions.
 */
final class FilterValuesController {

	public const PER_PAGE     = 20;
	public const MAX_PER_PAGE = 50;
	public const MAX_INCLUDE  = 100;
	public const MAX_SEARCH   = 100;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		register_rest_route(
			SetsController::NAMESPACE,
			'/sets/(?P<set>\d+)/filter-values',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'column'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => self::PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
					'include'  => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => self::MAX_INCLUDE,
					),
				),
			)
		);
	}

	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->plugin->sets->find( (int) $request['set'] );
		if ( ! $set || ! SegmentsController::can_use( $this->plugin, $set ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		$screen = $this->plugin->screens->get( $set->screen );
		$config = $set->column( sanitize_key( (string) $request['column'] ) );
		$type   = $config ? $this->plugin->registry->get( $config->type ) : null;
		if ( ! $screen || ! $config || ! $type instanceof Filterable || ! $type instanceof FilterOptions || false === $config->setting( 'filterable', true )
			|| ! array_filter( $type->filter_operators( $config, $screen ), static fn( $o ) => ! empty( $o['search'] ) ) ) {
			return new WP_Error( 'corinthian_not_searchable', __( 'This column has no values to search.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}

		$include = $request['include'];
		if ( is_array( $include ) && array() !== $include ) {
			$values = array_values( array_unique( array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $include ) ) );
			return new WP_REST_Response(
				array(
					'items' => self::items( $type->filter_labels( $config, $screen, $values ) ),
					'page'  => 1,
					'more'  => false,
				)
			);
		}

		$search = mb_substr( trim( sanitize_text_field( (string) $request['search'] ) ), 0, self::MAX_SEARCH );
		$page   = (int) $request['page'];
		$result = $type->filter_options( $config, $screen, $search, $page, (int) $request['per_page'] );
		return new WP_REST_Response(
			array(
				'items' => self::items( $result['options'] ),
				'page'  => $page,
				'more'  => $result['more'],
			)
		);
	}

	/**
	 * A list keeps the server's order; numeric keys in a JSON object would be re-sorted by the browser.
	 *
	 * @param array<int|string,string> $options
	 * @return list<array{value:string,label:string}>
	 */
	private static function items( array $options ): array {
		$out = array();
		foreach ( $options as $value => $label ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}
		return $out;
	}
}
