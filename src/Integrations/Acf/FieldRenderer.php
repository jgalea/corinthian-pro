<?php

namespace CorinthianPro\Integrations\Acf;

final class FieldRenderer {

	private const CAP = 5;

	/**
	 * Escaped HTML for a raw (unformatted) ACF value.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function render( array $field, mixed $value ): string {
		$type = FieldKind::type( $field );

		if ( 'true_false' === $type ) {
			return self::bool( $value );
		}
		if ( in_array( $type, array( 'repeater', 'flexible_content' ), true ) ) {
			$rows = is_array( $value ) ? count( $value ) : (int) $value;
			/* translators: %d: number of rows */
			return $rows > 0 ? esc_html( sprintf( _n( '%d row', '%d rows', $rows, 'corinthian-pro' ), $rows ) ) : self::dash();
		}
		if ( 'group' === $type ) {
			$filled = is_array( $value ) ? count( array_filter( $value, static fn( $v ) => null !== $v && '' !== $v && array() !== $v ) ) : 0;
			/* translators: %d: number of fields with a value */
			return $filled > 0 ? esc_html( sprintf( _n( '%d field set', '%d fields set', $filled, 'corinthian-pro' ), $filled ) ) : self::dash();
		}
		if ( null === $value || '' === $value || array() === $value ) {
			return self::dash();
		}

		switch ( $type ) {
			case 'number':
			case 'range':
				return is_numeric( $value ) ? esc_html( number_format_i18n( (float) $value, (int) ( fmod( (float) $value, 1 ) ? 2 : 0 ) ) ) : esc_html( self::text( $value ) );
			case 'email':
				$mail = self::text( $value );
				return is_email( $mail ) ? '<a href="' . esc_url( 'mailto:' . $mail ) . '">' . esc_html( $mail ) . '</a>' : esc_html( $mail );
			case 'url':
			case 'oembed':
				return self::link( self::text( $value ), self::text( $value ) );
			case 'textarea':
			case 'wysiwyg':
				return esc_html( wp_trim_words( wp_strip_all_tags( self::text( $value ) ), 30 ) );
			case 'select':
			case 'radio':
			case 'button_group':
			case 'checkbox':
				return self::choices( $field, $value );
			case 'date_picker':
				return self::date( $field, $value, 'Ymd', 'd/m/Y' );
			case 'date_time_picker':
				return self::date( $field, $value, 'Y-m-d H:i:s', 'd/m/Y g:i a' );
			case 'time_picker':
				return self::date( $field, $value, 'H:i:s', 'g:i a' );
			case 'image':
				return self::image( $value );
			case 'file':
				return self::file( $value );
			case 'gallery':
				return self::gallery( $value );
			case 'link':
				return self::acf_link( $value );
			case 'post_object':
			case 'relationship':
			case 'page_link':
				return self::posts( $value );
			case 'taxonomy':
				return self::terms( $field, $value );
			case 'user':
				return self::users( $value );
			case 'color_picker':
				return self::color( self::text( $value ) );
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

	private static function link( string $url, string $label ): string {
		if ( ! self::is_web_url( $url ) ) {
			return esc_html( $label );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}

	private static function is_web_url( string $url ): bool {
		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true );
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
	private static function choices( array $field, mixed $value ): string {
		$choices = FieldKind::choices( $field );
		$labels  = array();
		foreach ( FieldKind::as_list( $value ) as $item ) {
			$labels[] = $choices[ $item ] ?? $item;
		}
		return $labels ? esc_html( implode( ', ', $labels ) ) : self::dash();
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function date( array $field, mixed $value, string $storage, string $fallback_format ): string {
		$raw = self::text( $value );
		$dt  = \DateTimeImmutable::createFromFormat( '!' . $storage, $raw, new \DateTimeZone( 'UTC' ) );
		if ( ! $dt ) {
			return esc_html( $raw );
		}
		$format = (string) ( $field['display_format'] ?? '' );
		return esc_html( $dt->format( '' !== $format ? $format : $fallback_format ) );
	}

	private static function thumb( int $id ): string {
		return wp_get_attachment_image( $id, array( 60, 60 ) );
	}

	private static function image( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['ID'] ?? ( $value['id'] ?? '' );
		}
		$raw = self::text( $value );
		if ( is_numeric( $raw ) ) {
			$img = self::thumb( (int) $raw );
			return '' !== $img ? $img : self::dash();
		}
		return self::is_web_url( $raw ) ? '<img src="' . esc_url( $raw ) . '" alt="" style="max-width:60px;max-height:60px">' : esc_html( $raw );
	}

	private static function file( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['ID'] ?? ( $value['id'] ?? '' );
		}
		$raw = self::text( $value );
		if ( ! is_numeric( $raw ) ) {
			return self::link( $raw, $raw );
		}
		$url = wp_get_attachment_url( (int) $raw );
		if ( ! $url ) {
			return self::dash();
		}
		$title = get_the_title( (int) $raw );
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : wp_basename( $url ) ) . '</a>';
	}

	private static function gallery( mixed $value ): string {
		$ids   = array_values( array_filter( array_map( 'intval', FieldKind::as_list( $value ) ) ) );
		$count = count( $ids );
		if ( 0 === $count ) {
			return self::dash();
		}
		/* translators: %d: number of images in the gallery */
		return self::thumb( $ids[0] ) . ' <span>' . esc_html( sprintf( _n( '%d image', '%d images', $count, 'corinthian-pro' ), $count ) ) . '</span>';
	}

	private static function acf_link( mixed $value ): string {
		if ( ! is_array( $value ) || empty( $value['url'] ) ) {
			return self::dash();
		}
		$url   = (string) $value['url'];
		$title = isset( $value['title'] ) && '' !== $value['title'] ? (string) $value['title'] : $url;
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
	}

	private static function posts( mixed $value ): string {
		$items = FieldKind::as_list( $value );
		$out   = array();
		foreach ( array_slice( $items, 0, self::CAP ) as $item ) {
			if ( ! is_numeric( $item ) ) {
				$out[] = '<a href="' . esc_url( $item ) . '">' . esc_html( $item ) . '</a>';
				continue;
			}
			$post = get_post( (int) $item );
			if ( ! $post ) {
				continue;
			}
			$url   = get_edit_post_link( $post->ID );
			$url   = $url ? $url : get_permalink( $post );
			$title = get_the_title( $post );
			$out[] = '<a href="' . esc_url( (string) $url ) . '">' . esc_html( '' !== $title ? $title : '#' . $post->ID ) . '</a>';
		}
		return self::joined( $out, count( $items ) );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function terms( array $field, mixed $value ): string {
		$taxonomy = (string) ( $field['taxonomy'] ?? '' );
		$names    = array();
		foreach ( FieldKind::as_list( $value ) as $id ) {
			$term = get_term( (int) $id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$names[] = esc_html( $term->name );
			}
		}
		return $names ? implode( ', ', $names ) : self::dash();
	}

	private static function users( mixed $value ): string {
		$items = FieldKind::as_list( $value );
		$names = array();
		foreach ( array_slice( $items, 0, self::CAP ) as $id ) {
			$user = get_userdata( (int) $id );
			if ( $user ) {
				$names[] = esc_html( $user->display_name );
			}
		}
		return self::joined( $names, count( $items ) );
	}

	/**
	 * @param list<string> $html Already escaped.
	 */
	private static function joined( array $html, int $total ): string {
		if ( ! $html ) {
			return self::dash();
		}
		$out = implode( ', ', $html );
		return $total > self::CAP ? $out . ' ' . esc_html( '+' . ( $total - self::CAP ) ) : $out;
	}

	private static function color( string $color ): string {
		$hex  = sanitize_hex_color( $color );
		$rgba = (bool) preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[01]?(\.\d+)?\s*)?\)$/', $color );
		if ( ! $hex && ! $rgba ) {
			return esc_html( $color );
		}
		return '<span style="display:inline-block;width:1em;height:1em;vertical-align:-2px;margin-right:4px;border:1px solid #c3c4c7;background:' . esc_attr( $color ) . '" aria-hidden="true"></span>' . esc_html( $color );
	}
}
