<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Screens\CommentScreen;
use Corinthian\Screens\MediaScreen;
use Corinthian\Screens\Screen;
use Corinthian\Screens\TermScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_Comment_Query;
use WP_Query;
use WP_Term_Query;
use WP_UnitTestCase;
use WP_User_Query;

/**
 * Runs the compiled filter SQL through the same queries and hooks the screens use.
 */
class ScreenFiltersTest extends WP_UnitTestCase {

	private Registry $registry;

	private int $seq = 0;

	/** @var array<string,int> */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$this->registry = new Registry();
		ScreenTypeLoader::register( $this->registry );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function screen( string $kind ): Screen {
		$sets = new SetRepository();
		switch ( $kind ) {
			case 'user':
				return new UserScreen( $this->registry, $sets );
			case 'comment':
				return new CommentScreen( $this->registry, $sets );
			case 'term':
				$taxonomy = get_taxonomy( 'category' );
				$this->assertNotFalse( $taxonomy );
				return new TermScreen( $this->registry, $sets, $taxonomy );
			default:
				return new MediaScreen( $this->registry, $sets, get_post_type_object( 'attachment' ) );
		}
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function config( string $key, array $settings = array() ): Config {
		return new Config( 'c' . ( ++$this->seq ), $key, 'Label', null, 'px', $settings );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array{join:string,where:string}|null
	 */
	private function condition( string $kind, string $key, array $settings, string $op, mixed $value ): ?array {
		$type = $this->registry->get( $key );
		$this->assertInstanceOf( Filterable::class, $type, "$key is filterable" );
		$config = $this->config( $key, $settings );
		$screen = $this->screen( $kind );
		$allowed = array_column( $type->filter_operators( $config, $screen ), 'op' );
		if ( ! in_array( $op, $allowed, true ) ) {
			return null;
		}
		return $type->filter_condition( $config, $op, $value, $screen, 'clf0' );
	}

	/**
	 * @param array<string,int> $candidates name => id
	 * @return list<string> names of the matching candidates, sorted
	 */
	private function run_query( string $kind, array $candidates, array $sql ): array {
		$ids = array_values( $candidates );
		switch ( $kind ) {
			case 'user':
				$apply = static function ( WP_User_Query $query ) use ( $sql ): void {
					$query->query_from  .= ' ' . $sql['join'];
					$query->query_where .= ' AND ' . $sql['where'];
				};
				add_action( 'pre_user_query', $apply );
				$query = new WP_User_Query(
					array(
						'include' => $ids,
						'fields'  => 'ID',
					)
				);
				$found = $query->get_results();
				remove_action( 'pre_user_query', $apply );
				break;
			case 'comment':
				$apply = static function ( $clauses ) use ( $sql ) {
					$clauses['join']  .= ' ' . $sql['join'];
					$clauses['where'] .= ' AND ' . $sql['where'];
					return $clauses;
				};
				add_filter( 'comments_clauses', $apply, 5, 2 );
				$query = new WP_Comment_Query();
				$found = $query->query(
					array(
						'comment__in'  => $ids,
						'status'       => array( 'hold', 'approve', 'spam', 'trash' ),
						'fields'       => 'ids',
						'orderby'      => 'none',
						'cache_domain' => 'sft' . ( ++$this->seq ),
					)
				);
				remove_filter( 'comments_clauses', $apply, 5 );
				break;
			case 'term':
				$apply = static function ( $clauses ) use ( $sql ) {
					$clauses['join']  .= ' ' . $sql['join'];
					$clauses['where'] .= ' AND ' . $sql['where'];
					return $clauses;
				};
				add_filter( 'terms_clauses', $apply, 5, 2 );
				$query = new WP_Term_Query();
				$found = $query->query(
					array(
						'taxonomy'     => 'category',
						'include'      => $ids,
						'hide_empty'   => false,
						'fields'       => 'ids',
						'orderby'      => 'none',
						'cache_domain' => 'sft' . ( ++$this->seq ),
					)
				);
				remove_filter( 'terms_clauses', $apply, 5 );
				break;
			default:
				$apply = static function ( $clauses ) use ( $sql ) {
					$clauses['join']  .= ' ' . $sql['join'];
					$clauses['where'] .= ' AND ' . $sql['where'];
					return $clauses;
				};
				add_filter( 'posts_clauses', $apply, 5, 2 );
				$query = new WP_Query(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post__in'       => $ids,
						'fields'         => 'ids',
						'posts_per_page' => -1,
						'orderby'        => 'none',
						'no_found_rows'  => true,
					)
				);
				$found = $query->posts;
				remove_filter( 'posts_clauses', $apply, 5 );
		}
		$this->assertSame( '', $GLOBALS['wpdb']->last_error, 'The compiled SQL runs without a database error' );
		$names = array();
		$flip  = array_flip( $candidates );
		foreach ( $found as $id ) {
			$this->assertArrayHasKey( (int) $id, $flip );
			$names[] = $flip[ (int) $id ];
		}
		sort( $names );
		return $names;
	}

	/**
	 * Each case: [ type key, settings, op, value, expected names ].
	 *
	 * @param list<array{0:string,1:array<string,mixed>,2:string,3:mixed,4:list<string>}> $cases
	 * @param array<string,int> $candidates
	 */
	private function check( string $kind, array $candidates, array $cases ): void {
		$this->assertNotEmpty( $cases );
		foreach ( $cases as $case ) {
			[ $key, $settings, $op, $value, $expected ] = $case;
			$label = $key . ' ' . $op . ' ' . wp_json_encode( $value );
			$sql   = $this->condition( $kind, $key, $settings, $op, $value );
			$this->assertNotNull( $sql, "$label compiles" );
			$this->assertSame( '', $sql['join'], "$label needs no join" );
			sort( $expected );
			$this->assertSame( $expected, $this->run_query( $kind, $candidates, $sql ), $label );
		}
	}

	/**
	 * @param list<array<int,mixed>> $cases
	 */
	private function check_invalid( string $kind, array $cases ): void {
		foreach ( $cases as [ $key, $settings, $op, $value ] ) {
			$this->assertNull( $this->condition( $kind, $key, $settings, $op, $value ), "$key $op " . wp_json_encode( $value ) . ' is ignored' );
		}
	}

	private function ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	// Users.

	/** @return array<string,int> */
	private function seed_users(): array {
		$factory = self::factory()->user;
		$users   = array(
			'a' => $factory->create(
				array(
					'user_login'      => 'alice',
					'display_name'    => 'Alice Anders',
					'user_email'      => 'alice@example.com',
					'user_url'        => 'https://alice.test',
					'role'            => 'editor',
					'user_registered' => '2020-01-10 08:00:00',
					'first_name'      => 'Alice',
					'nickname'        => 'ally',
					'description'     => 'Writes about cats',
					'locale'          => 'fr_FR',
				)
			),
			'b' => $factory->create(
				array(
					'user_login'      => 'bob',
					'display_name'    => 'Bob Brown',
					'user_email'      => 'bob@example.org',
					'role'            => 'author',
					'user_registered' => '2021-06-15 12:00:00',
					'first_name'      => 'Bob',
					'nickname'        => 'bobby',
				)
			),
			'c' => $factory->create(
				array(
					'user_login'      => 'carol',
					'display_name'    => 'Carol Clark',
					'user_email'      => 'carol@example.com',
					'role'            => 'subscriber',
					'user_registered' => $this->ago( 5 ),
				)
			),
			'd' => $factory->create(
				array(
					'user_login'      => 'dave',
					'display_name'    => 'Dave Dunn',
					'user_email'      => 'dave@example.net',
					'user_url'        => 'https://dave.test',
					'role'            => 'administrator',
					'user_registered' => $this->ago( 100 ),
					'first_name'      => 'Dave',
					'rich_editing'    => 'false',
				)
			),
			'e' => $factory->create(
				array(
					'user_login'      => 'eve',
					'display_name'    => 'Eve Evans',
					'user_email'      => 'eve@example.com',
					'role'            => 'contributor',
					'user_registered' => '2022-02-02 00:00:00',
					'first_name'      => 'Eve',
					'admin_color'     => 'sunrise',
				)
			),
		);
		( new \WP_User( $users['d'] ) )->add_role( 'editor' );
		// Core stores a default scheme on new users; the column shows "fresh" for users with none.
		foreach ( array( 'a', 'b', 'c', 'd' ) as $name ) {
			delete_user_meta( $users[ $name ], 'admin_color' );
		}

		foreach ( array(
			'score' => array( 'a' => array( '10' ), 'b' => array( '20' ), 'c' => array( '30' ), 'd' => array( '20', '99' ), 'e' => array( '' ) ),
			'flag'  => array( 'a' => array( '1' ), 'b' => array( '0' ), 'c' => array( 'yes' ), 'e' => array( 'false' ) ),
			'seen'  => array( 'a' => array( '2024-03-05' ), 'b' => array( '1710000000' ), 'c' => array( '2020-01-01 10:00:00' ), 'e' => array( '' ) ),
			'note'  => array( 'a' => array( 'Hello World' ), 'b' => array( 'hello there' ), 'e' => array( '' ) ),
		) as $key => $by_user ) {
			foreach ( $by_user as $name => $values ) {
				foreach ( $values as $value ) {
					add_user_meta( $users[ $name ], $key, $value );
				}
			}
		}

		$post = static fn( int $author, string $type = 'post', string $status = 'publish' ) => self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_type'   => $type,
				'post_status' => $status,
			)
		);
		$post( $users['a'] );
		$post( $users['a'] );
		$post( $users['b'] );
		$post( $users['b'], 'post', 'draft' );
		$post( $users['c'], 'page' );

		$on = $post( get_current_user_id() );
		foreach ( array( 'a', 'a', 'a', 'b' ) as $who ) {
			wp_insert_comment(
				array(
					'comment_post_ID'  => $on,
					'user_id'          => $users[ $who ],
					'comment_content'  => 'c',
					'comment_approved' => '1',
				)
			);
		}
		wp_insert_comment(
			array(
				'comment_post_ID'  => $on,
				'user_id'          => $users['a'],
				'comment_content'  => 'spam',
				'comment_approved' => 'spam',
			)
		);
		return $users;
	}

	public function test_user_columns(): void {
		$u   = $this->seed_users();
		$ids = $u;
		$id  = static fn( string $n ): int => $u[ $n ];

		$this->check(
			'user',
			$ids,
			array(
				array( 'user.id', array(), 'eq', (string) $id( 'a' ), array( 'a' ) ),
				array( 'user.id', array(), 'neq', (string) $id( 'a' ), array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.id', array(), 'gt', (string) $id( 'a' ), array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.id', array(), 'lt', (string) $id( 'c' ), array( 'a', 'b' ) ),
				array( 'user.id', array(), 'between', array( (string) $id( 'b' ), (string) $id( 'd' ) ), array( 'b', 'c', 'd' ) ),
				array( 'user.username', array(), 'contains', 'a', array( 'a', 'c', 'd' ) ),
				array( 'user.username', array(), 'not_contains', 'a', array( 'b', 'e' ) ),
				array( 'user.username', array(), 'eq', 'bob', array( 'b' ) ),
				array( 'user.username', array(), 'neq', 'bob', array( 'a', 'c', 'd', 'e' ) ),
				array( 'user.username', array(), 'starts', 'ca', array( 'c' ) ),
				array( 'user.display_name', array(), 'starts', 'Dave', array( 'd' ) ),
				array( 'user.email', array(), 'contains', 'example.com', array( 'a', 'c', 'e' ) ),
				array( 'user.email', array(), 'not_contains', 'example.com', array( 'b', 'd' ) ),
				array( 'user.website', array(), 'empty', null, array( 'b', 'c', 'e' ) ),
				array( 'user.website', array(), 'not_empty', null, array( 'a', 'd' ) ),
				array( 'user.registered', array(), 'before', '2021-01-01', array( 'a' ) ),
				array( 'user.registered', array(), 'after', '2021-06-15', array( 'c', 'd', 'e' ) ),
				array( 'user.registered', array(), 'within_days', '30', array( 'c' ) ),
				array( 'user.registered', array(), 'within_days', '150', array( 'c', 'd' ) ),
				array( 'user.registered', array(), 'older_than_days', '200', array( 'a', 'b', 'e' ) ),
				array( 'user.roles', array(), 'in', array( 'editor' ), array( 'a', 'd' ) ),
				array( 'user.roles', array(), 'in', array( 'author', 'subscriber' ), array( 'b', 'c' ) ),
				array( 'user.roles', array(), 'in', array( 'administrator' ), array( 'd' ) ),
				array( 'user.roles', array(), 'not_in', array( 'editor' ), array( 'b', 'c', 'e' ) ),
				array( 'user.post_count', array(), 'eq', '2', array( 'a' ) ),
				array( 'user.post_count', array(), 'eq', '1', array( 'b' ) ),
				array( 'user.post_count', array(), 'eq', '0', array( 'c', 'd', 'e' ) ),
				array( 'user.post_count', array(), 'neq', '0', array( 'a', 'b' ) ),
				array( 'user.post_count', array(), 'gt', '0', array( 'a', 'b' ) ),
				array( 'user.post_count', array(), 'lt', '2', array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.post_count', array(), 'between', array( '1', '2' ), array( 'a', 'b' ) ),
				array( 'user.post_count', array( 'post_type' => 'page' ), 'eq', '1', array( 'c' ) ),
				array( 'user.comment_count', array(), 'eq', '3', array( 'a' ) ),
				array( 'user.comment_count', array(), 'gt', '0', array( 'a', 'b' ) ),
				array( 'user.comment_count', array(), 'lt', '2', array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.first_name', array(), 'eq', 'Bob', array( 'b' ) ),
				array( 'user.first_name', array(), 'neq', 'Bob', array( 'a', 'c', 'd', 'e' ) ),
				array( 'user.first_name', array(), 'starts', 'Da', array( 'd' ) ),
				array( 'user.first_name', array(), 'contains', 've', array( 'd', 'e' ) ),
				array( 'user.first_name', array(), 'not_contains', 've', array( 'a', 'b', 'c' ) ),
				array( 'user.first_name', array(), 'empty', null, array( 'c' ) ),
				array( 'user.first_name', array(), 'not_empty', null, array( 'a', 'b', 'd', 'e' ) ),
				array( 'user.last_name', array(), 'empty', null, array( 'a', 'b', 'c', 'd', 'e' ) ),
				array( 'user.nickname', array(), 'contains', 'by', array( 'b' ) ),
				array( 'user.description', array(), 'contains', 'cats', array( 'a' ) ),
				array( 'user.locale', array(), 'eq', 'fr_FR', array( 'a' ) ),
				array( 'user.locale', array(), 'empty', null, array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.rich_editing', array(), 'in', array( 'false' ), array( 'd' ) ),
				array( 'user.rich_editing', array(), 'in', array( 'true' ), array( 'a', 'b', 'c', 'e' ) ),
				array( 'user.rich_editing', array(), 'not_in', array( 'false' ), array( 'a', 'b', 'c', 'e' ) ),
				array( 'user.admin_color', array(), 'eq', 'sunrise', array( 'e' ) ),
				array( 'user.admin_color', array(), 'eq', 'fresh', array( 'a', 'b', 'c', 'd' ) ),
				array( 'user.admin_color', array(), 'neq', 'fresh', array( 'e' ) ),
			)
		);

		$meta = static fn( string $key, string $display ): array => array(
			'meta_key' => $key,
			'display'  => $display,
		);
		$this->check(
			'user',
			$ids,
			array(
				array( 'user.meta', $meta( 'score', 'number' ), 'eq', '20', array( 'b', 'd' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'neq', '20', array( 'a', 'c', 'e' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'gt', '25', array( 'c', 'd' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'lt', '15', array( 'a' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'between', array( '15', '25' ), array( 'b', 'd' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'empty', null, array( 'e' ) ),
				array( 'user.meta', $meta( 'score', 'number' ), 'not_empty', null, array( 'a', 'b', 'c', 'd' ) ),
				array( 'user.meta', $meta( 'flag', 'boolean' ), 'eq', '1', array( 'a', 'c' ) ),
				array( 'user.meta', $meta( 'flag', 'boolean' ), 'eq', '0', array( 'b', 'd', 'e' ) ),
				array( 'user.meta', $meta( 'seen', 'date' ), 'before', '2024-03-06', array( 'a', 'c' ) ),
				array( 'user.meta', $meta( 'seen', 'datetime' ), 'after', '2024-03-05', array( 'b' ) ),
				array( 'user.meta', $meta( 'seen', 'date' ), 'older_than_days', '365', array( 'a', 'b', 'c' ) ),
				array( 'user.meta', $meta( 'seen', 'date' ), 'not_empty', null, array( 'a', 'b', 'c' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'contains', 'hello', array( 'a', 'b' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'not_contains', 'hello', array( 'c', 'd', 'e' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'eq', 'Hello World', array( 'a' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'neq', 'Hello World', array( 'b', 'c', 'd', 'e' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'starts', 'hello t', array( 'b' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'empty', null, array( 'c', 'd', 'e' ) ),
				array( 'user.meta', $meta( 'note', 'text' ), 'not_empty', null, array( 'a', 'b' ) ),
			)
		);

		// Negative controls, one per kind: a valid rule that matches nothing.
		add_role( 'ghost', 'Ghost' );
		$this->check(
			'user',
			$ids,
			array(
				array( 'user.username', array(), 'contains', 'zzz-no-match', array() ),
				array( 'user.post_count', array(), 'gt', '1000', array() ),
				array( 'user.registered', array(), 'before', '1990-01-01', array() ),
				array( 'user.roles', array(), 'in', array( 'ghost' ), array() ),
			)
		);
		remove_role( 'ghost' );

		$this->check_invalid(
			'user',
			array(
				array( 'user.post_count', array(), 'gt', 'abc', null ),
				array( 'user.username', array(), 'contains', '', null ),
				array( 'user.registered', array(), 'before', 'not a date', null ),
				array( 'user.roles', array(), 'in', array( 'no_such_role' ), null ),
				array( 'user.roles', array(), 'eq', 'editor', null ),
				array( 'user.meta', array(), 'contains', 'x', null ),
			)
		);

		$roles = array_column( $this->registry->get( 'user.roles' )->filter_operators( $this->config( 'user.roles' ), $this->screen( 'user' ) ), 'options', 'op' );
		$this->assertArrayHasKey( 'editor', $roles['in'] );
		$this->assertArrayHasKey( 'administrator', $roles['not_in'] );
	}

	public function test_user_rule_injection_is_escaped(): void {
		$u   = $this->seed_users();
		$sql = $this->condition( 'user', 'user.username', array(), 'eq', "x' OR 1=1 -- " );
		$this->assertNotNull( $sql );
		$this->assertSame( array(), $this->run_query( 'user', $u, $sql ) );
		$sql = $this->condition( 'user', 'user.username', array(), 'contains', '100%_' );
		$this->assertNotNull( $sql );
		$this->assertSame( array(), $this->run_query( 'user', $u, $sql ) );
	}

	// Comments.

	/** @return array<string,int> */
	private function seed_comments(): array {
		$p1 = self::factory()->post->create( array( 'post_title' => 'Alpha Post' ) );
		$p2 = self::factory()->post->create( array( 'post_title' => 'Beta Story' ) );
		$rows = array(
			'c1' => array( $p1, 'Zed Zane', 'zed@example.com', '10.0.0.1', 'https://zed.test', 'one two three', 'Mozilla/5.0', '1', 'comment', '2020-01-01 10:00:00' ),
			'c2' => array( $p1, 'Yoko Yu', 'yoko@example.org', '10.0.0.2', '', 'second comment', 'Curl', '0', '', $this->ago( 2 ) ),
			'c3' => array( $p1, 'Xena Xu', 'xena@example.com', '10.0.0.3', 'https://xena.test', 'spam text', '', 'spam', 'pingback', $this->ago( 50 ) ),
			'c4' => array( $p2, 'Walt Wu', 'walt@example.net', '10.1.0.4', '', 'trash text', 'Bot', 'trash', 'review', '2021-05-05 00:00:00' ),
			'c5' => array( $p2, 'Vera Vo', 'vera@example.net', '10.1.0.5', '', 'five', '', '1', 'trackback', '2022-02-02 00:00:00' ),
		);
		$ids  = array();
		foreach ( $rows as $name => $r ) {
			$ids[ $name ] = (int) wp_insert_comment(
				array(
					'comment_post_ID'      => $r[0],
					'comment_author'       => $r[1],
					'comment_author_email' => $r[2],
					'comment_author_IP'    => $r[3],
					'comment_author_url'   => $r[4],
					'comment_content'      => $r[5],
					'comment_agent'        => $r[6],
					'comment_approved'     => $r[7],
					'comment_date'         => $r[9],
					'comment_date_gmt'     => $r[9],
				)
			);
			// wp_insert_comment() turns an empty type into "comment"; older rows hold "".
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->comments, array( 'comment_type' => $r[8] ), array( 'comment_ID' => $ids[ $name ] ) );
		}
		clean_comment_cache( array_values( $ids ) );
		foreach ( array(
			'c1' => '5',
			'c2' => '3',
		) as $name => $rating ) {
			add_comment_meta( $ids[ $name ], 'rating', $rating );
		}
		return $ids;
	}

	public function test_comment_columns(): void {
		$c  = $this->seed_comments();
		$id = static fn( string $n ): string => (string) $c[ $n ];

		$this->check(
			'comment',
			$c,
			array(
				array( 'comment.id', array(), 'eq', $id( 'c1' ), array( 'c1' ) ),
				array( 'comment.id', array(), 'neq', $id( 'c1' ), array( 'c2', 'c3', 'c4', 'c5' ) ),
				array( 'comment.id', array(), 'gt', $id( 'c2' ), array( 'c3', 'c4', 'c5' ) ),
				array( 'comment.id', array(), 'lt', $id( 'c3' ), array( 'c1', 'c2' ) ),
				array( 'comment.id', array(), 'between', array( $id( 'c2' ), $id( 'c4' ) ), array( 'c2', 'c3', 'c4' ) ),
				array( 'comment.author', array(), 'contains', 'z', array( 'c1' ) ),
				array( 'comment.author', array(), 'not_contains', 'z', array( 'c2', 'c3', 'c4', 'c5' ) ),
				array( 'comment.author', array(), 'eq', 'Yoko Yu', array( 'c2' ) ),
				array( 'comment.author', array(), 'neq', 'Yoko Yu', array( 'c1', 'c3', 'c4', 'c5' ) ),
				array( 'comment.author', array(), 'starts', 'Wa', array( 'c4' ) ),
				array( 'comment.author_email', array(), 'contains', 'example.com', array( 'c1', 'c3' ) ),
				array( 'comment.author_ip', array(), 'eq', '10.0.0.2', array( 'c2' ) ),
				array( 'comment.author_ip', array(), 'starts', '10.0.0', array( 'c1', 'c2', 'c3' ) ),
				array( 'comment.author_url', array(), 'empty', null, array( 'c2', 'c4', 'c5' ) ),
				array( 'comment.author_url', array(), 'not_empty', null, array( 'c1', 'c3' ) ),
				array( 'comment.excerpt', array(), 'contains', 'text', array( 'c3', 'c4' ) ),
				array( 'comment.user_agent', array(), 'contains', 'Mozilla', array( 'c1' ) ),
				array( 'comment.user_agent', array(), 'empty', null, array( 'c3', 'c5' ) ),
				array( 'comment.date', array(), 'before', '2021-01-01', array( 'c1' ) ),
				array( 'comment.date', array(), 'after', '2021-05-05', array( 'c2', 'c3', 'c5' ) ),
				array( 'comment.date', array(), 'within_days', '7', array( 'c2' ) ),
				array( 'comment.date', array(), 'within_days', '60', array( 'c2', 'c3' ) ),
				array( 'comment.date', array(), 'older_than_days', '30', array( 'c1', 'c3', 'c4', 'c5' ) ),
				array( 'comment.status', array(), 'in', array( 'approved' ), array( 'c1', 'c5' ) ),
				array( 'comment.status', array(), 'in', array( 'unapproved' ), array( 'c2' ) ),
				array( 'comment.status', array(), 'in', array( 'spam', 'trash' ), array( 'c3', 'c4' ) ),
				array( 'comment.status', array(), 'not_in', array( 'approved' ), array( 'c2', 'c3', 'c4' ) ),
				array( 'comment.type', array(), 'in', array( 'comment' ), array( 'c1', 'c2' ) ),
				array( 'comment.type', array(), 'in', array( 'pingback' ), array( 'c3' ) ),
				array( 'comment.type', array(), 'in', array( 'review' ), array( 'c4' ) ),
				array( 'comment.type', array(), 'not_in', array( 'comment' ), array( 'c3', 'c4', 'c5' ) ),
				array( 'comment.post_title', array(), 'contains', 'Beta', array( 'c4', 'c5' ) ),
				array( 'comment.post_title', array(), 'starts', 'Alpha', array( 'c1', 'c2', 'c3' ) ),
				array( 'comment.post_title', array(), 'eq', 'Beta Story', array( 'c4', 'c5' ) ),
				array( 'comment.post_title', array(), 'neq', 'Beta Story', array( 'c1', 'c2', 'c3' ) ),
			)
		);

		$rating = array(
			'meta_key' => 'rating',
			'display'  => 'number',
		);
		$this->check(
			'comment',
			$c,
			array(
				array( 'comment.meta', $rating, 'eq', '5', array( 'c1' ) ),
				array( 'comment.meta', $rating, 'neq', '5', array( 'c2', 'c3', 'c4', 'c5' ) ),
				array( 'comment.meta', $rating, 'gt', '3', array( 'c1' ) ),
				array( 'comment.meta', $rating, 'lt', '4', array( 'c2' ) ),
				array( 'comment.meta', $rating, 'between', array( '3', '5' ), array( 'c1', 'c2' ) ),
				array( 'comment.meta', $rating, 'empty', null, array( 'c3', 'c4', 'c5' ) ),
				array( 'comment.meta', $rating, 'not_empty', null, array( 'c1', 'c2' ) ),
				array( 'comment.meta', array_merge( $rating, array( 'display' => 'text' ) ), 'starts', '3', array( 'c2' ) ),
			)
		);

		// Negative controls, one per kind.
		$this->check(
			'comment',
			$c,
			array(
				array( 'comment.author', array(), 'contains', 'zzz-no-match', array() ),
				array( 'comment.id', array(), 'gt', '999999999', array() ),
				array( 'comment.date', array(), 'before', '1990-01-01', array() ),
				array( 'comment.post_title', array(), 'empty', null, array() ),
			)
		);
		$without_pending = array_diff_key( $c, array( 'c2' => 1 ) );
		$this->check( 'comment', $without_pending, array( array( 'comment.status', array(), 'in', array( 'unapproved' ), array() ) ) );

		$this->check_invalid(
			'comment',
			array(
				array( 'comment.id', array(), 'gt', 'abc', null ),
				array( 'comment.status', array(), 'in', array( 'bogus' ), null ),
				array( 'comment.date', array(), 'within_days', '-3', null ),
				array( 'comment.author', array(), 'gt', '1', null ),
			)
		);

		$options = array_column( $this->registry->get( 'comment.type' )->filter_operators( $this->config( 'comment.type' ), $this->screen( 'comment' ) ), 'options', 'op' );
		$this->assertArrayHasKey( 'review', $options['in'], 'Types present in the table are offered' );
		$this->assertArrayHasKey( 'pingback', $options['in'] );
	}

	// Terms.

	/** @return array<string,int> */
	private function seed_terms(): array {
		$factory = self::factory()->term;
		$alpha   = $factory->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Alpha',
				'slug'        => 'alpha',
				'description' => 'first desc',
			)
		);
		$t       = array(
			'alpha' => $alpha,
			'beta'  => $factory->create(
				array(
					'taxonomy' => 'category',
					'name'        => 'Beta',
					'parent'      => $alpha,
					'description' => '',
				)
			),
			'gamma' => $factory->create(
				array(
					'taxonomy'    => 'category',
					'name'        => 'Gamma',
					'parent'      => $alpha,
					'description' => 'third',
				)
			),
			'delta' => $factory->create(
				array(
					'taxonomy'    => 'category',
					'name'        => 'Delta',
					'description' => 'fourth',
				)
			),
		);
		foreach ( array( array( $t['alpha'] ), array( $t['alpha'] ), array( $t['beta'] ) ) as $cats ) {
			self::factory()->post->create( array( 'post_category' => $cats ) );
		}
		foreach ( array(
			'rank'   => array( 'alpha' => '1', 'beta' => '5', 'gamma' => '9' ),
			'colour' => array( 'alpha' => 'red', 'beta' => 'blue' ),
		) as $key => $values ) {
			foreach ( $values as $name => $value ) {
				add_term_meta( $t[ $name ], $key, $value );
			}
		}
		return $t;
	}

	public function test_term_columns(): void {
		$t  = $this->seed_terms();
		$id = static fn( string $n ): string => (string) $t[ $n ];

		$this->check(
			'term',
			$t,
			array(
				array( 'term.id', array(), 'eq', $id( 'alpha' ), array( 'alpha' ) ),
				array( 'term.id', array(), 'neq', $id( 'alpha' ), array( 'beta', 'gamma', 'delta' ) ),
				array( 'term.id', array(), 'gt', $id( 'alpha' ), array( 'beta', 'gamma', 'delta' ) ),
				array( 'term.id', array(), 'lt', $id( 'gamma' ), array( 'alpha', 'beta' ) ),
				array( 'term.id', array(), 'between', array( $id( 'beta' ), $id( 'gamma' ) ), array( 'beta', 'gamma' ) ),
				array( 'term.name', array(), 'starts', 'Ga', array( 'gamma' ) ),
				array( 'term.name', array(), 'contains', 'lt', array( 'delta' ) ),
				array( 'term.name', array(), 'not_contains', 'Alp', array( 'beta', 'gamma', 'delta' ) ),
				array( 'term.name', array(), 'eq', 'Beta', array( 'beta' ) ),
				array( 'term.name', array(), 'neq', 'Beta', array( 'alpha', 'gamma', 'delta' ) ),
				array( 'term.slug', array(), 'starts', 'al', array( 'alpha' ) ),
				array( 'term.slug', array(), 'eq', 'delta', array( 'delta' ) ),
				array( 'term.post_count', array(), 'eq', '2', array( 'alpha' ) ),
				array( 'term.post_count', array(), 'eq', '1', array( 'beta' ) ),
				array( 'term.post_count', array(), 'eq', '0', array( 'gamma', 'delta' ) ),
				array( 'term.post_count', array(), 'neq', '0', array( 'alpha', 'beta' ) ),
				array( 'term.post_count', array(), 'gt', '0', array( 'alpha', 'beta' ) ),
				array( 'term.post_count', array(), 'lt', '1', array( 'gamma', 'delta' ) ),
				array( 'term.post_count', array(), 'between', array( '1', '2' ), array( 'alpha', 'beta' ) ),
				array( 'term.parent', array(), 'in', array( $id( 'alpha' ) ), array( 'beta', 'gamma' ) ),
				array( 'term.parent', array(), 'in', array( '0' ), array( 'alpha', 'delta' ) ),
				array( 'term.parent', array(), 'not_in', array( $id( 'alpha' ) ), array( 'alpha', 'delta' ) ),
				array( 'term.description', array(), 'contains', 'desc', array( 'alpha' ) ),
				array( 'term.description', array(), 'starts', 'th', array( 'gamma' ) ),
				array( 'term.description', array(), 'eq', 'fourth', array( 'delta' ) ),
				array( 'term.description', array(), 'neq', 'fourth', array( 'alpha', 'beta', 'gamma' ) ),
				array( 'term.description', array(), 'empty', null, array( 'beta' ) ),
				array( 'term.description', array(), 'not_empty', null, array( 'alpha', 'gamma', 'delta' ) ),
			)
		);

		$rank   = array(
			'meta_key' => 'rank',
			'display'  => 'number',
		);
		$colour = array( 'meta_key' => 'colour' );
		$this->check(
			'term',
			$t,
			array(
				array( 'term.meta', $rank, 'gt', '3', array( 'beta', 'gamma' ) ),
				array( 'term.meta', $rank, 'lt', '2', array( 'alpha' ) ),
				array( 'term.meta', $rank, 'eq', '5', array( 'beta' ) ),
				array( 'term.meta', $rank, 'neq', '5', array( 'alpha', 'gamma', 'delta' ) ),
				array( 'term.meta', $rank, 'between', array( '2', '9' ), array( 'beta', 'gamma' ) ),
				array( 'term.meta', $rank, 'empty', null, array( 'delta' ) ),
				array( 'term.meta', $colour, 'contains', 'r', array( 'alpha' ) ),
				array( 'term.meta', $colour, 'not_contains', 'red', array( 'beta', 'gamma', 'delta' ) ),
				array( 'term.meta', $colour, 'empty', null, array( 'gamma', 'delta' ) ),
				array( 'term.meta', $colour, 'not_empty', null, array( 'alpha', 'beta' ) ),
			)
		);

		// Negative controls, one per kind.
		$this->check(
			'term',
			$t,
			array(
				array( 'term.name', array(), 'contains', 'zzz-no-match', array() ),
				array( 'term.post_count', array(), 'gt', '1000', array() ),
				array( 'term.parent', array(), 'in', array( $id( 'beta' ) ), array() ),
				array( 'term.meta', $rank, 'gt', '1000', array() ),
			)
		);

		$this->check_invalid(
			'term',
			array(
				array( 'term.post_count', array(), 'gt', 'abc', null ),
				array( 'term.parent', array(), 'in', array( '99999999' ), null ),
				array( 'term.name', array(), 'contains', '', null ),
				array( 'term.meta', array(), 'eq', '1', null ),
			)
		);

		$options = array_column( $this->registry->get( 'term.parent' )->filter_operators( $this->config( 'term.parent' ), $this->screen( 'term' ) ), 'options', 'op' );
		$this->assertSame( 'Alpha', $options['in'][ $id( 'alpha' ) ] );
		$this->assertArrayHasKey( '0', $options['in'] );
	}

	// Media.

	/** @return array<string,int> */
	private function seed_media(): array {
		$ann    = self::factory()->user->create( array( 'display_name' => 'Ann Author' ) );
		$ben    = self::factory()->user->create( array( 'display_name' => 'Ben Builder' ) );
		$parent = self::factory()->post->create( array( 'post_title' => 'Parent One' ) );
		$other  = self::factory()->post->create( array( 'post_title' => 'Something Else' ) );

		$rows = array(
			'm1' => array( '2024/01/photo-one.jpg', 'image/jpeg', 'A cat', 1000, 800, 600, $parent, $ann, 'Cat photo', '' ),
			'm2' => array( '2024/01/doc-two.pdf', 'application/pdf', '', 5000, '', '', 0, $ben, '', 'Quarterly report' ),
			'm3' => array( '2023/12/photo-three.png', 'image/png', 'A dog', 20000, 1600, 1200, $other, $ann, 'Nice shot', '' ),
			'm4' => array( '2023/12/song.mp3', 'audio/mpeg', null, '', '', '', 0, 0, '', '' ),
		);
		$ids  = array();
		foreach ( $rows as $name => $r ) {
			$id = self::factory()->attachment->create_object(
				array(
					'file'           => $r[0],
					'post_mime_type' => $r[1],
					'post_parent'    => $r[6],
					'post_author'    => $r[7],
					'post_excerpt'   => $r[8],
					'post_content'   => $r[9],
				)
			);
			update_post_meta( $id, '_wp_attached_file', $r[0] );
			if ( null !== $r[2] ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $r[2] );
			}
			update_post_meta( $id, '_corinthian_filesize', $r[3] );
			update_post_meta( $id, '_corinthian_width', $r[4] );
			update_post_meta( $id, '_corinthian_height', $r[5] );
			$ids[ $name ] = $id;
		}
		// m1 is the featured image of two posts and m3 of one.
		foreach ( array( 'm1', 'm1', 'm3' ) as $name ) {
			$post = self::factory()->post->create();
			set_post_thumbnail( $post, $ids[ $name ] );
		}
		return $ids;
	}

	public function test_media_columns(): void {
		$m = $this->seed_media();

		$this->check(
			'media',
			$m,
			array(
				array( 'media.file_name', array(), 'contains', 'photo', array( 'm1', 'm3' ) ),
				array( 'media.file_name', array(), 'not_contains', 'photo', array( 'm2', 'm4' ) ),
				array( 'media.file_name', array(), 'starts', 'doc', array( 'm2' ) ),
				array( 'media.file_name', array(), 'eq', 'song.mp3', array( 'm4' ) ),
				array( 'media.file_name', array(), 'neq', 'song.mp3', array( 'm1', 'm2', 'm3' ) ),
				array( 'media.file_name', array(), 'not_empty', null, array( 'm1', 'm2', 'm3', 'm4' ) ),
				array( 'media.mime_type', array(), 'in', array( 'image/jpeg' ), array( 'm1' ) ),
				array( 'media.mime_type', array(), 'in', array( 'image/jpeg', 'image/png' ), array( 'm1', 'm3' ) ),
				array( 'media.mime_type', array(), 'not_in', array( 'image/jpeg' ), array( 'm2', 'm3', 'm4' ) ),
				array( 'media.alt', array(), 'contains', 'cat', array( 'm1' ) ),
				array( 'media.alt', array(), 'eq', 'A dog', array( 'm3' ) ),
				array( 'media.alt', array(), 'neq', 'A dog', array( 'm1', 'm2', 'm4' ) ),
				array( 'media.alt', array(), 'starts', 'A ', array( 'm1', 'm3' ) ),
				array( 'media.alt', array(), 'empty', null, array( 'm2', 'm4' ) ),
				array( 'media.alt', array(), 'not_empty', null, array( 'm1', 'm3' ) ),
				array( 'media.attached_to', array(), 'contains', 'Parent', array( 'm1' ) ),
				array( 'media.attached_to', array(), 'starts', 'Some', array( 'm3' ) ),
				array( 'media.attached_to', array(), 'empty', null, array( 'm2', 'm4' ) ),
				array( 'media.uploaded_by', array(), 'contains', 'Ann', array( 'm1', 'm3' ) ),
				array( 'media.uploaded_by', array(), 'eq', 'Ben Builder', array( 'm2' ) ),
				array( 'media.uploaded_by', array(), 'neq', 'Ann Author', array( 'm2', 'm4' ) ),
				array( 'media.caption', array(), 'contains', 'shot', array( 'm3' ) ),
				array( 'media.caption', array(), 'empty', null, array( 'm2', 'm4' ) ),
				array( 'media.caption', array(), 'not_empty', null, array( 'm1', 'm3' ) ),
				array( 'media.description', array(), 'starts', 'Quarterly', array( 'm2' ) ),
				array( 'media.description', array(), 'not_empty', null, array( 'm2' ) ),
				array( 'media.file_size', array(), 'gt', '4000', array( 'm2', 'm3' ) ),
				array( 'media.file_size', array(), 'lt', '2000', array( 'm1' ) ),
				array( 'media.file_size', array(), 'eq', '5000', array( 'm2' ) ),
				array( 'media.file_size', array(), 'neq', '5000', array( 'm1', 'm3', 'm4' ) ),
				array( 'media.file_size', array(), 'between', array( '900', '6000' ), array( 'm1', 'm2' ) ),
				array( 'media.width', array(), 'gt', '1000', array( 'm3' ) ),
				array( 'media.width', array(), 'eq', '800', array( 'm1' ) ),
				array( 'media.height', array(), 'lt', '700', array( 'm1' ) ),
				array( 'media.height', array(), 'between', array( '1000', '1300' ), array( 'm3' ) ),
				array( 'media.featured_count', array(), 'eq', '0', array( 'm2', 'm4' ) ),
				array( 'media.featured_count', array(), 'gt', '1', array( 'm1' ) ),
				array( 'media.featured_count', array(), 'between', array( '1', '2' ), array( 'm1', 'm3' ) ),
				array( 'media.featured_count', array(), 'neq', '0', array( 'm1', 'm3' ) ),
				array( 'media.featured_count', array(), 'lt', '1', array( 'm2', 'm4' ) ),
			)
		);

		// Negative controls, one per kind.
		$this->check(
			'media',
			$m,
			array(
				array( 'media.file_name', array(), 'contains', 'zzz-no-match', array() ),
				array( 'media.file_name', array(), 'empty', null, array() ),
				array( 'media.file_size', array(), 'gt', '100000000', array() ),
			)
		);
		$without_pdf = array_diff_key( $m, array( 'm2' => 1 ) );
		$this->check( 'media', $without_pdf, array( array( 'media.mime_type', array(), 'in', array( 'application/pdf' ), array() ) ) );

		$this->check_invalid(
			'media',
			array(
				array( 'media.file_size', array(), 'gt', 'big', null ),
				array( 'media.mime_type', array(), 'in', array( 'video/x-not-present' ), null ),
				array( 'media.file_name', array(), 'starts', '', null ),
				array( 'media.width', array(), 'empty', null, null ),
			)
		);

		$options = array_column( $this->registry->get( 'media.mime_type' )->filter_operators( $this->config( 'media.mime_type' ), $this->screen( 'media' ) ), 'options', 'op' );
		foreach ( array( 'image/jpeg', 'image/png', 'application/pdf', 'audio/mpeg' ) as $mime ) {
			$this->assertArrayHasKey( $mime, $options['in'] );
		}
		$this->assertArrayNotHasKey( 'video/mp4', $options['in'], 'Only MIME types present are offered' );
	}

	public function test_non_filterable_types_stay_display_only(): void {
		$display_only = array(
			'user.avatar', 'user.capabilities',
			'media.url', 'media.sizes', 'media.dimensions', 'media.exif_camera', 'media.exif_aperture', 'media.exif_iso',
			'media.exif_shutter', 'media.exif_focal_length', 'media.exif_created',
			'comment.parent', 'comment.word_count',
			'term.link', 'term.depth',
		);
		foreach ( $display_only as $key ) {
			$type = $this->registry->get( $key );
			$this->assertNotNull( $type, $key );
			$this->assertNotInstanceOf( Filterable::class, $type, "$key stays display-only" );
		}
		$filterable = array(
			'user.id', 'user.username', 'user.display_name', 'user.email', 'user.website', 'user.registered', 'user.roles',
			'user.post_count', 'user.comment_count', 'user.first_name', 'user.last_name', 'user.nickname', 'user.description',
			'user.rich_editing', 'user.admin_color', 'user.locale', 'user.meta',
			'media.file_name', 'media.mime_type', 'media.alt', 'media.attached_to', 'media.uploaded_by', 'media.file_size',
			'media.width', 'media.height', 'media.caption', 'media.description', 'media.featured_count',
			'comment.id', 'comment.author', 'comment.author_email', 'comment.author_ip', 'comment.author_url', 'comment.date',
			'comment.status', 'comment.type', 'comment.post_title', 'comment.excerpt', 'comment.user_agent', 'comment.meta',
			'term.id', 'term.name', 'term.slug', 'term.post_count', 'term.parent', 'term.description', 'term.meta',
		);
		foreach ( $filterable as $key ) {
			$type = $this->registry->get( $key );
			$this->assertInstanceOf( Filterable::class, $type, "$key is filterable" );
			$kind = explode( '.', $key )[0];
			$ops  = $type->filter_operators( $this->config( $key, array( 'meta_key' => 'x' ) ), $this->screen( $kind ) );
			$this->assertNotEmpty( $ops, "$key offers operators" );
		}
	}
}
