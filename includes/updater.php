<?php
defined( 'ABSPATH' ) || exit;

const CORINTHIAN_PRO_REPO = 'jgalea/corinthian-pro';

function corinthian_pro_latest_release() {
	$release = get_site_transient( 'corinthian_pro_release' );
	if ( false !== $release ) {
		return $release ? $release : null;
	}

	$release  = array();
	$response = wp_remote_get(
		'https://api.github.com/repos/' . CORINTHIAN_PRO_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		)
	);

	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( 'corinthian-pro.zip' === ( $asset['name'] ?? '' ) ) {
				$release = array(
					'version' => ltrim( (string) $data['tag_name'], 'v' ),
					'package' => $asset['browser_download_url'],
					'url'     => $data['html_url'],
					'notes'   => (string) ( $data['body'] ?? '' ),
				);
				break;
			}
		}
	}

	set_site_transient( 'corinthian_pro_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $release ? $release : null;
}

// Hooked through the plugin's Update URI header, so WordPress.org never answers for this slug.
function corinthian_pro_check_update( $update, $plugin_data, $plugin_file ) {
	if ( CORINTHIAN_PRO_BASENAME !== $plugin_file ) {
		return $update;
	}

	$release = corinthian_pro_latest_release();
	if ( ! $release ) {
		return $update;
	}

	return array(
		'slug'    => 'corinthian-pro',
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	);
}
add_filter( 'update_plugins_github.com', 'corinthian_pro_check_update', 10, 3 );

function corinthian_pro_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || 'corinthian-pro' !== ( $args->slug ?? '' ) ) {
		return $result;
	}

	$release = corinthian_pro_latest_release();
	if ( ! $release ) {
		return $result;
	}

	return (object) array(
		'name'          => 'Corinthian Pro',
		'slug'          => 'corinthian-pro',
		'version'       => $release['version'],
		'author'        => 'Jean Galea',
		'homepage'      => 'https://github.com/' . CORINTHIAN_PRO_REPO,
		'download_link' => $release['package'],
		'requires'      => '6.9',
		'requires_php'  => '8.1',
		'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['notes'] ) ) ),
	);
}
add_filter( 'plugins_api', 'corinthian_pro_plugin_info', 10, 3 );

function corinthian_pro_clear_release_cache( $upgrader, $options ) {
	if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
		delete_site_transient( 'corinthian_pro_release' );
	}
}
add_action( 'upgrader_process_complete', 'corinthian_pro_clear_release_cache', 10, 2 );
