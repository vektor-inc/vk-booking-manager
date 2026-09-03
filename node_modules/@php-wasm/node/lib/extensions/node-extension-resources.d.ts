import type { PHPExtensionSource } from '@php-wasm/universal';
/**
 * Converts extension sources accepted by the Node runtime into URL-shaped
 * resources before the universal resolver sees them.
 *
 * The universal resolver deals in URLs because browsers can only fetch
 * extension bytes from URLs. Node callers often pass local paths instead, so
 * this function rewrites direct artifact URLs, manifest URLs, and inline
 * manifest base URLs into `URL` objects while leaving byte sources unchanged.
 */
export declare function normalizeNodeExtensionSource(source: PHPExtensionSource): PHPExtensionSource;
/**
 * Fetch implementation used by Node extension loading.
 *
 * It behaves like global `fetch()` for HTTP(S) resources and adds `file:`
 * support for local manifests and artifacts. This lets callers use the same
 * extension API for packages installed on disk and for artifacts hosted
 * remotely.
 */
export declare function fetchNodeExtensionResource(input: RequestInfo | URL): Promise<Response>;
