<?php

namespace CorinthianPro\Integrations\Acf;

/**
 * Editor shape, current value and validation for the editable ACF kinds.
 */
final class FieldEditor {

	public const OPTION_CAP = 200;

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function shape( array $field ): array {
		$multi = FieldKind::multiple( $field );
		$bulk  = $multi ? array( 'set', 'clear', 'append', 'remove' ) : array( 'set', 'clear' );

		switch ( FieldKind::type( $field ) ) {
			case 'textarea':
				$input = 'textarea';
				break;
			case 'number':
			case 'range':
				$input = 'number';
				break;
			case 'true_false':
				$input = 'toggle';
				break;
			case 'date_picker':
				$input = 'date';
				break;
			case 'date_time_picker':
				$input = 'datetime';
				break;
			case 'image':
			case 'file':
				$input = 'media';
				break;
			case 'select':
			case 'radio':
			case 'button_group':
			case 'checkbox':
			case 'post_object':
			case 'relationship':
			case 'page_link':
			case 'taxonomy':
			case 'user':
				$input = $multi ? 'multiselect' : 'select';
				break;
			default:
				$input = 'text';
		}

		$out = array(
			'input' => $input,
			'bulk'  => $bulk,
		);
		if ( in_array( $input, array( 'select', 'multiselect' ), true ) ) {
			$out['options'] = self::options( $field );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	public static function options( array $field ): array {
		$options = array();
		switch ( FieldKind::type( $field ) ) {
			case 'post_object':
			case 'relationship':
			case 'page_link':
				$types = array_values( array_filter( (array) ( $field['post_type'] ?? array() ) ) );
				$posts = get_posts(
					array(
						'post_type'        => $types ? $types : 'any',
						'post_status'      => ! empty( $field['post_status'] ) ? (array) $field['post_status'] : 'publish',
						'numberposts'      => self::OPTION_CAP,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
						'no_found_rows'    => true,
					)
				);
				foreach ( $posts as $post ) {
					$title                         = get_the_title( $post );
					$options[ (string) $post->ID ] = '' !== $title ? $title : '#' . $post->ID;
				}
				break;
			case 'taxonomy':
				$terms = get_terms(
					array(
						'taxonomy'   => (string) ( $field['taxonomy'] ?? '' ),
						'hide_empty' => false,
						'number'     => self::OPTION_CAP,
						'orderby'    => 'name',
					)
				);
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$options[ (string) $term->term_id ] = $term->name;
				}
				break;
			case 'user':
				$users = get_users(
					array(
						'number'   => self::OPTION_CAP,
						'role__in' => array_values( array_filter( (array) ( $field['role'] ?? array() ) ) ),
						'orderby'  => 'display_name',
						'fields'   => array( 'ID', 'display_name' ),
					)
				);
				foreach ( $users as $user ) {
					$options[ (string) $user->ID ] = $user->display_name;
				}
				break;
			default:
				$options = FieldKind::choices( $field );
		}
		return $options;
	}

	/**
	 * Current value in the shape the editor expects.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function current( array $field, mixed $raw ): mixed {
		if ( FieldKind::multiple( $field ) ) {
			return FieldKind::as_list( $raw );
		}
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$raw = (string) $raw;
		switch ( FieldKind::type( $field ) ) {
			case 'date_picker':
				$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $raw );
				return $dt ? $dt->format( 'Y-m-d' ) : '';
			case 'date_time_picker':
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw );
				return $dt ? $dt->format( 'Y-m-d\TH:i' ) : '';
			case 'true_false':
				return '1' === $raw ? '1' : '0';
		}
		return $raw;
	}

	/**
	 * Validate and normalize a submitted value to what update_field() stores.
	 * An empty result means "no value".
	 *
	 * @param array<string,mixed> $field
	 * @return array<mixed>|int|string|\WP_Error
	 */
	public static function normalize( array $field, mixed $value ): array|int|string|\WP_Error {
		if ( FieldKind::multiple( $field ) ) {
			return self::normalize_many( $field, $value );
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			return self::invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$value = null === $value ? '' : trim( (string) $value );
		$type  = FieldKind::type( $field );

		if ( 'true_false' === $type ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
		}
		if ( '' === $value ) {
			return empty( $field['required'] ) ? '' : self::invalid( __( 'This field is required.', 'corinthian-pro' ) );
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
				$clean = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
				if ( ! empty( $field['maxlength'] ) && mb_strlen( $clean ) > (int) $field['maxlength'] ) {
					/* translators: %d: maximum number of characters */
					return self::invalid( sprintf( __( 'Use at most %d characters.', 'corinthian-pro' ), (int) $field['maxlength'] ) );
				}
				return $clean;
			case 'email':
				return is_email( $value ) ? sanitize_email( $value ) : self::invalid( __( 'Enter a valid email address.', 'corinthian-pro' ) );
			case 'url':
				$check = str_starts_with( $value, '//' ) ? 'https:' . $value : $value;
				return false !== filter_var( $check, FILTER_VALIDATE_URL ) ? esc_url_raw( $value ) : self::invalid( __( 'Enter a valid URL.', 'corinthian-pro' ) );
			case 'number':
			case 'range':
				return self::number( $field, $value );
			case 'date_picker':
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				return $dt && $dt->format( 'Y-m-d' ) === $value ? $dt->format( 'Ymd' ) : self::invalid( __( 'Enter a date as YYYY-MM-DD.', 'corinthian-pro' ) );
			case 'date_time_picker':
				foreach ( array( 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s' ) as $format ) {
					$dt = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
					if ( $dt && $dt->format( $format ) === $value ) {
						return $dt->format( 'Y-m-d H:i:s' );
					}
				}
				return self::invalid( __( 'Enter a date and time.', 'corinthian-pro' ) );
			case 'time_picker':
				if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value ) ) {
					return self::invalid( __( 'Enter a time as HH:MM.', 'corinthian-pro' ) );
				}
				return 5 === strlen( $value ) ? $value . ':00' : $value;
			case 'color_picker':
				$hex = sanitize_hex_color( $value );
				return $hex ? $hex : self::invalid( __( 'Enter a colour as #RRGGBB.', 'corinthian-pro' ) );
			case 'select':
			case 'radio':
			case 'button_group':
				if ( array_key_exists( $value, FieldKind::choices( $field ) ) ) {
					return $value;
				}
				return FieldKind::allows_custom( $field ) ? sanitize_text_field( $value ) : self::invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
			case 'image':
			case 'file':
				$id = ctype_digit( $value ) ? (int) $value : 0;
				if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
					return self::invalid( __( 'Choose a file from the media library.', 'corinthian-pro' ) );
				}
				if ( 'image' === $type && ! wp_attachment_is_image( $id ) ) {
					return self::invalid( __( 'Choose an image.', 'corinthian-pro' ) );
				}
				return $id;
			case 'post_object':
			case 'page_link':
			case 'taxonomy':
			case 'user':
				$ok = self::relation_ok( $field, $value );
				return true === $ok ? (int) $value : $ok;
		}
		return self::invalid( __( 'This field cannot be edited here.', 'corinthian-pro' ) );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return list<string>|\WP_Error
	 */
	private static function normalize_many( array $field, mixed $value ): array|\WP_Error {
		if ( null === $value || '' === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				return self::invalid( __( 'Invalid value.', 'corinthian-pro' ) );
			}
			if ( '' !== (string) $item ) {
				$items[] = (string) $item;
			}
		}
		$items = array_values( array_unique( $items ) );

		$choice = in_array( FieldKind::type( $field ), array( 'checkbox', 'select' ), true );
		foreach ( $items as $i => $item ) {
			if ( $choice ) {
				if ( ! array_key_exists( $item, FieldKind::choices( $field ) ) ) {
					if ( ! FieldKind::allows_custom( $field ) ) {
						return self::invalid( __( 'Choose only from the available options.', 'corinthian-pro' ) );
					}
					$items[ $i ] = sanitize_text_field( $item );
				}
				continue;
			}
			$ok = self::relation_ok( $field, $item );
			if ( true !== $ok ) {
				return $ok;
			}
		}

		if ( ! $items && ! empty( $field['required'] ) ) {
			return self::invalid( __( 'This field is required.', 'corinthian-pro' ) );
		}
		$min = isset( $field['min'] ) && '' !== $field['min'] ? (int) $field['min'] : 0;
		$max = isset( $field['max'] ) && '' !== $field['max'] ? (int) $field['max'] : 0;
		if ( $items && $min > 0 && count( $items ) < $min ) {
			/* translators: %d: minimum number of selections */
			return self::invalid( sprintf( __( 'Select at least %d.', 'corinthian-pro' ), $min ) );
		}
		if ( $max > 0 && count( $items ) > $max ) {
			/* translators: %d: maximum number of selections */
			return self::invalid( sprintf( __( 'Select at most %d.', 'corinthian-pro' ), $max ) );
		}
		return $items;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return true|\WP_Error
	 */
	private static function relation_ok( array $field, string $value ): bool|\WP_Error {
		$id = ctype_digit( $value ) ? (int) $value : 0;
		if ( $id <= 0 ) {
			return self::invalid( __( 'Choose an existing item.', 'corinthian-pro' ) );
		}
		switch ( FieldKind::type( $field ) ) {
			case 'taxonomy':
				$ok = (bool) term_exists( $id, (string) ( $field['taxonomy'] ?? '' ) );
				break;
			case 'user':
				$user  = get_userdata( $id );
				$roles = array_values( array_filter( (array) ( $field['role'] ?? array() ) ) );
				$ok    = $user && ( ! $roles || array_intersect( $roles, $user->roles ) );
				break;
			default:
				$post  = get_post( $id );
				$types = array_values( array_filter( (array) ( $field['post_type'] ?? array() ) ) );
				$ok    = $post && ( ! $types || in_array( $post->post_type, $types, true ) );
		}
		return $ok ? true : self::invalid( __( 'Choose an existing item.', 'corinthian-pro' ) );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function number( array $field, string $value ): string|\WP_Error {
		if ( ! is_numeric( $value ) ) {
			return self::invalid( __( 'Enter a number.', 'corinthian-pro' ) );
		}
		$min = isset( $field['min'] ) && '' !== $field['min'] ? (float) $field['min'] : null;
		$max = isset( $field['max'] ) && '' !== $field['max'] ? (float) $field['max'] : null;
		if ( null !== $min && (float) $value < $min ) {
			/* translators: %s: minimum value */
			return self::invalid( sprintf( __( 'The value must be at least %s.', 'corinthian-pro' ), (string) $field['min'] ) );
		}
		if ( null !== $max && (float) $value > $max ) {
			/* translators: %s: maximum value */
			return self::invalid( sprintf( __( 'The value must be at most %s.', 'corinthian-pro' ), (string) $field['max'] ) );
		}
		return $value;
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'corinthian_invalid', $message );
	}
}
