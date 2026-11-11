<?php

namespace CorinthianPro\Export;

use Corinthian\Columns\Config;
use CorinthianPro\Filters\FilterSet;
use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the current list (active set, filter and sort) as CSV.
 */
final class CsvExporter {

	public const ACTION = 'corinthian_export';
	public const BATCH  = 500;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public static function url( Screen $screen, ColumnSet $set ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Carrying the list's own view state.
		$args = array(
			'action' => self::ACTION,
			'screen' => rawurlencode( $screen->key() ),
			'set'    => $set->id,
		);
		foreach ( array( FilterSet::QUERY_VAR, 'orderby', 'order', 's', 'post_status', 'author', 'cat', 'm', 'role', 'comment_status', 'post_mime_type' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				// Passed through verbatim and re-encoded; the filter JSON and search text are sanitised when handle() reads them back.
				$args[ $key ] = rawurlencode( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		// phpcs:enable
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public function handle(): void {
		check_admin_referer( self::ACTION );
		$screen = $this->plugin->screens->get( self::query_text( 'screen' ) );
		$set    = $this->plugin->sets->find( absint( $_GET['set'] ?? 0 ) );
		if ( ! $screen || ! $set || $set->screen !== $screen->key() || ! $set->visible_to( wp_get_current_user() ) || ! $screen->user_can_list() || false === ( $set->settings['export'] ?? true ) ) {
			wp_die( esc_html__( 'Export not available.', 'corinthian-pro' ), '', array( 'response' => 403 ) );
		}
		$this->stream( $screen, $set );
		exit;
	}

	private function stream( Screen $screen, ColumnSet $set ): void {
		// The filter is JSON that FilterSet::from() parses and validates rule by rule, so it can't go through sanitize_text_field().
		$filter  = FilterSet::from( rawurldecode( wp_unslash( (string) ( $_GET[ FilterSet::QUERY_VAR ] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in handle().
		$columns = $set->columns;
		$native  = $screen->native_columns();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $screen->label() . '-' . gmdate( 'Y-m-d' ) ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			return;
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to php://output, not the filesystem. BOM so Excel reads UTF-8.

		$header = array( 'ID' );
		foreach ( $columns as $config ) {
			$header[] = '' !== $config->label ? $config->label : ( $native[ (string) $config->setting( 'key' ) ] ?? $config->id );
		}
		fputcsv( $out, array_map( array( self::class, 'cell' ), $header ), ',', '"', '' );

		$offset = 0;
		do {
			$ids = $this->ids( $screen, $set, $filter, $offset );
			foreach ( $ids as $id ) {
				if ( ! $screen->user_can_read( $id ) ) {
					continue;
				}
				$row = array( $id );
				foreach ( $columns as $config ) {
					$row[] = $this->value( $screen, $config, $id );
				}
				fputcsv( $out, array_map( array( self::class, 'cell' ), $row ), ',', '"', '' );
			}
			$offset += self::BATCH;
			$this->flush_caches( $screen, $ids );
			$more = count( $ids ) === self::BATCH;
		} while ( $more );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output stream.
	}

	/**
	 * Spreadsheet apps run cells that start with these characters as formulas.
	 */
	public static function cell( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $value ) );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( ! is_scalar( $value ) ) {
			$value = '';
		}
		$value = (string) $value;
		$clean = wp_check_invalid_utf8( $value, true );
		$bad   = $clean !== $value;
		$value = $clean;
		$risky = array( '=', '+', '-', '@', "\t", "\r", '＝', '＋', '－', '＠' );
		$lead  = preg_replace( '/^[\p{Z}\s\x{FEFF}]+/u', '', $value );
		if ( '' !== $value && ( $bad || null === $lead || in_array( mb_substr( $value, 0, 1 ), $risky, true ) || in_array( mb_substr( $lead, 0, 1 ), $risky, true ) ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * The list's status view when it names a real status, otherwise every status the list shows.
	 */
	private static function post_status( Screen $screen ): string {
		$status = self::query_text( 'post_status' );
		if ( 'attachment' === $screen->subtype() ) {
			return 'inherit';
		}
		return in_array( $status, get_post_stati(), true ) ? $status : 'any';
	}

	private static function query_text( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in handle().
		$raw = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
		return sanitize_text_field( rawurldecode( $raw ) );
	}

	private function value( Screen $screen, Config $config, int $id ): mixed {
		if ( 'native' === $config->type ) {
			return NativeValues::get( $screen, (string) $config->setting( 'key' ), $id );
		}
		$type = $this->plugin->registry->get( $config->type );
		if ( ! $type ) {
			return '';
		}
		$value = $type->value( $id, $config, $screen );
		if ( is_object( $value ) ) {
			$value = $value instanceof \WP_Term ? $value->name : ( $value instanceof \WP_User ? $value->display_name : ( $value instanceof \WP_Post ? $value->post_title : '' ) );
		}
		return $value;
	}

	/**
	 * @return list<int>
	 */
	private function ids( Screen $screen, ColumnSet $set, FilterSet $filter, int $offset ): array {
		$sql     = $filter->compile( $screen, $set );
		$orderby = self::query_text( 'orderby' );
		$order   = Screen::normalize_order( self::query_text( 'order', 'DESC' ) );
		$search  = self::query_text( 's' );
		$target  = $screen->sort_target( $orderby );

		$append = static function ( $clauses ) use ( $sql, $target, $order, $screen ) {
			if ( '' !== $sql['where'] ) {
				$clauses['join']  .= ' ' . $sql['join'];
				$clauses['where'] .= ' AND ' . $sql['where'];
			}
			if ( $target ) {
				$clauses = $target[1]->sort_clauses( $clauses + array( 'groupby' => '' ), $target[0], $order, $screen );
			}
			return $clauses;
		};

		switch ( $screen->object_type() ) {
			case 'post':
				add_filter( 'posts_clauses', $append );
				$args = array(
					'post_type'        => $screen->subtype(),
					'post_status'      => self::post_status( $screen ),
					'fields'           => 'ids',
					'posts_per_page'   => self::BATCH,
					'offset'           => $offset,
					'orderby'          => $target ? 'none' : ( '' !== $orderby ? $orderby : 'date' ),
					'order'            => $order,
					'suppress_filters' => false,
					'no_found_rows'    => true,
				);
				if ( '' !== $search ) {
					$args['s'] = $search;
				}
				if ( absint( self::query_text( 'author' ) ) > 0 ) {
					$args['author'] = absint( self::query_text( 'author' ) );
				}
				if ( absint( self::query_text( 'cat' ) ) > 0 ) {
					$args['cat'] = absint( self::query_text( 'cat' ) );
				}
				if ( 1 === preg_match( '/^\d{6}$/', self::query_text( 'm' ) ) ) {
					$args['m'] = self::query_text( 'm' );
				}
				if ( '' !== self::query_text( 'post_mime_type' ) ) {
					$args['post_mime_type'] = self::query_text( 'post_mime_type' );
				}
				$ids = ( new \WP_Query( $args ) )->posts;
				remove_filter( 'posts_clauses', $append );
				return array_map( 'intval', $ids );
			case 'user':
				$apply = static function ( \WP_User_Query $q ) use ( $sql ) {
					if ( '' !== $sql['where'] ) {
						$q->query_from  .= ' ' . $sql['join'];
						$q->query_where .= ' AND ' . $sql['where'];
					}
				};
				add_action( 'pre_user_query', $apply );
				$role = sanitize_key( self::query_text( 'role' ) );
				$ids  = ( new \WP_User_Query(
					array(
						'role'        => get_role( $role ) ? $role : '',
						'fields'      => 'ID',
						'number'      => self::BATCH,
						'offset'      => $offset,
						'orderby'     => '' !== $orderby && ! $target ? $orderby : 'login',
						'order'       => $order,
						'search'      => '' !== $search ? '*' . $search . '*' : '',
						'count_total' => false,
					)
				) )->get_results();
				remove_action( 'pre_user_query', $apply );
				return array_map( 'intval', $ids );
			case 'comment':
				add_filter( 'comments_clauses', $append );
				$ids = get_comments(
					array(
						'status'       => array(
							'moderated' => 'hold',
							'approved'  => 'approve',
							'spam'      => 'spam',
							'trash'     => 'trash',
						)[ self::query_text( 'comment_status' ) ] ?? 'all',
						'fields'       => 'ids',
						'number'       => self::BATCH,
						'offset'       => $offset,
						'orderby'      => $target ? 'none' : 'comment_date_gmt',
						'order'        => $order,
						'search'       => $search,
						'cache_domain' => 'corinthian-export:' . md5( wp_json_encode( array( $sql, $orderby, $order, self::query_text( 'comment_status' ) ) ) . $offset ),
					)
				);
				remove_filter( 'comments_clauses', $append );
				return array_map( 'intval', (array) $ids );
			case 'term':
				$apply = static function ( $clauses ) use ( $sql ) {
					if ( '' !== $sql['where'] ) {
						$clauses['join']  .= ' ' . $sql['join'];
						$clauses['where'] .= ' AND ' . $sql['where'];
					}
					return $clauses;
				};
				add_filter( 'terms_clauses', $apply );
				$ids = get_terms(
					array(
						'taxonomy'   => $screen->subtype(),
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => self::BATCH,
						'offset'     => $offset,
						'orderby'    => 'name',
						'order'      => $order,
						'search'     => $search,
					)
				);
				remove_filter( 'terms_clauses', $apply );
				return array_map( 'intval', is_array( $ids ) ? $ids : array() );
			case 'site':
				\Corinthian\Screens\SiteQuery::hook();
				$apply = static function ( $clauses ) use ( $sql, $target, $order, $screen ) {
					$clauses = \Corinthian\Screens\SiteQuery::narrow( $clauses, $sql );
					return $target ? \Corinthian\Screens\SiteQuery::order( $clauses, $target[0], $target[1], $order, $screen ) : $clauses;
				};
				add_filter( 'sites_clauses', $apply );
				$ids = ( new \WP_Site_Query(
					array(
						'fields'     => 'ids',
						'number'     => self::BATCH,
						'offset'     => $offset,
						'orderby'    => $target ? 'none' : 'id',
						'order'      => $order,
						'network_id' => get_current_network_id(),
						'search'     => $search,
						\Corinthian\Screens\SiteQuery::UNCACHED => true,
					)
				) )->get_sites();
				remove_filter( 'sites_clauses', $apply );
				return array_map( 'intval', (array) $ids );
			default:
				// Screens with their own storage export in id order, one batch at a time.
				$all = $screen->query_ids( $sql, null, $offset + self::BATCH ) ?? array();
				return array_values( array_map( 'intval', array_slice( $all, $offset, self::BATCH ) ) );
		}
	}

	/**
	 * @param list<int> $ids
	 */
	private function flush_caches( Screen $screen, array $ids ): void {
		if ( 'post' === $screen->object_type() ) {
			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'post_meta' );
			}
		}
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
