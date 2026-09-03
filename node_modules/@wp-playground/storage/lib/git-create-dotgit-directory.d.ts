import type { SparseCheckoutObject } from './git-sparse-checkout';
type GitDirectoryRefType = 'branch' | 'tag' | 'commit' | 'refname';
/**
 * Creates a complete .git directory structure with all necessary files.
 * This includes HEAD, config, refs, objects, and the Git index.
 */
export declare function createDotGitDirectory({ repoUrl, commitHash, ref, refType, objects, fileOids, pathPrefix, }: {
    repoUrl: string;
    commitHash: string;
    ref: string;
    refType?: GitDirectoryRefType;
    objects: SparseCheckoutObject[];
    fileOids: Record<string, string>;
    pathPrefix: string;
}): Promise<Record<string, string | Uint8Array>>;
export {};
