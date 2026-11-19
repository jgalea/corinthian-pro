<?php

namespace CorinthianPro\Rest;

use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\ViewState;
use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use Corinthian\Sets\ColumnSet;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Saved filters ("segments") for a column set. Shared segments live on the set
 * and need the manage capability; personal ones live in the user's meta.
 *
 * A segment keeps the applied view: its rules plus sort, search and status.
 * A set can have a shared default segment, and each user a personal default
 * that wins over it.
 */
final class SegmentsController {

	public const USER_META_PREFIX    = 'corinthian_segments_';
	public const DEFAULT_META_PREFIX = 'corinthian_segments_default_';
	public const MAX_PERSONAL        = 50;

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
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
		register_rest_route(
			SetsController::NAMESPACE,
			'/sets/(?P<set>\d+)/default-segment',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_default' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'set_default' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_default' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	/**
	 * Segments this user can see on a set, shared first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function for_user( ColumnSet $set, int $user_id ): array {
		$out = array();
		foreach ( self::shared_list( $set ) as $segment ) {
			$out[] = self::shape( $segment, true );
		}
		foreach ( self::personal_list( $set, $user_id ) as $segment ) {
			$out[] = self::shape( $segment, false );
		}
		return $out;
	}

	/**
	 * The shared default, the user's own default, and the one that applies.
	 * An id that no longer points at a segment reads as null.
	 *
	 * @return array{shared:?string,personal:?string,effective:?string}
	 */
	public static function defaults( ColumnSet $set, int $user_id ): array {
		$shared_ids = array_column( self::shared_list( $set ), 'id' );
		$all_ids    = array_merge( $shared_ids, array_column( self::personal_list( $set, $user_id ), 'id' ) );
		$shared     = (string) ( $set->settings['default_segment'] ?? '' );
		$personal   = (string) get_user_meta( $user_id, self::DEFAULT_META_PREFIX . $set->id, true );
		$shared     = '' !== $shared && in_array( $shared, $shared_ids, true ) ? $shared : null;
		$personal   = '' !== $personal && in_array( $personal, $all_ids, true ) ? $personal : null;
		return array(
			'shared'    => $shared,
			'personal'  => $personal,
			'effective' => $personal ?? $shared,
		);
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
		$shared = (bool) $request->get_param( 'shared' );
		if ( $shared && ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You can only save personal segments.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		// Undo sends the deleted segment's id and position back, so it returns where it was.
		$id = strtolower( (string) $request->get_param( 'id' ) );
		if ( '' === $id ) {
			$id = strtolower( wp_generate_password( 10, false ) );
		} elseif ( ! preg_match( '/^[a-z0-9]{1,32}$/', $id ) ) {
			return new WP_Error( 'corinthian_invalid', __( 'That segment id is not valid.', 'corinthian-pro' ), array( 'status' => 400 ) );
		} elseif ( $this->locate( $set, $id ) ) {
			return new WP_Error( 'corinthian_exists', __( 'A segment with that id already exists.', 'corinthian-pro' ), array( 'status' => 409 ) );
		}
		$segment = self::build( array( 'id' => $id ), $request );
		if ( is_wp_error( $segment ) ) {
			return $segment;
		}

		$position = $request->has_param( 'position' ) ? max( 0, (int) $request->get_param( 'position' ) ) : null;
		$list     = $shared ? self::shared_list( $set ) : self::personal_list( $set, get_current_user_id() );
		array_splice( $list, $position ?? count( $list ), 0, array( $segment ) );
		$error = $this->save_list( $set, $shared, $shared ? $list : array_slice( $list, -self::MAX_PERSONAL ) );
		if ( $error ) {
			return $error;
		}
		return new WP_REST_Response( self::shape( $segment, $shared ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$found = $this->editable( $set, (string) $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $shared, $index, $list ] = $found;

		$segment = self::build( $list[ $index ], $request );
		if ( is_wp_error( $segment ) ) {
			return $segment;
		}
		$list[ $index ] = $segment;
		$error          = $this->save_list( $set, $shared, $list );
		if ( $error ) {
			return $error;
		}
		return new WP_REST_Response( self::shape( $segment, $shared ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$found = $this->editable( $set, (string) $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $shared, $index, $list ] = $found;

		$segment = $list[ $index ];
		array_splice( $list, $index, 1 );
		$error = $this->save_list( $set, $shared, $list );
		if ( $error ) {
			return $error;
		}
		// Default pointers are left alone, so restoring the segment under its id brings its defaults back.
		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'segment'  => self::shape( $segment, $shared ),
				'position' => $index,
			)
		);
	}

	public function get_default( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		return is_wp_error( $set ) ? $set : new WP_REST_Response( self::defaults( $set, get_current_user_id() ) );
	}

	public function set_default( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$id    = (string) $request->get_param( 'id' );
		$found = '' !== $id ? $this->locate( $set, $id ) : null;
		if ( ! $found || ( 'shared' === $scope && ! $found[0] ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Segment not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		$error = $this->write_default( $set, $scope, $id );
		if ( $error ) {
			return $error;
		}
		return new WP_REST_Response( self::defaults( $this->fresh( $set ), get_current_user_id() ) );
	}

	public function clear_default( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->visible_set( $request );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$error = $this->write_default( $set, $scope, '' );
		if ( $error ) {
			return $error;
		}
		return new WP_REST_Response( self::defaults( $this->fresh( $set ), get_current_user_id() ) );
	}

	private function scope( WP_REST_Request $request ): string|WP_Error {
		$scope = (string) ( $request->get_param( 'scope' ) ?? 'personal' );
		if ( ! in_array( $scope, array( 'personal', 'shared' ), true ) ) {
			return new WP_Error( 'corinthian_invalid', __( 'The scope must be personal or shared.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		if ( 'shared' === $scope && ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'Only administrators can set the default for everyone.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		return $scope;
	}

	private function write_default( ColumnSet $set, string $scope, string $id ): WP_Error|null {
		if ( 'personal' === $scope ) {
			$key = self::DEFAULT_META_PREFIX . $set->id;
			if ( '' === $id ) {
				delete_user_meta( get_current_user_id(), $key );
			} else {
				update_user_meta( get_current_user_id(), $key, $id );
			}
			return null;
		}
		$data                                = $set->to_array();
		$data['settings']                    = (array) $data['settings'];
		$data['settings']['default_segment'] = $id;
		return $this->save_shared( $data, $set );
	}

	/**
	 * A segment from stored data plus the fields the request sends.
	 *
	 * @param array<string,mixed> $segment
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build( array $segment, WP_REST_Request $request ): array|WP_Error {
		if ( $request->has_param( 'name' ) ) {
			$segment['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( $request->has_param( 'filter' ) ) {
			$segment['filter'] = FilterSet::from( (array) $request->get_param( 'filter' ) )->to_array();
		}
		$sent = array();
		foreach ( ViewState::FIELDS as $field ) {
			if ( $request->has_param( $field ) ) {
				$sent[ $field ] = $request->get_param( $field ) ?? '';
			}
		}
		$segment = array_merge( $segment, array_intersect_key( ViewState::clean( $sent ), $sent ) );

		$filter = FilterSet::from( (array) ( $segment['filter'] ?? array() ) );
		// An order without a column to sort by changes nothing, so it doesn't count.
		$view = array_filter( array_diff_key( ViewState::clean( $segment ), array( 'order' => true ) ), static fn( $v ) => null !== $v && '' !== $v );
		if ( '' === trim( (string) ( $segment['name'] ?? '' ) ) ) {
			return new WP_Error( 'corinthian_invalid', __( 'A segment needs a name.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		if ( $filter->is_empty() && ! $view ) {
			return new WP_Error( 'corinthian_invalid', __( 'A segment needs at least one rule, a search, a status or a sort.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		return $segment;
	}

	/**
	 * Where a segment this user can see is stored: [shared, index, list].
	 *
	 * @return array{0:bool,1:int,2:list<array<string,mixed>>}|null
	 */
	private function locate( ColumnSet $set, string $id ): ?array {
		$mine  = self::personal_list( $set, get_current_user_id() );
		$index = array_search( $id, array_column( $mine, 'id' ), true );
		if ( false !== $index ) {
			return array( false, (int) $index, $mine );
		}
		$shared = self::shared_list( $set );
		$index  = array_search( $id, array_column( $shared, 'id' ), true );
		return false === $index ? null : array( true, (int) $index, $shared );
	}

	/**
	 * @return array{0:bool,1:int,2:list<array<string,mixed>>}|WP_Error
	 */
	private function editable( ColumnSet $set, string $id ): array|WP_Error {
		$found = $this->locate( $set, $id );
		if ( ! $found ) {
			return new WP_Error( 'corinthian_not_found', __( 'Segment not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		if ( $found[0] && ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'Only administrators can change shared segments.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		return $found;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private static function shared_list( ColumnSet $set ): array {
		return array_values( array_filter( (array) ( $set->settings['segments'] ?? array() ), 'is_array' ) );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private static function personal_list( ColumnSet $set, int $user_id ): array {
		$mine = get_user_meta( $user_id, self::USER_META_PREFIX . $set->id, true );
		return is_array( $mine ) ? array_values( array_filter( $mine, 'is_array' ) ) : array();
	}

	/**
	 * @param list<array<string,mixed>> $segments
	 */
	private function save_list( ColumnSet $set, bool $shared, array $segments ): WP_Error|null {
		if ( ! $shared ) {
			update_user_meta( get_current_user_id(), self::USER_META_PREFIX . $set->id, array_values( $segments ) );
			return null;
		}
		$data                         = $set->to_array();
		$data['settings']             = (array) $data['settings'];
		$data['settings']['segments'] = array_values( $segments );
		return $this->save_shared( $data, $set );
	}

	private function fresh( ColumnSet $set ): ColumnSet {
		return $this->plugin->sets->find( $set->id ) ?? $set;
	}

	/**
	 * @param array<string,mixed> $segment
	 * @return array<string,mixed>
	 */
	private static function shape( array $segment, bool $shared ): array {
		return array(
			'id'     => (string) ( $segment['id'] ?? '' ),
			'name'   => (string) ( $segment['name'] ?? '' ),
			'shared' => $shared,
			'filter' => FilterSet::from( (array) ( $segment['filter'] ?? array() ) )->to_array(),
		) + ViewState::clean( $segment );
	}

	private function visible_set( WP_REST_Request $request ): ColumnSet|WP_Error {
		$set = $this->plugin->sets->find( (int) $request['set'] );
		if ( ! $set || ! self::can_use( $this->plugin, $set ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		return $set;
	}

	/**
	 * A user may use a set's segments only if the set is shown to them and
	 * they can open the list screen it belongs to.
	 */
	public static function can_use( Plugin $plugin, ColumnSet $set ): bool {
		$screen = $plugin->screens->get( $set->screen );
		return $set->visible_to( wp_get_current_user() ) && $screen && $screen->user_can_list();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function save_shared( array $data, ColumnSet $set ): WP_Error|null {
		try {
			$this->plugin->sets->save( $data, $set->id );
		} catch ( \DomainException ) {
			return new WP_Error( 'corinthian_readonly', __( 'This column set is defined in code, so shared segments cannot be saved on it. Save a personal segment instead.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		return null;
	}
}
