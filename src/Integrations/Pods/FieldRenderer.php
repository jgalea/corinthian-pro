<?php

namespace CorinthianPro\Integrations\Pods;

defined( 'ABSPATH' ) || exit;

final class FieldRenderer {

	private const CAP = 5;

	/**
	 * Escaped HTML for a Pods value; relations and files arrive as ids.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function render( array $field, mixed $value ): string {
		$family = FieldKind::family( $field );

		if ( 'bool' === $family ) {
			return self::bool( $value );
		}
		if ( null === $value || '' === $value || false === $value || array() === $value ) {
			return self::dash();
		}

		switch ( $family ) {
			case 'text':
				return self::text_field( $field, self::text( $value ) );
			case 'number':
				return is_numeric( $value ) ? esc_html( number_format_i18n( (float) $value, (int) ( fmod( (float) $value, 1 ) ? 2 : 0 ) ) ) : esc_html( self::text( $value ) );
			case 'choice':
				$choices = FieldKind::choices( $field );
				$labels  = array();
				foreach ( FieldKind::as_list( $value ) as $item ) {
					$labels[] = $choices[ $item ] ?? $item;
				}
				return $labels ? esc_html( implode( ', ', $labels ) ) : self::dash();
			case 'date':
			case 'datetime':
			case 'time':
				return self::date( $family, self::text( $value ) );
			case 'media':
				return self::media( $value );
			case 'relation':
				return self::relation( $field, $value );
		}
		return self::other( $value );
	}

	private static function dash(): string {
		return '<span aria-hidden="true">&#8212;</span>';
	}

	private static function text( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $value ), static fn( string $v ) => '' !== $v ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function other( mixed $value ): string {
		$items = array();
		foreach ( is_array( $value ) && ! isset( $value['pod_item_id'] ) ? $value : array( $value ) as $item ) {
			if ( is_array( $item ) ) {
				$label = $item['post_title'] ?? ( $item['name'] ?? ( $item['display_name'] ?? '' ) );
				$item  = is_scalar( $label ) ? $label : '';
			}
			if ( is_scalar( $item ) && '' !== (string) $item ) {
				$items[] = (string) $item;
			}
		}
		return $items ? esc_html( mb_strimwidth( implode( ', ', $items ), 0, 300, '…' ) ) : self::dash();
	}

	private static function is_web_url( string $url ): bool {
		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function text_field( array $field, string $text ): string {
		switch ( FieldKind::type( $field ) ) {
			case 'email':
				return is_email( $text ) ? '<a href="' . esc_url( 'mailto:' . $text ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
			case 'website':
			case 'oembed':
				return self::is_web_url( $text ) ? '<a href="' . esc_url( $text ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>' : esc_html( $text );
			case 'paragraph':
			case 'wysiwyg':
			case 'code':
				return esc_html( wp_trim_words( wp_strip_all_tags( $text ), 30 ) );
			case 'password':
				return esc_html( '••••••' );
			case 'color':
				$hex = sanitize_hex_color( $text );
				if ( ! $hex ) {
					return esc_html( $text );
				}
				return '<span style="display:inline-block;width:1em;height:1em;vertical-align:-2px;margin-right:4px;border:1px solid #c3c4c7;background:' . esc_attr( $hex ) . '" aria-hidden="true"></span>' . esc_html( $hex );
		}
		return esc_html( mb_strimwidth( $text, 0, 300, '…' ) );
	}

	private static function bool( mixed $value ): string {
		$on = ! in_array( strtolower( self::text( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		return $on
			? '<span class="dashicons dashicons-yes" role="img" aria-label="' . esc_attr__( 'Yes', 'corinthian-pro' ) . '"></span>'
			: '<span class="dashicons dashicons-no-alt" role="img" aria-label="' . esc_attr__( 'No', 'corinthian-pro' ) . '"></span>';
	}

	private static function date( string $family, string $raw ): string {
		if ( in_array( $raw, FieldKind::EMPTY_VALUES, true ) ) {
			return self::dash();
		}
		$utc    = new \DateTimeZone( 'UTC' );
		$time   = \DateTimeImmutable::createFromFormat( '!' . ( 'date' === $family ? 'Y-m-d' : ( 'time' === $family ? 'H:i:s' : 'Y-m-d H:i:s' ) ), $raw, $utc );
		$date   = (string) get_option( 'date_format' );
		$clock  = (string) get_option( 'time_format' );
		$format = match ( $family ) {
			'date'  => $date,
			'time'  => $clock,
			default => $date . ' ' . $clock,
		};
		return $time ? esc_html( wp_date( $format, $time->getTimestamp(), $utc ) ) : esc_html( $raw );
	}

	private static function media( mixed $value ): string {
		$ids   = array_values( array_filter( array_map( 'intval', FieldKind::as_list( $value ) ) ) );
		$count = count( $ids );
		$out   = array();
		foreach ( array_slice( $ids, 0, self::CAP ) as $id ) {
			if ( wp_attachment_is_image( $id ) ) {
				$out[] = wp_get_attachment_image( $id, array( 60, 60 ) );
				continue;
			}
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$title = get_the_title( $id );
				$out[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : wp_basename( $url ) ) . '</a>';
			}
		}
		$out = array_filter( $out );
		if ( ! $out ) {
			return self::dash();
		}
		return implode( ' ', $out ) . ( $count > self::CAP ? ' ' . esc_html( '+' . ( $count - self::CAP ) ) : '' );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function relation( array $field, mixed $value ): string {
		$items = FieldKind::as_list( $value );
		$out   = array();
		foreach ( array_slice( $items, 0, self::CAP ) as $id ) {
			switch ( FieldKind::pick_object( $field ) ) {
				case 'user':
					$user = get_userdata( (int) $id );
					if ( $user ) {
						$out[] = esc_html( $user->display_name );
					}
					break;
				case 'taxonomy':
					$term = get_term( (int) $id, (string) ( $field['pick_val'] ?? '' ) );
					if ( $term instanceof \WP_Term ) {
						$out[] = esc_html( $term->name );
					}
					break;
				default:
					$post = get_post( (int) $id );
					if ( $post ) {
						$url   = get_edit_post_link( $post->ID );
						$url   = $url ? $url : get_permalink( $post );
						$title = get_the_title( $post );
						$out[] = '<a href="' . esc_url( (string) $url ) . '">' . esc_html( '' !== $title ? $title : '#' . $post->ID ) . '</a>';
					}
			}
		}
		if ( ! $out ) {
			return self::dash();
		}
		return implode( ', ', $out ) . ( count( $items ) > self::CAP ? ' ' . esc_html( '+' . ( count( $items ) - self::CAP ) ) : '' );
	}
}
