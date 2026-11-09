<?php

namespace CorinthianPro\Integrations\BuddyPress;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;
use DateTimeImmutable;
use WP_Error;

/**
 * One extended profile (xprofile) field, chosen with the field_id setting.
 * Values live in the bp_xprofile_data table, one row per member and field;
 * multi-value fields store a serialized list in the value column.
 */
final class XProfileType extends BpType implements Filterable, Editable {

	private const KINDS = array(
		'textbox'        => 'text',
		'wp-textbox'     => 'text',
		'telephone'      => 'text',
		'url'            => 'url',
		'textarea'       => 'textarea',
		'wp-biography'   => 'textarea',
		'number'         => 'number',
		'datebox'        => 'date',
		'selectbox'      => 'choice',
		'radio'          => 'choice',
		'checkbox'       => 'multi',
		'multiselectbox' => 'multi',
	);

	public function key(): string {
		return 'bp.xprofile';
	}

	public function label(): string {
		return __( 'Profile field', 'corinthian-pro' );
	}

	public function component(): string {
		return 'xprofile';
	}

	public function settings_schema( Screen $screen ): array {
		$options = array();
		foreach ( BpApi::fields() as [ $id, $label, $type ] ) {
			if ( isset( self::KINDS[ $type ] ) ) {
				$options[ (string) $id ] = $label;
			}
		}
		return array(
			array(
				'name'    => 'field_id',
				'type'    => 'select',
				'label'   => __( 'Profile field', 'corinthian-pro' ),
				'options' => $options,
				'default' => '',
			),
		);
	}

	/**
	 * @return array{id:int,name:string,type:string,required:bool,options:list<string>,kind:string}|null
	 */
	private function field( Config $config ): ?array {
		$id    = $config->setting( 'field_id', 0 );
		$field = is_scalar( $id ) ? BpApi::field( absint( $id ) ) : null;
		if ( ! $field || ! isset( self::KINDS[ $field['type'] ] ) ) {
			return null;
		}
		return $field + array( 'kind' => self::KINDS[ $field['type'] ] );
	}

	/**
	 * The stored value, or null when empty or when the member's visibility
	 * setting hides it from the current user.
	 */
	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config );
		if ( ! $field || BpApi::field_hidden( $field['id'], $object_id ) ) {
			return null;
		}
		return BpApi::field_data( $field['id'], $object_id );
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$field = $this->field( $config );
		$value = $this->value( $object_id, $config, $screen );
		if ( ! $field || null === $value ) {
			return parent::render( $object_id, $config, $screen );
		}
		if ( is_array( $value ) ) {
			return esc_html( implode( ', ', $value ) );
		}
		if ( 'date' === $field['kind'] ) {
			return esc_html( (string) mysql2date( (string) get_option( 'date_format' ), $value ) );
		}
		if ( 'url' === $field['kind'] ) {
			$safe = esc_url( $value, array( 'http', 'https' ) );
			return '' === $safe ? esc_html( $value ) : '<a href="' . esc_url( $safe ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
		}
		return esc_html( $value );
	}

	/**
	 * A filter on a value would reveal it, so only members who see every field may use one.
	 */
	public function filter_operators( Config $config, Screen $screen ): array {
		$field = $this->field( $config );
		if ( ! $field || ! BpApi::is_moderator() ) {
			return array();
		}
		return match ( $field['kind'] ) {
			'number' => PostFilter::number_ops( true ),
			'date'   => array(
				array(
					'op'    => 'before',
					'label' => __( 'before', 'corinthian-pro' ),
					'input' => 'date',
				),
				array(
					'op'    => 'after',
					'label' => __( 'after', 'corinthian-pro' ),
					'input' => 'date',
				),
			),
			'choice', 'multi' => PostFilter::in_ops( array_combine( $field['options'], $field['options'] ) ),
			default  => PostFilter::text_ops(),
		};
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$field = $this->field( $config );
		if ( ! $field || ! BpApi::is_moderator() ) {
			return null;
		}
		$col  = "{$alias}.value";
		$rule = match ( $field['kind'] ) {
			'number' => $this->number_rule( $col, $op, $value ),
			'date'   => $this->date_rule( $col, $op, $value ),
			'choice' => $this->choice_rule( $col, $op, $value, $field['options'], false ),
			'multi'  => $this->choice_rule( $col, $op, $value, $field['options'], true ),
			default  => $this->text_rule( $col, $op, $value ),
		};
		if ( null === $rule ) {
			return null;
		}
		[ $cond, $negate ] = $rule;
		$table             = BpApi::xprofile_table();
		$exists            = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$table} AS {$alias} WHERE {$alias}.user_id = {$wpdb->users}.ID AND {$alias}.field_id = %d AND ", $field['id'] ) . $cond . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'join'  => '',
			'where' => $negate ? "NOT {$exists}" : $exists,
		);
	}

	/**
	 * A rule is the condition on one data row and whether the member must lack such a row.
	 *
	 * @return array{0:string,1:bool}|null
	 */
	private function text_rule( string $col, string $op, mixed $value ): ?array {
		$present = "{$col} <> ''";
		return match ( $op ) {
			'empty'        => array( $present, true ),
			'not_empty'    => array( $present, false ),
			'neq'          => $this->positive( PostFilter::text_where( $col, 'eq', $value ), true ),
			'not_contains' => $this->positive( PostFilter::text_where( $col, 'contains', $value ), true ),
			'contains', 'eq', 'starts' => $this->positive( PostFilter::text_where( $col, $op, $value ), false ),
			default        => null,
		};
	}

	/**
	 * @return array{0:string,1:bool}|null
	 */
	private function number_rule( string $col, string $op, mixed $value ): ?array {
		$num = "CAST({$col} AS DECIMAL(20,6))";
		if ( 'empty' === $op ) {
			return array( "{$col} <> ''", true );
		}
		if ( 'neq' === $op ) {
			return $this->positive( PostFilter::number_where( $num, 'eq', $value ), true );
		}
		$cond = in_array( $op, array( 'eq', 'gt', 'lt', 'between' ), true ) ? PostFilter::number_where( $num, $op, $value ) : null;
		return $this->positive( null === $cond ? null : "{$col} <> '' AND {$cond}", false );
	}

	/**
	 * Dates are stored as "Y-m-d 00:00:00".
	 *
	 * @return array{0:string,1:bool}|null
	 */
	private function date_rule( string $col, string $op, mixed $value ): ?array {
		global $wpdb;
		if ( ( 'before' !== $op && 'after' !== $op ) || ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! wp_checkdate( (int) $m[2], (int) $m[3], (int) $m[1], $value ) ) {
			return null;
		}
		if ( 'before' === $op ) {
			return $this->positive( $wpdb->prepare( "{$col} <> '' AND {$col} < %s", $value . ' 00:00:00' ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$next = gmdate( 'Y-m-d', (int) strtotime( $value . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		return $this->positive( $wpdb->prepare( "{$col} <> '' AND {$col} >= %s", $next . ' 00:00:00' ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param list<string> $options
	 * @return array{0:string,1:bool}|null
	 */
	private function choice_rule( string $col, string $op, mixed $value, array $options, bool $multi ): ?array {
		global $wpdb;
		$picked = array_values( array_intersect( PostFilter::list_values( $value, false ), $options ) );
		if ( ! $picked || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
			return null;
		}
		if ( $multi ) {
			// A multi-value field is stored as a serialized list: s:5:"Music";
			$parts = array();
			foreach ( $picked as $item ) {
				$parts[] = $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( 's:' . strlen( (string) $item ) . ':"' . $item . '";' ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$cond = '(' . implode( ' OR ', $parts ) . ')';
		} else {
			$cond = $wpdb->prepare( "{$col} IN (" . implode( ',', array_fill( 0, count( $picked ), '%s' ) ) . ')', $picked ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		return array( $cond, 'not_in' === $op );
	}

	/**
	 * @return array{0:string,1:bool}|null
	 */
	private function positive( ?string $cond, bool $negate ): ?array {
		return null === $cond || '' === $cond ? null : array( $cond, $negate );
	}

	public function edit_field( Config $config, Screen $screen ): array {
		$field = $this->field( $config );
		if ( ! $field ) {
			return array(
				'input' => 'text',
				'bulk'  => array(),
			);
		}
		$out = match ( $field['kind'] ) {
			'textarea' => array( 'input' => 'textarea' ),
			'number'   => array( 'input' => 'number' ),
			'date'     => array( 'input' => 'date' ),
			'choice'   => array(
				'input'   => 'select',
				'options' => array_combine( $field['options'], $field['options'] ),
			),
			'multi'    => array(
				'input'   => 'multiselect',
				'options' => array_combine( $field['options'], $field['options'] ),
			),
			default    => array( 'input' => 'text' ),
		};
		$out['bulk'] = 'multi' === $field['kind'] ? array( 'set', 'clear', 'append', 'remove' ) : array( 'set', 'clear' );
		return $out;
	}

	public function edit_value( int $object_id, Config $config, Screen $screen ): mixed {
		$field = $this->field( $config );
		$value = $this->value( $object_id, $config, $screen );
		if ( ! $field ) {
			return null;
		}
		if ( 'multi' === $field['kind'] ) {
			return is_array( $value ) ? $value : ( null === $value ? array() : array( $value ) );
		}
		if ( ! is_string( $value ) ) {
			return '';
		}
		$dt = 'date' === $field['kind'] ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value ) : false;
		return 'date' === $field['kind'] ? ( $dt ? $dt->format( 'Y-m-d' ) : '' ) : $value;
	}

	public function save( int $object_id, Config $config, Screen $screen, mixed $value, string $mode = 'set' ): bool|WP_Error {
		$field = $this->field( $config );
		if ( ! $field ) {
			return $this->invalid( __( 'This profile field cannot be edited here.', 'corinthian-pro' ) );
		}
		$modes = $this->edit_field( $config, $screen )['bulk'];
		if ( ! is_array( $modes ) || ! in_array( $mode, $modes, true ) ) {
			return $this->bad_mode();
		}
		$refused = $this->guard( $object_id );
		if ( $refused ) {
			return $refused;
		}

		$multi = 'multi' === $field['kind'];
		if ( 'clear' === $mode ) {
			$new = $multi ? array() : '';
		} else {
			$new = $this->normalize( $field, $value );
			if ( $new instanceof WP_Error ) {
				return $new;
			}
			if ( $multi && ( 'append' === $mode || 'remove' === $mode ) ) {
				$current = BpApi::field_data( $field['id'], $object_id );
				$current = is_array( $current ) ? $current : ( null === $current ? array() : array( $current ) );
				$new     = array_values( 'append' === $mode ? array_unique( array_merge( $current, (array) $new ) ) : array_diff( $current, (array) $new ) );
			}
		}
		if ( $field['required'] && ( '' === $new || array() === $new ) ) {
			return $this->invalid( __( 'This field is required.', 'corinthian-pro' ) );
		}
		return BpApi::set_field_data( $field['id'], $object_id, $new, $field['required'] ) ? true : $this->invalid( __( 'BuddyPress rejected this value.', 'corinthian-pro' ) );
	}

	/**
	 * @param array{id:int,name:string,type:string,required:bool,options:list<string>,kind:string} $field
	 * @return string|list<string>|WP_Error
	 */
	private function normalize( array $field, mixed $value ): string|array|WP_Error {
		if ( 'multi' === $field['kind'] ) {
			$items = null === $value || '' === $value ? array() : ( is_array( $value ) ? $value : array( $value ) );
			$out   = array();
			foreach ( $items as $item ) {
				if ( ! is_scalar( $item ) ) {
					return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
				}
				if ( '' === (string) $item ) {
					continue;
				}
				if ( ! in_array( (string) $item, $field['options'], true ) ) {
					return $this->invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
				}
				$out[] = (string) $item;
			}
			return array_values( array_unique( $out ) );
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			return $this->invalid( __( 'Invalid value.', 'corinthian-pro' ) );
		}
		$text = null === $value ? '' : trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}
		switch ( $field['kind'] ) {
			case 'textarea':
				return sanitize_textarea_field( $text );
			case 'number':
				return is_numeric( $text ) ? $text : $this->invalid( __( 'Enter a number.', 'corinthian-pro' ) );
			case 'date':
				$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $text );
				return $dt && $dt->format( 'Y-m-d' ) === $text ? $text . ' 00:00:00' : $this->invalid( __( 'Enter a date as YYYY-MM-DD.', 'corinthian-pro' ) );
			case 'url':
				$check = str_starts_with( $text, '//' ) ? 'https:' . $text : $text;
				return false !== filter_var( $check, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $check ) ? esc_url_raw( $text, array( 'http', 'https' ) ) : $this->invalid( __( 'Enter a full URL starting with http:// or https://.', 'corinthian-pro' ) );
			case 'choice':
				return in_array( $text, $field['options'], true ) ? $text : $this->invalid( __( 'Choose one of the available options.', 'corinthian-pro' ) );
		}
		return sanitize_text_field( $text );
	}
}
