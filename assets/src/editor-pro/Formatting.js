import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
} from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { move, newRuleId, operatorsFor, optionList } from './model';

const MAX_RULES = 20;

const TONES = [
	{ value: 'red', label: __( 'Red', 'corinthian-pro' ), color: '#d63638' },
	{
		value: 'amber',
		label: __( 'Amber', 'corinthian-pro' ),
		color: '#dba617',
	},
	{
		value: 'green',
		label: __( 'Green', 'corinthian-pro' ),
		color: '#00a32a',
	},
	{ value: 'blue', label: __( 'Blue', 'corinthian-pro' ), color: '#2271b1' },
	{
		value: 'purple',
		label: __( 'Purple', 'corinthian-pro' ),
		color: '#8a4fff',
	},
	{ value: 'grey', label: __( 'Grey', 'corinthian-pro' ), color: '#787c82' },
];

function ValueInput( { rule, operator, label, onChange } ) {
	const input = operator?.input || 'none';
	if ( input === 'none' ) {
		return null;
	}
	const options = optionList( operator.options || [] );
	if ( rule.op === 'between' ) {
		const pair = Array.isArray( rule.value ) ? rule.value : [ '', '' ];
		const type = input === 'number' || input === 'date' ? input : 'text';
		return (
			<div className="corinthian-rule__between">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type={ type }
					label={ __( 'From', 'corinthian-pro' ) }
					value={ pair[ 0 ] ?? '' }
					onChange={ ( v ) => onChange( [ v, pair[ 1 ] ?? '' ] ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type={ type }
					label={ __( 'To', 'corinthian-pro' ) }
					value={ pair[ 1 ] ?? '' }
					onChange={ ( v ) => onChange( [ pair[ 0 ] ?? '', v ] ) }
				/>
			</div>
		);
	}
	if ( ( rule.op === 'in' || rule.op === 'not_in' ) && options.length > 0 ) {
		const list = Array.isArray( rule.value ) ? rule.value : [];
		return (
			<fieldset className="corinthian-rule__choices">
				<legend>{ label }</legend>
				<div className="corinthian-roles__list">
					{ options.map( ( o ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ o.value }
							label={ o.label }
							checked={ list.includes( o.value ) }
							onChange={ ( on ) =>
								onChange(
									on
										? [ ...list, o.value ]
										: list.filter( ( x ) => x !== o.value )
								)
							}
						/>
					) ) }
				</div>
			</fieldset>
		);
	}
	if ( input === 'select' && options.length > 0 ) {
		return (
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ String( rule.value ?? '' ) }
				options={ [
					{ value: '', label: __( 'Choose', 'corinthian-pro' ) },
					...options.map( ( o ) => ( {
						value: String( o.value ),
						label: o.label,
					} ) ),
				] }
				onChange={ onChange }
			/>
		);
	}
	const type = input === 'number' || input === 'date' ? input : 'text';
	const scalar = Array.isArray( rule.value )
		? rule.value.join( ', ' )
		: rule.value ?? '';
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type={ type }
			label={ label }
			value={ scalar }
			onChange={ onChange }
		/>
	);
}

function Rule( {
	rule,
	index,
	count,
	columns,
	typeMap,
	onChange,
	onMove,
	onRemove,
	ruleKey,
} ) {
	const column = columns.find( ( c ) => c.id === rule.column );
	const operators = operatorsFor( column, typeMap[ column?.type ] );
	const operator = operators.find( ( o ) => o.op === rule.op );
	const n = index + 1;
	const columnOptions = [
		{ value: '', label: __( 'Choose a column', 'corinthian-pro' ) },
		...( column || ! rule.column
			? []
			: [ { value: rule.column, label: rule.column } ] ),
		...columns.map( ( c ) => ( { value: c.id, label: c.label || c.id } ) ),
	];

	const pickColumn = ( id ) => {
		const next = columns.find( ( c ) => c.id === id );
		const first = operatorsFor( next, typeMap[ next?.type ] )[ 0 ];
		onChange( { column: id, op: first?.op || '', value: '' } );
	};

	return (
		<li className="corinthian-rule" data-rule-id={ rule.id }>
			<fieldset className="corinthian-rule__fields">
				<legend className="corinthian-rule__title">
					{ sprintf(
						/* translators: %d: rule number */
						__( 'Rule %d', 'corinthian-pro' ),
						n
					) }
				</legend>
				<div className="corinthian-rule__condition">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Column', 'corinthian-pro' ) }
						value={ rule.column }
						options={ columnOptions }
						onChange={ pickColumn }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Condition', 'corinthian-pro' ) }
						value={ rule.op }
						disabled={ operators.length === 0 }
						options={ operators.map( ( o ) => ( {
							value: o.op,
							label: o.label,
						} ) ) }
						onChange={ ( op ) => onChange( { op, value: '' } ) }
					/>
					<ValueInput
						rule={ rule }
						operator={ operator }
						label={ __( 'Value', 'corinthian-pro' ) }
						onChange={ ( value ) => onChange( { value } ) }
					/>
				</div>
				<fieldset className="corinthian-rule__tones">
					<legend>{ __( 'Colour', 'corinthian-pro' ) }</legend>
					<div className="corinthian-tones">
						{ TONES.map( ( t ) => (
							// eslint-disable-next-line jsx-a11y/label-has-associated-control -- label wraps its input
							<label
								key={ t.value }
								className={
									'corinthian-tone' +
									( rule.tone === t.value
										? ' is-selected'
										: '' )
								}
							>
								<input
									type="radio"
									name={ `${ ruleKey }-tone` }
									value={ t.value }
									checked={ rule.tone === t.value }
									onChange={ () =>
										onChange( { tone: t.value } )
									}
								/>
								<span
									className="corinthian-tone__swatch"
									style={ { background: t.color } }
									aria-hidden="true"
								/>
								<span className="corinthian-tone__name">
									{ t.label }
								</span>
							</label>
						) ) }
					</div>
				</fieldset>
				<div className="corinthian-rule__style">
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Bold', 'corinthian-pro' ) }
						checked={ !! rule.bold }
						onChange={ ( bold ) => onChange( { bold } ) }
					/>
					<fieldset className="corinthian-rule__scope">
						<legend>{ __( 'Apply to', 'corinthian-pro' ) }</legend>
						<div className="corinthian-scope">
							{ [
								{
									value: 'cell',
									label: __( 'Cell', 'corinthian-pro' ),
								},
								{
									value: 'row',
									label: __( 'Row', 'corinthian-pro' ),
								},
							].map( ( s ) => (
								// eslint-disable-next-line jsx-a11y/label-has-associated-control -- label wraps its input
								<label
									key={ s.value }
									className="corinthian-scope__option"
								>
									<input
										type="radio"
										name={ `${ ruleKey }-scope` }
										value={ s.value }
										checked={ rule.scope === s.value }
										onChange={ () =>
											onChange( { scope: s.value } )
										}
									/>
									<span>{ s.label }</span>
								</label>
							) ) }
						</div>
					</fieldset>
				</div>
			</fieldset>
			<div className="corinthian-rule__actions">
				<Button
					icon={ chevronUp }
					label={ sprintf(
						/* translators: %d: rule number */
						__( 'Move rule %d up', 'corinthian-pro' ),
						n
					) }
					disabled={ index === 0 }
					onClick={ () => onMove( index, index - 1 ) }
				/>
				<Button
					icon={ chevronDown }
					label={ sprintf(
						/* translators: %d: rule number */
						__( 'Move rule %d down', 'corinthian-pro' ),
						n
					) }
					disabled={ index === count - 1 }
					onClick={ () => onMove( index, index + 1 ) }
				/>
				<Button
					icon={ trash }
					isDestructive
					label={ sprintf(
						/* translators: %d: rule number */
						__( 'Remove rule %d', 'corinthian-pro' ),
						n
					) }
					onClick={ onRemove }
				/>
			</div>
		</li>
	);
}

export default function Formatting( { rules, columns, typeMap, onChange } ) {
	const [ announce, setAnnounce ] = useState( '' );
	const eligible = columns.filter(
		( c ) =>
			typeMap[ c.type ]?.filterable && c.settings?.filterable !== false
	);

	const add = () => {
		const first = eligible[ 0 ];
		const op = operatorsFor( first, typeMap[ first?.type ] )[ 0 ]?.op || '';
		onChange( [
			...rules,
			{
				id: newRuleId( rules ),
				column: first?.id || '',
				op,
				value: '',
				tone: 'red',
				bold: false,
				scope: 'cell',
			},
		] );
	};
	const patch = ( id, changes ) =>
		onChange(
			rules.map( ( r ) => ( r.id === id ? { ...r, ...changes } : r ) )
		);
	const reorder = ( from, to ) => {
		onChange( move( rules, from, to ) );
		setAnnounce(
			sprintf(
				/* translators: 1: position, 2: total */
				__( 'Rule moved to position %1$d of %2$d', 'corinthian-pro' ),
				to + 1,
				rules.length
			)
		);
	};

	return (
		<div className="corinthian-card corinthian-formatting">
			<h2 className="corinthian-heading">
				{ __( 'Conditional formatting', 'corinthian-pro' ) }
			</h2>
			{ rules.length === 0 ? (
				<p className="corinthian-muted">
					{ __( 'No rules yet.', 'corinthian-pro' ) }
				</p>
			) : (
				<ol className="corinthian-rules">
					{ rules.map( ( rule, index ) => (
						<Rule
							key={ rule.id }
							ruleKey={ `corinthian-rule-${ rule.id }` }
							rule={ rule }
							index={ index }
							count={ rules.length }
							columns={ eligible }
							typeMap={ typeMap }
							onChange={ ( changes ) =>
								patch( rule.id, changes )
							}
							onMove={ reorder }
							onRemove={ () =>
								onChange(
									rules.filter( ( r ) => r.id !== rule.id )
								)
							}
						/>
					) ) }
				</ol>
			) }
			<div className="screen-reader-text" aria-live="polite">
				{ announce }
			</div>
			<div>
				<Button
					variant="secondary"
					onClick={ add }
					disabled={
						eligible.length === 0 || rules.length >= MAX_RULES
					}
				>
					{ __( 'Add rule', 'corinthian-pro' ) }
				</Button>
			</div>
		</div>
	);
}
