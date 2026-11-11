<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\AbstractType;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

abstract class WpraType extends AbstractType {

	public function group(): string {
		return __( 'WP RSS Aggregator', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && WpraApi::handles_post_type( $screen->subtype() );
	}

	protected function readable( int $item_id ): bool {
		return current_user_can( 'read_post', $item_id );
	}

	protected function meta( int $item_id, string $key ): string {
		$value = get_post_meta( $item_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
