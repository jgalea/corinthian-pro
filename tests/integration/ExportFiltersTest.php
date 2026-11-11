<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Export\CsvExporter;
use WP_UnitTestCase;

final class ExportFiltersTest extends WP_UnitTestCase {

	private int $alice;
	private int $bob;

	public function set_up(): void {
		parent::set_up();
		$this->alice = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->bob   = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @param array<string,string> $get
	 * @return list<int>
	 */
	private function export_ids( string $screen_key, array $get ): array {
		$plugin = Plugin::instance();
		$set    = $plugin->sets->save(
			array(
				'screen'  => $screen_key,
				'title'   => 'Export',
				'columns' => array(
					array(
						'id'   => 'id',
						'type' => 'post.id',
					),
				),
			)
		);
		$screen = $plugin->screens->get( $screen_key );
		$_GET   = $get;
		$stream = new \ReflectionMethod( CsvExporter::class, 'stream' );
		$stream->setAccessible( true );
		// PHPUnit has already written output, so header() warns; the CSV body is what's under test.
		set_error_handler( static fn( $no, $message ) => str_contains( $message, 'headers already sent' ), E_WARNING );
		ob_start();
		$stream->invoke( new CsvExporter( $plugin ), $screen, $set );
		$csv = (string) ob_get_clean();
		restore_error_handler();
		$_GET = array();
		$rows = array_values( array_filter( explode( "\n", substr( $csv, 3 ) ), 'strlen' ) );
		array_shift( $rows );
		return array_map( static fn( $line ) => (int) str_getcsv( $line, ',', '"', '' )[0], $rows );
	}

	public function test_export_follows_the_status_author_and_month_the_list_was_filtered_by(): void {
		$published_a = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $this->alice, 'post_date' => '2024-03-10 10:00:00' ) );
		$published_b = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $this->bob, 'post_date' => '2024-04-10 10:00:00' ) );
		$draft_a     = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $this->alice, 'post_date' => '2024-03-12 10:00:00' ) );

		$all = $this->export_ids( 'post:post', array() );
		$this->assertEqualsCanonicalizing( array( $published_a, $published_b, $draft_a ), array_intersect( $all, array( $published_a, $published_b, $draft_a ) ), 'control: unfiltered export has everything' );

		$published = $this->export_ids( 'post:post', array( 'post_status' => 'publish' ) );
		$this->assertContains( $published_a, $published );
		$this->assertNotContains( $draft_a, $published );

		$by_alice = $this->export_ids( 'post:post', array( 'post_status' => 'publish', 'author' => (string) $this->alice ) );
		$this->assertSame( array( $published_a ), array_values( array_intersect( $by_alice, array( $published_a, $published_b, $draft_a ) ) ) );

		$march = $this->export_ids( 'post:post', array( 'm' => '202403' ) );
		$this->assertEqualsCanonicalizing( array( $published_a, $draft_a ), array_values( array_intersect( $march, array( $published_a, $published_b, $draft_a ) ) ) );
	}

	public function test_export_follows_the_category_and_role_filters(): void {
		$cat   = self::factory()->category->create();
		$in    = self::factory()->post->create();
		$out   = self::factory()->post->create();
		wp_set_post_categories( $in, array( $cat ) );

		$ids = $this->export_ids( 'post:post', array( 'cat' => (string) $cat ) );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );

		$users = $this->export_ids( 'users', array( 'role' => 'editor' ) );
		$this->assertContains( $this->alice, $users );
		$this->assertNotContains( $this->bob, $users );
	}

	public function test_export_follows_the_comment_status_view(): void {
		$post     = self::factory()->post->create();
		$approved = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );
		$held     = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '0' ) );

		$ids = $this->export_ids( 'comments', array( 'comment_status' => 'moderated' ) );
		$this->assertContains( $held, $ids );
		$this->assertNotContains( $approved, $ids );
	}

	/**
	 * @dataProvider formula_cells
	 */
	public function test_cells_that_could_run_as_formulas_are_prefixed( string $value ): void {
		$this->assertSame( "'" . $value, CsvExporter::cell( $value ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function formula_cells(): array {
		return array(
			'equals'       => array( '=1+1' ),
			'fullwidth'    => array( '＝1+1' ),
			'padded'       => array( ' =1+1' ),
			'ideographic space' => array( "\u{3000}=2+3" ),
			'no-break space'    => array( "\u{00A0}@SUM(A1)" ),
			'bom'               => array( "\u{FEFF}=1+1" ),
			'at'           => array( '@SUM(A1)' ),
			'fullwidth at' => array( '＠SUM(A1)' ),
		);
	}

	public function test_invalid_utf8_cannot_hide_a_formula(): void {
		$this->assertStringStartsWith( "'", CsvExporter::cell( "\xFF=1+1" ) );
		$this->assertSame( "'\u{3000}=2+3", CsvExporter::cell( "\u{3000}=2+3" ) );
		$this->assertSame( "'\u{FEFF}+1", CsvExporter::cell( "\u{FEFF}+1" ) );
		$this->assertSame( 'plain text', CsvExporter::cell( 'plain text' ) );
	}

	public function test_plain_text_and_numbers_are_left_alone(): void {
		$this->assertSame( 'hello', CsvExporter::cell( 'hello' ) );
		$this->assertSame( '-5', CsvExporter::cell( '-5' ) );
	}
}
