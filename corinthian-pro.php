<?php
/**
 * Plugin Name:       Corinthian Pro
 * Description:       Pro extensions for Corinthian.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  corinthian-admin-column-manager
 * Author:            Jean Galea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       corinthian-pro
 * Update URI:        https://github.com/jgalea/corinthian-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CORINTHIAN_PRO_VERSION', '0.1.0' );
define( 'CORINTHIAN_PRO_MIN_FREE', '0.1.0' );
define( 'CORINTHIAN_PRO_PLUGIN_FILE', __FILE__ );
define( 'CORINTHIAN_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CORINTHIAN_PRO_BASENAME', plugin_basename( __FILE__ ) );

require_once CORINTHIAN_PRO_PLUGIN_DIR . 'includes/updater.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'CorinthianPro\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = CORINTHIAN_PRO_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Why Pro can't boot, or null when it can.
 *
 * @param bool|null   $free_loaded  Whether the free plugin's Plugin class exists; null reads the live state.
 * @param string|null $free_version The free plugin's version; null reads CORINTHIAN_VERSION.
 * @return string|null 'missing', 'outdated' or null.
 */
function corinthian_pro_requirement_problem( ?bool $free_loaded = null, ?string $free_version = null ): ?string {
	if ( null === $free_loaded ) {
		$free_loaded = class_exists( '\Corinthian\Plugin' );
	}
	if ( ! $free_loaded ) {
		return 'missing';
	}
	if ( null === $free_version ) {
		$free_version = defined( 'CORINTHIAN_VERSION' ) ? CORINTHIAN_VERSION : '0';
	}
	if ( version_compare( $free_version, CORINTHIAN_PRO_MIN_FREE, '<' ) ) {
		return 'outdated';
	}
	return null;
}

function corinthian_pro_requirement_notice( string $problem ): void {
	add_action(
		'admin_notices',
		static function () use ( $problem ) {
			if ( 'outdated' === $problem ) {
				/* translators: %s: minimum free plugin version. */
				$message = sprintf( __( 'Corinthian Pro needs Corinthian %s or newer. Update the free plugin to continue.', 'corinthian-pro' ), CORINTHIAN_PRO_MIN_FREE );
			} else {
				$message = __( 'Corinthian Pro requires the free Corinthian plugin. Install and activate Corinthian first.', 'corinthian-pro' );
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
}

function corinthian_pro_boot(): void {
	\CorinthianPro\Plugin::instance();
}

add_action(
	'plugins_loaded',
	static function () {
		$problem = corinthian_pro_requirement_problem();
		if ( null !== $problem ) {
			corinthian_pro_requirement_notice( $problem );
			return;
		}
		add_action( 'corinthian_booted', 'corinthian_pro_boot' );
	},
	5
);
