<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Columns\Types\Sites\SiteTypeLoader;
use CorinthianPro\Filters\FilterSet;
use Corinthian\Plugin;
use Corinthian\Screens\NetworkSiteScreen;
use Corinthian\Sets\ColumnSet;
use Corinthian\Sets\SetRepository;
use CorinthianPro\Export\CsvExporter;
use CorinthianPro\Export\NativeValues;
use WP_UnitTestCase;

/**
 * Network sites CSV export. Run with phpunit-multisite.xml.dist.
 *
 * @group MultisiteTest
 * @group ms-required
 */
class MultisiteExportTest extends WP_UnitTestCase {

	private Registry $registry;
	private SetRepository $sets;
	private NetworkSiteScreen $sites_screen;
	private int $super;

	/** @var array<string,int> */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite: run phpunit-multisite.xml.dist.' );
		}
		$this->registry     = new Registry();
		ScreenTypeLoader::register( $this->registry );
		SiteTypeLoader::register( $this->registry );
		$this->sets         = new SetRepository();
		$this->sites_screen = new NetworkSiteScreen( $this->registry, $this->sets );

		$this->super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $this->super );
		wp_set_current_user( $this->super );

		$rows = array(
			'alpha' => array( 'a.example.org', '2020-01-10 10:00:00', '2023-03-01 10:00:00', 'Alpha Site' ),
			'beta'  => array( 'b.example.org', '2021-02-10 10:00:00', '2022-02-01 10:00:00', 'Beta Site' ),
			'gamma' => array( 'c.example.org', '2022-03-10 10:00:00', '2021-01-05 10:00:00', 'Gamma Site' ),
			'delta' => array( 'd.example.org', '2023-04-10 10:00:00', '2020-06-01 10:00:00', 'Delta Site' ),
		);
		global $wpdb;
		foreach ( $rows as $slug => $row ) {
			$id = self::factory()->blog->create(
				array(
					'domain'  => get_network()->domain,
					'path'    => '/s-' . $slug . '/',
					'title'   => $row[3],
					'user_id' => $this->super,
				)
			);
			$this->ids[ $slug ] = $id;
		}

		foreach ( $this->ids as $id ) {
			update_blog_status( $id, 'public', 1 );
		}
		update_blog_status( $this->ids['beta'], 'public', 0 );
		update_blog_status( $this->ids['beta'], 'archived', 1 );
		update_blog_status( $this->ids['gamma'], 'spam', 1 );
		update_blog_status( $this->ids['gamma'], 'mature', 1 );
		update_blog_status( $this->ids['delta'], 'deleted', 1 );

		// Last, because every status or option change above bumps last_updated.
		foreach ( $rows as $slug => $row ) {
			$wpdb->update(
				$wpdb->blogs,
				array(
					'domain'       => $row[0],
					'registered'   => $row[1],
					'last_updated' => $row[2],
				),
				array( 'blog_id' => $this->ids[ $slug ] )
			);
			clean_blog_cache( $this->ids[ $slug ] );
		}
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function column_set( string $screen, string $key, array $settings = array() ): ColumnSet {
		return $this->sets->save(
			array(
				'screen'  => $screen,
				'title'   => 'Test ' . $key,
				'columns' => array(
					array(
						'id'       => 'c',
						'type'     => $key,
						'label'    => 'Label',
						'settings' => $settings,
					),
				),
			)
		);
	}

	/**
	 * @return list<list<string>>
	 */
	private function export( string $key, array $settings, array $get = array() ): array {
		$set = $this->column_set( 'network-sites', $key, $settings );
		$screen = new NetworkSiteScreen( $this->registry, $this->sets );
		$_GET   = $get;
		$stream = new \ReflectionMethod( CsvExporter::class, 'stream' );
		$stream->setAccessible( true );
		// PHPUnit has already written output, so header() warns; the CSV body is what's under test.
		set_error_handler( static fn( $no, $message ) => str_contains( $message, 'headers already sent' ), E_WARNING );
		ob_start();
		$stream->invoke( new CsvExporter( Plugin::instance() ), $screen, $set );
		$csv = (string) ob_get_clean();
		restore_error_handler();
		$_GET = array();
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$rows = array_map( static fn( $line ) => str_getcsv( $line, ',', '"', '' ), array_values( array_filter( explode( "\n", substr( $csv, 3 ) ), 'strlen' ) ) );
		$this->assertSame( array( 'ID', 'Label' ), $rows[0] );
		array_shift( $rows );
		return $rows;
	}

	public function test_csv_export_lists_sites_with_their_values_filter_and_sort(): void {
		$rows = $this->export( 'site.domain', array() );
		$byid = array_column( $rows, 1, 0 );
		foreach ( array( 'alpha' => 'a.example.org', 'delta' => 'd.example.org' ) as $slug => $domain ) {
			$this->assertSame( $domain, $byid[ (string) $this->ids[ $slug ] ] );
		}
		$this->assertGreaterThanOrEqual( 4, count( $rows ) );

		$filter = (string) wp_json_encode( array( 'match' => 'all', 'rules' => array( array( 'column' => 'c', 'op' => 'eq', 'value' => 'c.example.org' ) ) ) );
		$rows   = $this->export( 'site.domain', array(), array( FilterSet::QUERY_VAR => rawurlencode( $filter ) ) );
		$this->assertSame( array( array( (string) $this->ids['gamma'], 'c.example.org' ) ), $rows, 'filter applied' );

		$sorted = $this->export( 'site.domain', array(), array( 'orderby' => 'corinthian-c', 'order' => 'DESC' ) );
		$mine   = array_values( array_filter( array_column( $sorted, 1 ), static fn( $d ) => in_array( $d, array( 'a.example.org', 'b.example.org', 'c.example.org', 'd.example.org' ), true ) ) );
		$this->assertSame( array( 'd.example.org', 'c.example.org', 'b.example.org', 'a.example.org' ), $mine, 'sort applied' );

		$searched = $this->export( 'site.domain', array(), array( 's' => 'b.example' ) );
		$this->assertSame( array( array( (string) $this->ids['beta'], 'b.example.org' ) ), $searched );
	}

	public function test_csv_export_reads_native_site_columns_and_skips_unreadable_sites(): void {
		$set = $this->sets->save(
			array(
				'screen'  => 'network-sites',
				'title'   => 'Native',
				'columns' => array( array( 'id' => 'n', 'type' => 'native', 'label' => 'URL', 'settings' => array( 'key' => 'blogname' ) ), array( 'id' => 'r', 'type' => 'native', 'label' => 'Registered', 'settings' => array( 'key' => 'registered' ) ) ),
			)
		);
		$this->assertSame( 'a.example.org/s-alpha/', NativeValues::get( $this->sites_screen, 'blogname', $this->ids['alpha'] ) );
		$this->assertSame( '2020-01-10 10:00:00', NativeValues::get( $this->sites_screen, 'registered', $this->ids['alpha'] ) );
		$this->assertSame( '2023-03-01 10:00:00', NativeValues::get( $this->sites_screen, 'lastupdated', $this->ids['alpha'] ) );
		$this->assertSame( 1, NativeValues::get( $this->sites_screen, 'users', $this->ids['alpha'] ) );
		$this->assertNull( NativeValues::get( $this->sites_screen, 'blogname', 99999999 ) );
		$this->assertSame( 'native', $set->columns[0]->type );
	}
}
