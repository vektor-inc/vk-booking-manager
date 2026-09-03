import type { StreamedPHPResponse } from './php-response';
import { PHPResponse } from './php-response';
export declare function printDebugDetails(e: any, streamedResponse?: StreamedPHPResponse): Promise<void>;
/**
 * Pretty prints the full stack trace of the error and all its causes.
 * Includes debug details for each error in the chain.
 * This is needed
 *
 * @param e
 */
export declare function prettyPrintFullStackTrace(e: any): Promise<void>;
export declare function printResponseDebugDetails(response: PHPResponse): void;
