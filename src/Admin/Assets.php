<?php

namespace CorinthianPro\Admin;

use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

final class Assets {

	public function register(): void {
		add_action( 'corinthian_table_enqueue', array( $this, 'table' ), 10, 2 );
		add_action( 'corinthian_editor_enqueue', array( $this, 'editor' ) );
	}

	public function table( Screen $screen, ColumnSet $set ): void {
		$this->enqueue( 'table-pro', 'corinthian-table' );
	}

	public function editor(): void {
		$this->enqueue( 'editor-pro', 'corinthian-editor' );
	}

	private function enqueue( string $name, string $free_handle ): void {
		$asset = CORINTHIAN_PRO_PLUGIN_DIR . "assets/build/{$name}.asset.php";
		if ( ! file_exists( $asset ) ) {
			return;
		}
		$meta   = require $asset;
		$handle = "corinthian-pro-{$name}";
		wp_enqueue_script(
			$handle,
			plugins_url( "assets/build/{$name}.js", CORINTHIAN_PRO_PLUGIN_FILE ),
			array_merge( $meta['dependencies'], array( $free_handle ) ),
			$meta['version'],
			true
		);
		wp_set_script_translations( $handle, 'corinthian-pro' );
		if ( file_exists( CORINTHIAN_PRO_PLUGIN_DIR . "assets/build/{$name}.css" ) ) {
			wp_enqueue_style( $handle, plugins_url( "assets/build/{$name}.css", CORINTHIAN_PRO_PLUGIN_FILE ), array( $free_handle ), $meta['version'] );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}
}
