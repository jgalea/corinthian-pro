<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Closure;

/**
 * What a WooCommerce column shows and how it sorts, filters and edits.
 */
final class Spec {

	/**
	 * @param string $scope  product, order or coupon.
	 * @param Closure(mixed):mixed $value Raw value from the WooCommerce object.
	 * @param (Closure(mixed,mixed):string)|null $html Escaped cell HTML from (value, object).
	 * @param (Closure(\Corinthian\Screens\Screen,string):array{join:string,col:string,ts?:bool})|null $column
	 *        SQL for the value: any join it needs plus a column expression. Drives sort and the generic filter.
	 * @param string $kind   Filter input kind: text, number, date, select or bool.
	 * @param (Closure():array<string,string>)|null $options Select options for filters, resolved lazily.
	 * @param (Closure(string,mixed,\Corinthian\Screens\Screen,string):(array{join:string,where:string}|null))|null $condition
	 *        Filter SQL when the generic one on $column doesn't fit.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $scope,
		public readonly Closure $value,
		public readonly ?Closure $html = null,
		public readonly ?Closure $column = null,
		public readonly string $kind = 'text',
		public readonly ?Closure $options = null,
		public readonly ?Closure $condition = null,
		public readonly ?Edit $edit = null,
	) {}
}
