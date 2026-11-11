<?php

namespace CorinthianPro\Rest;

use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\Matcher;
use Corinthian\Plugin;
use Corinthian\Rest\SetsController;
use Corinthian\Sets\ColumnSet;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Conditional formatting: which rows on the current page match each rule.
 *
 * Rules live in the set's settings as
 * [ { id, column, op, value, tone, bold, scope: "cell"|"row" } ].
 * Matching reuses the smart-filter SQL, limited to the ids on the page.
 */
final class FormattingController {

	public const TONES     = array( 'red', 'amber', 'green', 'blue', 'purple', 'grey' );
	public const MAX_RULES = 20;
	public const MAX_IDS   = 500;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		register_rest_route(
			SetsController::NAMESPACE,
			'/format',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'match' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	public function match( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$screen = $this->plugin->screens->get( (string) $request->get_param( 'screen' ) );
		$set    = $this->plugin->sets->find( (int) $request->get_param( 'set' ) );
		if ( ! $screen || ! $set || $set->screen !== $screen->key() || ! $set->visible_to( wp_get_current_user() ) ) {
			return new WP_Error( 'corinthian_not_found', __( 'Column set not found.', 'corinthian-pro' ), array( 'status' => 404 ) );
		}
		if ( ! $screen->user_can_list() ) {
			return new WP_Error( 'corinthian_forbidden', __( 'You cannot view these items.', 'corinthian-pro' ), array( 'status' => 403 ) );
		}
		$ids = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, self::MAX_IDS );
		// Matching reveals data about each id, so only consider ones this user may read.
		$ids = array_values( array_filter( $ids, array( $screen, 'user_can_read' ) ) );

		$out = array();
		foreach ( self::rules( $set ) as $rule ) {
			$filter = FilterSet::from(
				array(
					'rules' => array(
						array(
							'column' => $rule['column'],
							'op'     => $rule['op'],
							'value'  => $rule['value'],
						),
					),
				)
			);
			$out[]  = array(
				'rule' => $rule,
				'ids'  => Matcher::ids( $screen, $set, $filter, $ids, self::MAX_IDS ),
			);
		}
		return new WP_REST_Response( array( 'matches' => $out ) );
	}

	/**
	 * Clean formatting rules from a set.
	 *
	 * @return list<array{id:string,column:string,op:string,value:mixed,tone:string,bold:bool,scope:string}>
	 */
	public static function rules( ColumnSet $set ): array {
		$rules = array();
		foreach ( array_slice( (array) ( $set->settings['formatting'] ?? array() ), 0, self::MAX_RULES ) as $i => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['column'] ) || empty( $rule['op'] ) ) {
				continue;
			}
			$tone    = in_array( $rule['tone'] ?? '', self::TONES, true ) ? $rule['tone'] : 'amber';
			$rules[] = array(
				'id'     => sanitize_key( (string) ( $rule['id'] ?? 'r' . $i ) ),
				'column' => sanitize_key( (string) $rule['column'] ),
				'op'     => sanitize_key( (string) $rule['op'] ),
				'value'  => $rule['value'] ?? null,
				'tone'   => $tone,
				'bold'   => ! empty( $rule['bold'] ),
				'scope'  => 'row' === ( $rule['scope'] ?? 'cell' ) ? 'row' : 'cell',
			);
		}
		return $rules;
	}
}
