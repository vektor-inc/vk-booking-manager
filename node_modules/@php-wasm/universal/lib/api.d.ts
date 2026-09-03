import { releaseProxy, type NodeEndpoint as NodeWorker, type Remote, type IsomorphicMessagePort, type ProxyMethods } from './comlink-sync';
import { type NodeProcess } from './comlink-node-process-adapter';
export declare const releaseApiProxy: typeof releaseProxy;
export type WithAPIState = {
    /**
     * Resolves to true when the remote API is ready for
     * Comlink communication, but not necessarily fully initialized yet.
     */
    isConnected: () => Promise<void>;
    /**
     * Resolves to true when the remote API is declares it's
     * fully loaded and ready to be used.
     */
    isReady: () => Promise<void>;
};
export type RemoteAPI<T> = Remote<T> & ProxyMethods & WithAPIState;
export declare function consumeAPISync<APIType>(remote: IsomorphicMessagePort): Promise<APIType>;
export declare function consumeAPI<APIType>(remote: Worker | Window | NodeWorker | NodeProcess, context?: undefined | EventTarget): RemoteAPI<APIType>;
export type PublicAPI<Methods, PipedAPI = unknown> = RemoteAPI<Methods & PipedAPI>;
export declare function exposeAPI<Methods, PipedAPI>(apiMethods?: Methods, pipedApi?: PipedAPI, targetWorker?: MessagePort | NodeWorker | NodeProcess): [() => void, (e: Error) => void, PublicAPI<Methods, PipedAPI>];
export declare function exposeSyncAPI<Methods>(apiMethods: Methods, port: IsomorphicMessagePort): Promise<[() => void, (e: Error) => void, Methods]>;
/**
 * Bridges a ReadableStream to a MessagePort by reading chunks and posting
 * messages to the port. Used as a fallback when transferable streams are not
 * supported (e.g., Safari).
 *
 * Protocol of the returned MessagePort:
 *
 *   { t: 'chunk', b: ArrayBuffer } – next binary chunk
 *   { t: 'close' }                 – end of stream
 *   { t: 'error', m: string }      – terminal error
 */
export declare function streamToPort(stream: ReadableStream<Uint8Array>): MessagePort;
/**
 * Reconstructs a ReadableStream from a MessagePort using the inverse of the
 * streamToPort protocol. Each message enqueues data, closes, or errors.
 */
export declare function portToStream(port: MessagePort): ReadableStream<Uint8Array>;
