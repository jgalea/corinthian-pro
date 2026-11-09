<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;

final class FilterEditColumn extends Column implements Filterable, Editable {

	use FiltersBySpec;
	use EditsBySpec;
}
