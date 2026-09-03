import { type LogHandler } from './log-handlers';
export { logEventType } from './handlers/log-event';
export { errorLogPath } from './collectors/collect-php-logs';
export type Log = {
    message: any;
    severity: LogSeverity;
    prefix?: LogPrefix;
    raw?: boolean;
};
/**
 * Log severity levels.
 */
export declare const LogSeverity: {
    readonly Fatal: {
        readonly name: "fatal";
        readonly level: 0;
    };
    readonly Error: {
        readonly name: "error";
        readonly level: 1;
    };
    readonly Warn: {
        readonly name: "warn";
        readonly level: 2;
    };
    readonly Log: {
        readonly name: "log";
        readonly level: 3;
    };
    readonly Info: {
        readonly name: "info";
        readonly level: 4;
    };
    readonly Debug: {
        readonly name: "debug";
        readonly level: 5;
    };
};
export type LogSeverity = (typeof LogSeverity)[keyof typeof LogSeverity];
/**
 * Log prefix.
 */
export declare const LogPrefix: {
    readonly WASM: "Wasm Crash";
    readonly PHP: "PHP";
    readonly JS: "JavaScript";
};
export type LogPrefix = (typeof LogPrefix)[keyof typeof LogPrefix];
/**
 * A logger for Playground.
 */
export declare class Logger extends EventTarget {
    readonly fatalErrorEvent = "playground-fatal-error";
    private readonly handlers;
    private severity;
    constructor(handlers?: LogHandler[]);
    /**
     * Get all logs.
     * @returns string[]
     */
    getLogs(): string[];
    /**
     * Log message with severity.
     *
     * @param log Log
     * @param args any
     */
    logMessage(log: Omit<Log, 'severity'> & {
        severity?: LogSeverity;
    }, ...args: any[]): void;
    /**
     * Filter message based on severity
     * @param severity LogSeverity
     */
    setSeverityFilterLevel(severity: LogSeverity): void;
    /**
     * Log message
     *
     * @param message any
     * @param args any
     */
    log(message: any, ...args: any[]): void;
    /**
     * Log debug message
     *
     * @param message any
     * @param args any
     */
    debug(message: any, ...args: any[]): void;
    /**
     * Log info message
     *
     * @param message any
     * @param args any
     */
    info(message: any, ...args: any[]): void;
    /**
     * Log warning message
     *
     * @param message any
     * @param args any
     */
    warn(message: any, ...args: any[]): void;
    /**
     * Log error message
     *
     * @param message any
     * @param args any
     */
    error(message: any, ...args: any[]): void;
}
/**
 * The logger instance.
 */
export declare const logger: Logger;
export declare const prepareLogMessage: (message: string) => string;
export declare const formatLogEntry: (message: string, severity: LogSeverity, prefix: string) => string;
/**
 * Add a listener for the Playground crashes.
 * These crashes include Playground errors like Asyncify errors.
 * The callback function will receive an Event object with logs in the detail
 * property.
 *
 * @param loggerInstance The logger instance
 * @param callback The callback function
 */
export declare const addCrashListener: (loggerInstance: Logger, callback: EventListenerOrEventListenerObject) => void;
