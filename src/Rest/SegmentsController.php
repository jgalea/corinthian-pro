<?php

namespace CorinthianPro\Rest;

use CorinthianPro\Filters\FilterSet;
use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use Corinthian\Sets\ColumnSet;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Saved filters ("segments") for a column set. Shared segments live on the set
 * and need the manage capability; personal ones live in the user's meta.
 */
final class SegmentsController {

	public const USER_META_PREFIX = 'corinthian_segments_';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		register_rest_route(
			SetsController::NAMESPACE,
			'/sets/(?P<set>\d+)/segments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
		register_rest_route(
			SetsController::NAMESPACE,
			'/sets/(?P<set>\d+)/segments/(?P<id>[a-z0-9]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * Segments this user can see on a set, shared first.
	 *
	 * @return list<array{id:string,name:string,shared:bool,filter:array<string,mixed>}>
	 */
	public static function for_user( ColumnSet $set, int $user_id ): array {
		$out = array();
		foreach ( (array) ( $set->settings['segments'] ?? array() ) as $segment ) {
			if ( is_array( $segment ) ) {
				$out[] = self::shape( $segment, true );
			}
		}
		$mine = get_user_meta( $user_id, self::USER_META_PREFIX . $set->id, true );
		foreach ( is_array( $mine ) ? $mine : array() as $segment ) {
			if ( is_array( $segment ) ) {
				$out[] = self::shape( $segment, false );
			}
		}
		return $out;
	}

	public function list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		return is_wp_error( $set ) ? $set : new WP_REST_Response( self::for_user( $set, get_current_user_id() ) );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$filter = FilterSet::from( (array) $request->get_param( 'filter' ) );
		$shared = (bool) $request->get_param( 'shared' );
		if ( '' === $name || $filter->is_empty() ) {
			return new WP_Error( 'corinthian_invalid', __( 'A segment needs a name and at least one rule.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		if ( $shared && ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You can only save personal segments.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		$segment = array(
			'id'     => strtolower( wp_generate_password( 10, false ) ),
			'name'   => $name,
			'filter' => $filter->to_array(),
		);

		if ( $shared ) {
			$data                           = $set->to_array();
			$data['settings']               = (array) $data['settings'];
			$data['settings']['segments']   = array_values( (array) ( $set->settings['segments'] ?? array() ) );
			$data['settings']['segments'][] = $segment;
			$this->plugin->sets->save( $data, $set->id );
		} else {
			$key    = self::USER_META_PREFIX . $set->id;
			$mine   = get_user_meta( get_current_user_id(), $key, true );
			$mine   = is_array( $mine ) ? $mine : array();
			$mine[] = $segment;
			update_user_meta( get_current_user_id(), $key, array_slice( $mine, -50 ) );
		}
		return new WP_REST_Response( self::shape( $segment, $shared ), 201 );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$id   = (string) $request['id'];
		$key  = self::USER_META_PREFIX . $set->id;
		$mine = get_user_meta( get_current_user_id(), $key, true );
		$mine = is_array( $mine ) ? $mine : array();
		$kept = array_values( array_filter( $mine, static fn( $s ) => ( $s['id'] ?? '' ) !== $id ) );
		if ( count( $kept ) !== count( $mine ) ) {
			update_user_meta( get_current_user_id(), $key, $kept );
			return new WP_REST_Response( array( 'deleted' => true ) );
		}

		$shared = array_values( (array) ( $set->settings['segments'] ?? array() ) );
		$left   = array_values( array_filter( $shared, static fn( $s ) => ( $s['id'] ?? '' ) !== $id ) );
		if ( count( $left ) === count( $shared ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Segment not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You cannot delete shared segments.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		$data                         = $set->to_array();
		$data['settings']             = (array) $data['settings'];
		$data['settings']['segments'] = $left;
		$this->plugin->sets->save( $data, $set->id );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * @param array<string,mixed> $segment
	 * @return array{id:string,name:string,shared:bool,filter:array<string,mixed>}
	 */
	private static function shape( array $segment, bool $shared ): array {
		return array(
			'id'     => (string) ( $segment['id'] ?? '' ),
			'name'   => (string) ( $segment['name'] ?? '' ),
			'shared' => $shared,
			'filter' => FilterSet::from( (array) ( $segment['filter'] ?? array() ) )->to_array(),
		);
	}

	private function visible_set( WP_REST_Request $request ): ColumnSet|WP_Error {
		$set = $this->plugin->sets->find( (int) $request['set'] );
		if ( ! $set || ! $set->visible_to( wp_get_current_user() ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		return $set;
	}
}
