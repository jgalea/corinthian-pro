<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Sortable;

final class SortColumn extends Column implements Sortable {

	use SortsBySpec;
}
