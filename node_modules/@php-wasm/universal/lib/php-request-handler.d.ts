import type { PHP } from './php';
import { PHPResponse, StreamedPHPResponse } from './php-response';
import type { PHPRequest } from './universal-php';
import type { PHPFactoryOptions } from './php-process-manager';
import type { PHPInstanceManager } from './php-instance-manager';
export type RewriteRule = {
    match: RegExp;
    replacement: string;
};
export type FileNotFoundToResponse = {
    type: 'response';
    response: PHPResponse;
};
export type FileNotFoundToInternalRedirect = {
    type: 'internal-redirect';
    uri: string;
};
export type FileNotFoundTo404 = {
    type: '404';
};
export type FileNotFoundAction = FileNotFoundToResponse | FileNotFoundToInternalRedirect | FileNotFoundTo404;
export type FileNotFoundGetActionCallback = (relativePath: string) => FileNotFoundAction;
/**
 * Interface for cookie storage implementations.
 * This allows different cookie handling strategies to be used with the PHP request handler.
 */
export interface CookieStore {
    /**
     * Processes and stores cookies from response headers
     * @param headers Response headers containing Set-Cookie directives
     */
    rememberCookiesFromResponseHeaders(headers: Record<string, string[]>): void;
    /**
     * Gets the cookie header string for the next request
     * @returns Formatted cookie header string
     */
    getCookieRequestHeader(): string;
}
/**
 * Maps a URL path prefix to an absolute filesystem path.
 * Similar to Nginx's `alias` directive or Apache's `Alias` directive.
 *
 * @example
 * ```ts
 * // Requests to /phpmyadmin/* will be served from /tools/phpmyadmin/*
 * { urlPrefix: '/phpmyadmin', fsPath: '/tools/phpmyadmin' }
 * ```
 */
export type PathAlias = {
    /**
     * The URL path prefix to match (e.g., '/phpmyadmin').
     */
    urlPrefix: string;
    /**
     * The absolute filesystem path to serve files from.
     */
    fsPath: string;
};
interface BaseConfiguration {
    /**
     * The directory in the PHP filesystem where the server will look
     * for the files to serve. Default: `/var/www`.
     */
    documentRoot?: string;
    /**
     * Request Handler URL. Used to populate $_SERVER details like HTTP_HOST.
     */
    absoluteUrl?: string;
    /**
     * Rewrite rules
     */
    rewriteRules?: RewriteRule[];
    /**
     * Path aliases that map URL prefixes to filesystem paths outside
     * the document root. Similar to Nginx's `alias` directive.
     *
     * @example
     * ```ts
     * pathAliases: [
     *   { urlPrefix: '/phpmyadmin', fsPath: '/tools/phpmyadmin' }
     * ]
     * ```
     */
    pathAliases?: PathAlias[];
    /**
     * A callback that decides how to handle a file-not-found condition for a
     * given request URI.
     */
    getFileNotFoundAction?: FileNotFoundGetActionCallback;
}
export type PHPRequestHandlerFactoryArgs = PHPFactoryOptions & {
    requestHandler: PHPRequestHandler;
};
export type PHPRequestHandlerConfiguration = BaseConfiguration & {
    cookieStore?: CookieStore | false;
    /**
     * Provide a single PHP instance directly.
     * PHPRequestHandler will create a SinglePHPInstanceManager internally.
     * This is the simplest option for CLI contexts with a single PHP instance.
     */
    php?: PHP;
    /**
     * Provide a factory function to create PHP instances.
     * PHPRequestHandler will create a PHPProcessManager internally.
     */
    phpFactory?: (requestHandler: PHPRequestHandlerFactoryArgs) => Promise<PHP>;
    /**
     * The maximum number of PHP instances that can exist at
     * the same time. Only used when phpFactory is provided.
     */
    maxPhpInstances?: number;
};
/**
 * Handles HTTP requests using PHP runtime as a backend.
 *
 * @public
 * @example Use PHPRequestHandler implicitly with a new PHP instance:
 * ```js
 * import { PHP } from '@php-wasm/web';
 *
 * const php = await PHP.load( '7.4', {
 *     requestHandler: {
 *         // PHP FS path to serve the files from:
 *         documentRoot: '/www',
 *
 *         // Used to populate $_SERVER['SERVER_NAME'] etc.:
 *         absoluteUrl: 'http://127.0.0.1'
 *     }
 * } );
 *
 * php.mkdirTree('/www');
 * php.writeFile('/www/index.php', '<?php echo "Hi from PHP!"; ');
 *
 * const response = await php.request({ path: '/index.php' });
 * console.log(response.text);
 * // "Hi from PHP!"
 * ```
 *
 * @example Explicitly create a PHPRequestHandler instance and run a PHP script:
 * ```js
 * import {
 *   loadPHPRuntime,
 *   PHP,
 *   PHPRequestHandler,
 *   getPHPLoaderModule,
 * } from '@php-wasm/web';
 *
 * const runtime = await loadPHPRuntime( await getPHPLoaderModule('7.4') );
 * const php = new PHP( runtime );
 *
 * php.mkdirTree('/www');
 * php.writeFile('/www/index.php', '<?php echo "Hi from PHP!"; ');
 *
 * const server = new PHPRequestHandler(php, {
 *     // PHP FS path to serve the files from:
 *     documentRoot: '/www',
 *
 *     // Used to populate $_SERVER['SERVER_NAME'] etc.:
 *     absoluteUrl: 'http://127.0.0.1'
 * });
 *
 * const response = server.request({ path: '/index.php' });
 * console.log(response.text);
 * // "Hi from PHP!"
 * ```
 */
export declare class PHPRequestHandler implements AsyncDisposable {
    #private;
    rewriteRules: RewriteRule[];
    /**
     * The instance manager used for PHP instance lifecycle.
     * This is either a provided instanceManager or a PHPProcessManager
     * created from the phpFactory.
     */
    instanceManager: PHPInstanceManager;
    getFileNotFoundAction: FileNotFoundGetActionCallback;
    /**
     * The request handler needs to decide whether to serve a static asset or
     * run the PHP interpreter. For static assets it should just reuse the primary
     * PHP even if there's 50 concurrent requests to serve. However, for
     * dynamic PHP requests, it needs to grab an available interpreter.
     * Therefore, it cannot just accept PHP as an argument as serving requests
     * requires access to ProcessManager.
     *
     * @param  php    - The PHP instance.
     * @param  config - Request Handler configuration.
     */
    constructor(config: PHPRequestHandlerConfiguration);
    getPrimaryPhp(): Promise<PHP>;
    /**
     * Converts a path to an absolute URL based at the PHPRequestHandler
     * root.
     *
     * @param  path The server path to convert to an absolute URL.
     * @returns The absolute URL.
     */
    pathToInternalUrl(path: string): string;
    /**
     * Converts an absolute URL based at the PHPRequestHandler to a relative path
     * without the server pathname and scope.
     *
     * @param  internalUrl An absolute URL based at the PHPRequestHandler root.
     * @returns The relative path.
     */
    internalUrlToPath(internalUrl: string): string;
    /**
     * The absolute URL of this PHPRequestHandler instance.
     */
    get absoluteUrl(): string;
    /**
     * The directory in the PHP filesystem where the server will look
     * for the files to serve. Default: `/var/www`.
     */
    get documentRoot(): string;
    /**
     * Serves the request – either by serving a static file, or by
     * dispatching it to the PHP runtime.
     *
     * The request() method mode behaves like a web server and only works if
     * the PHP was initialized with a `requestHandler` option (which the online
     * version of WordPress Playground does by default).
     *
     * In the request mode, you pass an object containing the request information
     * (method, headers, body, etc.) and the path to the PHP file to run:
     *
     * ```ts
     * const php = PHP.load('7.4', {
     * 	requestHandler: {
     * 		documentRoot: "/www"
     * 	}
     * })
     * php.writeFile("/www/index.php", `<?php echo file_get_contents("php://input");`);
     * const result = await php.request({
     * 	method: "GET",
     * 	headers: {
     * 		"Content-Type": "text/plain"
     * 	},
     * 	body: "Hello world!",
     * 	path: "/www/index.php"
     * });
     * // result.text === "Hello world!"
     * ```
     *
     * The `request()` method cannot be used in conjunction with `cli()`.
     *
     * @example
     * ```js
     * const output = await php.request({
     * 	method: 'GET',
     * 	url: '/index.php',
     * 	headers: {
     * 		'X-foo': 'bar',
     * 	},
     * 	body: {
     * 		foo: 'bar',
     * 	},
     * });
     * console.log(output.stdout); // "Hello world!"
     * ```
     *
     * @param  request - PHP Request data.
     */
    request(request: PHPRequest): Promise<PHPResponse>;
    /**
     * Serves the request with streaming support – returns a StreamedPHPResponse
     * that allows processing the response body incrementally without buffering
     * the entire response in memory.
     *
     * This is useful for large file downloads (>2GB) that would otherwise
     * exceed JavaScript's Uint8Array size limits.
     *
     * @param request - PHP Request data.
     * @returns A StreamedPHPResponse.
     */
    requestStreamed(request: PHPRequest): Promise<StreamedPHPResponse>;
    /**
     * Computes the essential $_SERVER entries for a request.
     *
     * php_wasm.c sets some defaults, assuming it runs as a CLI script.
     * This function overrides them with the values correct in the request
     * context.
     *
     * @TODO: Consolidate the $_SERVER setting logic into a single place instead
     *        of splitting it between the C SAPI and the TypeScript code. The PHP
     *        class has a `.cli()` method that could take care of the CLI-specific
     *        $_SERVER values.
     *
     * Path and URL-related $_SERVER entries are theoretically documented
     * at https://www.php.net/manual/en/reserved.variables.server.php,
     * but that page is not very helpful in practice. Here are tables derived
     * by interacting with PHP servers:
     *
     * ## PHP Dev Server
     *
     * Setup:
     *   – `/home/adam/subdir/script.php` file contains `<?php phpinfo(); ?>`
     *   – `php -S 127.0.0.1:8041` running in `/home/adam` directory
     *   – A request is sent to `http://127.0.0.1:8041/subdir/script.php/b.php/c.php`
     *
     * Results:
     *
     * $_SERVER['REQUEST_URI']    | `/subdir/script.php/b.php/c.php`
     * $_SERVER['SCRIPT_NAME']    | `/subdir/script.php`
     * $_SERVER['SCRIPT_FILENAME']| `/home/adam/subdir/script.php`
     * $_SERVER['PATH_INFO']      | `/b.php/c.php`
     * $_SERVER['PHP_SELF']       | `/subdir/script.php/b.php/c.php`
     *
     * ## Apache – rewriting rules
     *
     * Setup:
     *   – `/var/www/html/subdir/script.php` file contains `<?php phpinfo(); ?>`
     *   – Apache is listening on port 8041
     *   – The document root is `/var/www/html`
     *   – A request is sent to `http://127.0.0.1:8041/api/v1/user/123`
     *
     * .htaccess file:
     *
     * ```apache
     * RewriteEngine On
     * RewriteRule ^api/v1/user/([0-9]+)$ /subdir/script.php?endpoint=user&id=$1 [L,QSA]
     * ```
     *
     * Results:
     *
     * ```
     * $_SERVER['REQUEST_URI']             | /api/v1/user/123
     * $_SERVER['SCRIPT_NAME']             | /subdir/script.php
     * $_SERVER['SCRIPT_FILENAME']         | /var/www/html/subdir/script.php
     * $_SERVER['PATH_INFO']               | (key not set)
     * $_SERVER['PHP_SELF']                | /subdir/script.php
     * $_SERVER['QUERY_STRING']            | endpoint=user&id=123
     * $_SERVER['REDIRECT_STATUS']         | 200
     * $_SERVER['REDIRECT_URL']            | /api/v1/user/123
     * $_SERVER['REDIRECT_QUERY_STRING']   | endpoint=user&id=123
     * === $_GET Variables ===
     * $_GET['endpoint']                   | user
     * $_GET['id']                         | 123
     * ```
     *
     * ## Apache – vanilla request
     *
     * Setup:
     *    – The same as above.
     *    – A request sent http://localhost:8041/subdir/script.php?param=value
     *
     * Results:
     *
     * ```
     * $_SERVER['REQUEST_URI']     | /subdir/script.php?param=value
     * $_SERVER['SCRIPT_NAME']     | /subdir/script.php
     * $_SERVER['SCRIPT_FILENAME'] | /var/www/html/subdir/script.php
     * $_SERVER['PATH_INFO']       | (key not set)
     * $_SERVER['PHP_SELF']        | /subdir/script.php
     * $_SERVER['REDIRECT_URL']    | (key not set)
     * $_SERVER['REDIRECT_STATUS'] | (key not set)
     * $_SERVER['QUERY_STRING']    | param=value
     * $_SERVER['REQUEST_METHOD']  | GET
     * $_SERVER['DOCUMENT_ROOT']   | /var/www/html
     *
     * === $_GET Variables ===
     * $_GET['param']              | value
     * ```
     */
    private prepare_$_SERVER_superglobal;
    [Symbol.asyncDispose](): Promise<void>;
}
/**
 * Naively infer a file mime type from its path.
 *
 * @todo Infer the mime type based on the file contents.
 *       A naive function like this one can be inaccurate
 *       and potentially have negative security consequences.
 *
 * @param  path - The file path
 * @returns The inferred mime type.
 */
export declare function inferMimeType(path: string): string;
/**
 * Applies the given rewrite rules to the given path.
 *
 * @param  path  The path to apply the rules to.
 * @param  rules The rules to apply.
 * @returns The path with the rules applied.
 */
export declare function applyRewriteRules(path: string, rules: RewriteRule[]): string;
export {};
