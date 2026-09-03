import type { FileLockManager, RequestedRangeLock, WholeFileLockOp, Pid, Fd } from './file-lock-manager';
import { type LockedRange } from './file-lock-manager';
/**
 * This is the file lock manager for use within JS runtimes like Node.js.
 *
 * A FileLockManagerInMemory is a wrapper around a Map of FileLock instances.
 * It provides methods for locking and unlocking files, as well as finding conflicting locks.
 */
export declare class FileLockManagerInMemory implements FileLockManager {
    locks: Map<string, FileLock>;
    /**
     * Create a new FileLockManagerInMemory instance.
     *
     * @param nativeFlockSync A synchronous flock() function to lock files via the host OS.
     */
    constructor();
    /**
     * Lock the whole file.
     *
     * @param path The path to the file to lock. This should be the path
     *             of the file in the native filesystem.
     * @param op The whole file lock operation to perform.
     * @returns True if the lock was granted, false otherwise.
     */
    lockWholeFile(path: string, 
    /**
     * NOTE: FileLockManagerInMemory does not support waiting for a lock
     * because it is intended to be used with a native file lock manager
     * which does support waiting.
     */
    op: Omit<WholeFileLockOp, 'waitForLock'>): boolean;
    /**
     * Lock a byte range.
     *
     * @param path The path to the file to lock. This should be the path
     *             of the file in the native filesystem.
     * @param requestedLock The byte range lock to perform.
     * @returns True if the lock was granted, false otherwise.
     */
    lockFileByteRange(path: string, 
    /**
     * NOTE: fcntl()-style F_SETLK/F_GETLK do not associate
     * resulting locks with a file descrtiptor, so we ignore fd here.
     */
    requestedLock: Omit<RequestedRangeLock, 'fd'>
    /**
     * NOTE: FileLockManagerInMemory does not support waiting for a lock
     * because it is intended to be used with a native file lock manager
     * which does support waiting.
     */
    ): boolean;
    /**
     * Find the first conflicting byte range lock.
     *
     * @param path The path to the file to find the conflicting lock for.
     * @param desiredLock The desired byte range lock.
     * @returns The first conflicting byte range lock, or undefined if no conflicting lock exists.
     */
    findFirstConflictingByteRangeLock(path: string, 
    /**
     * NOTE: fcntl()-style F_SETLK/F_GETLK do not associate
     * resulting locks with a file descrtiptor, so we ignore fd here.
     */
    desiredLock: Omit<RequestedRangeLock, 'fd'>): Omit<RequestedRangeLock, 'fd'> | undefined;
    /**
     * Release all locks for the given process.
     *
     * @param pid The process ID to release locks for.
     */
    releaseLocksForProcess(pid: number): void;
    /**
     * Release all locks for the given process and file descriptor.
     *
     * @param pid The process ID to release locks for.
     * @param fd The file descriptor to release locks for.
     * @param path The path to the file to release locks for.
     */
    releaseLocksOnFdClose(pid: number, fd: number, nativePath: string): void;
    /**
     * Forget the path if it is unlocked.
     *
     * @param path The path to the file to forget.
     */
    private forgetPathIfUnlocked;
}
/**
 * A FileLock instance encapsulates a native whole-file lock and file locking between
 * php-wasm processes.
 *
 * A FileLock supports php-wasm whole-file locks and byte range locks.
 * Before granting a php-wasm lock, a FileLock ensures that it first holds a compatible
 * native lock. If a compatible native lock cannot be acquired, the php-wasm lock is
 * not granted.
 */
export declare class FileLock {
    private wholeFileLock;
    private rangeLocks;
    constructor();
    /**
     * Lock the whole file.
     *
     * This method corresponds to the flock() function.
     *
     * @param op The whole file lock operation to perform.
     * @returns True if the lock was granted, false otherwise.
     */
    lockWholeFile(op: Omit<WholeFileLockOp, 'waitForLock'>): boolean;
    /**
     * Lock a byte range.
     *
     * This method corresponds to the fcntl() F_SETLK command.
     *
     * @param requestedLock The byte range lock to perform.
     * @returns True if the lock was granted, false otherwise.
     */
    lockFileByteRange(requestedLock: Omit<RequestedRangeLock, 'fd' | 'waitForLock'>): boolean;
    /**
     * Find the first conflicting byte range lock.
     *
     * This method corresponds to the fcntl() F_GETLK command.
     *
     * @param desiredLock The desired byte range lock.
     * @returns The first conflicting byte range lock, or undefined if no conflicting lock exists.
     */
    findFirstConflictingByteRangeLock(
    /**
     * NOTE: fcntl()-style F_SETLK/F_GETLK do not associate
     * resulting locks with a file descrtiptor, so we ignore fd here.
     */
    desiredLock: Omit<RequestedRangeLock, 'fd'>): LockedRange | {
        type: "shared" | "exclusive";
        start: bigint;
        end: bigint;
        pid: number;
    } | undefined;
    /**
     * Release all locks for the given process.
     *
     * @param pid The process ID to release locks for.
     */
    releaseLocksForProcess(pid: Pid): void;
    /**
     * Release all locks for the given process and file descriptor.
     *
     * @param pid The process ID to release locks for.
     * @param fd The file descriptor to release locks for.
     */
    releaseLocksOnFdClose(pid: Pid, fd: Fd): void;
    /**
     * Check if the file lock is unlocked.
     *
     * @returns True if the file lock is unlocked, false otherwise.
     */
    isUnlocked(): boolean;
    /**
     * Check if a lock exists that conflicts with the requested range lock.
     *
     * @param requestedLock The desired byte range lock.
     * @returns True if a conflicting lock exists, false otherwise.
     */
    private isThereAConflictWithRequestedRangeLock;
    /**
     * Check if a lock exists that conflicts with the requested whole-file lock.
     *
     * @param requestedLock The desired whole-file lock.
     * @returns True if a conflicting lock exists, false otherwise.
     */
    private isThereAConflictWithRequestedWholeFileLock;
}
