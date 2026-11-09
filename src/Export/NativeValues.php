<?php

namespace CorinthianPro\Export;

use Corinthian\Screens\Screen;

/**
 * Plain values for WordPress's own columns, which only render HTML.
 * Columns other plugins add export through the corinthian_export_native_value filter.
 */
final class NativeValues {

	public static function get( Screen $screen, string $key, int $id ): mixed {
		$value = match ( $screen->object_type() ) {
			'post'    => self::post( $key, $id ),
			'user'    => self::user( $key, $id ),
			'comment' => self::comment( $key, $id ),
			'term'    => self::term( $key, $id, $screen->subtype() ),
			'site'    => self::site( $key, $id ),
			default   => null,
		};

		/**
		 * Export value for a native column Corinthian doesn't know.
		 */
		return apply_filters( 'corinthian_export_native_value', $value, $key, $id, $screen );
	}

	private static function post( string $key, int $id ): mixed {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		if ( str_starts_with( $key, 'taxonomy-' ) ) {
			return self::terms( $id, substr( $key, 9 ) );
		}
		return match ( $key ) {
			'title'      => get_the_title( $post ),
			'author'     => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'categories' => self::terms( $id, 'category' ),
			'tags'       => self::terms( $id, 'post_tag' ),
			'comments'   => (int) $post->comment_count,
			'date'       => $post->post_date,
			'parent'     => $post->post_parent ? get_the_title( $post->post_parent ) : '',
			default      => null,
		};
	}

	private static function user( string $key, int $id ): mixed {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return null;
		}
		return match ( $key ) {
			'username' => $user->user_login,
			'name'     => trim( $user->first_name . ' ' . $user->last_name ),
			'email'    => $user->user_email,
			'role'     => implode( ', ', array_map( static fn( $r ) => wp_roles()->role_names[ $r ] ?? $r, $user->roles ) ),
			'posts'    => count_user_posts( $id ),
			default    => null,
		};
	}

	private static function comment( string $key, int $id ): mixed {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return null;
		}
		return match ( $key ) {
			'author'   => $comment->comment_author,
			'comment'  => wp_strip_all_tags( $comment->comment_content ),
			'response' => get_the_title( (int) $comment->comment_post_ID ),
			'date'     => $comment->comment_date,
			default    => null,
		};
	}

	private static function term( string $key, int $id, string $taxonomy ): mixed {
		$term = get_term( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		return match ( $key ) {
			'name'        => $term->name,
			'description' => $term->description,
			'slug'        => $term->slug,
			'posts'       => (int) $term->count,
			default       => null,
		};
	}

	private static function site( string $key, int $id ): mixed {
		$site = get_site( $id );
		if ( ! $site ) {
			return null;
		}
		return match ( $key ) {
			'blogname'    => $site->domain . $site->path,
			'lastupdated' => $site->last_updated,
			'registered'  => $site->registered,
			'users'       => count( get_users( array( 'blog_id' => $id, 'fields' => 'ID' ) ) ),
			default       => null,
		};
	}

	private static function terms( int $id, string $taxonomy ): string {
		$terms = get_the_terms( $id, $taxonomy );
		return is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
	}
}
