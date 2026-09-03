import { EventEmitterPolyfill } from './event-emitter-polyfill';
import { WritablePolyfill } from './writable-polyfill';
type Listener = (...args: any[]) => any;
export interface ProcessOptions {
    cwd?: string;
    env?: Record<string, string>;
}
/**
 * Usage:
 * ```ts
 * php.setSpawnHandler(
 *   createSpawnHandler(function (command, processApi) {
 *     console.log(processApi.flushStdin());
 *     processApi.stdout('/\n/tmp\n/home');
 *	   processApi.exit(0);
 *   })
 * );
 * ```
 * @param program
 * @returns
 */
export declare function createSpawnHandler(program: (command: string[], processApi: ProcessApi, options: ProcessOptions) => void | Promise<void>): any;
export declare class ProcessApi extends EventEmitterPolyfill {
    exited: boolean;
    /**
     * Keeps track of the data that was written to stdin before the
     * first listener was registered.
     */
    private stdinBuffer;
    childProcess: ChildProcess;
    constructor(childProcess: ChildProcess);
    stdinEnd(): void;
    stdout(data: string | ArrayBuffer): void;
    stdoutEnd(): void;
    stderr(data: string | ArrayBuffer): void;
    stderrEnd(): void;
    notifySpawn(): void;
    exit(code: number): void;
    on(eventName: string, listener: Listener): void;
}
export declare class ChildProcess extends EventEmitterPolyfill {
    stdout: WritablePolyfill;
    stderr: WritablePolyfill;
    stdin: WritablePolyfill;
    pid: number;
    constructor(pid?: number);
}
export {};
