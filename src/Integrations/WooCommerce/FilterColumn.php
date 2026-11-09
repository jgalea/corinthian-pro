<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Filterable;

final class FilterColumn extends Column implements Filterable {

	use FiltersBySpec;
}
