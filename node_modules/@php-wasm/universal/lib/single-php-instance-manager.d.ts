import type { PHP } from './php';
import type { PHPInstanceManager, AcquiredPHP } from './php-instance-manager';
export interface SinglePHPInstanceManagerOptions {
    /**
     * Either provide an existing PHP instance...
     */
    php?: PHP;
    /**
     * ...or a factory to create one on demand.
     */
    phpFactory?: () => Promise<PHP>;
}
/**
 * A minimal PHP instance manager that manages a single PHP instance.
 *
 * Unlike PHPProcessManager, this does not maintain a pool of instances
 * or implement concurrency control. It simply returns the same PHP
 * instance for every request.
 *
 * This is suitable for CLI contexts where:
 * - Only one PHP instance is needed
 * - Runtime rotation is handled separately via php.enableRuntimeRotation()
 * - Concurrency is not a concern (each worker has its own instance)
 */
export declare class SinglePHPInstanceManager implements PHPInstanceManager {
    private php;
    private phpPromise;
    private phpFactory?;
    private isAcquired;
    constructor(options: SinglePHPInstanceManagerOptions);
    getPrimaryPhp(): Promise<PHP>;
    acquirePHPInstance(): Promise<AcquiredPHP>;
    [Symbol.asyncDispose](): Promise<void>;
}
