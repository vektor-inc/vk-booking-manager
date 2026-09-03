/// <reference types="node" />
/**
 * Polyfills Node.js WritableStream API. The main goal is to enable
 * using a child_process.spawn()-like API in both Node.js and the browser.
 *
 * @see https://nodejs.org/api/stream.html#stream_writable_end_chunk_encoding_callback
 */
import { EventEmitterPolyfill } from './event-emitter-polyfill';
export interface WritableOptions {
    highWaterMark?: number;
    decodeStrings?: boolean;
    defaultEncoding?: BufferEncoding;
    write: (chunk: any, encoding: BufferEncoding, cb: WriteCallback) => void;
}
export type WriteCallback = (error?: Error | null) => void;
export declare class WritablePolyfill extends EventEmitterPolyfill {
    private buffer;
    private writing;
    ended: boolean;
    private length;
    private highWaterMark;
    private decodeStrings;
    private defaultEncoding;
    private defer;
    private _write;
    constructor(opts: WritableOptions);
    write(chunk: any, encoding?: BufferEncoding | WriteCallback, cb?: WriteCallback): boolean;
    end(chunk?: any, encoding?: BufferEncoding | WriteCallback, cb?: WriteCallback): void;
    cork(): void;
    uncork(): void;
    setDefaultEncoding(enc: BufferEncoding): this;
    private _clearBuffer;
}
