/**
 * Error thrown when a CORS proxy response appears to have been
 * intercepted by a network firewall or corporate proxy.
 *
 * This is detected when a response from the CORS proxy is missing
 * the X-Playground-Cors-Proxy header that legitimate responses include.
 */
export declare class FirewallInterferenceError extends Error {
    readonly url: string;
    readonly status: number;
    readonly statusText: string;
    constructor(url: string, status: number, statusText: string);
}
