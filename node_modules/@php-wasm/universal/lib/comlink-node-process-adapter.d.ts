import type { Endpoint } from './comlink-sync';
export interface NodeProcess {
    send: (...args: any[]) => unknown;
    addListener: (type: string, listener: EventListenerOrEventListenerObject) => void;
    removeListener: (type: string, listener: EventListenerOrEventListenerObject) => void;
}
export declare function nodeProcessEndpoint(worker?: NodeProcess): Endpoint;
