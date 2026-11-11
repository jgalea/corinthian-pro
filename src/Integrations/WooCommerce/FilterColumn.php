<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Filterable;

defined( 'ABSPATH' ) || exit;

final class FilterColumn extends Column implements Filterable {

	use FiltersBySpec;
}
