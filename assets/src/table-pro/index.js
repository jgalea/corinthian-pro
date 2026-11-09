import { addAction } from '@wordpress/hooks';
import { mountFilters } from './filters';
import { mountInlineEdit } from './inline-edit';
import { mountBulkEdit } from './bulk-edit';
import { mountFormatting } from './formatting';
import { mountQuickAdd } from './quick-add';
import './table-pro.css';

addAction( 'corinthian.table.ready', 'corinthian-pro/filters', ( { toolbar } ) => mountFilters( toolbar ) );
addAction( 'corinthian.table.ready', 'corinthian-pro/bulk-edit', ( { toolbar } ) => mountBulkEdit( toolbar ) );
addAction( 'corinthian.table.ready', 'corinthian-pro/quick-add', ( { toolbar } ) => mountQuickAdd( toolbar ) );
addAction( 'corinthian.table.ready', 'corinthian-pro/inline-edit', () => mountInlineEdit() );
addAction( 'corinthian.table.ready', 'corinthian-pro/formatting', () => mountFormatting() );
