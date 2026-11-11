<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Sortable;

defined( 'ABSPATH' ) || exit;

final class SortColumn extends Column implements Sortable {

	use SortsBySpec;
}
