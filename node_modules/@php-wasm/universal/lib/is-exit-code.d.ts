/**
 * Check if the Emscripten-thrown error is an exit code 0 error.
 *
 * @param e The error to check
 * @returns True if the error appears to represent an exit code or status
 */
export declare function isExitCode(e: any): e is {
    status: number;
};
