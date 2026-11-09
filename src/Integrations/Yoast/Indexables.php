<?php

namespace CorinthianPro\Integrations\Yoast;

/**
 * Reads of wp_yoast_indexable, the table Yoast derives from post meta and
 * post content. Only the link counts are read from it, since they exist
 * nowhere else.
 */
final class Indexables {

	private const GROUP = 'corinthian_yoast_indexable';

	public static function register(): void {
		wp_cache_add_non_persistent_groups( self::GROUP );
	}

	public static function table_exists(): bool {
		global $wpdb;
		$table = YoastIntegration::indexable_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return array{link_count:int|null,incoming_link_count:int|null,indexed:bool}
	 */
	public static function link_counts( int $post_id ): array {
		$cached = wp_cache_get( $post_id, self::GROUP );
		if ( ! is_array( $cached ) ) {
			self::prime( $post_id );
			$cached = wp_cache_get( $post_id, self::GROUP );
			$cached = is_array( $cached ) ? $cached : array();
		}
		return array(
			'link_count'          => isset( $cached['link_count'] ) ? (int) $cached['link_count'] : null,
			'incoming_link_count' => isset( $cached['incoming_link_count'] ) ? (int) $cached['incoming_link_count'] : null,
			'indexed'             => array_key_exists( 'link_count', $cached ),
		);
	}

	/**
	 * Loads the counts for every post on the list being rendered in one query.
	 */
	private static function prime( int $post_id ): void {
		global $wpdb, $wp_query;
		$ids = array();
		if ( $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				$ids[] = $post instanceof \WP_Post ? $post->ID : (int) $post;
			}
		}
		$ids = in_array( $post_id, $ids, true ) ? array_values( array_unique( $ids ) ) : array( $post_id );
		$ids = array_slice( $ids, 0, 500 );

		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table = YoastIntegration::indexable_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, link_count, incoming_link_count FROM {$table} WHERE object_type = 'post' AND object_id IN ({$marks})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Yoast's own table name and the %d list is generated from the id count.
		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[ (int) $row['object_id'] ] = array(
				'link_count'          => $row['link_count'],
				'incoming_link_count' => $row['incoming_link_count'],
			);
		}
		foreach ( $ids as $id ) {
			wp_cache_set( $id, $found[ $id ] ?? array(), self::GROUP );
		}
	}
}
