import type { AllPHPVersion, PHPLoaderModule } from '@php-wasm/universal';
/**
 * Loads the PHP loader module for the given PHP version.
 *
 * Each PHP version is packaged separately to reduce bundle size:
 * - @php-wasm/node-8-5
 * - @php-wasm/node-8-4
 * - @php-wasm/node-8-3
 * - etc.
 *
 * @param version The PHP version to load.
 * @returns The PHP loader module.
 */
export declare function getPHPLoaderModule(version?: AllPHPVersion): Promise<PHPLoaderModule>;
