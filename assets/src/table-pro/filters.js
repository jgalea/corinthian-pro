import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createRoot, useState, useRef, useEffect } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Dropdown,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { aiEnabled } from './ai';

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

function describeRule( rule, columns ) {
	const column = findColumn( columns, rule.column );
	const operator = findOp( column, rule.op );
	if ( ! column || ! operator ) {
		return '';
	}
	const options = operator.options || {};
	const show = ( v ) => options[ v ] ?? v;
	let value = '';
	if ( operator.input !== 'none' ) {
		value = Array.isArray( rule.value )
			? rule.value
					.map( show )
					.join( operator.op === 'between' ? ' – ' : ', ' )
			: show( rule.value );
	}
	return [ column.label, operator.label, value ]
		.filter( ( p ) => p !== '' )
		.join( ' ' );
}

function navigate( filter ) {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'paged' );
	if ( filter && filter.rules.length ) {
		url.searchParams.set( filterConfig.param, JSON.stringify( filter ) );
	} else {
		url.searchParams.delete( filterConfig.param );
	}
	window.location.assign( url.toString() );
}

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
					type="number"
					label={ __( 'From', 'corinthian-pro' ) }
					hideLabelFromVision
					value={ from }
					onChange={ ( v ) => onChange( [ v, to ] ) }
				/>
				<span aria-hidden="true">–</span>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					label={ __( 'To', 'corinthian-pro' ) }
					hideLabelFromVision
					value={ to }
					onChange={ ( v ) => onChange( [ from, v ] ) }
				/>
			</div>
		);
	}
	if ( operator.input === 'select' ) {
		const options = optionList( operator );
		if ( isMulti( operator.op ) ) {
			const chosen = arr( rule.value );
			return (
				<fieldset className="corinthian-filter__multi">
					<legend className="screen-reader-text">{ label }</legend>
					{ options.map( ( o ) => (
						<CheckboxControl
							key={ o.value }
							__nextHasNoMarginBottom
							label={ o.label }
							checked={ chosen.includes( o.value ) }
							onChange={ ( on ) =>
								onChange(
									on
										? [ ...chosen, o.value ]
										: chosen.filter(
												( v ) => v !== o.value
										  )
								)
							}
						/>
					) ) }
				</fieldset>
			);
		}
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
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type={ operator.input }
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
		<li className="corinthian-filter__rule">
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

function SaveSegment( { draft, columns, canShare, onSaved } ) {
	const [ name, setName ] = useState( '' );
	const [ shared, setShared ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const filter = cleanFilter( draft, columns );

	const submit = ( event ) => {
		event.preventDefault();
		if ( ! name.trim() || ! filter.rules.length ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `/corinthian/v1/sets/${ config.set }/segments`,
			method: 'POST',
			data: { name: name.trim(), filter, shared: canShare && shared },
		} )
			.then( ( segment ) => {
				setName( '' );
				setShared( false );
				onSaved( segment );
			} )
			.catch( ( e ) =>
				setError(
					e?.message ||
						__(
							'The segment could not be saved.',
							'corinthian-pro'
						)
				)
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<form className="corinthian-filter__save" onSubmit={ submit }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Segment name', 'corinthian-pro' ) }
				value={ name }
				onChange={ setName }
			/>
			{ canShare && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Share with everyone', 'corinthian-pro' ) }
					checked={ shared }
					onChange={ setShared }
				/>
			) }
			<Button
				variant="secondary"
				type="submit"
				isBusy={ busy }
				accessibleWhenDisabled
				disabled={ busy || ! name.trim() || ! filter.rules.length }
			>
				{ __( 'Save as segment', 'corinthian-pro' ) }
			</Button>
			{ error && (
				<p className="corinthian-filter__error" role="alert">
					{ error }
				</p>
			) }
		</form>
	);
}

function SegmentRow( { segment, onApply, onDelete, canDelete } ) {
	return (
		<li className="corinthian-filter__segment">
			<Button
				className="corinthian-filter__segment-apply"
				onClick={ onApply }
			>
				{ segment.name }
			</Button>
			{ canDelete && (
				<Button
					icon="trash"
					label={ sprintf(
						/* translators: %s: segment name */ __(
							'Delete segment %s',
							'corinthian-pro'
						),
						segment.name
					) }
					onClick={ onDelete }
				/>
			) }
		</li>
	);
}

function SegmentGroup( { title, segments, canDelete, onApply, onDelete } ) {
	if ( ! segments.length ) {
		return null;
	}
	return (
		<section className="corinthian-filter__group">
			<h3>{ title }</h3>
			<ul>
				{ segments.map( ( s ) => (
					<SegmentRow
						key={ s.id }
						segment={ s }
						canDelete={ canDelete }
						onApply={ () => onApply( s ) }
						onDelete={ () => onDelete( s ) }
					/>
				) ) }
			</ul>
		</section>
	);
}

function App() {
	const { columns, current, canShare } = filterConfig;
	const [ segments, setSegments ] = useState( arr( filterConfig.segments ) );
	const [ open, setOpen ] = useState( false );
	const [ draft, setDraft ] = useState( () => toDraft( current, columns ) );
	const [ pendingDelete, setPendingDelete ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );
	const [ deleteError, setDeleteError ] = useState( '' );
	const toggleRef = useRef();

	const active = arr( current?.rules ).filter( ( r ) =>
		findOp( findColumn( columns, r.column ), r.op )
	);
	const count = active.length;
	const shared = segments.filter( ( s ) => s.shared );
	const personal = segments.filter( ( s ) => ! s.shared );

	useEffect( () => {
		if ( open ) {
			setDraft( toDraft( current, columns ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional
	}, [ open ] );

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
		navigate( { match: current.match, rules } );
	};

	const confirmDelete = () => {
		setDeleting( true );
		setDeleteError( '' );
		apiFetch( {
			path: `/corinthian/v1/sets/${ config.set }/segments/${ pendingDelete.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setSegments( ( list ) =>
					list.filter( ( s ) => s.id !== pendingDelete.id )
				);
				setPendingDelete( null );
			} )
			.catch( ( e ) =>
				setDeleteError(
					e?.message ||
						__(
							'The segment could not be deleted.',
							'corinthian-pro'
						)
				)
			)
			.finally( () => setDeleting( false ) );
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
				<Dropdown
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							className="corinthian-filter__segments-toggle"
							variant="tertiary"
							aria-expanded={ isOpen }
							aria-haspopup="true"
							onClick={ onToggle }
						>
							{ __( 'Segments', 'corinthian-pro' ) }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<div className="corinthian-filter__segments">
							{ ! segments.length && (
								<p className="corinthian-filter__empty">
									{ __(
										'No saved segments.',
										'corinthian-pro'
									) }
								</p>
							) }
							<SegmentGroup
								title={ __(
									'Shared segments',
									'corinthian-pro'
								) }
								segments={ shared }
								canDelete={ canShare }
								onApply={ ( s ) => navigate( s.filter ) }
								onDelete={ ( s ) => {
									onClose();
									setPendingDelete( s );
								} }
							/>
							<SegmentGroup
								title={ __( 'My segments', 'corinthian-pro' ) }
								segments={ personal }
								canDelete
								onApply={ ( s ) => navigate( s.filter ) }
								onDelete={ ( s ) => {
									onClose();
									setPendingDelete( s );
								} }
							/>
						</div>
					) }
				/>
				{ active.length > 0 && (
					<ul
						className="corinthian-filter__chips"
						aria-label={ __( 'Active filters', 'corinthian-pro' ) }
					>
						{ active.map( ( rule, i ) => {
							const text = describeRule( rule, columns );
							return (
								<li
									key={ i }
									className="corinthian-filter__chip"
								>
									<span>{ text }</span>
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
				) }
			</div>

			{ open && (
				// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- form-level Escape handler
				<div
					id="corinthian-filter-panel"
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
								onClick={ () => navigate( null ) }
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
						draft={ draft }
						columns={ columns }
						canShare={ canShare }
						onSaved={ ( segment ) =>
							setSegments( ( list ) => [ ...list, segment ] )
						}
					/>
				</div>
			) }

			{ pendingDelete && (
				<Modal
					title={ __( 'Delete segment', 'corinthian-pro' ) }
					size="small"
					onRequestClose={ () =>
						! deleting && setPendingDelete( null )
					}
				>
					<p>
						{ sprintf(
							/* translators: %s: segment name */ __(
								'Delete the segment "%s"?',
								'corinthian-pro'
							),
							pendingDelete.name
						) }
					</p>
					{ deleteError && (
						<p className="corinthian-filter__error" role="alert">
							{ deleteError }
						</p>
					) }
					<div className="corinthian-filter__actions">
						<span className="corinthian-filter__spacer" />
						<Button
							variant="tertiary"
							onClick={ () => setPendingDelete( null ) }
							disabled={ deleting }
							accessibleWhenDisabled
						>
							{ __( 'Cancel', 'corinthian-pro' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ deleting }
							onClick={ confirmDelete }
							disabled={ deleting }
							accessibleWhenDisabled
						>
							{ __( 'Delete', 'corinthian-pro' ) }
						</Button>
					</div>
				</Modal>
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
	createRoot( container ).render( <App /> );
}
