import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	createRoot,
	flushSync,
	useState,
	useRef,
	useEffect,
} from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { aiEnabled } from './ai';
import { ValuePicker } from './value-picker';
import {
	SaveSegment,
	SegmentToast,
	SegmentsMenu,
	holdDefault,
	useSegments,
} from './segments';

const config = window.corinthianTable;
const filterConfig = config?.filter;

let keySeed = 0;
const nextKey = () => ++keySeed;

const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

function findColumn( columns, id ) {
	return columns.find( ( c ) => c.id === id );
}

function findOp( column, op ) {
	return column?.operators?.find( ( o ) => o.op === op );
}

function optionList( operator ) {
	return Object.entries( operator?.options || {} ).map(
		( [ value, label ] ) => ( { value, label } )
	);
}

function isMulti( op ) {
	return op === 'in' || op === 'not_in';
}

function blankValue( operator ) {
	if ( ! operator || operator.input === 'none' ) {
		return null;
	}
	if ( isMulti( operator.op ) ) {
		return [];
	}
	return operator.op === 'between' ? [ '', '' ] : '';
}

function blankRule( columns ) {
	const column = columns[ 0 ];
	const operator = column?.operators?.[ 0 ];
	return {
		key: nextKey(),
		column: column?.id || '',
		op: operator?.op || '',
		value: blankValue( operator ),
	};
}

function toDraft( filter, columns ) {
	const rules = arr( filter?.rules )
		.filter( ( r ) => findOp( findColumn( columns, r.column ), r.op ) )
		.map( ( r ) => ( {
			key: nextKey(),
			column: r.column,
			op: r.op,
			value: r.value ?? null,
		} ) );
	return {
		match: filter?.match === 'any' ? 'any' : 'all',
		rules: rules.length ? rules : [ blankRule( columns ) ],
	};
}

/**
 * Rules that are complete enough to send, in the backend's shape.
 * @param {*} draft
 * @param {*} columns
 */
function cleanFilter( draft, columns ) {
	const rules = [];
	draft.rules.forEach( ( rule ) => {
		const operator = findOp( findColumn( columns, rule.column ), rule.op );
		if ( ! operator ) {
			return;
		}
		if ( operator.input === 'none' ) {
			rules.push( { column: rule.column, op: rule.op } );
			return;
		}
		let value = rule.value;
		if ( operator.op === 'between' ) {
			value = arr( value ).slice( 0, 2 );
			if (
				value.length < 2 ||
				value.some( ( v ) => v === '' || v === null )
			) {
				return;
			}
		} else if ( isMulti( operator.op ) ) {
			value = arr( value );
			if ( ! value.length ) {
				return;
			}
		} else if ( value === '' || value === null || value === undefined ) {
			return;
		}
		rules.push( { column: rule.column, op: rule.op, value } );
	} );
	return { match: draft.match, rules };
}

const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

function formatDay( value ) {
	const m = ISO_DAY.exec( String( value ) );
	if ( ! m ) {
		return String( value );
	}
	const date = new Date(
		Number( m[ 1 ] ),
		Number( m[ 2 ] ) - 1,
		Number( m[ 3 ] )
	);
	const locale = document.documentElement.lang || undefined;
	try {
		return new Intl.DateTimeFormat( locale, {
			dateStyle: 'medium',
		} ).format( date );
	} catch ( e ) {
		return new Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
		} ).format( date );
	}
}

function describeValue( rule, operator ) {
	const options = operator.options || {};
	const show = ( v ) =>
		operator.input === 'date' ? formatDay( v ) : options[ v ] ?? v;
	const days = Number( rule.value );
	switch ( Number.isFinite( days ) && rule.value !== '' ? rule.op : '' ) {
		case 'within_days':
			return sprintf(
				/* translators: %d: number of days */ _n(
					'in the last %d day',
					'in the last %d days',
					days,
					'corinthian-pro'
				),
				days
			);
		case 'older_than_days':
			return sprintf(
				/* translators: %d: number of days */ _n(
					'older than %d day',
					'older than %d days',
					days,
					'corinthian-pro'
				),
				days
			);
		case 'next_days':
			return sprintf(
				/* translators: %d: number of days */ _n(
					'in the next %d day',
					'in the next %d days',
					days,
					'corinthian-pro'
				),
				days
			);
	}
	if ( operator.input === 'none' ) {
		return operator.label;
	}
	if ( rule.op === 'between' ) {
		const [ from = '', to = '' ] = arr( rule.value );
		return sprintf(
			/* translators: 1: start of a range, 2: end of a range */ __(
				'between %1$s and %2$s',
				'corinthian-pro'
			),
			show( from ),
			show( to )
		);
	}
	const value = Array.isArray( rule.value )
		? rule.value.map( show ).join( ', ' )
		: show( rule.value );
	return `${ operator.label } ${ value }`;
}

function describeRule( rule, columns ) {
	const column = findColumn( columns, rule.column );
	const operator = findOp( column, rule.op );
	if ( ! column || ! operator ) {
		return '';
	}
	return `${ column.label } ${ describeValue( rule, operator ) }`;
}

const SCROLL_KEY = 'corinthianFilterScroll';

function holdScroll() {
	try {
		window.sessionStorage.setItem(
			SCROLL_KEY,
			JSON.stringify( {
				y: window.scrollY,
				path: window.location.pathname,
				at: Date.now(),
			} )
		);
	} catch ( e ) {}
}

function restoreScroll() {
	try {
		const saved = JSON.parse(
			window.sessionStorage.getItem( SCROLL_KEY ) || 'null'
		);
		window.sessionStorage.removeItem( SCROLL_KEY );
		if (
			saved &&
			saved.path === window.location.pathname &&
			Date.now() - saved.at < 15000
		) {
			const jump = () => window.scrollTo( 0, saved.y );
			jump();
			if ( document.readyState !== 'complete' ) {
				window.addEventListener( 'load', jump, { once: true } );
			}
		}
	} catch ( e ) {}
}

function navigate( filter, keepScroll = false ) {
	if ( keepScroll ) {
		holdScroll();
	}
	const url = new URL( window.location.href );
	url.searchParams.delete( 'paged' );
	if ( filter && filter.rules.length ) {
		url.searchParams.set( filterConfig.param, JSON.stringify( filter ) );
	} else {
		url.searchParams.delete( filterConfig.param );
	}
	holdDefault( url, ! filter );
	window.location.assign( url.toString() );
}

const DAY_OPS = new Set( [ 'within_days', 'older_than_days', 'next_days' ] );

function ValueInput( { rule, operator, onChange, label } ) {
	if ( ! operator || operator.input === 'none' ) {
		return null;
	}
	if ( operator.op === 'between' ) {
		const [ from = '', to = '' ] = arr( rule.value );
		return (
			<div className="corinthian-filter__between">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type={ operator.input === 'date' ? 'date' : 'number' }
					label={ __( 'From', 'corinthian-pro' ) }
					hideLabelFromVision
					value={ from }
					onChange={ ( v ) => onChange( [ v, to ] ) }
				/>
				<span aria-hidden="true">–</span>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type={ operator.input === 'date' ? 'date' : 'number' }
					label={ __( 'To', 'corinthian-pro' ) }
					hideLabelFromVision
					value={ to }
					onChange={ ( v ) => onChange( [ from, v ] ) }
				/>
			</div>
		);
	}
	if ( operator.input === 'select' ) {
		if ( isMulti( operator.op ) ) {
			return (
				<ValuePicker
					key={ rule.column }
					multiple
					column={ rule.column }
					operator={ operator }
					label={ label }
					value={ rule.value }
					onChange={ onChange }
				/>
			);
		}
		const options = optionList( operator );
		return (
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				hideLabelFromVision
				value={ rule.value ?? '' }
				options={ [
					{
						value: '',
						label: __( 'Choose…', 'corinthian-pro' ),
						disabled: true,
					},
					...options,
				] }
				onChange={ onChange }
			/>
		);
	}
	if ( operator.search && operator.input === 'text' ) {
		return (
			<ValuePicker
				key={ rule.column }
				column={ rule.column }
				operator={ operator }
				label={ label }
				value={ rule.value }
				onChange={ onChange }
			/>
		);
	}
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type={ operator.input }
			min={
				operator.input === 'number' && DAY_OPS.has( operator.op )
					? 0
					: undefined
			}
			label={ label }
			hideLabelFromVision
			value={ rule.value ?? '' }
			onChange={ onChange }
		/>
	);
}

function RuleRow( { rule, columns, onChange, onRemove, index } ) {
	const column = findColumn( columns, rule.column );
	const operator = findOp( column, rule.op );
	const n = index + 1;
	return (
		<li className="corinthian-filter__rule" data-rule-key={ rule.key }>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ sprintf(
					/* translators: %d: rule number */ __(
						'Column, rule %d',
						'corinthian-pro'
					),
					n
				) }
				hideLabelFromVision
				value={ rule.column }
				options={ columns.map( ( c ) => ( {
					value: c.id,
					label: c.label,
				} ) ) }
				onChange={ ( id ) => {
					const op = findColumn( columns, id )?.operators?.[ 0 ];
					onChange( {
						...rule,
						column: id,
						op: op?.op || '',
						value: blankValue( op ),
					} );
				} }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ sprintf(
					/* translators: %d: rule number */ __(
						'Operator, rule %d',
						'corinthian-pro'
					),
					n
				) }
				hideLabelFromVision
				value={ rule.op }
				options={ ( column?.operators || [] ).map( ( o ) => ( {
					value: o.op,
					label: o.label,
				} ) ) }
				onChange={ ( op ) => {
					const next = findOp( column, op );
					const same =
						next?.input === operator?.input &&
						isMulti( op ) === isMulti( rule.op ) &&
						( op === 'between' ) === ( rule.op === 'between' ) &&
						next?.input !== 'none';
					onChange( {
						...rule,
						op,
						value: same ? rule.value : blankValue( next ),
					} );
				} }
			/>
			<div className="corinthian-filter__value">
				<ValueInput
					rule={ rule }
					operator={ operator }
					label={ sprintf(
						/* translators: %d: rule number */ __(
							'Value, rule %d',
							'corinthian-pro'
						),
						n
					) }
					onChange={ ( value ) => onChange( { ...rule, value } ) }
				/>
			</div>
			<Button
				className="corinthian-filter__remove"
				icon="no-alt"
				label={ sprintf(
					/* translators: %d: rule number */ __(
						'Remove rule %d',
						'corinthian-pro'
					),
					n
				) }
				onClick={ onRemove }
			/>
		</li>
	);
}

const DROP_REASONS = () => ( {
	unknown_key: __( 'not something a filter can use', 'corinthian-pro' ),
	malformed: __( 'the rule was incomplete', 'corinthian-pro' ),
	too_many_rules: __( 'there were too many rules', 'corinthian-pro' ),
	unknown_rule_keys: __( 'extra settings were ignored', 'corinthian-pro' ),
	unknown_column: __( "that column can't be filtered", 'corinthian-pro' ),
	unsupported_op: __(
		"that comparison isn't available for the column",
		'corinthian-pro'
	),
	invalid_value: __( "the value doesn't fit the column", 'corinthian-pro' ),
} );

function describeDropped( item, columns ) {
	const rule = item?.rule;
	let what = item?.key || '';
	if ( rule && typeof rule === 'object' ) {
		what =
			describeRule( rule, columns ) ||
			[
				findColumn( columns, rule.column )?.label || rule.column,
				rule.op,
			]
				.filter( Boolean )
				.join( ' ' );
	}
	const reason = DROP_REASONS()[ item?.reason ] || '';
	return [ what, reason ].filter( Boolean ).join( ': ' );
}

function DescribeFilter( { columns, onLoad } ) {
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState( null );

	const submit = ( event ) => {
		event.preventDefault();
		if ( busy || ! text.trim() ) {
			return;
		}
		setBusy( true );
		setError( '' );
		setResult( null );
		apiFetch( {
			path: '/corinthian/v1/ai/filter',
			method: 'POST',
			data: { screen: config.screen, set: config.set, text: text.trim() },
		} )
			.then( ( r ) => {
				onLoad( r.filter );
				setResult( {
					rules: arr( r.filter?.rules ).length,
					dropped: arr( r.dropped ),
				} );
			} )
			.catch( ( e ) =>
				setError(
					e?.message ||
						__( 'The filter could not be built.', 'corinthian-pro' )
				)
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<form className="corinthian-filter__describe" onSubmit={ submit }>
			<div className="corinthian-filter__describe-row">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Describe a filter', 'corinthian-pro' ) }
					placeholder={ __(
						'Posts by Sam that are still drafts',
						'corinthian-pro'
					) }
					value={ text }
					onChange={ setText }
				/>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={ busy || ! text.trim() }
				>
					{ __( 'Build filter', 'corinthian-pro' ) }
				</Button>
			</div>
			<div aria-live="polite">
				{ result && (
					<Notice
						status={ result.dropped.length ? 'warning' : 'success' }
						isDismissible={ false }
					>
						{ result.rules
							? __(
									'The rules are loaded below. Check them, then press Apply.',
									'corinthian-pro'
							  )
							: __(
									'No rules could be built from that description. Try naming a column and a value.',
									'corinthian-pro'
							  ) }
						{ result.dropped.length > 0 && (
							<>
								{ ' ' }
								{ __( 'Left out:', 'corinthian-pro' ) }
								<ul className="corinthian-filter__dropped">
									{ result.dropped.map( ( d, i ) => (
										<li key={ i }>
											{ describeDropped( d, columns ) }
										</li>
									) ) }
								</ul>
							</>
						) }
					</Notice>
				) }
			</div>
			{ error && (
				<p className="corinthian-filter__error" role="alert">
					{ error }
				</p>
			) }
		</form>
	);
}

function App() {
	const { columns, current } = filterConfig;
	const segments = useSegments();
	const [ open, setOpen ] = useState( false );
	const [ draft, setDraft ] = useState( () => toDraft( current, columns ) );
	const toggleRef = useRef();
	const panelRef = useRef();
	const pendingRule = useRef( null );
	const [ focusKey, setFocusKey ] = useState( null );

	const active = arr( current?.rules ).filter( ( r ) =>
		findOp( findColumn( columns, r.column ), r.op )
	);
	const count = active.length;
	const anyMode = current?.match === 'any' && count > 1;

	useEffect( () => {
		if ( open ) {
			const next = toDraft( current, columns );
			setDraft( next );
			if ( pendingRule.current !== null ) {
				setFocusKey( next.rules[ pendingRule.current ]?.key ?? null );
				pendingRule.current = null;
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional
	}, [ open ] );

	useEffect( () => {
		if ( focusKey === null ) {
			return;
		}
		const row = panelRef.current?.querySelector(
			`[data-rule-key="${ focusKey }"]`
		);
		row?.querySelector( 'select, input, button' )?.focus();
		row?.scrollIntoView( { block: 'nearest' } );
		setFocusKey( null );
	}, [ focusKey ] );

	const editChip = ( index ) => {
		if ( open ) {
			setFocusKey( draft.rules[ index ]?.key ?? null );
			return;
		}
		pendingRule.current = index;
		setOpen( true );
	};

	const updateRule = ( key, next ) =>
		setDraft( ( d ) => ( {
			...d,
			rules: d.rules.map( ( r ) => ( r.key === key ? next : r ) ),
		} ) );
	const removeRule = ( key ) =>
		setDraft( ( d ) => {
			const rules = d.rules.filter( ( r ) => r.key !== key );
			return {
				...d,
				rules: rules.length ? rules : [ blankRule( columns ) ],
			};
		} );

	const removeChip = ( index ) => {
		const rules = active.filter( ( _, i ) => i !== index );
		navigate( { match: current.match, rules }, true );
	};

	return (
		<div className="corinthian-filter">
			<div className="corinthian-filter__bar">
				<Button
					ref={ toggleRef }
					className="corinthian-filter__toggle"
					variant="secondary"
					icon="filter"
					aria-expanded={ open }
					aria-controls="corinthian-filter-panel"
					onClick={ () => setOpen( ! open ) }
				>
					{ __( 'Filter', 'corinthian-pro' ) }
					{ count > 0 && (
						<span
							className="corinthian-filter__badge"
							aria-label={ sprintf(
								/* translators: %d: number of active filters */ _n(
									'%d active filter',
									'%d active filters',
									count,
									'corinthian-pro'
								),
								count
							) }
						>
							{ count }
						</span>
					) }
				</Button>
				<SegmentsMenu store={ segments } />
				<SegmentToast store={ segments } />
			</div>
			{ count > 0 && (
				<div className="corinthian-filter__chiprow">
					{ anyMode && (
						<span
							className="corinthian-filter__match-label"
							id="corinthian-filter-match"
						>
							{ __( 'Any of:', 'corinthian-pro' ) }
						</span>
					) }
					<ul
						className="corinthian-filter__chips"
						aria-label={ __( 'Active filters', 'corinthian-pro' ) }
						aria-describedby={
							anyMode ? 'corinthian-filter-match' : undefined
						}
					>
						{ active.map( ( rule, i ) => {
							const text = describeRule( rule, columns );
							return (
								<li
									key={ i }
									className="corinthian-filter__chip"
								>
									<Button
										className="corinthian-filter__chip-edit"
										title={ __(
											'Edit this filter',
											'corinthian-pro'
										) }
										onClick={ () => editChip( i ) }
									>
										{ text }
									</Button>
									<Button
										icon="no-alt"
										size="small"
										label={ sprintf(
											/* translators: %s: filter description */ __(
												'Remove filter %s',
												'corinthian-pro'
											),
											text
										) }
										onClick={ () => removeChip( i ) }
									/>
								</li>
							);
						} ) }
					</ul>
					<Button
						variant="link"
						className="corinthian-filter__clear-all"
						onClick={ () => navigate( null, true ) }
					>
						{ __( 'Clear all', 'corinthian-pro' ) }
					</Button>
				</div>
			) }

			{ open && (
				// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- form-level Escape handler
				<div
					id="corinthian-filter-panel"
					ref={ panelRef }
					className="corinthian-filter__panel"
					role="group"
					aria-label={ __( 'Filter rules', 'corinthian-pro' ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Escape' ) {
							setOpen( false );
							toggleRef.current?.focus();
						}
					} }
				>
					{ aiEnabled() && (
						<DescribeFilter
							columns={ columns }
							onLoad={ ( filter ) =>
								setDraft( toDraft( filter, columns ) )
							}
						/>
					) }

					<fieldset className="corinthian-filter__match">
						<legend>
							{ __( 'Show rows that match', 'corinthian-pro' ) }
						</legend>
						{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- label wraps its input */ }
						<label>
							<input
								type="radio"
								name="corinthian-match"
								checked={ draft.match === 'all' }
								onChange={ () =>
									setDraft( { ...draft, match: 'all' } )
								}
							/>
							{ __( 'All rules', 'corinthian-pro' ) }
						</label>
						{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- label wraps its input */ }
						<label>
							<input
								type="radio"
								name="corinthian-match"
								checked={ draft.match === 'any' }
								onChange={ () =>
									setDraft( { ...draft, match: 'any' } )
								}
							/>
							{ __( 'Any rule', 'corinthian-pro' ) }
						</label>
					</fieldset>

					<ul className="corinthian-filter__rules">
						{ draft.rules.map( ( rule, i ) => (
							<RuleRow
								key={ rule.key }
								index={ i }
								rule={ rule }
								columns={ columns }
								onChange={ ( next ) =>
									updateRule( rule.key, next )
								}
								onRemove={ () => removeRule( rule.key ) }
							/>
						) ) }
					</ul>

					<div className="corinthian-filter__actions">
						<Button
							variant="tertiary"
							icon="plus"
							onClick={ () =>
								setDraft( {
									...draft,
									rules: [
										...draft.rules,
										blankRule( columns ),
									],
								} )
							}
						>
							{ __( 'Add rule', 'corinthian-pro' ) }
						</Button>
						<span className="corinthian-filter__spacer" />
						{ count > 0 && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => navigate( null, true ) }
							>
								{ __( 'Clear', 'corinthian-pro' ) }
							</Button>
						) }
						<Button
							variant="primary"
							onClick={ () =>
								navigate( cleanFilter( draft, columns ) )
							}
						>
							{ __( 'Apply', 'corinthian-pro' ) }
						</Button>
					</div>

					<SaveSegment
						store={ segments }
						draft={ cleanFilter( draft, columns ) }
					/>
				</div>
			) }
		</div>
	);
}

export function mountFilters( parent ) {
	if ( ! filterConfig || ! arr( filterConfig.columns ).length ) {
		return;
	}
	const table = document.querySelector( 'table.wp-list-table' );
	if ( ! table || document.getElementById( 'corinthian-filter-root' ) ) {
		return;
	}
	const container = document.createElement( 'div' );
	container.id = 'corinthian-filter-root';
	if ( parent ) {
		parent.appendChild( container );
	} else {
		// The scroll wrapper around the table must not scroll the filter bar with it.
		const anchor = table.closest( '.corinthian-scroll' ) || table;
		anchor.parentNode.insertBefore( container, anchor );
	}
	const root = createRoot( container );
	flushSync( () => root.render( <App /> ) );
	restoreScroll();
}
