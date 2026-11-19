import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { Icon, check, chevronDown, closeSmall } from '@wordpress/icons';
import { optionEntries } from './field-input';

const config = window.corinthianTable;

const PER_PAGE = 20;
const DEBOUNCE_MS = 250;
const SPINNER_DELAY_MS = 200;

let idSeed = 0;

const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

const matches = ( label, query ) =>
	label.toLocaleLowerCase().includes( query.trim().toLocaleLowerCase() );

/**
 * Inline options in display order. A JSON object loses the server's order
 * for numeric keys (user and term ids), so those are sorted by label.
 * @param {*} operator
 */
function inlineOptions( operator ) {
	const list = optionEntries( operator );
	if ( ! list.length || ! list.every( ( o ) => /^\d+$/.test( o.value ) ) ) {
		return list;
	}
	return [ ...list ].sort( ( a, b ) =>
		a.label.localeCompare( b.label, undefined, {
			numeric: true,
			sensitivity: 'base',
		} )
	);
}

function fetchValues( column, params, signal ) {
	const query = new URLSearchParams( { column } );
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( Array.isArray( value ) ) {
			value.forEach( ( v ) => query.append( `${ key }[]`, v ) );
		} else {
			query.set( key, String( value ) );
		}
	} );
	return apiFetch( {
		path: `/corinthian/v1/sets/${ config.set }/filter-values?${ query }`,
		signal,
	} );
}

/**
 * Pages of values from the server for the current query. Typing waits for a
 * pause, and an answer to an older query is dropped.
 * @param {*} column
 * @param {*} query
 * @param {*} enabled
 */
function useServerValues( column, query, enabled ) {
	const [ state, setState ] = useState( {
		items: [],
		page: 0,
		more: false,
		status: 'idle',
		query: null,
	} );
	const latest = useRef( 0 );
	const controller = useRef( null );

	const load = ( q, page ) => {
		controller.current?.abort();
		const abort = new window.AbortController();
		controller.current = abort;
		const id = ++latest.current;
		setState( ( s ) => ( { ...s, status: 'loading' } ) );
		fetchValues(
			column,
			{ search: q, page, per_page: PER_PAGE },
			abort.signal
		)
			.then( ( r ) => {
				if ( id !== latest.current ) {
					return;
				}
				setState( ( s ) => ( {
					items:
						page === 1
							? arr( r?.items )
							: [ ...s.items, ...arr( r?.items ) ],
					page,
					more: !! r?.more,
					status: 'idle',
					query: q,
				} ) );
			} )
			.catch( ( e ) => {
				if ( id !== latest.current || e?.name === 'AbortError' ) {
					return;
				}
				setState( ( s ) => ( {
					...s,
					status: 'error',
					failedPage: page,
					failedQuery: q,
				} ) );
			} );
	};

	useEffect( () => {
		if ( ! enabled || ( state.query === query && state.page > 0 ) ) {
			return;
		}
		const first = state.page === 0 && query === '';
		const timer = setTimeout(
			() => load( query, 1 ),
			first ? 0 : DEBOUNCE_MS
		);
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- refetch only when the query or open state changes
	}, [ enabled, query ] );

	useEffect( () => () => controller.current?.abort(), [] );

	const stale = state.query !== query;
	return {
		...state,
		stale,
		loadMore: () => {
			if ( state.more && state.status !== 'loading' && ! stale ) {
				load( state.query, state.page + 1 );
			}
		},
		retry: () =>
			load(
				state.failedQuery ?? query,
				state.failedQuery === query ? state.failedPage || 1 : 1
			),
	};
}

/**
 * Labels for picked values that the inline options don't hold, looked up once.
 * @param {*} column
 * @param {*} values
 * @param {*} known
 * @param {*} enabled
 */
function useMissingLabels( column, values, known, enabled ) {
	const [ found, setFound ] = useState( {} );
	const asked = useRef( new Set() );
	const missing = values.filter(
		( v ) =>
			known[ v ] === undefined &&
			found[ v ] === undefined &&
			! asked.current.has( v )
	);
	const key = missing.join( '\u0000' );

	useEffect( () => {
		if ( ! enabled || ! missing.length ) {
			return;
		}
		missing.forEach( ( v ) => asked.current.add( v ) );
		fetchValues( column, { include: missing } )
			.then( ( r ) => {
				const next = {};
				arr( r?.items ).forEach( ( item ) => {
					next[ item.value ] = item.label;
				} );
				setFound( ( f ) => ( { ...f, ...next } ) );
			} )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- keyed on the missing values
	}, [ enabled, key ] );

	return found;
}

function useDelayed( on, ms ) {
	const [ shown, setShown ] = useState( false );
	useEffect( () => {
		if ( ! on ) {
			setShown( false );
			return;
		}
		const timer = setTimeout( () => setShown( true ), ms );
		return () => clearTimeout( timer );
	}, [ on, ms ] );
	return shown;
}

/**
 * Combobox for filter values (ARIA 1.2 combobox with a listbox popup).
 *
 * With `multiple`, picked values show as removable tokens and the list
 * toggles them. Without it the input is free text and the list only
 * suggests values. Columns flagged `search` page through the
 * filter-values endpoint; others filter their inline options.
 * @param {*} root0
 * @param {*} root0.column
 * @param {*} root0.operator
 * @param {*} root0.label
 * @param {*} root0.value
 * @param {*} root0.onChange
 * @param {*} root0.multiple
 */
export function ValuePicker( {
	column,
	operator,
	label,
	value,
	onChange,
	multiple = false,
} ) {
	const [ ids ] = useState( () => {
		const n = ++idSeed;
		return {
			input: `corinthian-picker-${ n }`,
			list: `corinthian-picker-${ n }-list`,
		};
	} );
	const remote = !! operator.search;
	const chosen = multiple ? arr( value ).map( String ) : [];
	const [ typed, setTyped ] = useState( '' );
	const query = multiple ? typed : String( value ?? '' );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ learned, setLearned ] = useState( {} );
	const inputRef = useRef();
	const listRef = useRef();

	const inline = inlineOptions( operator );
	const known = Object.fromEntries(
		inline.map( ( o ) => [ o.value, o.label ] )
	);
	const server = useServerValues( column, query, remote && open );
	const lookedUp = useMissingLabels(
		column,
		chosen,
		{ ...known, ...learned },
		remote
	);
	const labelOf = ( v ) => learned[ v ] ?? known[ v ] ?? lookedUp[ v ] ?? v;

	const suggestOnly = ! multiple;
	const found = remote
		? server.items
		: inline.filter( ( o ) => matches( o.label, query ) );
	// A suggestion identical to what's typed has nothing left to offer.
	const items = suggestOnly
		? found.filter( ( o ) => o.value !== query )
		: found;
	const loading = remote && ( server.status === 'loading' || server.stale );
	const spinner = useDelayed( loading, SPINNER_DELAY_MS );
	const failed = remote && server.status === 'error';
	const showPopup = open && ( ! suggestOnly || items.length > 0 );

	useEffect( () => {
		setActive( -1 );
	}, [ query, open ] );

	useEffect( () => {
		if ( active < 0 || ! listRef.current ) {
			return;
		}
		listRef.current
			.querySelector( `#${ ids.list }-${ active }` )
			?.scrollIntoView( { block: 'nearest' } );
	}, [ active, ids.list ] );

	useEffect( () => {
		if ( ! open || ! multiple || loading || failed ) {
			return;
		}
		speak(
			items.length
				? sprintf(
						/* translators: %d: number of values listed */ _n(
							'%d value listed.',
							'%d values listed.',
							items.length,
							'corinthian-pro'
						),
						items.length
				  )
				: __( 'No matching values.', 'corinthian-pro' )
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- announce once per result set
	}, [ open, loading, items.length ] );

	const remember = ( item ) =>
		setLearned( ( l ) => ( { ...l, [ item.value ]: item.label } ) );

	const remove = ( v ) => {
		onChange( chosen.filter( ( c ) => c !== v ) );
		speak(
			sprintf(
				/* translators: %s: value label */ __(
					'Removed %s.',
					'corinthian-pro'
				),
				labelOf( v )
			)
		);
	};

	const pick = ( item ) => {
		remember( item );
		if ( ! multiple ) {
			onChange( item.value );
			setOpen( false );
			return;
		}
		onChange(
			chosen.includes( item.value )
				? chosen.filter( ( c ) => c !== item.value )
				: [ ...chosen, item.value ]
		);
	};

	const moveTo = ( next ) => {
		if ( next >= items.length - 1 && remote ) {
			server.loadMore();
		}
		setActive( Math.max( 0, Math.min( next, items.length - 1 ) ) );
	};

	const onKeyDown = ( e ) => {
		switch ( e.key ) {
			case 'ArrowDown':
				e.preventDefault();
				if ( ! open ) {
					setOpen( true );
				} else if ( items.length ) {
					moveTo( active + 1 );
				}
				break;
			case 'ArrowUp':
				e.preventDefault();
				if ( open && items.length ) {
					moveTo( active < 0 ? items.length - 1 : active - 1 );
				}
				break;
			case 'Enter':
				if ( showPopup && active >= 0 && items[ active ] ) {
					e.preventDefault();
					pick( items[ active ] );
				}
				break;
			case 'Escape':
				if ( showPopup ) {
					e.preventDefault();
					e.stopPropagation();
					setOpen( false );
				}
				break;
			case 'Backspace':
				if ( multiple && typed === '' && chosen.length ) {
					e.preventDefault();
					remove( chosen[ chosen.length - 1 ] );
				}
				break;
			case 'Tab':
				setOpen( false );
				break;
		}
	};

	const onScroll = ( e ) => {
		const el = e.currentTarget;
		if ( el.scrollTop + el.clientHeight >= el.scrollHeight - 48 ) {
			server.loadMore();
		}
	};

	let status = null;
	if ( failed ) {
		status = (
			<>
				<span>
					{ __(
						'The values could not be loaded.',
						'corinthian-pro'
					) }
				</span>
				<Button
					variant="link"
					onMouseDown={ ( e ) => e.preventDefault() }
					onClick={ server.retry }
				>
					{ __( 'Retry', 'corinthian-pro' ) }
				</Button>
			</>
		);
	} else if ( spinner ) {
		status = (
			<>
				<Spinner />
				<span>{ __( 'Loading…', 'corinthian-pro' ) }</span>
			</>
		);
	} else if ( ! loading && ! items.length && ! suggestOnly ) {
		status = query.trim()
			? sprintf(
					/* translators: %s: what was typed */ __(
						'No values match “%s”.',
						'corinthian-pro'
					),
					query.trim()
			  )
			: __( 'There are no values to choose from yet.', 'corinthian-pro' );
	} else if ( remote && server.more && ! loading && ! suggestOnly ) {
		status = (
			<Button
				variant="link"
				onMouseDown={ ( e ) => e.preventDefault() }
				onClick={ server.loadMore }
			>
				{ __( 'Show more', 'corinthian-pro' ) }
			</Button>
		);
	}

	const activeId =
		showPopup && active >= 0 && items[ active ]
			? `${ ids.list }-${ active }`
			: undefined;

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- blur bookkeeping for the whole widget
		<div
			className={ `corinthian-picker${
				multiple ? ' corinthian-picker--multiple' : ''
			}` }
			onBlur={ ( e ) => {
				if ( ! e.currentTarget.contains( e.relatedTarget ) ) {
					setOpen( false );
				}
			} }
		>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions -- pointer shortcut to the input, which has its own keys */ }
			<div
				className="corinthian-picker__field"
				onMouseDown={ ( e ) => {
					if ( e.target === e.currentTarget ) {
						e.preventDefault();
						inputRef.current?.focus();
						setOpen( true );
					}
				} }
			>
				{ chosen.length > 0 && (
					<ul
						className="corinthian-picker__tokens"
						aria-label={ __( 'Chosen values', 'corinthian-pro' ) }
					>
						{ chosen.map( ( v ) => (
							<li key={ v } className="corinthian-picker__token">
								<span className="corinthian-picker__token-text">
									{ labelOf( v ) }
								</span>
								<button
									type="button"
									className="corinthian-picker__remove"
									aria-label={ sprintf(
										/* translators: %s: value label */ __(
											'Remove %s',
											'corinthian-pro'
										),
										labelOf( v )
									) }
									onClick={ () => {
										remove( v );
										inputRef.current?.focus();
									} }
								>
									<Icon icon={ closeSmall } size={ 18 } />
								</button>
							</li>
						) ) }
					</ul>
				) }
				<input
					ref={ inputRef }
					id={ ids.input }
					className="corinthian-picker__input"
					type="text"
					role="combobox"
					autoComplete="off"
					spellCheck={ false }
					aria-label={ label }
					aria-autocomplete="list"
					aria-expanded={ showPopup }
					aria-controls={ ids.list }
					aria-activedescendant={ activeId }
					placeholder={
						multiple && ! chosen.length
							? __( 'Search values', 'corinthian-pro' )
							: undefined
					}
					value={ query }
					onChange={ ( e ) => {
						if ( multiple ) {
							setTyped( e.target.value );
						} else {
							onChange( e.target.value );
						}
						setOpen( true );
					} }
					onClick={ () => setOpen( true ) }
					onKeyDown={ onKeyDown }
				/>
				{ multiple && (
					<Icon
						className="corinthian-picker__chevron"
						icon={ chevronDown }
						size={ 20 }
					/>
				) }
			</div>
			<div className="corinthian-picker__popup" hidden={ ! showPopup }>
				<ul
					ref={ listRef }
					id={ ids.list }
					role="listbox"
					aria-label={ __( 'Values', 'corinthian-pro' ) }
					aria-multiselectable={ multiple || undefined }
					aria-busy={ loading || undefined }
					className="corinthian-picker__list"
					onScroll={ remote ? onScroll : undefined }
				>
					{ showPopup &&
						items.map( ( item, i ) => {
							const selected = multiple
								? chosen.includes( item.value )
								: i === active;
							return (
								// eslint-disable-next-line jsx-a11y/click-events-have-key-events -- keys are handled on the combobox input
								<li
									key={ item.value }
									id={ `${ ids.list }-${ i }` }
									role="option"
									aria-selected={ selected }
									className={ [
										'corinthian-picker__option',
										i === active ? 'is-active' : '',
										multiple && selected
											? 'is-selected'
											: '',
									]
										.filter( Boolean )
										.join( ' ' ) }
									onMouseDown={ ( e ) => e.preventDefault() }
									onMouseMove={ () =>
										i !== active && setActive( i )
									}
									onClick={ () => pick( item ) }
								>
									{ multiple && (
										<span
											className="corinthian-picker__check"
											aria-hidden="true"
										>
											{ selected && (
												<Icon
													icon={ check }
													size={ 20 }
												/>
											) }
										</span>
									) }
									<span className="corinthian-picker__label">
										{ item.label }
									</span>
								</li>
							);
						} ) }
				</ul>
				{ showPopup && status && (
					<div className="corinthian-picker__status">{ status }</div>
				) }
			</div>
		</div>
	);
}
