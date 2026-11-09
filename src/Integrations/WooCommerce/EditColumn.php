<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Editable;

final class EditColumn extends Column implements Editable {

	use EditsBySpec;
}
