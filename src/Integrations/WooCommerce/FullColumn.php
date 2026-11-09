<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;

final class FullColumn extends Column implements Sortable, Filterable, Editable {

	use SortsBySpec;
	use FiltersBySpec;
	use EditsBySpec;
}
