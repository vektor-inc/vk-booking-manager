import type { UniversalPHP } from '@php-wasm/universal';
export type IterateFilesOptions = {
    /**
     * Should yield paths relative to the root directory?
     * If false, all paths will be absolute.
     */
    relativePaths?: boolean;
    /**
     * The root directory that Playground paths start from.
     */
    playgroundRoot?: string;
    /**
     * A prefix to add to all paths.
     * Only used if `relativePaths` is true.
     */
    pathPrefix?: string;
    /**
     * A list of paths to exclude from the results.
     */
    exceptPaths?: string[];
};
/**
 * Iterates over all files in a Playground directory and its subdirectories.
 *
 * @param playground - The Playground/PHP instance.
 * @param root - The root directory to start iterating from.
 * @param options - Optional configuration.
 * @returns All files found in the tree.
 */
export declare function iterateFiles(playground: UniversalPHP, root: string, { exceptPaths }?: IterateFilesOptions): AsyncGenerator<FileEntry>;
export type FileEntry = {
    path: string;
    read: () => Promise<Uint8Array>;
};
/**
 * Represents a set of changes to be applied to a data store.
 */
export type Changeset = {
    /**
     * Created files.
     */
    create: Map<string, Uint8Array>;
    /**
     * Updated files.
     */
    update: Map<string, Uint8Array>;
    /**
     * Deleted files.
     */
    delete: Set<string>;
};
/**
 * Compares two sets of files and returns a changeset object that describes
 * the differences between them.
 *
 * @param filesBefore - A map of file paths to Uint8Array objects representing the contents
 *                      of the files before the changes.
 * @param filesAfter - An async generator that yields FileEntry objects representing the files
 *                     after the changes.
 * @returns A changeset object that describes the differences between the two sets of files.
 */
export declare function changeset(filesBefore: Map<string, Uint8Array>, filesAfter: AsyncGenerator<FileEntry> | Iterable<FileEntry>): Promise<Changeset>;
