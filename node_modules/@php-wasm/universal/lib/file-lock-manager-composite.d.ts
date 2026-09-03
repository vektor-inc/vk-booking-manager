import { type Path, type RequestedRangeLock, type WholeFileLockOp, type FileLockManager } from './file-lock-manager';
export declare class FileLockManagerComposite implements FileLockManager {
    nativeLockManager: FileLockManager;
    wasmLockManager: FileLockManager;
    constructor({ nativeLockManager, wasmLockManager, }: {
        nativeLockManager: FileLockManager;
        wasmLockManager: FileLockManager;
    });
    lockWholeFile(path: Path, op: WholeFileLockOp): boolean;
    lockFileByteRange(path: Path, requestedLock: RequestedRangeLock, waitForLock: boolean): boolean;
    findFirstConflictingByteRangeLock(path: Path, desiredLock: RequestedRangeLock): Omit<RequestedRangeLock, 'fd'> | undefined;
    releaseLocksForProcess(pid: number): void;
    releaseLocksOnFdClose(pid: number, fd: number, path: Path): void;
}
