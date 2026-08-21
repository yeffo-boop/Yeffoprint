/**
 * Auto-register WooCommerce entities when this package is imported
 */
/**
 * Internal dependencies
 */
import { registerAddressEntity } from './register-entities';

// Register entities immediately when package is imported
registerAddressEntity();

export * from './register-entities';
export * from './constants';
export * from './types';
export * from './hooks';
