<?php

namespace CorinthianPro\Tests;

use CorinthianPro\Plugin;
use WP_UnitTestCase;

class SmokeTest extends WP_UnitTestCase {

	public function test_pro_boots_on_corinthian_booted(): void {
		$this->assertGreaterThan( 0, did_action( 'corinthian_booted' ) );
		$this->assertNotFalse( has_action( 'corinthian_booted', 'corinthian_pro_boot' ) );
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_guard_passes_with_current_free(): void {
		$this->assertNull( corinthian_pro_requirement_problem() );
		$this->assertNull( corinthian_pro_requirement_problem( true, CORINTHIAN_PRO_MIN_FREE ) );
	}

	public function test_guard_bails_when_free_is_missing(): void {
		$this->assertSame( 'missing', corinthian_pro_requirement_problem( false, null ) );
	}

	public function test_guard_bails_when_free_is_too_old(): void {
		$this->assertSame( 'outdated', corinthian_pro_requirement_problem( true, '0.0.9' ) );
	}

	public function test_notice_renders_without_fatal(): void {
		corinthian_pro_requirement_notice( 'missing' );
		corinthian_pro_requirement_notice( 'outdated' );
		ob_start();
		do_action( 'admin_notices' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'requires the free Corinthian Admin Column Manager plugin', $html );
		$this->assertStringContainsString( 'needs Corinthian Admin Column Manager 0.1.0', $html );
	}
}
