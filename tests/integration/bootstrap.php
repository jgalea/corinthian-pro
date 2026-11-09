<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}
$_tests_dir = rtrim( $_tests_dir, '/\\' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// The free plugin sits beside Pro, under its slug or its repo folder name.
$free_plugin_dir = null;
foreach ( array( 'corinthian-admin-column-manager', 'colonnade' ) as $free_folder ) {
	if ( file_exists( dirname( __DIR__, 3 ) . "/{$free_folder}/corinthian-admin-column-manager.php" ) ) {
		$free_plugin_dir = dirname( __DIR__, 3 ) . "/{$free_folder}";
		break;
	}
}
if ( null === $free_plugin_dir ) {
	throw new RuntimeException( 'Could not locate the free Corinthian plugin next to corinthian-pro.' );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $free_plugin_dir ) {
		// Free first, so its hooks exist before Pro's listeners attach.
		require $free_plugin_dir . '/corinthian-admin-column-manager.php';
		require dirname( __DIR__, 2 ) . '/corinthian-pro.php';
		\Corinthian\Schema::install();
	}
);

require $_tests_dir . '/includes/bootstrap.php';
