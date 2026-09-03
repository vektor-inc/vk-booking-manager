import type { GitFileTree } from './git-sparse-checkout';
export declare function listDescendantFiles(files: GitFileTree[], selectedPath: string): string[];
export declare function removePathPrefix(path: string, prefix: string): string;
