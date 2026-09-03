/**
 * Polyfills Node.js EventEmitter API. The main goal is to enable
 * using a child_process.spawn()-like API in both Node.js and the browser.
 *
 * @see https://nodejs.org/api/events.html#events_class_eventemitter
 */
type Listener = (...args: any[]) => any;
export declare class EventEmitterPolyfill {
    listeners: Record<string, Listener[]>;
    emit(eventName: string, data?: any): void;
    on(eventName: string, listener: Listener): void;
    once(eventName: string, listener: Listener): void;
    off(eventName: string, listener: Listener): void;
}
export {};
