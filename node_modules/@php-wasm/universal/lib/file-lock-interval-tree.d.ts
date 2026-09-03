import type { ByteRange, LockedRange, RequestedRangeLock } from './file-lock-manager';
export declare class FileLockIntervalTree {
    private root;
    isEmpty(): boolean;
    /**
     * Insert a new locked range into the tree
     */
    insert(range: LockedRange): void;
    /**
     * Find all ranges that overlap with the given range
     */
    findOverlapping(range: ByteRange): LockedRange[];
    /**
     * Remove a lock range from the tree
     */
    remove(range: RequestedRangeLock): void;
    /**
     * Find all ranges locked by the given process.
     *
     * @param pid The process ID to find locks for.
     * @returns All locked ranges for the given process.
     */
    findLocksForProcess(pid: number): RequestedRangeLock[];
    /**
     * Find the strictest existing lock type in the range lock tree.
     *
     * @returns The strictest existing lock type, or 'unlocked' if no locks exist.
     */
    findStrictestExistingLockType(): RequestedRangeLock['type'];
    private insertNode;
    private bigintMax;
    private findOverlappingRanges;
    private doRangesOverlap;
    private removeNode;
    private findMin;
    private areRangesEqual;
    private findLocksForProcessInNode;
}
