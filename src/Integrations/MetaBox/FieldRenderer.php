<?php

namespace CorinthianPro\Integrations\MetaBox;

defined( 'ABSPATH' ) || exit;

final class FieldRenderer {

	private const CAP = 5;

	/**
	 * Escaped HTML for a Meta Box value as rwmb_meta() returns it.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function render( array $field, mixed $value ): string {
		$family = FieldKind::family( $field );

		if ( 'bool' === $family ) {
			return self::bool( $value );
		}
		if ( null === $value || '' === $value || array() === $value ) {
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
				return self::date( $field, $family, self::text( $value ) );
			case 'media':
				return self::media( $field, $value );
			case 'relation':
				return self::relation( $field, $value );
			case 'term_rel':
				return self::terms( $field, FieldKind::as_list( $value ) );
		}
		return esc_html( mb_strimwidth( self::text( $value ), 0, 300, '…' ) );
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

	private static function is_web_url( string $url ): bool {
		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true );
	}

	private static function link( string $url, string $label ): string {
		if ( ! self::is_web_url( $url ) ) {
			return esc_html( $label );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function text_field( array $field, string $text ): string {
		switch ( FieldKind::type( $field ) ) {
			case 'email':
				return is_email( $text ) ? '<a href="' . esc_url( 'mailto:' . $text ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
			case 'url':
			case 'oembed':
			case 'file_input':
				return self::link( $text, $text );
			case 'textarea':
			case 'wysiwyg':
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

	/**
	 * @param array<string,mixed> $field
	 */
	private static function date( array $field, string $family, string $raw ): string {
		$spec = FieldKind::date_spec( $field );
		$utc  = new \DateTimeZone( 'UTC' );
		$time = $spec['timestamp'] && is_numeric( $raw )
			? new \DateTimeImmutable( '@' . (int) $raw )
			: \DateTimeImmutable::createFromFormat( '!' . $spec['store'], $raw, $utc );
		if ( ! $time ) {
			return esc_html( $raw );
		}
		$date   = (string) get_option( 'date_format' );
		$clock  = (string) get_option( 'time_format' );
		$format = match ( $family ) {
			'date'  => $date,
			'time'  => $clock,
			default => $date . ' ' . $clock,
		};
		return esc_html( wp_date( $format, $time->getTimestamp(), $utc ) );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function media( array $field, mixed $value ): string {
		$ids   = array_values( array_filter( array_map( 'intval', FieldKind::as_list( $value ) ) ) );
		$count = count( $ids );
		if ( 0 === $count ) {
			return self::dash();
		}
		$out = array();
		foreach ( array_slice( $ids, 0, self::CAP ) as $id ) {
			if ( FieldKind::is_image( $field ) ) {
				$img = wp_get_attachment_image( $id, array( 60, 60 ) );
				if ( '' !== $img ) {
					$out[] = $img;
				}
				continue;
			}
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$title = get_the_title( $id );
				$out[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : wp_basename( $url ) ) . '</a>';
			}
		}
		if ( ! $out ) {
			return self::dash();
		}
		$sep = FieldKind::is_image( $field ) ? ' ' : ', ';
		return implode( $sep, $out ) . ( $count > self::CAP ? ' ' . esc_html( '+' . ( $count - self::CAP ) ) : '' );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function relation( array $field, mixed $value ): string {
		$items = FieldKind::ids( $value );
		$out   = array();
		switch ( FieldKind::type( $field ) ) {
			case 'taxonomy_advanced':
				return self::terms( $field, $items );
			case 'user':
				foreach ( array_slice( $items, 0, self::CAP ) as $id ) {
					$user = get_userdata( (int) $id );
					if ( $user ) {
						$out[] = esc_html( $user->display_name );
					}
				}
				break;
			default:
				foreach ( array_slice( $items, 0, self::CAP ) as $id ) {
					$post = get_post( (int) $id );
					if ( ! $post ) {
						continue;
					}
					$url   = get_edit_post_link( $post->ID );
					$url   = $url ? $url : get_permalink( $post );
					$title = get_the_title( $post );
					$out[] = '<a href="' . esc_url( (string) $url ) . '">' . esc_html( '' !== $title ? $title : '#' . $post->ID ) . '</a>';
				}
		}
		if ( ! $out ) {
			return self::dash();
		}
		return implode( ', ', $out ) . ( count( $items ) > self::CAP ? ' ' . esc_html( '+' . ( count( $items ) - self::CAP ) ) : '' );
	}

	/**
	 * @param array<string,mixed> $field
	 * @param list<string>        $ids
	 */
	private static function terms( array $field, array $ids ): string {
		$taxonomies = array_values( array_filter( (array) ( $field['taxonomy'] ?? array() ) ) );
		$names      = array();
		foreach ( $ids as $id ) {
			foreach ( $taxonomies ? $taxonomies : array( '' ) as $taxonomy ) {
				$term = get_term( (int) $id, (string) $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$names[] = esc_html( $term->name );
					break;
				}
			}
		}
		return $names ? implode( ', ', $names ) : self::dash();
	}
}
