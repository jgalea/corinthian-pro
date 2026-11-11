<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The audio or enclosure URLs of an item (podcasts, video feeds), one meta row each.
 */
final class MediaUrlType extends WpraType implements Filterable {

	public function key(): string {
		return 'wpra.media';
	}

	public function label(): string {
		return __( 'Media file', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		if ( ! $this->readable( $object_id ) ) {
			return null;
		}
		$urls = array();
		foreach ( array( WpraApi::AUDIO_URL, WpraApi::ENCLOSURE_URL ) as $key ) {
			foreach ( get_post_meta( $object_id, $key, false ) as $url ) {
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					$urls[] = trim( $url );
				}
			}
		}
		$urls = array_values( array_unique( $urls ) );
		return $urls ? $urls : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$urls = $this->value( $object_id, $config, $screen );
		if ( ! is_array( $urls ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$out = array();
		foreach ( $urls as $url ) {
			$name  = basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
			$out[] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $url ), esc_html( '' !== $name ? $name : (string) $url ) );
		}
		return implode( '<br>', $out );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::empty_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		if ( 'empty' !== $op && 'not_empty' !== $op ) {
			return null;
		}
		global $wpdb;
		$has = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS {$alias} WHERE {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key IN (%s, %s) AND {$alias}.meta_value <> '')", WpraApi::AUDIO_URL, WpraApi::ENCLOSURE_URL ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return PostFilter::wrap( 'empty' === $op ? "NOT {$has}" : $has );
	}
}
