<?php

namespace CorinthianPro\Filters;

use Corinthian\Screens\Screen;

final class QuerySqlListener {

	public function register(): void {
		add_filter( 'corinthian_query_sql', array( $this, 'compile' ), 10, 2 );
	}

	/**
	 * @param mixed $sql
	 * @return mixed
	 */
	public function compile( $sql, Screen $screen ) {
		return FilterSet::from_request()->compile( $screen );
	}
}
