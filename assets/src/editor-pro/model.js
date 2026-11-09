export const optionList = ( options ) =>
	Array.isArray( options )
		? options.map( ( o ) => ( typeof o === 'object' ? o : { value: o, label: String( o ) } ) )
		: Object.entries( options || {} ).map( ( [ value, label ] ) => ( { value, label } ) );

export const move = ( list, from, to ) => {
	if ( to < 0 || to >= list.length || from === to ) {
		return list;
	}
	const next = list.slice();
	next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
	return next;
};

export const newRuleId = ( rules ) => {
	const taken = new Set( rules.map( ( r ) => r.id ) );
	let id;
	do {
		id = Math.random().toString( 36 ).slice( 2, 10 );
	} while ( ! id || taken.has( id ) );
	return id;
};

// Operators depend on column settings (a Custom field shown as Number differs from Text).
export const operatorsFor = ( column, typeInfo ) => {
	let ops = typeInfo?.filter_operators || [];
	Object.entries( typeInfo?.filter_operators_by_setting || {} ).forEach( ( [ name, byValue ] ) => {
		const field = ( typeInfo.settings || [] ).find( ( f ) => f.name === name );
		const value = column?.settings?.[ name ] ?? field?.default ?? '';
		if ( byValue[ value ] ) {
			ops = byValue[ value ];
		}
	} );
	return ops;
};
