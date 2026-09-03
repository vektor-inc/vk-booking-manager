/**
 * Custom error class for git authentication failures.
 */
export declare class GitAuthenticationError extends Error {
    repoUrl: string;
    status: number;
    constructor(repoUrl: string, status: number);
}
export type GitAdditionalHeaders = Record<string, string>;
/**
 * Downloads specific files from a git repository.
 * It uses the git protocol over HTTP to fetch the files. It only uses
 * three HTTP requests regardless of the number of paths requested.
 *
 * @param repoUrl The URL of the git repository.
 * @param fullyQualifiedBranchName The full name of the branch to fetch from (e.g., 'refs/heads/main').
 * @param filesPaths An array of all the file paths to fetch from the repository. Does **not** accept
 *                   patterns, wildcards, directory paths. All files must be explicitly listed.
 * @returns The requested files and packfiles required to recreate the Git objects locally.
 */
export type SparseCheckoutPackfile = {
    name: string;
    pack: Uint8Array;
    index: Uint8Array;
    promisor?: boolean;
};
export type SparseCheckoutObject = {
    oid: string;
    type: 'blob' | 'tree' | 'commit' | 'tag';
    body: Uint8Array;
};
export type SparseCheckoutResult = {
    files: Record<string, any>;
    packfiles?: SparseCheckoutPackfile[];
    objects?: SparseCheckoutObject[];
    fileOids?: Record<string, string>;
};
export declare function sparseCheckout(repoUrl: string, commitHash: string, filesPaths: string[], options?: {
    withObjects?: boolean;
    additionalHeaders?: GitAdditionalHeaders;
}): Promise<SparseCheckoutResult>;
export type GitFileTreeFile = {
    name: string;
    type: 'file';
};
export type GitFileTreeFolder = {
    name: string;
    type: 'folder';
    children: GitFileTree[];
};
export type GitFileTree = GitFileTreeFile | GitFileTreeFolder;
/**
 * A Git ref in a human-readable format. Could be a single string,
 * e.g. 'main', 'v0.1.28', '1234567890abcdef1234567890abcdef12345678',
 * could be a string and an explicit type, e.g. { value: 'main', type: 'branch' },
 */
export type GitRef = {
    value: string;
    type?: 'branch' | 'commit' | 'refname' | 'tag' | 'infer';
};
/**
 * Lists all files in a git repository.
 *
 * See https://git-scm.com/book/en/v2/Git-Internals-Git-Objects for more information.
 *
 * @param repoUrl The URL of the git repository.
 * @param commitHash The commit hash to fetch from.
 * @returns A list of all files in the repository.
 */
export declare function listGitFiles(repoUrl: string, commitHash: string, additionalHeaders?: GitAdditionalHeaders): Promise<GitFileTree[]>;
/**
 * Resolves a ref description, e.g. a branch name, to a commit hash.
 *
 * @param repoUrl The URL of the git repository.
 * @param ref The branch name or commit hash.
 * @returns The commit hash.
 */
export declare function resolveCommitHash(repoUrl: string, ref: GitRef, additionalHeaders?: GitAdditionalHeaders): Promise<string>;
/**
 * Retrieves a list of refs from a git repository.
 *
 * See https://git-scm.com/book/en/v2/Git-Internals-Git-References for more information.
 *
 * @param repoUrl The URL of the git repository. For example: https://github.com/WordPress/gutenberg.git
 * @param fullyQualifiedBranchPrefix The prefix of the refs to fetch. For example: refs/heads/my-feature-branch
 * @returns A map of refs to their corresponding commit hashes.
 */
export declare function listGitRefs(repoUrl: string, fullyQualifiedBranchPrefix: string, additionalHeaders?: GitAdditionalHeaders): Promise<Record<string, string>>;
