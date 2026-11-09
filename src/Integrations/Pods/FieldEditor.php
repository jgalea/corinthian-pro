<?php

namespace CorinthianPro\Integrations\Pods;

/**
 * Editor shape, current value and validation for the editable Pods kinds.
 */
final class FieldEditor {

	public const OPTION_CAP = 200;

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function shape( array $field ): array {
		$multi  = FieldKind::multiple( $field );
		$family = FieldKind::family( $field );
		$bulk   = $multi ? array( 'set', 'clear', 'append', 'remove' ) : array( 'set', 'clear' );

		$input = match ( $family ) {
			'number'             => 'number',
			'bool'               => 'toggle',
			'date'               => 'date',
			'datetime'           => 'datetime',
			'media'              => 'media',
			'choice', 'relation' => $multi ? 'multiselect' : 'select',
			default              => 'paragraph' === FieldKind::type( $field ) ? 'textarea' : 'text',
		};

		$out = array(
			'input' => $input,
			'bulk'  => $bulk,
		);
		if ( in_array( $input, array( 'select', 'multiselect' ), true ) ) {
			$out['options'] = self::options( $field );
		}
		if ( 'media' === $input && $multi ) {
			$out['multiple'] = true;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	public static function options( array $field ): array {
		$options = array();
		$val     = (string) ( $field['pick_val'] ?? '' );
		switch ( FieldKind::pick_object( $field ) ) {
			case 'post_type':
				$posts = get_posts(
					array(
						'post_type'        => '' !== $val ? $val : 'any',
						'post_status'      => 'publish',
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
						'taxonomy'   => $val,
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
						'number'  => self::OPTION_CAP,
						'orderby' => 'display_name',
						'fields'  => array( 'ID', 'display_name' ),
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
		$family = FieldKind::family( $field );
		if ( in_array( $family, array( 'media', 'relation' ), true ) ) {
			$ids = FieldKind::as_list( $raw );
			return FieldKind::multiple( $field ) ? $ids : ( $ids[0] ?? '' );
		}
		if ( FieldKind::multiple( $field ) ) {
			return FieldKind::as_list( $raw );
		}
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$raw = (string) $raw;
		switch ( $family ) {
			case 'bool':
				return '1' === $raw ? '1' : '0';
			case 'date':
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
				return $dt ? $dt->format( 'Y-m-d' ) : '';
			case 'datetime':
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw );
				return $dt ? $dt->format( 'Y-m-d\TH:i' ) : '';
			case 'time':
				$dt = \DateTimeImmutable::createFromFormat( '!H:i:s', $raw );
				return $dt ? $dt->format( 'H:i' ) : '';
		}
		return $raw;
	}

	/**
	 * Validate and normalize a submitted value to what Pods saves. An empty result means "no value".
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
		$value  = null === $value ? '' : trim( (string) $value );
		$family = FieldKind::family( $field );

		if ( 'bool' === $family ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
		}
		if ( '' === $value ) {
			return self::required( $field ) ? self::invalid( __( 'This field is required.', 'corinthian-pro' ) ) : '';
		}

		switch ( $family ) {
			case 'text':
				return self::text( $field, $value );
			case 'number':
				return self::number( $field, $value );
			case 'date':
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				return $dt && $dt->format( 'Y-m-d' ) === $value ? $value : self::invalid( __( 'Enter a date as YYYY-MM-DD.', 'corinthian-pro' ) );
			case 'datetime':
				foreach ( array( 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s' ) as $format ) {
					$dt = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
					if ( $dt && $dt->format( $format ) === $value ) {
						return $dt->format( 'Y-m-d H:i:s' );
					}
				}
				return self::invalid( __( 'Enter a date and time.', 'corinthian-pro' ) );
			case 'time':
				if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value ) ) {
					return self::invalid( __( 'Enter a time as HH:MM.', 'corinthian-pro' ) );
				}
				return 5 === strlen( $value ) ? $value . ':00' : $value;
			case 'choice':
				return array_key_exists( $value, FieldKind::choices( $field ) ) ? $value : self::invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
			case 'media':
			case 'relation':
				$ok = self::item_ok( $field, $value );
				return true === $ok ? (int) $value : $ok;
		}
		return self::invalid( __( 'This field cannot be edited here.', 'corinthian-pro' ) );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public static function required( array $field ): bool {
		return ! empty( $field['required'] );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function text( array $field, string $value ): string|\WP_Error {
		$type = FieldKind::type( $field );
		switch ( $type ) {
			case 'email':
				$clean = is_email( $value ) ? sanitize_email( $value ) : self::invalid( __( 'Enter a valid email address.', 'corinthian-pro' ) );
				break;
			case 'website':
				$check = str_starts_with( $value, '//' ) ? 'https:' . $value : $value;
				$clean = false !== filter_var( $check, FILTER_VALIDATE_URL ) ? esc_url_raw( $value ) : self::invalid( __( 'Enter a valid URL.', 'corinthian-pro' ) );
				break;
			case 'color':
				$hex   = sanitize_hex_color( $value );
				$clean = $hex ? $hex : self::invalid( __( 'Enter a colour as #RRGGBB.', 'corinthian-pro' ) );
				break;
			default:
				$clean = 'paragraph' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		// Pods cuts longer text without a word; refuse it instead so nothing is lost quietly.
		$max = (int) ( $field[ $type . '_max_length' ] ?? ( in_array( $type, array( 'text', 'website', 'email', 'phone' ), true ) ? 255 : -1 ) );
		if ( $max > 0 && mb_strlen( $clean ) > $max ) {
			/* translators: %d: maximum number of characters */
			return self::invalid( sprintf( __( 'Use at most %d characters.', 'corinthian-pro' ), $max ) );
		}
		return $clean;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function number( array $field, string $value ): string|\WP_Error {
		if ( ! is_numeric( $value ) ) {
			return self::invalid( __( 'Enter a number.', 'corinthian-pro' ) );
		}
		$type     = FieldKind::type( $field );
		$decimals = (int) ( $field[ $type . '_decimals' ] ?? ( 'currency' === $type ? 2 : 0 ) );
		$places   = str_contains( $value, '.' ) ? strlen( rtrim( substr( $value, (int) strpos( $value, '.' ) + 1 ), '0' ) ) : 0;
		if ( $places > $decimals ) {
			/* translators: %d: number of decimal places */
			return self::invalid( sprintf( _n( 'Use at most %d decimal place.', 'Use at most %d decimal places.', $decimals, 'corinthian-pro' ), $decimals ) );
		}
		$min = isset( $field[ $type . '_min' ] ) && is_numeric( $field[ $type . '_min' ] ) ? (float) $field[ $type . '_min' ] : null;
		$max = isset( $field[ $type . '_max' ] ) && is_numeric( $field[ $type . '_max' ] ) ? (float) $field[ $type . '_max' ] : null;
		if ( null !== $min && (float) $value < $min ) {
			/* translators: %s: minimum value */
			return self::invalid( sprintf( __( 'The value must be at least %s.', 'corinthian-pro' ), (string) $field[ $type . '_min' ] ) );
		}
		if ( null !== $max && (float) $value > $max ) {
			/* translators: %s: maximum value */
			return self::invalid( sprintf( __( 'The value must be at most %s.', 'corinthian-pro' ), (string) $field[ $type . '_max' ] ) );
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return list<int|string>|\WP_Error
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
		$items  = array_values( array_unique( $items ) );
		$choice = 'choice' === FieldKind::family( $field );

		foreach ( $items as $item ) {
			if ( $choice ) {
				if ( ! array_key_exists( $item, FieldKind::choices( $field ) ) ) {
					return self::invalid( __( 'Choose only from the available options.', 'corinthian-pro' ) );
				}
				continue;
			}
			$ok = self::item_ok( $field, $item );
			if ( true !== $ok ) {
				return $ok;
			}
		}

		if ( ! $items && self::required( $field ) ) {
			return self::invalid( __( 'This field is required.', 'corinthian-pro' ) );
		}
		$limit = (int) ( $field['pick_limit'] ?? $field['file_limit'] ?? 0 );
		if ( $limit > 0 && count( $items ) > $limit ) {
			/* translators: %d: maximum number of selections */
			return self::invalid( sprintf( __( 'Select at most %d.', 'corinthian-pro' ), $limit ) );
		}
		return $choice ? $items : array_map( 'intval', $items );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return true|\WP_Error
	 */
	private static function item_ok( array $field, string $value ): bool|\WP_Error {
		$id = ctype_digit( $value ) ? (int) $value : 0;
		if ( $id <= 0 ) {
			return self::invalid( __( 'Choose an existing item.', 'corinthian-pro' ) );
		}
		if ( 'media' === FieldKind::family( $field ) ) {
			if ( 'attachment' !== get_post_type( $id ) || ! self::readable_post( $id ) ) {
				return self::invalid( __( 'Choose a file from the media library.', 'corinthian-pro' ) );
			}
			if ( 'images' === ( $field['file_type'] ?? '' ) && ! wp_attachment_is_image( $id ) ) {
				return self::invalid( __( 'Choose an image.', 'corinthian-pro' ) );
			}
			return true;
		}
		$val = (string) ( $field['pick_val'] ?? '' );
		switch ( FieldKind::pick_object( $field ) ) {
			case 'taxonomy':
				$ok = (bool) term_exists( $id, $val );
				break;
			case 'user':
				$ok = (bool) get_userdata( $id );
				break;
			default:
				$post = get_post( $id );
				$ok   = $post && ( '' === $val || $post->post_type === $val ) && self::readable_post( $id );
		}
		return $ok ? true : self::invalid( __( 'Choose an existing item.', 'corinthian-pro' ) );
	}

	/**
	 * Linking an item reveals that it exists and shows its title, so only
	 * items the user can read qualify: published, or ones they can edit.
	 */
	private static function readable_post( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'read_post', $id ) ) {
			return false;
		}
		return in_array( $post->post_status, array( 'publish', 'inherit' ), true ) || current_user_can( 'edit_post', $id );
	}


	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'corinthian_invalid', $message );
	}
}
