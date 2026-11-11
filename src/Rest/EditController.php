<?php

namespace CorinthianPro\Rest;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use CorinthianPro\Filters\FilterSet;
use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Inline and bulk edits from the list table.
 *
 * Every write checks the user can see the set and can edit that specific
 * object. Bulk edits run in client-driven batches so no request runs long.
 */
final class EditController {

	public const BATCH   = 50;
	public const MAX_IDS = 100000;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		register_rest_route(
			SetsController::NAMESPACE,
			'/edit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inline' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			SetsController::NAMESPACE,
			'/bulk-edit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * Body: { screen, set, column, id, value }. Returns the re-rendered cell.
	 */
	public function inline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ctx = $this->context( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		[ $screen, , $config, $type ] = $ctx;
		$id                           = (int) $request->get_param( 'id' );

		if ( ! self::can_edit( $screen, $id ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You cannot edit this item.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		$result = $type->save( $id, $config, $screen, $request->get_param( 'value' ), 'set' );
		if ( is_wp_error( $result ) ) {
			$denied = in_array( $result->get_error_code(), array( 'corinthian_forbidden', 'corinthian_protected' ), true );
			$data   = (array) $result->get_error_data();
			if ( empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => $denied ? 403 : 400 ) );
			}
			return $result;
		}
		self::clean_cache( $screen, $id );
		return new WP_REST_Response(
			array(
				'id'    => $id,
				'value' => $type->edit_value( $id, $config, $screen ),
				'html'  => $type->render( $id, $config, $screen ),
			)
		);
	}

	/**
	 * Two phases, so editing rows can't change which rows a filter selects mid-run:
	 * 1. { screen, set, column, filter } returns { ids, total } and edits nothing.
	 * 2. { screen, set, column, value, mode, ids, offset } edits one batch of ids
	 *    and returns next_offset until it is null.
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ctx = $this->context( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		[ $screen, $set, $config, $type ] = $ctx;
		$field                            = $type->edit_field( $config, $screen );
		$mode                             = sanitize_key( (string) $request->get_param( 'mode' ) );
		$mode                             = '' !== $mode ? $mode : 'set';
		if ( ! in_array( $mode, (array) ( $field['bulk'] ?? array() ), true ) ) {
			return new WP_Error( 'corinthian_bad_mode', __( 'This column does not support that bulk edit.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids && null !== $request->get_param( 'filter' ) ) {
			// Only ids this user may edit: the resolve phase must not reveal anything else.
			$matched = array_values( array_filter( \CorinthianPro\Filters\Matcher::ids( $screen, $set, FilterSet::from( (array) $request->get_param( 'filter' ) ), null, self::MAX_IDS ), static fn( $id ) => self::can_edit( $screen, $id ) ) );
			return new WP_REST_Response(
				array(
					'ids'   => $matched,
					'total' => count( $matched ),
				)
			);
		}
		$ids    = array_slice( $ids, 0, self::MAX_IDS );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$total  = count( $ids );
		$ids    = array_slice( $ids, $offset, self::BATCH );

		$done   = 0;
		$failed = array();
		foreach ( $ids as $id ) {
			if ( ! self::can_edit( $screen, $id ) ) {
				$failed[] = array(
					'id'    => $id,
					'error' => __( 'Not allowed', 'corinthian-pro' ),
				);
				continue;
			}
			$result = $type->save( $id, $config, $screen, $request->get_param( 'value' ), $mode );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'    => $id,
					'error' => $result->get_error_message(),
				);
				continue;
			}
			self::clean_cache( $screen, $id );
			++$done;
		}

		$next = $offset + count( $ids );
		return new WP_REST_Response(
			array(
				'done'        => $done,
				'failed'      => $failed,
				'total'       => $total,
				'next_offset' => $next < $total && $ids ? $next : null,
			)
		);
	}

	/**
	 * @return array{0:Screen,1:ColumnSet,2:Config,3:Editable&\Corinthian\Columns\ColumnType}|WP_Error
	 */
	private function context( WP_REST_Request $request ): array|WP_Error {
		$screen = $this->plugin->screens->get( (string) $request->get_param( 'screen' ) );
		$set    = $this->plugin->sets->find( (int) $request->get_param( 'set' ) );
		if ( ! $screen || ! $set || $set->screen !== $screen->key() || ! $set->visible_to( wp_get_current_user() ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		if ( ! $screen->user_can_list() ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You cannot manage these items.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		$config = $set->column( sanitize_key( (string) $request->get_param( 'column' ) ) );
		$type   = $config ? $this->plugin->registry->get( $config->type ) : null;
		if ( ! $config || ! $type instanceof Editable || false === $config->setting( 'editable', true ) ) {
			return new WP_Error( 'corinthian_not_editable', __( 'This column cannot be edited.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}
		return array( $screen, $set, $config, $type );
	}

	public static function can_edit( Screen $screen, int $id ): bool {
		if ( $id <= 0 || $screen->network_refused() ) {
			return false;
		}
		return match ( $screen->object_type() ) {
			'post'    => get_post( $id ) && ( '' === $screen->subtype() || get_post_type( $id ) === $screen->subtype() ) && current_user_can( 'edit_post', $id ),
			'user'    => (bool) get_userdata( $id ) && current_user_can( 'edit_user', $id ),
			'comment' => (bool) get_comment( $id ) && current_user_can( 'edit_comment', $id ),
			'term'    => self::can_edit_term( $screen, $id ),
			'site'    => get_site( $id ) instanceof \WP_Site && current_user_can( 'manage_sites' ),
			default   => $screen->can_edit_object( $id ),
		};
	}

	private static function can_edit_term( Screen $screen, int $id ): bool {
		$term = get_term( $id );
		return $term instanceof \WP_Term && $term->taxonomy === $screen->subtype() && current_user_can( 'edit_term', $id );
	}

	private static function clean_cache( Screen $screen, int $id ): void {
		match ( $screen->object_type() ) {
			'post'    => clean_post_cache( $id ),
			'user'    => clean_user_cache( $id ),
			'comment' => clean_comment_cache( $id ),
			'term'    => clean_term_cache( $id, $screen->subtype() ),
			'site'    => clean_blog_cache( $id ),
			default   => $screen->clean_object_cache( $id ),
		};
	}
}
