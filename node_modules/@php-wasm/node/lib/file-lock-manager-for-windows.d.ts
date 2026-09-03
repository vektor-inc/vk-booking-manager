import type { FileLockManager, WholeFileLockOp, RequestedRangeLock, Path } from '@php-wasm/universal';
import { FileLockIntervalTree } from '@php-wasm/universal';
type StoredWholeFileLock = WholeFileLockOp & {
    path: Path;
};
export declare class FileLockManagerForWindows implements FileLockManager {
    wholeFileLockMap: Map<number, Map<number, StoredWholeFileLock>>;
    rangeLockedFds: Map<string, FileLockIntervalTree>;
    lockWholeFile(path: string, op: WholeFileLockOp): boolean;
    lockFileByteRange(path: string, op: RequestedRangeLock, waitForLock: boolean): boolean;
    findFirstConflictingByteRangeLock(path: string, op: RequestedRangeLock): ReturnType<FileLockManager['findFirstConflictingByteRangeLock']>;
    releaseLocksForProcess(targetPid: number): void;
    releaseLocksOnFdClose(targetPid: number, targetFd: number, targetPath: string): void;
}
export {};
