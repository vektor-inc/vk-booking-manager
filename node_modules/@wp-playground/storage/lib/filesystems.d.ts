import { StreamedFile } from '@php-wasm/stream-compression';
import type { FileTree } from '@php-wasm/universal';
import { ZipReader, BlobReader } from '@zip.js/zip.js';
export interface ReadableFilesystemBackend {
    read(path: string): Promise<StreamedFile>;
}
/**
 * A readable filesystem that can also be traversed (list directories).
 */
export interface TraversableFilesystemBackend extends ReadableFilesystemBackend {
    listFiles(path: string): Promise<string[]>;
    isDir(path: string): Promise<boolean>;
}
/**
 * Backend interface for writable filesystem operations.
 * All paths passed to these methods are expected to be absolute paths.
 */
export interface WritableFilesystemBackend extends TraversableFilesystemBackend {
    fileExists(absolutePath: string): Promise<boolean>;
    writeFile(absolutePath: string, data: Uint8Array): Promise<void>;
    mkdir(absolutePath: string, recursive?: boolean): Promise<void>;
    rmdir(absolutePath: string, recursive: boolean): Promise<void>;
    mv(absoluteSource: string, absoluteDestination: string): Promise<void>;
    unlink(absolutePath: string): Promise<void>;
    clear(): Promise<void>;
}
/**
 * Interface for a writable filesystem with EventTarget support.
 * Used by UI components that need to react to filesystem changes.
 */
export interface AsyncWritableFilesystem extends EventTarget {
    isDir(path: string): Promise<boolean>;
    fileExists(path: string): Promise<boolean>;
    read(path: string): Promise<{
        arrayBuffer(): Promise<ArrayBuffer>;
    }>;
    readFileAsText(path: string): Promise<string>;
    listFiles(path: string): Promise<string[]>;
    writeFile(path: string, data: Uint8Array | string): Promise<void>;
    mkdir(path: string, options?: {
        recursive?: boolean;
    }): Promise<void>;
    rmdir(path: string, options?: {
        recursive?: boolean;
    }): Promise<void>;
    mv(source: string, destination: string): Promise<void>;
    unlink(path: string): Promise<void>;
}
/**
 * Copy all files from source filesystem to destination filesystem.
 * Clears the destination before copying.
 */
export declare function copyFilesystem(source: TraversableFilesystemBackend, destination: WritableFilesystemBackend): Promise<void>;
/**
 * Wraps a WritableFilesystemBackend with EventTarget support and convenience methods.
 * Dispatches 'change' events on write operations.
 */
export declare class EventedFilesystem extends EventTarget implements AsyncWritableFilesystem {
    private readonly encoder;
    private readonly decoder;
    readonly backend: WritableFilesystemBackend;
    constructor(backend: WritableFilesystemBackend);
    isDir(path: string): Promise<boolean>;
    fileExists(path: string): Promise<boolean>;
    read(path: string): Promise<StreamedFile>;
    readFileAsText(path: string): Promise<string>;
    listFiles(path: string): Promise<string[]>;
    writeFile(path: string, data: Uint8Array | string): Promise<void>;
    mkdir(path: string, options?: {
        recursive?: boolean;
    }): Promise<void>;
    rmdir(path: string, options?: {
        recursive?: boolean;
    }): Promise<void>;
    mv(source: string, destination: string): Promise<void>;
    unlink(path: string): Promise<void>;
    clear(): Promise<void>;
}
export declare class InMemoryFilesystem implements ReadableFilesystemBackend {
    private fileTree;
    constructor(fileTree: FileTree);
    read(path: string): Promise<StreamedFile>;
    private getEntryAtPath;
}
export declare class ZipFilesystem implements ReadableFilesystemBackend {
    private entries;
    private zipReader;
    static fromStream(stream: ReadableStream<Uint8Array>): ZipFilesystem;
    static fromArrayBuffer(arrayBuffer: ArrayBuffer): ZipFilesystem;
    constructor(zipReader: ZipReader<BlobReader>);
    read(relativePath: string): Promise<StreamedFile>;
    private getEntry;
    private getEntries;
    /**
     * Returns the paths of all entries in the zip (file and directory names).
     */
    getAllFilePaths(): Promise<string[]>;
}
/**
 * A ReadableFilesystemBackend that exposes a subdirectory of another backend
 * as the root, similar to chroot. Paths are resolved via joinPaths.
 */
export declare class ChrootFilesystem implements ReadableFilesystemBackend {
    private readonly chroot;
    private readonly backend;
    constructor(chroot: string, backend: ReadableFilesystemBackend);
    read(path: string): Promise<StreamedFile>;
}
/**
 * A Filesystem implementation that cascades through multiple filesystems
 * and returns the first successful result.
 *
 * This is useful for creating a layered approach to file resolution,
 * such as checking a local cache before fetching from a remote source.
 */
export declare class OverlayFilesystem implements ReadableFilesystemBackend {
    private filesystems;
    /**
     * Creates a new OverlayFilesystem.
     *
     * @param filesystems An array of Filesystem instances to cascade through.
     *                    The order determines the priority - earlier filesystems
     *                    are checked first.
     */
    constructor(filesystems: ReadableFilesystemBackend[]);
    /**
     * Reads a file by trying each filesystem in order until one succeeds.
     *
     * @param path The path to the file to read.
     * @returns A Promise that resolves to a StreamedFile from the first
     *          filesystem that successfully resolves the path.
     * @throws Error if all filesystems fail to resolve the path.
     */
    read(path: string): Promise<StreamedFile>;
}
export interface FetchFilesystemOptions {
    corsProxy?: string;
    baseUrl: string;
}
/**
 * A Filesystem implementation that fetches files from URLs.
 * It can optionally use a CORS proxy and resolve paths relative to a base URL.
 */
export declare class FetchFilesystem implements ReadableFilesystemBackend {
    private baseUrl;
    private options;
    private isDataUrl;
    constructor(options: FetchFilesystemOptions);
    read(path: string): Promise<StreamedFile>;
}
/**
 * A Filesystem implementation that uses the "fs" and "path" modules from Node.js
 * to read files from the local file system.
 *
 * This is only available in a local environment.
 */
export declare class NodeJsFilesystem implements ReadableFilesystemBackend {
    private fs;
    private path;
    private root;
    constructor(root: string);
    private ensureNodeModules;
    read(filePath: string): Promise<StreamedFile>;
}
/**
 * OPFS filesystem backend that operates directly on the Origin Private File System.
 * Implements both ReadableFilesystemBackend (for BlueprintBundle) and
 * WritableFilesystemBackend (for the editor).
 */
export declare class OpfsFilesystemBackend implements WritableFilesystemBackend {
    private readonly opfsRoot;
    constructor(opfsRoot: FileSystemDirectoryHandle);
    /**
     * Create a backend for a specific OPFS directory handle.
     */
    static fromDirectoryHandle(handle: FileSystemDirectoryHandle): OpfsFilesystemBackend;
    /**
     * Create a backend for a specific path in OPFS.
     * The path will be created if `create` is true.
     * @throws Error if OPFS is not available or path doesn't exist (when create=false)
     */
    static fromPath(path: string, create?: boolean): Promise<OpfsFilesystemBackend>;
    clear(): Promise<void>;
    read(path: string): Promise<StreamedFile>;
    isDir(absolutePath: string): Promise<boolean>;
    fileExists(absolutePath: string): Promise<boolean>;
    listFiles(absolutePath: string): Promise<string[]>;
    writeFile(absolutePath: string, data: Uint8Array): Promise<void>;
    mkdir(absolutePath: string, recursive?: boolean): Promise<void>;
    rmdir(absolutePath: string, recursive: boolean): Promise<void>;
    mv(absoluteSource: string, absoluteDestination: string): Promise<void>;
    unlink(absolutePath: string): Promise<void>;
    private readFileAsBuffer;
    private copyDir;
}
/**
 * In-memory writable filesystem backend that stores files in a tree structure.
 */
export declare class InMemoryFilesystemBackend implements WritableFilesystemBackend {
    private root;
    constructor(initialFiles?: Record<string, Uint8Array>);
    read(path: string): Promise<StreamedFile>;
    isDir(absolutePath: string): Promise<boolean>;
    fileExists(absolutePath: string): Promise<boolean>;
    listFiles(absolutePath: string): Promise<string[]>;
    writeFile(absolutePath: string, data: Uint8Array): Promise<void>;
    mkdir(absolutePath: string, recursive?: boolean): Promise<void>;
    rmdir(absolutePath: string, recursive: boolean): Promise<void>;
    mv(absoluteSource: string, absoluteDestination: string): Promise<void>;
    unlink(absolutePath: string): Promise<void>;
    clear(): Promise<void>;
    private writeFileSync;
    private getNode;
    private getDir;
    private getFile;
    /**
     * Get parent directory, throwing if it doesn't exist.
     */
    private getParent;
    /**
     * Get parent directory, creating it if it doesn't exist.
     */
    private getOrCreateParent;
    private ensureDir;
}
