import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Dropdown,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	TextControl,
} from '@wordpress/components';
import {
	Icon,
	check,
	chevronDown,
	closeSmall,
	moreVertical,
} from '@wordpress/icons';

const config = window.corinthianTable;
const filterConfig = config?.filter;

const VIEW_FIELDS = [ 'orderby', 'order', 'search', 'status' ];
const TOAST_MS = 8000;

const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
const segmentsPath = ( suffix = '' ) =>
	`/corinthian/v1/sets/${ config.set }/segments${ suffix }`;
const defaultPath = () => `/corinthian/v1/sets/${ config.set }/default-segment`;

function valueKey( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( String );
	}
	return value === null || value === undefined ? null : String( value );
}

function filterKey( filter ) {
	const rules = arr( filter?.rules ).map( ( r ) => [
		r.column,
		r.op,
		valueKey( r.value ),
	] );
	return JSON.stringify( {
		match: rules.length && filter?.match === 'any' ? 'any' : 'all',
		rules,
	} );
}

export const sameFilter = ( a, b ) => filterKey( a ) === filterKey( b );

const hasView = ( view ) =>
	[ 'orderby', 'search', 'status' ].some( ( f ) => !! view?.[ f ] );

const hasState = ( state ) =>
	arr( state.filter?.rules ).length > 0 || hasView( state );

// A null field is one the segment doesn't set (segments saved before sort, search and status were kept).
function matchesApplied( segment, applied ) {
	return (
		sameFilter( segment.filter, applied.filter ) &&
		VIEW_FIELDS.every(
			( f ) =>
				segment[ f ] === null ||
				segment[ f ] === undefined ||
				segment[ f ] === applied[ f ]
		)
	);
}

export function segmentUrl( segment ) {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'paged' );
	url.searchParams.delete( filterConfig.param );
	if ( arr( segment.filter?.rules ).length ) {
		url.searchParams.set(
			filterConfig.param,
			JSON.stringify( segment.filter )
		);
	}
	const keys = {
		orderby: 'orderby',
		order: 'order',
		search: 's',
		status: filterConfig.statusParam,
	};
	VIEW_FIELDS.forEach( ( f ) => {
		const value = segment[ f ];
		if ( ! keys[ f ] || value === null || value === undefined ) {
			return;
		}
		if ( value === '' ) {
			url.searchParams.delete( keys[ f ] );
		} else {
			url.searchParams.set( keys[ f ], value );
		}
	} );
	url.searchParams.set( filterConfig.segmentParam, segment.id );
	return url.toString();
}

/**
 * Mark a URL so a default segment isn't applied over it: a list with its
 * filters cleared must stay cleared.
 *
 * @param {URL}     url
 * @param {boolean} leaveSegment Drop the active segment too, as Clear does.
 */
export function holdDefault( url, leaveSegment ) {
	if ( leaveSegment ) {
		url.searchParams.delete( filterConfig.segmentParam );
	}
	if (
		filterConfig.defaults?.effective &&
		! url.searchParams.has( filterConfig.segmentParam )
	) {
		url.searchParams.set(
			filterConfig.segmentParam,
			filterConfig.noSegment
		);
	}
}

// The list's search and status controls submit their form as a new GET, which would drop the filter and segment.
function keepStateInForm() {
	const form = document
		.querySelector( 'table.wp-list-table' )
		?.closest( 'form' );
	if ( ! form || ( form.method || 'get' ).toLowerCase() !== 'get' ) {
		return;
	}
	const params = new URL( window.location.href ).searchParams;
	[ filterConfig.param, filterConfig.segmentParam ].forEach( ( name ) => {
		if (
			! params.has( name ) ||
			form.querySelector( `input[name="${ name }"]` )
		) {
			return;
		}
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = params.get( name );
		form.appendChild( input );
	} );
}

function viewPayload( segment ) {
	const out = {};
	VIEW_FIELDS.forEach( ( f ) => {
		if ( segment[ f ] !== null && segment[ f ] !== undefined ) {
			out[ f ] = segment[ f ];
		}
	} );
	return out;
}

const errorText = ( e, fallback ) => e?.message || fallback;

export function useSegments() {
	const [ segments, setSegments ] = useState( arr( filterConfig.segments ) );
	const [ defaults, setDefaults ] = useState( {
		shared: null,
		personal: null,
		effective: null,
		...filterConfig.defaults,
	} );
	const [ activeId, setActiveId ] = useState(
		filterConfig.activeSegment || ''
	);
	const [ toast, setToast ] = useState( null );
	const canShare = !! filterConfig.canShare;

	useEffect( keepStateInForm, [] );

	const applied = { filter: filterConfig.current, ...filterConfig.view };
	const active = segments.find( ( s ) => s.id === activeId ) || null;
	const edited = !! active && ! matchesApplied( active, applied );

	const canEdit = ( segment ) => ! segment.shared || canShare;

	const replace = ( id, next ) =>
		setSegments( ( list ) =>
			list.map( ( s ) => ( s.id === id ? next : s ) )
		);

	const patch = ( segment, changes, done ) => {
		const before = segment;
		replace( segment.id, { ...segment, ...changes } );
		return apiFetch( {
			path: segmentsPath( `/${ segment.id }` ),
			method: 'PATCH',
			data: changes,
		} )
			.then( ( saved ) => {
				replace( segment.id, saved );
				setToast( { message: done( saved ) } );
			} )
			.catch( ( e ) => {
				replace( segment.id, before );
				setToast( {
					message: errorText(
						e,
						__(
							'The segment could not be saved.',
							'corinthian-pro'
						)
					),
					tone: 'error',
					action: {
						label: __( 'Retry', 'corinthian-pro' ),
						onClick: () => patch( before, changes, done ),
					},
				} );
			} );
	};

	const rename = ( segment, name ) =>
		patch( segment, { name }, ( saved ) =>
			sprintf(
				/* translators: %s: segment name */ __(
					'Renamed to “%s”.',
					'corinthian-pro'
				),
				saved.name
			)
		);

	const updateActive = () =>
		active &&
		patch(
			active,
			{
				filter: applied.filter,
				orderby: applied.orderby,
				order: applied.order,
				search: applied.search,
				status: applied.status,
			},
			( saved ) =>
				sprintf(
					/* translators: %s: segment name */ __(
						'Updated “%s” with the current filters.',
						'corinthian-pro'
					),
					saved.name
				)
		);

	const restore = ( segment, deletion ) => {
		setToast( null );
		deletion.then( ( position ) => {
			if ( position === false ) {
				return;
			}
			setSegments( ( list ) => {
				const group = list.filter(
					( s ) => s.shared === segment.shared
				);
				const after = group[ position ];
				const index = after ? list.indexOf( after ) : -1;
				if ( index < 0 ) {
					return segment.shared
						? [
								...group,
								segment,
								...list.filter( ( s ) => ! s.shared ),
						  ]
						: [ ...list, segment ];
				}
				return [
					...list.slice( 0, index ),
					segment,
					...list.slice( index ),
				];
			} );
			apiFetch( {
				path: segmentsPath(),
				method: 'POST',
				data: {
					id: segment.id,
					name: segment.name,
					shared: segment.shared,
					filter: segment.filter,
					position,
					...viewPayload( segment ),
				},
			} )
				.then( () =>
					setToast( {
						message: sprintf(
							/* translators: %s: segment name */ __(
								'Restored “%s”.',
								'corinthian-pro'
							),
							segment.name
						),
					} )
				)
				.catch( ( e ) => {
					setSegments( ( list ) =>
						list.filter( ( s ) => s.id !== segment.id )
					);
					setToast( {
						message: errorText(
							e,
							__(
								'The segment could not be restored.',
								'corinthian-pro'
							)
						),
						tone: 'error',
					} );
				} );
		} );
	};

	const remove = ( segment ) => {
		const before = segments;
		const fallback = before
			.filter( ( s ) => s.shared === segment.shared )
			.findIndex( ( s ) => s.id === segment.id );
		setSegments( ( list ) => list.filter( ( s ) => s.id !== segment.id ) );
		const deletion = apiFetch( {
			path: segmentsPath( `/${ segment.id }` ),
			method: 'DELETE',
		} )
			.then( ( r ) =>
				Number.isInteger( r?.position ) ? r.position : fallback
			)
			.catch( ( e ) => {
				setSegments( before );
				setToast( {
					message: errorText(
						e,
						__(
							'The segment could not be deleted.',
							'corinthian-pro'
						)
					),
					tone: 'error',
				} );
				return false;
			} );
		setToast( {
			message: sprintf(
				/* translators: %s: segment name */ __(
					'Deleted “%s”.',
					'corinthian-pro'
				),
				segment.name
			),
			action: {
				label: __( 'Undo', 'corinthian-pro' ),
				onClick: () => restore( segment, deletion ),
			},
		} );
	};

	const setDefault = ( segment, scope, on ) => {
		const before = defaults;
		const next = { ...defaults, [ scope ]: on ? segment.id : null };
		setDefaults( { ...next, effective: next.personal ?? next.shared } );
		apiFetch( {
			path: on ? defaultPath() : `${ defaultPath() }?scope=${ scope }`,
			method: on ? 'PUT' : 'DELETE',
			data: on ? { id: segment.id, scope } : undefined,
		} )
			.then( ( saved ) => {
				setDefaults( saved );
				filterConfig.defaults = saved;
				let message = __(
					'The list opens unfiltered for you now.',
					'corinthian-pro'
				);
				if ( on && scope === 'shared' ) {
					message = sprintf(
						/* translators: %s: segment name */ __(
							'The list opens on “%s” for everyone without a default of their own.',
							'corinthian-pro'
						),
						segment.name
					);
				} else if ( on ) {
					message = sprintf(
						/* translators: %s: segment name */ __(
							'The list opens on “%s” for you.',
							'corinthian-pro'
						),
						segment.name
					);
				} else if ( saved.effective ) {
					const fallbackSegment = segments.find(
						( s ) => s.id === saved.effective
					);
					message = sprintf(
						/* translators: %s: segment name */ __(
							'The list opens on “%s” for you now.',
							'corinthian-pro'
						),
						fallbackSegment?.name || ''
					);
				}
				setToast( { message } );
			} )
			.catch( ( e ) => {
				setDefaults( before );
				setToast( {
					message: errorText(
						e,
						__(
							'The default could not be saved.',
							'corinthian-pro'
						)
					),
					tone: 'error',
					action: {
						label: __( 'Retry', 'corinthian-pro' ),
						onClick: () => setDefault( segment, scope, on ),
					},
				} );
			} );
	};

	const added = ( segment ) => {
		setSegments( ( list ) => [ ...list, segment ] );
		setActiveId( segment.id );
		const url = new URL( window.location.href );
		url.searchParams.set( filterConfig.segmentParam, segment.id );
		window.history.replaceState( window.history.state, '', url.toString() );
		setToast( {
			message: sprintf(
				/* translators: %s: segment name */ __(
					'Saved “%s” with the filters, sort and search the list shows now.',
					'corinthian-pro'
				),
				segment.name
			),
		} );
	};

	return {
		segments,
		defaults,
		active,
		edited,
		applied,
		toast,
		canShare,
		canEdit,
		rename,
		updateActive,
		remove,
		setDefault,
		added,
		dismissToast: () => setToast( null ),
	};
}

function Badges( { segment, defaults } ) {
	const badges = [];
	if ( defaults.personal === segment.id ) {
		badges.push( __( 'My default', 'corinthian-pro' ) );
	}
	if ( segment.shared && defaults.shared === segment.id ) {
		badges.push( __( 'Default for everyone', 'corinthian-pro' ) );
	}
	return badges.map( ( b ) => (
		<span key={ b } className="corinthian-segments__badge">
			{ b }
		</span>
	) );
}

function RenameForm( { segment, onSave, onCancel } ) {
	const [ name, setName ] = useState( segment.name );
	const ref = useRef();
	useEffect( () => {
		const input = ref.current?.querySelector( 'input' );
		input?.focus();
		input?.select();
	}, [] );
	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- Escape cancels the rename without closing the menu.
		<form
			ref={ ref }
			className="corinthian-segments__rename"
			onSubmit={ ( e ) => {
				e.preventDefault();
				if ( name.trim() && name.trim() !== segment.name ) {
					onSave( name.trim() );
				} else {
					onCancel();
				}
			} }
			onKeyDown={ ( e ) => {
				if ( e.key === 'Escape' ) {
					e.stopPropagation();
					e.preventDefault();
					onCancel();
				}
			} }
		>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ sprintf(
					/* translators: %s: segment name */ __(
						'New name for %s',
						'corinthian-pro'
					),
					segment.name
				) }
				hideLabelFromVision
				value={ name }
				onChange={ setName }
			/>
			<Button variant="primary" size="compact" type="submit">
				{ __( 'Save', 'corinthian-pro' ) }
			</Button>
			<Button variant="tertiary" size="compact" onClick={ onCancel }>
				{ __( 'Cancel', 'corinthian-pro' ) }
			</Button>
		</form>
	);
}

function SegmentRow( { segment, store, renaming, setRenaming, onDeleted } ) {
	const { defaults, canShare, active } = store;
	const isActive = active?.id === segment.id;
	const isMine = defaults.personal === segment.id;
	const isShared = segment.shared && defaults.shared === segment.id;

	if ( renaming ) {
		return (
			<li className="corinthian-segments__row">
				<RenameForm
					segment={ segment }
					onCancel={ () => setRenaming( null, segment.id ) }
					onSave={ ( name ) => {
						store.rename( segment, name );
						setRenaming( null, segment.id );
					} }
				/>
			</li>
		);
	}

	return (
		<li className="corinthian-segments__row">
			<Button
				className="corinthian-segments__apply"
				data-segment={ segment.id }
				aria-current={ isActive ? 'true' : undefined }
				onClick={ () =>
					window.location.assign( segmentUrl( segment ) )
				}
			>
				<span className="corinthian-segments__check" aria-hidden="true">
					{ isActive && <Icon icon={ check } size={ 20 } /> }
				</span>
				<span className="corinthian-segments__label">
					{ segment.name }
					{ isActive && (
						<span className="screen-reader-text">
							{ __( '(active)', 'corinthian-pro' ) }
						</span>
					) }
				</span>
				<Badges segment={ segment } defaults={ defaults } />
			</Button>
			<DropdownMenu
				icon={ moreVertical }
				label={ sprintf(
					/* translators: %s: segment name */ __(
						'Actions for %s',
						'corinthian-pro'
					),
					segment.name
				) }
				className="corinthian-segments__more"
				toggleProps={ { size: 'compact' } }
				popoverProps={ { placement: 'bottom-end', shift: true } }
			>
				{ ( { onClose } ) => (
					<>
						<MenuGroup>
							{ store.canEdit( segment ) && (
								<MenuItem
									onClick={ () => {
										onClose();
										setRenaming( segment.id );
									} }
								>
									{ __( 'Rename', 'corinthian-pro' ) }
								</MenuItem>
							) }
							<MenuItem
								onClick={ () => {
									onClose();
									store.setDefault(
										segment,
										'personal',
										! isMine
									);
								} }
							>
								{ isMine
									? __(
											'Stop opening on this',
											'corinthian-pro'
									  )
									: __(
											'Open on this by default',
											'corinthian-pro'
									  ) }
							</MenuItem>
							{ segment.shared && canShare && (
								<MenuItem
									onClick={ () => {
										onClose();
										store.setDefault(
											segment,
											'shared',
											! isShared
										);
									} }
								>
									{ isShared
										? __(
												'Remove default for everyone',
												'corinthian-pro'
										  )
										: __(
												'Make default for everyone',
												'corinthian-pro'
										  ) }
								</MenuItem>
							) }
						</MenuGroup>
						{ store.canEdit( segment ) && (
							<MenuGroup>
								<MenuItem
									isDestructive
									onClick={ () => {
										onClose();
										store.remove( segment );
										onDeleted();
									} }
								>
									{ __( 'Delete', 'corinthian-pro' ) }
								</MenuItem>
							</MenuGroup>
						) }
					</>
				) }
			</DropdownMenu>
		</li>
	);
}

function SegmentsList( { store, onDeleted } ) {
	const { segments, active, edited } = store;
	const ref = useRef();
	const [ renaming, setRenamingId ] = useState( null );
	const groups = [
		{
			title: __( 'Shared segments', 'corinthian-pro' ),
			list: segments.filter( ( s ) => s.shared ),
		},
		{
			title: __( 'My segments', 'corinthian-pro' ),
			list: segments.filter( ( s ) => ! s.shared ),
		},
	];

	const focusRow = ( id ) =>
		window.requestAnimationFrame( () =>
			ref.current?.querySelector( `[data-segment="${ id }"]` )?.focus()
		);
	const setRenaming = ( id, back ) => {
		setRenamingId( id );
		if ( back ) {
			focusRow( back );
		}
	};

	useEffect( () => {
		const first =
			ref.current?.querySelector(
				'.corinthian-segments__apply[aria-current]'
			) || ref.current?.querySelector( '.corinthian-segments__apply' );
		( first || ref.current )?.focus();
	}, [] );

	const onKeyDown = ( e ) => {
		if (
			! [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ].includes( e.key ) ||
			! ref.current?.contains( e.target ) ||
			e.target.tagName === 'INPUT'
		) {
			return;
		}
		const items = [
			...ref.current.querySelectorAll( '.corinthian-segments__apply' ),
		];
		if ( ! items.length ) {
			return;
		}
		e.preventDefault();
		const row = e.target.closest( '.corinthian-segments__row' );
		const current = items.findIndex(
			( el ) => el.closest( '.corinthian-segments__row' ) === row
		);
		let next = 0;
		if ( e.key === 'End' ) {
			next = items.length - 1;
		} else if ( e.key === 'ArrowDown' ) {
			next = current < 0 ? 0 : ( current + 1 ) % items.length;
		} else if ( e.key === 'ArrowUp' ) {
			next =
				current < 0
					? items.length - 1
					: ( current - 1 + items.length ) % items.length;
		}
		items[ next ].focus();
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/no-noninteractive-tabindex -- Arrow keys move between segments; the wrapper takes focus only when the list is empty.
		<div
			ref={ ref }
			className="corinthian-segments__menu"
			role="group"
			aria-label={ __( 'Saved segments', 'corinthian-pro' ) }
			tabIndex={ -1 }
			onKeyDown={ onKeyDown }
		>
			{ ! segments.length && (
				<p className="corinthian-segments__empty">
					{ __(
						'No saved segments yet. Apply some filters, then save them from the Filter panel.',
						'corinthian-pro'
					) }
				</p>
			) }
			{ groups.map(
				( g ) =>
					g.list.length > 0 && (
						<section
							key={ g.title }
							className="corinthian-segments__group"
						>
							<h3>{ g.title }</h3>
							<ul>
								{ g.list.map( ( s ) => (
									<SegmentRow
										key={ s.id }
										segment={ s }
										store={ store }
										renaming={ renaming === s.id }
										setRenaming={ setRenaming }
										onDeleted={ onDeleted }
									/>
								) ) }
							</ul>
						</section>
					)
			) }
			{ active && edited && store.canEdit( active ) && (
				<div className="corinthian-segments__footer">
					<Button
						variant="secondary"
						size="compact"
						onClick={ store.updateActive }
					>
						{ sprintf(
							/* translators: %s: segment name */ __(
								'Update “%s” with current filters',
								'corinthian-pro'
							),
							active.name
						) }
					</Button>
				</div>
			) }
		</div>
	);
}

export function SegmentsMenu( { store } ) {
	const { active, edited } = store;
	const toggleRef = useRef();
	return (
		<Dropdown
			className="corinthian-segments"
			contentClassName="corinthian-segments__popover"
			popoverProps={ {
				placement: 'bottom-start',
				focusOnMount: false,
				shift: true,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					ref={ toggleRef }
					className="corinthian-filter__segments-toggle"
					variant="tertiary"
					aria-expanded={ isOpen }
					aria-haspopup="true"
					title={ active ? active.name : undefined }
					icon={ chevronDown }
					iconPosition="right"
					onClick={ onToggle }
				>
					{ active ? (
						<span className="corinthian-segments__current">
							<span className="corinthian-segments__prefix">
								{ __( 'Segment:', 'corinthian-pro' ) }
							</span>
							<span className="corinthian-segments__name">
								{ active.name }
							</span>
							{ edited && (
								<span className="corinthian-segments__edited">
									{ __( '· edited', 'corinthian-pro' ) }
								</span>
							) }
						</span>
					) : (
						__( 'Segments', 'corinthian-pro' )
					) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<SegmentsList
					store={ store }
					onDeleted={ () => {
						onClose();
						toggleRef.current?.focus();
					} }
				/>
			) }
		/>
	);
}

export function SegmentToast( { store } ) {
	const { toast, dismissToast } = store;
	const [ paused, setPaused ] = useState( false );

	useEffect( () => {
		if ( ! toast || paused ) {
			return;
		}
		const timer = window.setTimeout( dismissToast, TOAST_MS );
		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- restart only when the toast or pause changes
	}, [ toast, paused ] );

	useEffect( () => setPaused( false ), [ toast ] );

	return (
		<div
			className="corinthian-segments__toast-region"
			role="status"
			aria-live="polite"
		>
			{ toast && (
				<div
					className={ `corinthian-segments__toast${
						toast.tone === 'error' ? ' is-error' : ''
					}` }
					onMouseEnter={ () => setPaused( true ) }
					onMouseLeave={ () => setPaused( false ) }
					onFocus={ () => setPaused( true ) }
					onBlur={ () => setPaused( false ) }
				>
					<span className="corinthian-segments__toast-text">
						{ toast.message }
					</span>
					{ toast.action && (
						<Button
							variant="link"
							className="corinthian-segments__toast-action"
							onClick={ toast.action.onClick }
						>
							{ toast.action.label }
						</Button>
					) }
					<Button
						icon={ closeSmall }
						size="small"
						label={ __( 'Dismiss', 'corinthian-pro' ) }
						onClick={ dismissToast }
					/>
				</div>
			) }
		</div>
	);
}

export function SaveSegment( { store, draft } ) {
	const [ name, setName ] = useState( '' );
	const [ shared, setShared ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ asking, setAsking ] = useState( false );
	const { applied, canShare } = store;
	const dirty = ! sameFilter( draft, applied.filter );
	const appliedReady = hasState( applied );
	const draftReady = draft.rules.length > 0 || hasView( applied );

	const save = ( which ) => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: segmentsPath(),
			method: 'POST',
			data: {
				name: name.trim(),
				shared: canShare && shared,
				filter: which === 'draft' ? draft : applied.filter,
				orderby: applied.orderby,
				order: applied.order,
				search: applied.search,
				status: applied.status,
			},
		} )
			.then( ( segment ) => {
				if ( which === 'draft' ) {
					window.location.assign( segmentUrl( segment ) );
					return;
				}
				setName( '' );
				setShared( false );
				setAsking( false );
				store.added( segment );
				setBusy( false );
			} )
			.catch( ( e ) => {
				setError(
					errorText(
						e,
						__(
							'The segment could not be saved.',
							'corinthian-pro'
						)
					)
				);
				setBusy( false );
			} );
	};

	const submit = ( event ) => {
		event.preventDefault();
		if ( ! name.trim() || busy ) {
			return;
		}
		if ( dirty ) {
			setAsking( true );
			return;
		}
		save( 'applied' );
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
				isBusy={ busy && ! asking }
				accessibleWhenDisabled
				disabled={
					busy ||
					! name.trim() ||
					! ( dirty ? draftReady || appliedReady : appliedReady )
				}
			>
				{ __( 'Save as segment', 'corinthian-pro' ) }
			</Button>
			{ asking && (
				<div
					className="corinthian-filter__save-choice"
					role="group"
					aria-labelledby="corinthian-save-choice"
				>
					<p id="corinthian-save-choice">
						{ __(
							'The rules above aren’t applied to the list yet. A segment saves what the list shows.',
							'corinthian-pro'
						) }
					</p>
					<div className="corinthian-filter__actions">
						{ draftReady && (
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								accessibleWhenDisabled
								onClick={ () => save( 'draft' ) }
							>
								{ __(
									'Apply the rules, then save',
									'corinthian-pro'
								) }
							</Button>
						) }
						{ appliedReady && (
							<Button
								variant="secondary"
								disabled={ busy }
								accessibleWhenDisabled
								onClick={ () => save( 'applied' ) }
							>
								{ __(
									'Save the applied filters only',
									'corinthian-pro'
								) }
							</Button>
						) }
						<Button
							variant="tertiary"
							disabled={ busy }
							accessibleWhenDisabled
							onClick={ () => setAsking( false ) }
						>
							{ __( 'Cancel', 'corinthian-pro' ) }
						</Button>
					</div>
				</div>
			) }
			{ error && (
				<p className="corinthian-filter__error" role="alert">
					{ error }
				</p>
			) }
		</form>
	);
}
