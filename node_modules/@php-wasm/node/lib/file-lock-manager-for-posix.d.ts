import type { FileLockManager, WholeFileLockOp, RequestedRangeLock, Path } from '@php-wasm/universal';
type StoredWholeFileLock = WholeFileLockOp & {
    path: Path;
};
export declare class FileLockManagerForPosix implements FileLockManager {
    wholeFileLockMap: Map<number, Map<number, StoredWholeFileLock>>;
    rangeLockedFds: Map<number, Map<string, Set<number>>>;
    lockWholeFile(path: string, op: WholeFileLockOp): boolean;
    lockFileByteRange(path: string, op: RequestedRangeLock, waitForLock: boolean): boolean;
    findFirstConflictingByteRangeLock(path: string, op: RequestedRangeLock): ReturnType<FileLockManager['findFirstConflictingByteRangeLock']>;
    releaseLocksForProcess(targetPid: number): void;
    releaseLocksOnFdClose(targetPid: number, targetFd: number, targetPath: string): void;
}
export {};
