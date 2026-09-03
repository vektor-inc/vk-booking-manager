import type { ProgressTracker } from '@php-wasm/progress';
import type { FileTree, UniversalPHP } from '@php-wasm/universal';
import type { Semaphore } from '@php-wasm/util';
import { StreamedFile } from '@php-wasm/stream-compression';
import type { StreamBundledFile } from './types';
export declare class BlueprintFilesystemRequiredError extends Error {
    constructor(message?: string);
}
/**
 * Error thrown when a resource could not be downloaded from a URL.
 */
export declare class ResourceDownloadError extends Error {
    readonly url: string;
    constructor(message: string, url: string, options?: ErrorOptions);
}
export type { FileTree };
export declare const ResourceTypes: readonly ["vfs", "literal", "wordpress.org/themes", "wordpress.org/plugins", "url", "git:directory", "bundled", "zip"];
export type VFSReference = {
    /** Identifies the file resource as Virtual File System (VFS) */
    resource: 'vfs';
    /** The path to the file in the VFS */
    path: string;
};
export type LiteralReference = {
    /** Identifies the file resource as a literal file */
    resource: 'literal';
    /** The name of the file */
    name: string;
    /** The contents of the file */
    contents: string | Uint8Array;
};
export type CoreThemeReference = {
    /** Identifies the file resource as a WordPress Core theme */
    resource: 'wordpress.org/themes';
    /** The slug of the WordPress Core theme */
    slug: string;
};
export type CorePluginReference = {
    /** Identifies the file resource as a WordPress Core plugin */
    resource: 'wordpress.org/plugins';
    /** The slug of the WordPress Core plugin */
    slug: string;
};
export type UrlReference = {
    /** Identifies the file resource as a URL */
    resource: 'url';
    /** The URL of the file */
    url: string;
    /** Optional caption for displaying a progress message */
    caption?: string;
};
type GitDirectoryRefType = 'branch' | 'tag' | 'commit' | 'refname';
export type GitDirectoryReference = {
    /** Identifies the file resource as a git directory */
    resource: 'git:directory';
    /** The URL of the git repository */
    url: string;
    /** The ref (branch, tag, or commit) of the git repository */
    ref: string;
    /** Explicit hint about the ref type (branch, tag, commit, refname) */
    refType?: GitDirectoryRefType;
    /** The path to the directory in the git repository. Defaults to the repo root. */
    path?: string;
    /** When true, include a `.git` directory with Git metadata (experimental). */
    '.git'?: boolean;
};
export interface Directory {
    files: FileTree;
    name: string;
}
export type DirectoryLiteralReference = Directory & {
    /** Identifies the file resource as a git directory */
    resource: 'literal:directory';
};
export type BundledReference = {
    /** Identifies the file resource as a Blueprint file */
    resource: 'bundled';
    /** The path to the file in the Blueprint */
    path: string;
};
export type ZipWrapperReference = {
    /** Identifies the resource as a ZIP wrapper */
    resource: 'zip';
    /** The inner resource to wrap in a ZIP file */
    inner: FileReference | DirectoryReference;
    /** Optional filename for the ZIP (defaults to inner resource name + .zip) */
    name?: string;
};
export type FileReference = VFSReference | LiteralReference | CoreThemeReference | CorePluginReference | UrlReference | BundledReference | ZipWrapperReference;
export type DirectoryReference = GitDirectoryReference | DirectoryLiteralReference;
export declare function isResourceReference(ref: any): ref is FileReference;
/**
 * Checks if a URL is a github-proxy.com URL that can be rewritten.
 *
 * @param url The URL to check
 * @returns true if the URL is a github-proxy.com URL
 */
export declare function isGithubProxyUrl(url: string): boolean;
/**
 * Rewrites a github-proxy.com URL to an equivalent Blueprint resource reference.
 *
 * github-proxy.com is being deprecated. This function enables automatic migration
 * of existing Blueprints that use github-proxy.com URLs to native Blueprint resources.
 *
 * Supported URL patterns:
 * - `?repo=owner/name` - Full repository at default branch
 * - `?repo=owner/name&branch=trunk` - Full repository at specific branch
 * - `?repo=owner/name&pr=123` - Full repository at PR head
 * - `?repo=owner/name&commit=abc` - Full repository at specific commit
 * - `?repo=owner/name&release=v1.0` - Full repository at release tag
 * - `?repo=owner/name&directory=subdir` - Subdirectory of repository
 * - `?repo=owner/name&release=v1.0&asset=file.zip` - Release asset download
 * - `https://github-proxy.com/https://github.com/...` - Direct GitHub URL proxy
 *
 * @param url The github-proxy.com URL to rewrite
 * @returns A ZipWrapperReference (wrapping git:directory) or UrlReference, or null if URL cannot be rewritten
 */
export declare function rewriteGithubProxyUrl(url: string): ZipWrapperReference | UrlReference | null;
export declare abstract class Resource<T extends File | Directory> {
    /** Optional progress tracker to monitor progress */
    protected _progress?: ProgressTracker;
    get progress(): ProgressTracker | undefined;
    set progress(value: ProgressTracker | undefined);
    /** A Promise that resolves to the file contents */
    protected promise?: Promise<T>;
    protected playground?: UniversalPHP;
    setPlayground(playground: UniversalPHP): void;
    abstract resolve(): Promise<T>;
    /** The name of the referenced file */
    abstract get name(): string;
    /** Whether this Resource is loaded asynchronously */
    get isAsync(): boolean;
    /**
     * Creates a new Resource based on the given file reference
     *
     * @param ref The file reference to create the Resource for
     * @param options Additional options for the Resource
     * @returns A new Resource instance
     */
    static create(ref: FileReference | DirectoryReference, { semaphore, progress, corsProxy, streamBundledFile, gitAdditionalHeadersCallback, }: {
        /** Optional semaphore to limit concurrent downloads */
        semaphore?: Semaphore;
        progress?: ProgressTracker;
        corsProxy?: string;
        streamBundledFile?: StreamBundledFile;
        gitAdditionalHeadersCallback?: (url: string) => Record<string, string>;
    }): Resource<File | Directory>;
}
export declare abstract class ResourceDecorator<T extends File | Directory> extends Resource<T> {
    protected resource: Resource<T>;
    constructor(resource: Resource<T>);
    /** @inheritDoc */
    get progress(): ProgressTracker | undefined;
    /** @inheritDoc */
    set progress(value: ProgressTracker | undefined);
    /** @inheritDoc */
    abstract resolve(): Promise<T>;
    /** @inheritDoc */
    get name(): string;
    /** @inheritDoc */
    get isAsync(): boolean;
    /** @inheritDoc */
    setPlayground(playground: UniversalPHP): void;
}
/**
 * A `Resource` that represents a file in the VFS (virtual file system) of the
 * playground.
 */
export declare class VFSResource extends Resource<File> {
    private resource;
    /**
     * Creates a new instance of `VFSResource`.
     * @param playground The playground client.
     * @param resource The VFS reference.
     * @param progress The progress tracker.
     */
    constructor(resource: VFSReference, _progress?: ProgressTracker);
    /** @inheritDoc */
    resolve(): Promise<File>;
    /** @inheritDoc */
    get name(): string;
}
/**
 * A `Resource` that represents a literal file.
 */
export declare class LiteralResource extends Resource<File> {
    private resource;
    /**
     * Creates a new instance of `LiteralResource`.
     * @param resource The literal reference.
     * @param progress The progress tracker.
     */
    constructor(resource: LiteralReference, _progress?: ProgressTracker);
    /** @inheritDoc */
    resolve(): Promise<File>;
    /** @inheritDoc */
    get name(): string;
}
/**
 * A base class for `Resource`s that require fetching data from a remote URL.
 */
export declare abstract class FetchResource extends Resource<File> {
    private corsProxy?;
    /**
     * Creates a new instance of `FetchResource`.
     * @param progress The progress tracker.
     */
    constructor(_progress?: ProgressTracker, corsProxy?: string);
    /** @inheritDoc */
    resolve(): Promise<File>;
    /**
     * Gets the URL to fetch the data from.
     * @returns The URL.
     */
    protected abstract getURL(): string;
    /**
     * Gets the caption for the progress tracker.
     * @returns The caption.
     */
    protected get caption(): string;
    /** @inheritDoc */
    get name(): string;
    /** @inheritDoc */
    get isAsync(): boolean;
}
/**
 * A `Resource` that represents a file available from a URL.
 */
export declare class UrlResource extends FetchResource {
    private resource;
    private options?;
    /**
     * Creates a new instance of `UrlResource`.
     * @param resource The URL reference.
     * @param progress The progress tracker.
     */
    constructor(resource: UrlReference, progress?: ProgressTracker, options?: {
        corsProxy?: string;
    });
    /** @inheritDoc */
    getURL(): string;
    /** @inheritDoc */
    protected get caption(): string;
}
/**
 * A `Resource` that represents a git directory.
 */
export declare class GitDirectoryResource extends Resource<Directory> {
    private reference;
    private options?;
    constructor(reference: GitDirectoryReference, _progress?: ProgressTracker, options?: {
        corsProxy?: string;
        additionalHeaders?: (url: string) => Record<string, string>;
    });
    resolve(): Promise<{
        name: string;
        files: Record<string, any>;
    }>;
    /**
     * Generate a nice, non-empty filename – the installPlugin step depends on it.
     */
    get filename(): string;
    /** @inheritDoc */
    get name(): string;
}
/**
 * A `Resource` that represents a git directory.
 */
export declare class LiteralDirectoryResource extends Resource<Directory> {
    private reference;
    constructor(reference: DirectoryLiteralReference, _progress?: ProgressTracker);
    resolve(): Promise<DirectoryLiteralReference>;
    /** @inheritDoc */
    get name(): string;
}
/**
 * A `Resource` that represents a WordPress core theme.
 */
export declare class CoreThemeResource extends FetchResource {
    private resource;
    constructor(resource: CoreThemeReference, progress?: ProgressTracker);
    get name(): string;
    getURL(): string;
}
/**
 * A resource that fetches a WordPress plugin from wordpress.org.
 */
export declare class CorePluginResource extends FetchResource {
    private resource;
    constructor(resource: CorePluginReference, progress?: ProgressTracker);
    /** @inheritDoc */
    get name(): string;
    /** @inheritDoc */
    getURL(): string;
}
/**
 * Transforms a plugin slug into a directory zip name.
 * If the input already ends with ".zip", returns it unchanged.
 * Otherwise, appends ".latest-stable.zip".
 */
export declare function toDirectoryZipName(rawInput: string): string;
/**
 * A decorator for a resource that adds caching functionality.
 */
export declare class CachedResource<T extends File | Directory> extends ResourceDecorator<T> {
    protected promise?: Promise<T>;
    /** @inheritDoc */
    resolve(): Promise<T>;
}
/**
 * A decorator for a resource that adds concurrency control functionality
 * through a semaphore.
 */
export declare class SemaphoreResource<T extends File | Directory> extends ResourceDecorator<T> {
    private readonly semaphore;
    constructor(resource: Resource<T>, semaphore: Semaphore);
    /** @inheritDoc */
    resolve(): Promise<T>;
}
/**
 * A `Resource` that represents a file bundled with the Blueprint.
 */
export declare class BundledResource extends Resource<File> {
    private resource;
    private streamBundledFile;
    /**
     * Creates a new instance of `BlueprintResource`.
     * @param resource The blueprint reference.
     * @param filesystem The filesystem to read from.
     * @param progress The progress tracker.
     */
    constructor(resource: BundledReference, streamBundledFile: StreamBundledFile, _progress?: ProgressTracker);
    /** @inheritDoc */
    resolve(): Promise<StreamedFile>;
    /** @inheritDoc */
    get name(): string;
    /** @inheritDoc */
    get isAsync(): boolean;
}
/**
 * A `Resource` that wraps another resource and outputs it as a ZIP file.
 * This is useful for converting directory resources to ZIP files, enabling
 * compatibility with steps that expect ZIP input (like `unzip`).
 */
export declare class ZipResource extends Resource<File> {
    private reference;
    private innerResource;
    constructor(reference: ZipWrapperReference, innerResource: Resource<File | Directory>, _progress?: ProgressTracker);
    /** @inheritDoc */
    resolve(): Promise<File>;
    /** @inheritDoc */
    get name(): string;
    /** @inheritDoc */
    get isAsync(): boolean;
}
