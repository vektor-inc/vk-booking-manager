/// <reference types="node" />
import type { MessagePort as NodeMessagePort } from 'worker_threads';
/**
 * Comlink library protocol extension to use synchronous messaging.
 *
 * Debugging Asyncify is too much of a burden. This extension enables exchanging
 * messages between threads synchronously so that we don't need to rely on Asyncify.
 *
 * Upsides:
 *
 * * Saves dozens-to-hundreds of hours on debugging Asyncify issues
 * * Increased reliability
 * * Useful stack traces when errors do happen.
 *
 * Downsides:
 *
 * * Fragmentation: Both synchronous and asynchronous handlers exist to get the best our of both
 * Asyncify and JSPI. * Node.js-only: This extension does not implement a Safari-friendly
 * transport. SharedArrayBuffer is an option, but
 *                 it requires more restrictive CORP+COEP headers which breaks, e.g., YouTube
 *                 embeds. Synchronous XHR might work if we really need Safari support for one of
 *                 the new asynchronous features, but other than that let's just skip adding new
 *                 asynchronous WASM features to Safari until WebKit supports stack switching.
 * * Message passing between workers is slow. Avoid using synchronous messaging for syscalls that
 * are invoked frequently and
 *   handled asynchronously in the same worker.
 *
 * @see https://github.com/adamziel/js-synchronous-messaging for additional ideas.
 * @see https://github.com/WordPress/wordpress-playground/blob/9a9262cc62cc161d220a9992706b9ed2817f2eb5/packages/docs/site/docs/developers/23-architecture/07-wasm-asyncify.md
 */
interface SyncMessage {
    /** original Comlink envelope            */
    id?: string;
    type: MessageType;
    /** existing Comlink fields …            */
    [k: string]: any;
    /** new part that carries the latch      */
    notifyBuffer?: SharedArrayBuffer;
}
interface SyncTransport {
    afterResponseSent(ev: MessageEvent): void;
    send(ep: IsomorphicMessagePort, msg: Omit<SyncMessage, 'id' | 'notifyBuffer'>, transferables?: Transferable[]): WireValue;
}
export declare function exposeSync(obj: any, ep: Endpoint, transport: SyncTransport, allowedOrigins?: (string | RegExp)[]): void;
export declare function wrapSync<T>(ep: IsomorphicMessagePort, transport: SyncTransport): T;
export type IsomorphicMessagePort = MessagePort | NodeMessagePort;
export declare class NodeSABSyncReceiveMessageTransport {
    private static receiveMessageOnPort;
    static create(): Promise<NodeSABSyncReceiveMessageTransport>;
    private constructor();
    afterResponseSent(ev: MessageEvent): void;
    send(ep: IsomorphicMessagePort, msg: Omit<SyncMessage, 'id' | 'notifyBuffer'>, transferables?: Transferable[]): WireValue;
}
/**
 * Original, unmodified Comlink library from Google:
 *
 * @license
 * Copyright 2019 Google LLC
 * SPDX-License-Identifier: Apache-2.0
 */
export declare const proxyMarker: unique symbol;
export declare const createEndpoint: unique symbol;
export declare const releaseProxy: unique symbol;
export declare const finalizer: unique symbol;
/**
 * @license
 * Copyright 2019 Google LLC
 * SPDX-License-Identifier: Apache-2.0
 */
export interface EventSource {
    addEventListener(type: string, listener: EventListenerOrEventListenerObject, options?: object): void;
    removeEventListener(type: string, listener: EventListenerOrEventListenerObject, options?: object): void;
}
export interface PostMessageWithOrigin {
    postMessage(message: any, targetOrigin: string, transfer?: Transferable[]): void;
}
export interface Endpoint extends EventSource {
    postMessage(message: any, transfer?: Transferable[]): void;
    start?: () => void;
}
export declare const WireValueType: {
    readonly RAW: "RAW";
    readonly PROXY: "PROXY";
    readonly THROW: "THROW";
    readonly HANDLER: "HANDLER";
};
export type WireValueType = typeof WireValueType;
export interface RawWireValue {
    id?: string;
    type: WireValueType['RAW'];
    value: any;
}
export interface HandlerWireValue {
    id?: string;
    type: WireValueType['HANDLER'];
    name: string;
    value: unknown;
}
export type WireValue = RawWireValue | HandlerWireValue;
export type MessageID = string;
export declare const MessageType: {
    readonly GET: "GET";
    readonly SET: "SET";
    readonly APPLY: "APPLY";
    readonly CONSTRUCT: "CONSTRUCT";
    readonly ENDPOINT: "ENDPOINT";
    readonly RELEASE: "RELEASE";
};
export type MessageType = typeof MessageType;
export interface GetMessage {
    id?: MessageID;
    type: MessageType['GET'];
    path: string[];
}
export interface SetMessage {
    id?: MessageID;
    type: MessageType['SET'];
    path: string[];
    value: WireValue;
}
export interface ApplyMessage {
    id?: MessageID;
    type: MessageType['APPLY'];
    path: string[];
    argumentList: WireValue[];
}
export interface ConstructMessage {
    id?: MessageID;
    type: MessageType['CONSTRUCT'];
    path: string[];
    argumentList: WireValue[];
}
export interface EndpointMessage {
    id?: MessageID;
    type: MessageType['ENDPOINT'];
}
export interface ReleaseMessage {
    id?: MessageID;
    type: MessageType['RELEASE'];
}
export type Message = GetMessage | SetMessage | ApplyMessage | ConstructMessage | EndpointMessage | ReleaseMessage;
/**
 * Interface of values that were marked to be proxied with `comlink.proxy()`.
 * Can also be implemented by classes.
 */
export interface ProxyMarked {
    [proxyMarker]: true;
}
/**
 * Takes a type and wraps it in a Promise, if it not already is one.
 * This is to avoid `Promise<Promise<T>>`.
 *
 * This is the inverse of `Unpromisify<T>`.
 */
type Promisify<T> = T extends Promise<unknown> ? T : Promise<T>;
/**
 * Takes a type that may be Promise and unwraps the Promise type.
 * If `P` is not a Promise, it returns `P`.
 *
 * This is the inverse of `Promisify<T>`.
 */
type Unpromisify<P> = P extends Promise<infer T> ? T : P;
/**
 * Takes the raw type of a remote property and returns the type that is visible to the local thread
 * on the proxy.
 *
 * Note: This needs to be its own type alias, otherwise it will not distribute over unions.
 * See https://www.typescriptlang.org/docs/handbook/advanced-types.html#distributive-conditional-types
 */
type RemoteProperty<T> = T extends Function | ProxyMarked ? Remote<T> : Promisify<T>;
/**
 * Takes the raw type of a property as a remote thread would see it through a proxy (e.g. when
 * passed in as a function argument) and returns the type that the local thread has to supply.
 *
 * This is the inverse of `RemoteProperty<T>`.
 *
 * Note: This needs to be its own type alias, otherwise it will not distribute over unions. See
 * https://www.typescriptlang.org/docs/handbook/advanced-types.html#distributive-conditional-types
 */
type LocalProperty<T> = T extends Function | ProxyMarked ? Local<T> : Unpromisify<T>;
/**
 * Proxies `T` if it is a `ProxyMarked`, clones it otherwise (as handled by structured cloning and
 * transfer handlers).
 */
export type ProxyOrClone<T> = T extends ProxyMarked ? Remote<T> : T;
/**
 * Inverse of `ProxyOrClone<T>`.
 */
export type UnproxyOrClone<T> = T extends RemoteObject<ProxyMarked> ? Local<T> : T;
/**
 * Takes the raw type of a remote object in the other thread and returns the type as it is visible
 * to the local thread when proxied with `Comlink.proxy()`.
 *
 * This does not handle call signatures, which is handled by the more general `Remote<T>` type.
 *
 * @template T The raw type of a remote object as seen in the other thread.
 */
export type RemoteObject<T> = {
    [P in keyof T]: RemoteProperty<T[P]>;
};
/**
 * Takes the type of an object as a remote thread would see it through a proxy (e.g. when passed in
 * as a function argument) and returns the type that the local thread has to supply.
 *
 * This does not handle call signatures, which is handled by the more general `Local<T>` type.
 *
 * This is the inverse of `RemoteObject<T>`.
 *
 * @template T The type of a proxied object.
 */
export type LocalObject<T> = {
    [P in keyof T]: LocalProperty<T[P]>;
};
/**
 * Additional special comlink methods available on each proxy returned by `Comlink.wrap()`.
 */
export interface ProxyMethods {
    [createEndpoint]: () => Promise<MessagePort>;
    [releaseProxy]: () => void;
}
/**
 * Takes the raw type of a remote object, function or class in the other thread and returns the
 * type as it is visible to the local thread from the proxy return value of `Comlink.wrap()` or
 * `Comlink.proxy()`.
 */
export type Remote<T> = RemoteObject<T> & (T extends (...args: infer TArguments) => infer TReturn ? (...args: {
    [I in keyof TArguments]: UnproxyOrClone<TArguments[I]>;
}) => Promisify<ProxyOrClone<Unpromisify<TReturn>>> : unknown) & (T extends {
    new (...args: infer TArguments): infer TInstance;
} ? {
    new (...args: {
        [I in keyof TArguments]: UnproxyOrClone<TArguments[I]>;
    }): Promisify<Remote<TInstance>>;
} : unknown) & ProxyMethods;
/**
 * Expresses that a type can be either a sync or async.
 */
type MaybePromise<T> = Promise<T> | T;
/**
 * Takes the raw type of a remote object, function or class as a remote thread would see it through
 * a proxy (e.g. when passed in as a function argument) and returns the type the local thread has
 * to supply.
 *
 * This is the inverse of `Remote<T>`. It takes a `Remote<T>` and returns its original input `T`.
 */
export type Local<T> = Omit<LocalObject<T>, keyof ProxyMethods> & (T extends (...args: infer TArguments) => infer TReturn ? (...args: {
    [I in keyof TArguments]: ProxyOrClone<TArguments[I]>;
}) => MaybePromise<UnproxyOrClone<Unpromisify<TReturn>>> : unknown) & (T extends {
    new (...args: infer TArguments): infer TInstance;
} ? {
    new (...args: {
        [I in keyof TArguments]: ProxyOrClone<TArguments[I]>;
    }): MaybePromise<Local<Unpromisify<TInstance>>>;
} : unknown);
/**
 * Customizes the serialization of certain values as determined by `canHandle()`.
 *
 * @template T The input type being handled by this transfer handler.
 * @template S The serialized type sent over the wire.
 */
export interface TransferHandler<T, S> {
    /**
     * Gets called for every value to determine whether this transfer handler
     * should serialize the value, which includes checking that it is of the right
     * type (but can perform checks beyond that as well).
     */
    canHandle(value: unknown): value is T;
    /**
     * Gets called with the value if `canHandle()` returned `true` to produce a
     * value that can be sent in a message, consisting of structured-cloneable
     * values and/or transferrable objects.
     */
    serialize(value: T): [S, Transferable[]];
    /**
     * Gets called to deserialize an incoming value that was serialized in the
     * other thread with this transfer handler (known through the name it was
     * registered under).
     */
    deserialize(value: S): T;
}
/**
 * Allows customizing the serialization of certain values.
 */
export declare const transferHandlers: Map<string, TransferHandler<unknown, unknown>>;
export declare function expose(obj: any, ep?: Endpoint, allowedOrigins?: (string | RegExp)[], afterResponseSent?: (ev: MessageEvent) => void): void;
export declare function wrap<T>(ep: Endpoint, target?: any): Remote<T>;
export declare function transfer<T>(obj: T, transfers: Transferable[]): T;
export declare function proxy<T extends object>(obj: T): T & ProxyMarked;
export declare function windowEndpoint(w: PostMessageWithOrigin, context?: EventSource, targetOrigin?: string): Endpoint;
export interface NodeEndpoint {
    postMessage(message: any, transfer?: any[]): void;
    on(type: string, listener: EventListenerOrEventListenerObject, options?: object): void;
    off(type: string, listener: EventListenerOrEventListenerObject, options?: object): void;
    start?: () => void;
}
export declare function nodeEndpoint(nep: NodeEndpoint): Endpoint;
export {};
