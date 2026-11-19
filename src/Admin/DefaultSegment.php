<?php

namespace CorinthianPro\Admin;

use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use CorinthianPro\Filters\ViewState;
use CorinthianPro\Rest\SegmentsController;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a list screen on its default segment. Only a bare visit is
 * redirected; a link with filters, sort, search or a cleared segment
 * shows exactly what it asks for.
 */
final class DefaultSegment {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'current_screen', array( $this, 'maybe_redirect' ) );
	}

	public function maybe_redirect( \WP_Screen $wp_screen ): void {
		$url = $this->redirect_url( $wp_screen );
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	public function redirect_url( \WP_Screen $wp_screen ): ?string {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ! ViewState::is_bare_request() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared to a literal.
			return null;
		}
		$screen = $this->plugin->screens->for_screen_id( $wp_screen->id );
		$set    = $screen ? $screen->active_set() : null;
		if ( ! $screen || ! $set || ! SegmentsController::can_use( $this->plugin, $set ) || $this->media_grid( $screen ) ) {
			return null;
		}
		$id = SegmentsController::defaults( $set, get_current_user_id() )['effective'];
		foreach ( $id ? SegmentsController::for_user( $set, get_current_user_id() ) : array() as $segment ) {
			if ( $segment['id'] === $id ) {
				$args = array_map( 'rawurlencode', ViewState::segment_args( $screen, $segment ) );
				return add_query_arg( $args, add_query_arg( array_map( 'rawurlencode', $this->neutral_args() ), $screen->url() ) );
			}
		}
		return null;
	}

	/**
	 * @return array<string,string>
	 */
	private function neutral_args(): array {
		$out = array();
		foreach ( ViewState::NEUTRAL_ARGS as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
				$out[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
			}
		}
		return $out;
	}

	private function media_grid( Screen $screen ): bool {
		if ( 'attachment' !== $screen->subtype() ) {
			return false;
		}
		$mode = isset( $_GET['mode'] ) && is_string( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : (string) get_user_option( 'media_library_mode' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		return 'list' !== $mode;
	}
}
