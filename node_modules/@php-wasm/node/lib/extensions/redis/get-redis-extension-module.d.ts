import type { SupportedPHPVersion } from '@php-wasm/universal';
/**
 * Returns the path to the redis extension for the specified PHP version.
 *
 * Each PHP version's redis extension is packaged separately. Install the
 * version-specific package you need:
 * - @php-wasm/node-8-5
 * - @php-wasm/node-8-4
 * - etc.
 */
export declare function getRedisExtensionModule(version?: SupportedPHPVersion): Promise<any>;
