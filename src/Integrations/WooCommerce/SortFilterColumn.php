<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;

final class SortFilterColumn extends Column implements Sortable, Filterable {

	use SortsBySpec;
	use FiltersBySpec;
}
