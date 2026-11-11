<?php

namespace CorinthianPro;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private function __construct() {
		Integrations\Acf\AcfIntegration::register();
		Integrations\MetaBox\MetaBoxIntegration::register();
		Integrations\Pods\PodsIntegration::register();
		Integrations\RankMath\RankMathIntegration::register();
		Integrations\Yoast\YoastIntegration::register();
		Integrations\SeoPress\SeoPressIntegration::register();
		Integrations\EventsCalendar\EventsCalendarIntegration::register();
		Integrations\Wpra\WpraIntegration::register();
		Integrations\BuddyPress\BuddyPressIntegration::register();
		Integrations\WooCommerce\WooCommerceIntegration::register();
		Abilities\Abilities::register();

		$plugin = \Corinthian\Plugin::instance();
		add_action(
			'corinthian_register_types',
			static function ( \Corinthian\Columns\Registry $registry ) {
				$registry->add( new Ai\ContentHealthType() );
			}
		);
		add_filter(
			'corinthian_word_count_types',
			static fn( $types ) => array_merge( (array) $types, array( 'post.content_health' ) )
		);
		add_action(
			'corinthian_set_deleted',
			static function ( $id ) {
				delete_metadata( 'user', 0, Rest\SegmentsController::USER_META_PREFIX . (int) $id, '', true );
			}
		);
		add_action( 'rest_api_init', array( new Ai\AiController( $plugin ), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\TransferController( $plugin ), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\EditController( $plugin ), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\BulkDeleteController( $plugin ), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\SegmentsController( $plugin ), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\FormattingController( $plugin ), 'register' ) );
		( new Filters\QuerySqlListener() )->register();
		( new Rest\TypeDescriptionListener() )->register();
		if ( is_admin() ) {
			( new Admin\Assets() )->register();
			( new Admin\AiPayload() )->register();
			( new Admin\EditPayload( $plugin ) )->register();
			( new Admin\FilterPayload( $plugin ) )->register();
			( new Export\ExportButtonListener() )->register();
			( new Export\CsvExporter( $plugin ) )->register();
		}
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
