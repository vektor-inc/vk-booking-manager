import type { PHP } from './php';
import type { PHPWorker } from './php-worker';
import type { Remote } from './comlink-sync';
/**
 * An isomorphic proc_open() handler that implements typical shell in TypeScript
 * without relying on a server runtime. It can be used in the browser and Node.js
 * alike whenever you need to spawn a PHP subprocess, query the terminal size, etc.
 * It is open for future expansion if more shell or busybox calls are needed, but
 * advanced shell features such as piping, stream redirection etc. are outside of
 * the scope of this minimal handler. If they become vital at any point, let's
 * explore bringing in an actual shell implementation or at least a proper command
 * parser.
 */
export declare function sandboxedSpawnHandlerFactory(getPHPInstance?: () => Promise<{
    php: PHP | Remote<PHPWorker>;
    reap: () => void;
}>): any;
