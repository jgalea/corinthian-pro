<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Closure;

/**
 * How one column is edited: the editor input, the current value for it, and the
 * change to make on the WooCommerce object.
 */
final class Edit {

	/**
	 * @param string                                       $input   Editor input type.
	 * @param Closure(mixed):mixed                         $value   Current value for the editor, from the object.
	 * @param Closure(mixed,mixed,string):(\WP_Error|null) $apply   Changes the object (value, mode); returns an error to refuse.
	 * @param list<string>                                 $bulk    Bulk modes beyond inline "set".
	 * @param (Closure():array<string,string>)|null        $options Select options, resolved lazily.
	 * @param bool                                         $saves   True when $apply already saves the object.
	 */
	public function __construct(
		public readonly string $input,
		public readonly Closure $value,
		public readonly Closure $apply,
		public readonly array $bulk = array(),
		public readonly ?Closure $options = null,
		public readonly bool $saves = false,
	) {}
}
