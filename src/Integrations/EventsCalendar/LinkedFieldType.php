<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * One stored detail of a venue or organizer, shown on that post type's own list.
 */
final class LinkedFieldType extends TecType {

	public function __construct(
		private string $key,
		private string $label,
		private string $post_type,
		private string $meta_key,
		private bool $email = false,
	) {}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function supports( Screen $screen ): bool {
		return 'post' === $screen->object_type() && $this->post_type === $screen->subtype();
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$value = $this->meta( $object_id, $this->meta_key );
		return '' === $value ? null : $value;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$value = $this->value( $object_id, $config, $screen );
		if ( $this->email && is_string( $value ) && is_email( $value ) ) {
			return '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
		}
		return parent::render( $object_id, $config, $screen );
	}
}
