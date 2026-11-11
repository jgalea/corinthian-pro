<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Editable;

defined( 'ABSPATH' ) || exit;

final class EditColumn extends Column implements Editable {

	use EditsBySpec;
}
