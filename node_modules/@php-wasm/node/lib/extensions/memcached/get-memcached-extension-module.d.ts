import type { SupportedPHPVersion } from '@php-wasm/universal';
/**
 * Returns the path to the memcached extension for the specified PHP version.
 *
 * Each PHP version's memcached extension is packaged separately. Install the
 * version-specific package you need:
 * - @php-wasm/node-8-5
 * - @php-wasm/node-8-4
 * - etc.
 */
export declare function getMemcachedExtensionModule(version?: SupportedPHPVersion): Promise<any>;
