import type { EmscriptenOptions, ResolvedInstallOptions, SupportedPHPVersion } from '@php-wasm/universal';
type PHPWasmAsyncMode = 'jspi' | 'asyncify';
export interface PathMapping {
    hostPath: string;
    vfsPath: string;
}
export interface XdebugOptions {
    ideKey?: string;
    pathMappings?: PathMapping[];
    pathSkippings?: string[];
}
/**
 * Built-in PHP extensions shipped with `@php-wasm/node`.
 */
export type BuiltInPHPExtensionName = 'intl' | 'xdebug' | 'redis' | 'memcached';
/**
 * External PHP extension source that can be installed before PHP starts.
 *
 * External sources are supported in JSPI runtimes only. Asyncify support is
 * limited to bundled extensions shipped with this package.
 */
export type RuntimePHPExtensionSource = Omit<ResolvedInstallOptions, 'phpVersion'>;
/**
 * Built-in PHP extension request accepted by `loadNodeRuntime()`.
 *
 * Pass a string for defaults, or an object when a built-in extension exposes
 * options. Currently only `xdebug` has options.
 */
export type BuiltInPHPExtension = BuiltInPHPExtensionName | {
    name: 'xdebug';
    options?: XdebugOptions;
} | {
    name: Exclude<BuiltInPHPExtensionName, 'xdebug'>;
};
/**
 * PHP extension request accepted by `loadNodeRuntime()`.
 *
 * The array may mix built-in extension names with external extension sources:
 *
 * ```ts
 * await loadNodeRuntime('8.4', {
 *   extensions: [
 *     'intl',
 *     { source: { format: 'manifest', manifestUrl: './manifest.json' } },
 *   ],
 * });
 * ```
 *
 * In Node, local manifest and artifact files work without a custom `fetch`
 * implementation. Pass `manifestUrl` as a filesystem path, a `file:` URL, or
 * an HTTP URL.
 */
export type PHPExtension = BuiltInPHPExtension | RuntimePHPExtensionSource;
/**
 * Adds PHP extensions to Emscripten options before the Node runtime starts.
 *
 * Extension sources are resolved in parallel so multiple manifest or artifact
 * downloads do not block each other.
 */
export declare function withPHPExtensions(version: SupportedPHPVersion, asyncMode: PHPWasmAsyncMode, options: EmscriptenOptions, extensions?: PHPExtension[]): Promise<EmscriptenOptions>;
export {};
