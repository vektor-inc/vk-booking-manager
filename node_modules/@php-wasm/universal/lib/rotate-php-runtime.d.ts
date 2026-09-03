import type { PHP } from './php';
export interface RotateOptions {
    php: PHP;
    cwd?: string;
    recreateRuntime: () => Promise<number> | number;
    maxRequests?: number;
}
/**
 * Configures inline runtime rotation on the provided PHP instance.
 * Returns a cleanup function that disables rotation.
 *
 * @deprecated Use `php.enableRuntimeRotation()` instead.
 */
export declare function rotatePHPRuntime({ php, recreateRuntime, maxRequests, }: RotateOptions): void;
