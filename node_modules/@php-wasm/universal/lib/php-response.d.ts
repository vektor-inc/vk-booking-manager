export interface PHPResponseData {
    /**
     * Response headers.
     */
    readonly headers: Record<string, string[]>;
    /**
     * Response body. Contains the output from `echo`,
     * `print`, inline HTML etc.
     */
    readonly bytes: Uint8Array;
    /**
     * Stderr contents, if any.
     */
    readonly errors: string;
    /**
     * The exit code of the script. `0` is a success, while
     * `1` and `2` indicate an error.
     */
    readonly exitCode: number;
    /**
     * Response HTTP status code, e.g. 200.
     */
    readonly httpStatusCode: number;
}
export declare class StreamedPHPResponse {
    #private;
    /**
     * Response body. Contains the output from `echo`,
     * `print`, inline HTML etc.
     */
    readonly stdout: ReadableStream<Uint8Array>;
    /**
     * Stderr contents, if any.
     */
    readonly stderr: ReadableStream<Uint8Array>;
    /**
     * The exit code of the script. `0` is a success, anything
     * else is an error.
     */
    readonly exitCode: Promise<number>;
    private cachedParsedHeaders;
    private cachedStdoutBytes;
    private cachedStderrText;
    constructor(headers: ReadableStream<Uint8Array>, stdout: ReadableStream<Uint8Array>, stderr: ReadableStream<Uint8Array>, exitCode: Promise<number>);
    /**
     * Creates a StreamedPHPResponse from a buffered PHPResponse.
     * Useful for unifying response handling when both types may be returned.
     */
    static fromPHPResponse(response: PHPResponse): StreamedPHPResponse;
    /**
     * Creates a StreamedPHPResponse for a given HTTP status code.
     * Shorthand for `StreamedPHPResponse.fromPHPResponse(PHPResponse.forHttpCode(...))`.
     */
    static forHttpCode(httpStatusCode: number, text?: string): StreamedPHPResponse;
    /**
     * Returns the raw headers stream for serialization purposes.
     * For parsed headers, use the `headers` property instead.
     */
    getHeadersStream(): ReadableStream<Uint8Array>;
    /**
     * True if the response is successful (HTTP status code 200-399),
     * false otherwise.
     */
    ok(): Promise<boolean>;
    /**
     * Resolves when the response has finished processing – either successfully or not.
     */
    get finished(): Promise<void>;
    /**
     * Resolves once HTTP headers are available.
     */
    get headers(): Promise<Record<string, string[]>>;
    /**
     * Resolves once HTTP status code is available.
     */
    get httpStatusCode(): Promise<number>;
    /**
     * Exposes the stdout bytes as they're produced by the PHP instance
     */
    get stdoutText(): Promise<string>;
    /**
     * Exposes the stdout bytes as they're produced by the PHP instance
     */
    get stdoutBytes(): Promise<Uint8Array>;
    /**
     * Exposes the stderr bytes as they're produced by the PHP instance
     */
    get stderrText(): Promise<string>;
    private getParsedHeaders;
}
/**
 * PHP response. Body is an `ArrayBuffer` because it can
 * contain binary data.
 *
 * This type is used in Comlink.transferHandlers.set('PHPResponse', \{ ... \})
 * so be sure to update that if you change this type.
 */
export declare class PHPResponse implements PHPResponseData {
    /** @inheritDoc */
    readonly headers: Record<string, string[]>;
    /** @inheritDoc */
    readonly bytes: Uint8Array;
    /** @inheritDoc */
    readonly errors: string;
    /** @inheritDoc */
    readonly exitCode: number;
    /** @inheritDoc */
    readonly httpStatusCode: number;
    constructor(httpStatusCode: number, headers: Record<string, string[]>, body: Uint8Array, errors?: string, exitCode?: number);
    static forHttpCode(httpStatusCode: number, text?: string): PHPResponse;
    static fromRawData(data: PHPResponseData): PHPResponse;
    static fromStreamedResponse(streamedResponse: StreamedPHPResponse): Promise<PHPResponse>;
    /**
     * True if the response is successful (HTTP status code 200-399),
     * false otherwise.
     */
    ok(): boolean;
    toRawData(): PHPResponseData;
    /**
     * Response body as JSON.
     */
    get json(): any;
    /**
     * Response body as text.
     */
    get text(): string;
}
