export type ErrorObject = {
    name?: string;
    message?: string;
    stack?: string;
    cause?: unknown;
    code?: string;
} & Record<string, unknown>;
export declare const errorConstructors: Map<unknown, unknown>;
export declare function addKnownErrorConstructor(constructor: any): void;
export declare class NonError extends Error {
    name: string;
    constructor(message: any);
    static _prepareSuperMessage(message: any): string;
}
export declare function serializeError(value: any, options?: any): any;
export declare function deserializeError(value: any, options?: any): any;
export declare function isErrorLike(value: any): boolean;
