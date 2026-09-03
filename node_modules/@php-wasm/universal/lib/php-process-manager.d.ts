import type { PHP } from './php';
import type { PHPInstanceManager, AcquiredPHP } from './php-instance-manager';
export type PHPFactoryOptions = {
    isPrimary: boolean;
};
export type PHPFactory = (options: PHPFactoryOptions) => Promise<PHP>;
export interface ProcessManagerOptions {
    /**
     * The maximum number of PHP instances that can be in use at
     * the same time.
     */
    maxPhpInstances?: number;
    /**
     * The number of milliseconds to wait for a PHP instance when
     * all instances are busy. If the timeout is reached, we assume
     * all the PHP instances are deadlocked and throw MaxPhpInstancesError.
     *
     * Default: 30000
     */
    timeout?: number;
    /**
     * A factory function used for spawning new PHP instances.
     */
    phpFactory?: PHPFactory;
}
export declare class MaxPhpInstancesError extends Error {
    constructor(limit: number);
}
/**
 * A PHP Process manager that maintains a pool of reusable PHP instances.
 *
 * Instances are spawned on demand up to `maxPhpInstances` and reused across
 * requests. The first instance spawned is the "primary" instance which
 * contains the reference filesystem used by all other instances.
 *
 * The semaphore controls how many requests can be processed concurrently.
 * When all instances are busy, new requests wait in a queue until an
 * instance becomes available or the timeout is reached.
 */
export declare class PHPProcessManager implements PHPInstanceManager {
    /** All PHP instances that have been spawned. */
    private instances;
    /** Instances that are currently idle and available for use. */
    private idleInstances;
    /** Maximum number of concurrent PHP instances allowed. */
    private maxPhpInstances;
    /** Factory function for creating new PHP instances. */
    private phpFactory?;
    /** Controls concurrent access to PHP instances. */
    private semaphore;
    /** Prevents spawning duplicate primary instances during concurrent calls. */
    private primaryPhpPromise?;
    constructor(options?: ProcessManagerOptions);
    /**
     * Get the primary PHP instance (the first one spawned).
     * If no instance exists yet, one will be spawned and marked as idle.
     */
    getPrimaryPhp(): Promise<PHP>;
    /**
     * Acquire a PHP instance for processing a request.
     *
     * Returns an idle instance from the pool, or spawns a new one if
     * the pool isn't at capacity. If all instances are busy, waits
     * until one becomes available.
     *
     * @throws {MaxPhpInstancesError} when the timeout is reached waiting
     *                                for an available instance.
     */
    acquirePHPInstance(): Promise<AcquiredPHP>;
    /**
     * Get an idle instance or spawn a new one.
     */
    private getOrSpawnInstance;
    /**
     * Spawn a new PHP instance.
     */
    private spawnInstance;
    [Symbol.asyncDispose](): Promise<void>;
}
