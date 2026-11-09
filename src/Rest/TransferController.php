<?php

namespace CorinthianPro\Rest;

use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use CorinthianPro\Sets\PhpExport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Move column sets between sites as JSON, or export one as PHP.
 */
final class TransferController {

	public const FORMAT  = 'corinthian-sets';
	public const VERSION = 1;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		$auth = static fn() => current_user_can( Plugin::capability() );
		register_rest_route(
			SetsController::NAMESPACE,
			'/transfer/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			SetsController::NAMESPACE,
			'/transfer/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			SetsController::NAMESPACE,
			'/transfer/php/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'php' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Body: { ids: [] } (empty = every database set).
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$ids  = array_map( 'absint', (array) $request->get_param( 'ids' ) );
		$sets = array();
		foreach ( $this->plugin->sets->all() as $set ) {
			if ( $ids && ! in_array( $set->id, $ids, true ) ) {
				continue;
			}
			$data = $set->to_array();
			unset( $data['id'], $data['position'] );
			$data['settings'] = self::without_user_ids( array_diff_key( (array) $data['settings'], array_flip( array( 'imported_from' ) ) ) );
			$sets[]           = $data;
		}
		return new WP_REST_Response(
			array(
				'format'  => self::FORMAT,
				'version' => self::VERSION,
				'sets'    => $sets,
			)
		);
	}

	/**
	 * Body: the export document. Sets for screens this site doesn't have are skipped.
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$doc = (array) $request->get_json_params();
		if ( self::FORMAT !== ( $doc['format'] ?? '' ) || ! is_array( $doc['sets'] ?? null ) ) {
			return new WP_Error( 'corinthian_bad_file', __( 'This is not a Corinthian export.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		$created    = array();
		$skipped    = array();
		$restricted = array();
		foreach ( array_slice( $doc['sets'], 0, 200 ) as $data ) {
			$data   = (array) $data;
			$screen = (string) ( $data['screen'] ?? '' );
			if ( ! $this->plugin->screens->get( $screen ) ) {
				$skipped[] = $screen;
				continue;
			}
			$data['settings'] = self::without_user_ids( (array) ( $data['settings'] ?? array() ) );
			unset( $data['settings']['readonly'], $data['settings']['code_key'] );
			$id               = $this->plugin->sets->save( $data )->id;
			$created[]        = $id;
			if ( ! empty( $data['settings']['users_dropped'] ) ) {
				$restricted[] = $id;
			}
		}
		return new WP_REST_Response(
			array(
				'created'    => $created,
				'skipped'    => $skipped,
				// Sets that were limited to specific users now show to administrators only.
				'restricted' => $restricted,
			)
		);
	}

	public function php( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$set = $this->plugin->sets->find( (int) $request['id'] );
		if ( ! $set ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'php' => PhpExport::to_php( $set ) ) );
	}

	/**
	 * User ids mean nothing on another site. A set limited to specific users must
	 * not become visible to everyone, so it falls back to administrators only.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function without_user_ids( array $settings ): array {
		$users = array_filter( (array) ( $settings['users'] ?? array() ) );
		unset( $settings['users'] );
		if ( $users && ! array_filter( (array) ( $settings['roles'] ?? array() ) ) ) {
			$settings['roles']         = array( 'administrator' );
			$settings['users_dropped'] = true;
		}
		return $settings;
	}
}
