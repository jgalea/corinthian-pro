<?php

namespace CorinthianPro\Rest;

use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use Corinthian\Screens\Screen;
use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\Matcher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk trash and delete for posts, comments and terms.
 *
 * Two phases like bulk edit: a filter is resolved to ids first, then ids are
 * removed in batches. Every object is checked against its own delete
 * capability, and a batch only runs when the caller echoes back how many ids it
 * was shown (the confirm step).
 */
final class BulkDeleteController {

	public const BATCH   = 25;
	public const MAX_IDS = 100000;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		register_rest_route(
			SetsController::NAMESPACE,
			'/bulk-delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * Object types a list can bulk delete from the table.
	 */
	public static function supports( Screen $screen ): bool {
		if ( $screen->network_refused() ) {
			return false;
		}
		switch ( $screen->object_type() ) {
			case 'post':
				$type = get_post_type_object( $screen->subtype() );
				return $type && current_user_can( $type->cap->delete_posts );
			case 'comment':
				return current_user_can( 'moderate_comments' );
			case 'term':
				$taxonomy = get_taxonomy( $screen->subtype() );
				return $taxonomy && current_user_can( $taxonomy->cap->delete_terms );
			default:
				return false;
		}
	}

	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$screen = $this->plugin->screens->get( (string) $request->get_param( 'screen' ) );
		$set    = $this->plugin->sets->find( (int) $request->get_param( 'set' ) );
		if ( ! $screen || ! $set || $set->screen !== $screen->key() || ! $set->visible_to( wp_get_current_user() ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		if ( ! $screen->user_can_list() || ! self::supports( $screen ) ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You cannot delete these items.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}

		$permanent = 'delete' === sanitize_key( (string) $request->get_param( 'mode' ) );
		$ids       = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );

		if ( ! $ids ) {
			if ( null === $request->get_param( 'filter' ) ) {
				return new WP_Error( 'corinthian_invalid', __( 'Choose rows or apply a filter first.', 'corinthian-pro' ), array( 'status' => 400 ) );
			}
			$matched = array_values( array_filter( Matcher::ids( $screen, $set, FilterSet::from( (array) $request->get_param( 'filter' ) ), null, self::MAX_IDS ), fn( $id ) => $this->can_remove( $screen, $id, $permanent ) ) );
			return new WP_REST_Response(
				array(
					'ids'   => $matched,
					'total' => count( $matched ),
				)
			);
		}

		$ids = array_slice( $ids, 0, self::MAX_IDS );
		if ( (int) $request->get_param( 'confirm' ) !== count( $ids ) ) {
			return new WP_Error( 'corinthian_unconfirmed', __( 'Confirm how many rows will be removed first.', 'corinthian-pro' ), array( 'status' => 400 ) );
		}

		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$total  = count( $ids );
		$batch  = array_slice( $ids, $offset, self::BATCH );
		$done   = 0;
		$failed = array();
		foreach ( $batch as $id ) {
			$error = $this->remove( $screen, $id, $permanent );
			if ( null === $error ) {
				++$done;
			} else {
				$failed[] = array(
					'id'    => $id,
					'error' => $error,
				);
			}
		}

		$next = $offset + count( $batch );
		return new WP_REST_Response(
			array(
				'done'        => $done,
				'failed'      => $failed,
				'total'       => $total,
				'next_offset' => $next < $total && $batch ? $next : null,
			)
		);
	}

	private function can_remove( Screen $screen, int $id, bool $permanent ): bool {
		switch ( $screen->object_type() ) {
			case 'post':
				$post = get_post( $id );
				return $post && $post->post_type === $screen->subtype() && current_user_can( 'delete_post', $id ) && ( $permanent || 'trash' !== $post->post_status );
			case 'comment':
				return (bool) get_comment( $id ) && current_user_can( 'edit_comment', $id );
			case 'term':
				$term = get_term( $id );
				return $term instanceof \WP_Term && $term->taxonomy === $screen->subtype() && current_user_can( 'delete_term', $id );
			default:
				return false;
		}
	}

	/**
	 * @return string|null An error message, or null when the object was removed.
	 */
	private function remove( Screen $screen, int $id, bool $permanent ): ?string {
		if ( ! $this->can_remove( $screen, $id, $permanent ) ) {
			return __( 'Not allowed', 'corinthian-pro' );
		}
		switch ( $screen->object_type() ) {
			case 'post':
				$result = $permanent ? wp_delete_post( $id, true ) : wp_trash_post( $id );
				break;
			case 'comment':
				$result = $permanent ? wp_delete_comment( $id, true ) : wp_trash_comment( $id );
				break;
			default:
				$result = wp_delete_term( $id, $screen->subtype() );
				if ( is_wp_error( $result ) ) {
					return $result->get_error_message();
				}
		}
		return $result ? null : __( 'Could not be removed', 'corinthian-pro' );
	}
}
