<?php

namespace CorinthianPro\Ai;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\AbstractPostType;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Columns\Types\WordCountMeta;
use Corinthian\Screens\Screen;
use WP_Post;

/**
 * Rule-based content checks: thin, stale, no featured image, no excerpt.
 *
 * PHP and SQL evaluate the same conditions, so the cell, the sort and the
 * filter always agree. Thin content reads the stored word count; posts
 * without one yet aren't flagged in SQL until it's stored (on save, on first
 * render, or by the backfill command).
 */
final class ContentHealthType extends AbstractPostType implements Sortable, Filterable {

	public const ISSUES = array( 'thin', 'stale', 'no_image', 'no_excerpt' );

	public function key(): string {
		return 'post.content_health';
	}

	public function label(): string {
		return __( 'Content health', 'corinthian-pro' );
	}

	protected function group_slug(): string {
		return 'content';
	}

	protected function supports_post_type( string $post_type ): bool {
		return 'attachment' !== $post_type;
	}

	public function settings_schema( Screen $screen ): array {
		return array(
			array(
				'name'    => 'min_words',
				'type'    => 'number',
				'label'   => __( 'Thin below (words)', 'corinthian-pro' ),
				'default' => 300,
			),
			array(
				'name'    => 'stale_days',
				'type'    => 'number',
				'label'   => __( 'Stale after (days without changes)', 'corinthian-pro' ),
				'default' => 365,
			),
		);
	}

	/**
	 * Issues that apply to this screen's post type.
	 *
	 * @return list<string>
	 */
	public function checks( Screen $screen ): array {
		$type   = $screen->subtype();
		$checks = array();
		if ( post_type_supports( $type, 'editor' ) ) {
			$checks[] = 'thin';
		}
		$checks[] = 'stale';
		if ( post_type_supports( $type, 'thumbnail' ) ) {
			$checks[] = 'no_image';
		}
		if ( post_type_supports( $type, 'excerpt' ) ) {
			$checks[] = 'no_excerpt';
		}
		return $checks;
	}

	protected function post_value( WP_Post $post, Config $config, Screen $screen ): mixed {
		$issues = array();
		foreach ( $this->checks( $screen ) as $issue ) {
			$flagged = match ( $issue ) {
				'thin'       => WordCountMeta::get( $post->ID ) < $this->min_words( $config ),
				'stale'      => ! str_starts_with( $post->post_modified, '0000' ) && $post->post_modified < $this->stale_edge( $config ),
				'no_image'   => (int) get_post_meta( $post->ID, '_thumbnail_id', true ) <= 0,
				'no_excerpt' => '' === trim( $post->post_excerpt ),
				default      => false,
			};
			if ( $flagged ) {
				$issues[] = $issue;
			}
		}
		return $issues;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$issues = $this->value( $object_id, $config, $screen );
		if ( ! is_array( $issues ) ) {
			return $this->dash();
		}
		if ( ! $issues ) {
			return '<span class="corinthian-health corinthian-health-ok"><span class="dashicons dashicons-yes" aria-hidden="true"></span> ' . esc_html__( 'No issues', 'corinthian-pro' ) . '</span>';
		}
		$labels = $this->labels();
		$icons  = array(
			'thin'       => 'dashicons-editor-paragraph',
			'stale'      => 'dashicons-clock',
			'no_image'   => 'dashicons-format-image',
			'no_excerpt' => 'dashicons-editor-quote',
		);
		$out    = array();
		foreach ( $issues as $issue ) {
			$out[] = '<span class="corinthian-health corinthian-health-' . esc_attr( str_replace( '_', '-', $issue ) ) . '"><span class="dashicons ' . esc_attr( $icons[ $issue ] ) . '" aria-hidden="true"></span> ' . esc_html( $labels[ $issue ] ) . '</span>';
		}
		return implode( ' ', $out );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		$parts = array();
		foreach ( $this->checks( $screen ) as $issue ) {
			$parts[] = 'CASE WHEN ' . $this->condition( $issue, $config, $this->alias( $config ) . '_' . $issue ) . ' THEN 1 ELSE 0 END';
		}
		return $this->order_by( $clauses, '(' . implode( ' + ', $parts ) . ')', $order );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		$labels  = $this->labels();
		$options = array();
		foreach ( $this->checks( $screen ) as $issue ) {
			$options[ $issue ] = $labels[ $issue ];
		}
		return array(
			array(
				'op'      => 'in',
				'label'   => __( 'has any of', 'corinthian-pro' ),
				'input'   => 'select',
				'options' => $options,
			),
		);
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		if ( 'in' !== $op ) {
			return null;
		}
		$wanted = array_values( array_intersect( $this->checks( $screen ), PostFilter::list_values( $value, false ) ) );
		if ( ! $wanted ) {
			return null;
		}
		$conds = array();
		foreach ( $wanted as $issue ) {
			$conds[] = $this->condition( $issue, $config, $alias . '_' . $issue );
		}
		return PostFilter::wrap( '(' . implode( ' OR ', $conds ) . ')' );
	}

	private function condition( string $issue, Config $config, string $alias ): string {
		global $wpdb;
		if ( 'thin' === $issue ) {
			$min = $this->min_words( $config );
			return PostFilter::meta_exists( $alias, WordCountMeta::KEY, $wpdb->prepare( "CAST({$alias}.meta_value AS UNSIGNED) < %d", $min ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'stale' === $issue ) {
			$edge = $this->stale_edge( $config );
			return $wpdb->prepare( "({$wpdb->posts}.post_modified <> '0000-00-00 00:00:00' AND {$wpdb->posts}.post_modified < %s)", $edge ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return match ( $issue ) {
			'no_image'   => 'NOT ' . PostFilter::meta_exists( $alias, '_thumbnail_id', "CAST({$alias}.meta_value AS UNSIGNED) > 0" ),
			'no_excerpt' => "TRIM({$wpdb->posts}.post_excerpt) = ''",
			default      => '0 = 1',
		};
	}

	/**
	 * @return array<string,string>
	 */
	private function labels(): array {
		return array(
			'thin'       => __( 'Thin content', 'corinthian-pro' ),
			'stale'      => __( 'Stale', 'corinthian-pro' ),
			'no_image'   => __( 'No featured image', 'corinthian-pro' ),
			'no_excerpt' => __( 'No excerpt', 'corinthian-pro' ),
		);
	}

	private function min_words( Config $config ): int {
		return max( 0, (int) $config->setting( 'min_words', 300 ) );
	}

	/**
	 * Local-time cutoff, matching post_modified (stored in site time).
	 */
	private function stale_edge( Config $config ): string {
		return (string) wp_date( 'Y-m-d H:i:s', time() - max( 0, (int) $config->setting( 'stale_days', 365 ) ) * DAY_IN_SECONDS );
	}
}
