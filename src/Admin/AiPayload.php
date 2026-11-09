<?php

namespace CorinthianPro\Admin;

use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;
use CorinthianPro\Ai\AiClient;

final class AiPayload {

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
		$can_use     = current_user_can( (string) apply_filters( 'corinthian_ai_capability', 'edit_others_posts' ) );
		$data['ai'] = array(
			'available' => $can_use && ( new AiClient() )->available(),
			'canUse'    => $can_use,
		);
		return $data;
	}
}
