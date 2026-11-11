<?php

namespace CorinthianPro\Export;

use Corinthian\Screens\Screen;
use Corinthian\Sets\ColumnSet;

defined( 'ABSPATH' ) || exit;

final class ExportButtonListener {

	public function register(): void {
		add_action( 'corinthian_switcher_actions', array( $this, 'print_button' ), 10, 2 );
	}

	public function print_button( Screen $screen, ?ColumnSet $active ): void {
		if ( ! $active || false === ( $active->settings['export'] ?? true ) ) {
			return;
		}
		?>
		<a class="button corinthian-export" href="<?php echo esc_url( CsvExporter::url( $screen, $active ) ); ?>"><?php esc_html_e( 'Export CSV', 'corinthian-pro' ); ?></a>
		<?php
	}
}
