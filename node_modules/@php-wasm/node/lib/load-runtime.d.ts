import { type AllPHPVersion, type EmscriptenOptions, type FileLockManager } from '@php-wasm/universal';
import type { WasmUserSpaceAPI, WasmUserSpaceContext } from './wasm-user-space';
import { type PHPExtension, type XdebugOptions } from './extensions/load-extensions';
export interface PHPLoaderOptions {
    followSymlinks?: boolean;
    /**
     * PHP extensions to install before the runtime starts.
     *
     * Use built-in names such as `intl`, `xdebug`, `redis`, and `memcached`,
     * or pass an external JSPI extension source such as a manifest.
     */
    extensions?: PHPExtension[];
    /**
     * @deprecated Use `extensions: ['xdebug']` or
     * `extensions: [{ name: 'xdebug', options }]` instead.
     */
    withXdebug?: boolean | XdebugOptions;
    /**
     * @deprecated Use `extensions: ['intl']` instead.
     */
    withIntl?: boolean;
    /**
     * @deprecated Use `extensions: ['redis']` instead.
     */
    withRedis?: boolean;
    /**
     * @deprecated Use `extensions: ['memcached']` instead.
     */
    withMemcached?: boolean;
}
export type PHPLoaderOptionsForNode = PHPLoaderOptions & {
    /**
     * A file lock manager to coordinate file locks between
     * multiple php-wasm instances and other OS processes.
     */
    fileLockManager?: FileLockManager;
    emscriptenOptions?: EmscriptenOptions & {
        /**
         * The process ID for the PHP runtime.
         *
         * This is used to distinguish between php-wasm processes for the
         * purpose of file locking and more informative trace messages.
         *
         * This ID is optional when running a single php-wasm process.
         */
        processId?: number;
        /**
         * Factory called during WASM initialization to create
         * user-space syscall implementations (flock, fcntl, etc.)
         * for a PHP process. Receives process context (PID,
         * constants, errno codes) and returns the bound syscall
         * functions.
         */
        bindUserSpace?: (userSpaceContext: WasmUserSpaceContext) => WasmUserSpaceAPI;
        /**
         * An optional function to collect trace messages.
         *
         * @param processId - The process ID of the PHP runtime.
         * @param format - A printf-style format string.
         * @param args - Arguments to the format string.
         */
        trace?: (processId: number, format: string, ...args: any[]) => void;
        /**
         * An optional path used to a real, native directory
         * to be mounted as the php-wasm /internal directory.
         */
        nativeInternalDirPath?: string;
    };
};
/**
 * Does what load() does, but synchronously returns
 * an object with the PHP instance and a promise that
 * resolves when the PHP instance is ready.
 *
 * @see load
 */
export declare function loadNodeRuntime(phpVersion: AllPHPVersion, options?: PHPLoaderOptionsForNode): Promise<number>;
