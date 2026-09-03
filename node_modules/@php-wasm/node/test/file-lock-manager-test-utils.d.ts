/// <reference types="node" />
import { closeSync, openSync } from 'fs';
import { type FileLockManager, type RequestedRangeLock } from '@php-wasm/universal';
export type RequestedRangeLockWithNonBigIntAddresses = Omit<RequestedRangeLock, 'start' | 'end'> & {
    start: number;
    end: number;
};
export type TestWorkerAPI = Omit<FileLockManager, 'lockFileByteRange' | 'findFirstConflictingByteRangeLock'> & {
    lockFileByteRange: (path: string, requestedLock: RequestedRangeLockWithNonBigIntAddresses, waitForLock: boolean) => boolean;
    findFirstConflictingByteRangeLock: (path: string, requestedLock: RequestedRangeLockWithNonBigIntAddresses) => Omit<RequestedRangeLockWithNonBigIntAddresses, 'fd'> | undefined;
    openSync: typeof openSync;
    closeSync: typeof closeSync;
};
/**
 * Create a remote process API for a file lock manager.
 *
 * @param fileLockManager - The file lock manager to create a remote process API for.
 * @returns An API for the remote test process to expose.
 */
export declare function createRemoteProcessAPIFromFileLockManager(fileLockManager: FileLockManager): TestWorkerAPI;
