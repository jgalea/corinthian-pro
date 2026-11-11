<?php

namespace CorinthianPro\Integrations\Wpra;

defined( 'ABSPATH' ) || exit;

final class WpraApi {

	public const ITEM_POST_TYPE = 'wprss_feed_item';
	public const SEE_AGGREGATOR = 'see_aggregator';

	public const SOURCE        = '_wpra_source';
	public const SOURCE_NAME   = '_wpra_source_name';
	public const SOURCE_URL    = '_wpra_source_url';
	public const URL           = '_wpra_url';
	public const IMPORT_DATE   = '_wpra_import_date';
	public const AUTHOR_NAME   = '_wpra_author_name';
	public const ENCLOSURE_URL = '_wpra_enclosure_url';
	public const AUDIO_URL     = '_wpra_audio_url';

	/** @var array<int,string>|null */
	private static ?array $names = null;

	/**
	 * Post types that hold imported items. Sources can import into any post
	 * type, so sites that do list them with this filter.
	 */
	public static function handles_post_type( string $post_type ): bool {
		$types = (array) apply_filters( 'corinthian_wpra_post_types', array( self::ITEM_POST_TYPE ) );
		return in_array( $post_type, $types, true );
	}

	public static function can_see_sources(): bool {
		return current_user_can( self::SEE_AGGREGATOR );
	}

	/**
	 * WPRA keeps its sources in its own table, prefixed by its wpra.db.prefix filter.
	 */
	public static function sources_table(): string {
		global $wpdb;
		return $wpdb->prefix . (string) apply_filters( 'wpra.db.prefix', 'agg_' ) . 'sources'; // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WPRA's own hook.
	}

	public static function has_sources_table(): bool {
		global $wpdb;
		$table = self::sources_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return array<int,string> source id => name
	 */
	public static function source_names(): array {
		if ( null !== self::$names ) {
			return self::$names;
		}
		global $wpdb;
		self::$names = array();
		if ( self::has_sources_table() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i ORDER BY name ASC', self::sources_table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( (array) $rows as $row ) {
				self::$names[ (int) $row['id'] ] = (string) $row['name'];
			}
		}
		return self::$names;
	}

	/**
	 * @return array<string,string> source id => name, for the filter dropdown
	 */
	public static function source_options(): array {
		if ( ! self::can_see_sources() ) {
			return array();
		}
		$out = array();
		foreach ( self::source_names() as $id => $name ) {
			$out[ (string) $id ] = '' !== $name ? $name : '#' . $id;
		}
		return $out;
	}

	public static function forget_sources(): void {
		self::$names = null;
	}
}
