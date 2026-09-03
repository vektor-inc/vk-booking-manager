import { Octokit } from 'octokit';
import type { Changeset } from './changeset';
export type GithubClient = ReturnType<typeof createClient>;
export declare function createClient(githubToken: string): Octokit;
export type Files = Record<string, Uint8Array>;
export declare function filesListToObject(files: any[], root?: string): Files;
export interface GetFilesProgress {
    foundFiles: number;
    downloadedFiles: number;
}
export interface GetFilesOptions {
    onProgress?: ({ foundFiles, downloadedFiles }: GetFilesProgress) => void;
    progress?: GetFilesProgress;
}
export declare function getFilesFromDirectory(octokit: GithubClient, owner: string, repo: string, ref: string, path: string, options?: GetFilesOptions): Promise<any[]>;
/**
 * Usage:
 * > await getArtifact('wordpress', 'wordpress-develop', 5511, 'build.yml')
 *
 * To get the first artifact produced by the "build.yml" workflow running
 * as a part of the PR 5511
 *
 * @returns
 */
export declare function getArtifact(octokit: GithubClient, owner: string, repo: string, pull_number: number, workflow_id: string): Promise<unknown>;
export declare function mayPush(octokit: Octokit, owner: string, repo: string): Promise<boolean>;
export declare function createOrUpdateBranch(octokit: Octokit, owner: string, repo: string, branch: string, newHead: string): Promise<void>;
/**
 * @param octokit
 * @param owner
 * @param repo
 * @returns The owner of the forked repository
 */
export declare function fork(octokit: Octokit, owner: string, repo: string): Promise<string>;
export declare function createCommit(octokit: Octokit, owner: string, repo: string, message: string, parentSha: string, treeSha: string): Promise<string>;
export declare function createTree(octokit: Octokit, owner: string, repo: string, parentSha: string, changeset: Changeset): Promise<string | null>;
export type GitHubTreeNode = {
    path: string;
    mode: '100644';
} & ({
    sha: string | null;
} | {
    content: string;
});
export declare function createTreeNodes(octokit: Octokit, owner: string, repo: string, parentSha: string, changeset: Changeset): Promise<GitHubTreeNode[]>;
export declare function createTreeNode(octokit: Octokit, owner: string, repo: string, path: string, content: string | Uint8Array): Promise<GitHubTreeNode>;
export declare function deleteFile(octokit: Octokit, owner: string, repo: string, parentSha: string, path: string): Promise<GitHubTreeNode | undefined>;
