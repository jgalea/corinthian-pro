<?php

namespace CorinthianPro\Filters;

use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a FilterSet as a query on a screen and returns matching ids.
 */
final class Matcher {

	/**
	 * Ids matching a filter on a set, optionally limited to $within.
	 *
	 * @param list<int>|null $within Only consider these ids (e.g. the rows on the current page).
	 * @return list<int>
	 */
	public static function ids( Screen $screen, ColumnSet $set, FilterSet $filter, ?array $within = null, int $limit = 100000 ): array {
		if ( null !== $within && array() === $within ) {
			return array();
		}
		$sql = $filter->compile( $screen, $set );
		if ( '' === $sql['where'] ) {
			return array();
		}
		$append = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		$ids    = array();
		switch ( $screen->object_type() ) {
			case 'post':
				add_filter( 'posts_clauses', $append );
				$query = new \WP_Query(
					array(
						'post_type'        => $screen->subtype(),
						'post_status'      => 'any',
						'fields'           => 'ids',
						'posts_per_page'   => $limit,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'suppress_filters' => false,
						'no_found_rows'    => true,
						'post__in'         => $within ?? array(),
					)
				);
				remove_filter( 'posts_clauses', $append );
				$ids = $query->posts;
				break;
			case 'user':
				$apply = static function ( \WP_User_Query $q ) use ( $sql ) {
					$q->query_from  .= ' ' . $sql['join'];
					$q->query_where .= ' AND ' . $sql['where'];
				};
				add_action( 'pre_user_query', $apply );
				$query = new \WP_User_Query(
					array(
						'fields'      => 'ID',
						'number'      => $limit,
						'orderby'     => 'ID',
						'order'       => 'ASC',
						'count_total' => false,
						'include'     => $within ?? array(),
					)
				);
				remove_action( 'pre_user_query', $apply );
				$ids = $query->get_results();
				break;
			case 'comment':
				add_filter( 'comments_clauses', $append );
				$ids = get_comments(
					array(
						'status'       => 'all',
						'fields'       => 'ids',
						'number'       => $limit,
						'orderby'      => 'comment_ID',
						'order'        => 'ASC',
						'cache_domain' => 'corinthian-match:' . md5( $sql['where'] . wp_json_encode( $within ) ),
						'comment__in'  => $within ?? array(),
					)
				);
				remove_filter( 'comments_clauses', $append );
				break;
			case 'term':
				add_filter( 'terms_clauses', $append );
				$ids = get_terms(
					array(
						'taxonomy'   => $screen->subtype(),
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => $limit,
						'orderby'    => 'term_id',
						'order'      => 'ASC',
						'include'    => $within ?? array(),
					)
				);
				remove_filter( 'terms_clauses', $append );
				break;
			case 'site':
				\Corinthian\Screens\SiteQuery::hook();
				$apply = static fn( $clauses ) => \Corinthian\Screens\SiteQuery::narrow( $clauses, $sql );
				add_filter( 'sites_clauses', $apply );
				$ids   = ( new \WP_Site_Query(
					array(
						'fields'        => 'ids',
						'number'        => $limit,
						'orderby'       => 'id',
						'order'         => 'ASC',
						'network_id'    => get_current_network_id(),
						'site__in'      => $within ?? array(),
						'no_found_rows' => true,
						\Corinthian\Screens\SiteQuery::UNCACHED => true,
					)
				) )->get_sites();
				remove_filter( 'sites_clauses', $apply );
				break;
			default:
				$ids = $screen->query_ids( $sql, $within, $limit ) ?? array();
		}
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}
}
