/**
 * Per-process syscall implementations (flock, fcntl, etc.) that run
 * in the context of a single WASM PHP process. Analogous to OS
 * user space: each process gets its own instance bound to its PID,
 * constants, and file descriptor table.
 */
import type { Emscripten } from '@php-wasm/universal';
import type { WasmKernelSpace } from './wasm-kernel-space';
type FSNode = Emscripten.FS.FSNode;
type HeapAccessor<T> = {
    get(offset: number): T;
    set(offset: number, value: T): void;
};
type NonZeroNumber = Exclude<number, 0>;
export type WasmUserSpaceContext = {
    pid: number;
    constants: {
        F_RDLCK: number;
        F_WRLCK: number;
        F_UNLCK: number;
        F_GETFL: number;
        O_ACCMODE: number;
        O_RDONLY: number;
        O_WRONLY: number;
        O_APPEND: number;
        O_NONBLOCK: number;
        F_SETFL: number;
        F_GETLK: number;
        F_SETLK: number;
        F_SETLKW: number;
        SEEK_SET: number;
        SEEK_CUR: number;
        SEEK_END: number;
        LOCK_SH: 1;
        LOCK_EX: 2;
        LOCK_NB: 4;
        LOCK_UN: 8;
    };
    errnoCodes: {
        EBADF: NonZeroNumber;
        EINVAL: NonZeroNumber;
        EAGAIN: NonZeroNumber;
        EDEADLK: NonZeroNumber;
        EWOULDBLOCK: NonZeroNumber;
    };
    memory: {
        HEAP8: HeapAccessor<number>;
        HEAPU8: HeapAccessor<number>;
        HEAP16: HeapAccessor<number>;
        HEAPU16: HeapAccessor<number>;
        HEAP32: HeapAccessor<number>;
        HEAPU32: HeapAccessor<number>;
        HEAPF32: HeapAccessor<number>;
        HEAP64: HeapAccessor<bigint>;
        HEAPU64: HeapAccessor<bigint>;
        HEAPF64: HeapAccessor<bigint>;
    };
    wasmImports: {
        builtin_fcntl64: (fd: number, cmd: number, varargs?: any) => number;
        builtin_fd_close: (fd: number) => number;
        js_wasm_trace: (...args: any[]) => void;
    };
    wasmExports: {
        wasm_get_end_offset: (fd: number) => bigint;
    };
    syscalls: {
        getStreamFromFD: (fd: number) => Emscripten.FS.FSStream;
    };
    FS: typeof Emscripten.FS;
    PROXYFS: typeof Emscripten.PROXYFS & {
        realPath(node: FSNode): string;
    };
    NODEFS: typeof Emscripten.NODEFS & {
        realPath(node: FSNode): string;
    };
};
export type WasmUserSpaceAPI = {
    fcntl64: (fd: number, cmd: number, varargs?: number) => number;
    flock: (fd: number, op: number) => number;
    fd_close: (fd: number) => number;
    js_release_file_locks: () => void;
    gethostbyname: (hostname: string) => Promise<string>;
};
export declare function bindUserSpace({ fileLockManager }: WasmKernelSpace, { pid, memory, constants: { F_RDLCK, F_WRLCK, F_UNLCK, F_GETFL, O_ACCMODE, O_RDONLY, O_WRONLY, O_APPEND, O_NONBLOCK, F_SETFL, F_GETLK, F_SETLK, F_SETLKW, SEEK_SET, SEEK_CUR, SEEK_END, LOCK_SH, LOCK_EX, LOCK_NB, LOCK_UN, }, errnoCodes: { EBADF, EINVAL, EAGAIN, EWOULDBLOCK }, wasmImports: { builtin_fcntl64, builtin_fd_close, js_wasm_trace }, wasmExports: { wasm_get_end_offset }, syscalls: { getStreamFromFD }, FS, PROXYFS, NODEFS, }: WasmUserSpaceContext): WasmUserSpaceAPI;
export {};
