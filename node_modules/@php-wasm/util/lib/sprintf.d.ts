/**
 * Formats a string like sprintf().
 *
 * This function:
 * - Supports basic format specifiers: %s, %d, %f, %x, %%
 * - Supports bigint values
 *
 * The purpose of this function is for use in optional php-wasm tracing.
 * If we use printf-style formatting for trace messages, we let the trace
 * function decide whether to format and do not have to pay for formatting
 * unless tracing is enabled.
 */
export declare function sprintf(format: string, ...args: any[]): string;
