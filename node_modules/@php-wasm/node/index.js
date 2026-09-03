var __require = /* @__PURE__ */ ((x) => typeof require !== "undefined" ? require : typeof Proxy !== "undefined" ? new Proxy(x, {
  get: (a, b) => (typeof require !== "undefined" ? require : a)[b]
}) : x)(function(x) {
  if (typeof require !== "undefined")
    return require.apply(this, arguments);
  throw Error('Dynamic require of "' + x + '" is not supported');
});

// packages/php-wasm/node/src/lib/get-php-loader-module.ts
import {
  LatestSupportedPHPVersion,
  isPHPNextVersion
} from "@php-wasm/universal";
async function getPHPLoaderModule(version = LatestSupportedPHPVersion) {
  if (isPHPNextVersion(version)) {
    throw new Error(
      "PHP next is only available in the web runtime for now."
    );
  }
  try {
    switch (version) {
      case "8.5":
        return (await import("@php-wasm/node-8-5")).getPHPLoaderModule();
      case "8.4":
        return (await import("@php-wasm/node-8-4")).getPHPLoaderModule();
      case "8.3":
        return (await import("@php-wasm/node-8-3")).getPHPLoaderModule();
      case "8.2":
        return (await import("@php-wasm/node-8-2")).getPHPLoaderModule();
      case "8.1":
        return (await import("@php-wasm/node-8-1")).getPHPLoaderModule();
      case "8.0":
        return (await import("@php-wasm/node-8-0")).getPHPLoaderModule();
      case "7.4":
        return (await import("@php-wasm/node-7-4")).getPHPLoaderModule();
      case "5.2":
        return (await import("@php-wasm/node-5-2")).getPHPLoaderModule();
    }
    throw new Error(`Unsupported PHP version ${version}`);
  } catch (errorCandidate) {
    if (!errorCandidate || typeof errorCandidate !== "object") {
      throw errorCandidate;
    }
    const error = errorCandidate;
    if (error.message?.includes(
      `SyntaxError: Cannot use 'import.meta' outside a module`
    )) {
      throw new Error(
        `Node.js crashed on a 'import.meta' statement. This happens when running in a node:vm context. Some testing libraries like Jest use heavily customized runtime contexts, involving node:vm, jest-runtime, and custom require() implementations. These contexts do not support 'import.meta' statements and, by extension, cannot run the @php-wasm/node package. Consider using the --experimental-vm-modules flag or switching to a different test runner such as vitest.`,
        {
          cause: error
        }
      );
    } else if (error?.code === `ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING` || error?.code === `ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING_FLAG` || error?.message?.includes(
      `A dynamic import callback was invoked without --experimental-vm-modules`
    ) || error?.message?.includes(
      `A dynamic import callback was not specified`
    ) || error?.message?.includes(`Must use import to load ES Module`)) {
      throw new Error(
        `Node.js crashed on a 'import()' statement. This happens when running in a node:vm context. Some testing libraries like Jest use runtime contexts, involving node:vm, jest-runtime, or other custom require() implementations. These contexts do not support import() statements and, by extension, cannot run the @php-wasm/node package. Consider using the --experimental-vm-modules flag or switching to a different test runner such as vitest.`,
        {
          cause: error
        }
      );
    }
    throw error;
  }
}

// packages/php-wasm/node/src/lib/networking/outbound-ws-to-tcp-proxy.ts
import * as dns from "dns";
import * as http from "http";
import * as net2 from "net";
import { WebSocketServer } from "ws";

// packages/php-wasm/node/src/lib/networking/utils.ts
import * as net from "net";
import { logger } from "@php-wasm/logger";
function debugLog(message, ...args) {
  if (process.env["DEV"] && !process.env["TEST"]) {
    logger.log(message, ...args);
  }
}
async function findFreePorts(n) {
  const serversPromises = [];
  for (let i = 0; i < n; i++) {
    serversPromises.push(listenOnRandomPort());
  }
  const servers = await Promise.all(serversPromises);
  const ports = [];
  for (const server of servers) {
    const address = server.address();
    ports.push(address.port);
    server.close();
  }
  return ports;
}
function listenOnRandomPort() {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.listen(0, () => {
      resolve(server);
    });
  });
}

// packages/php-wasm/node/src/lib/networking/outbound-ws-to-tcp-proxy.ts
function log(...args) {
  debugLog("[WS Server]", ...args);
}
function lookupIPv4(hostname) {
  return new Promise((resolve, reject) => {
    dns.lookup(hostname, { family: 4 }, (err, address) => {
      if (err) {
        reject(err);
      } else {
        resolve(address);
      }
    });
  });
}
function prependByte(chunk, byte) {
  if (typeof chunk === "string") {
    chunk = String.fromCharCode(byte) + chunk;
  } else if (chunk instanceof ArrayBuffer || "byteLength" in chunk) {
    const buffer = new Uint8Array(chunk.byteLength + 1);
    buffer[0] = byte;
    buffer.set(new Uint8Array(chunk), 1);
    chunk = buffer.buffer;
  } else {
    log({ chunk });
    throw new Error("Unsupported chunk type: " + typeof chunk);
  }
  return chunk;
}
var COMMAND_CHUNK = 1;
var COMMAND_SET_SOCKETOPT = 2;
function addSocketOptionsSupportToWebSocketClass(WebSocketConstructor) {
  return class PHPWasmWebSocketConstructor extends WebSocketConstructor {
    // @ts-ignore
    send(chunk, callback) {
      return this.sendCommand(COMMAND_CHUNK, chunk, callback);
    }
    setSocketOpt(optionClass, optionName, optionValue) {
      return this.sendCommand(
        COMMAND_SET_SOCKETOPT,
        new Uint8Array([optionClass, optionName, optionValue]).buffer,
        () => void 0
      );
    }
    sendCommand(commandType, chunk, callback) {
      return WebSocketConstructor.prototype.send.call(
        this,
        prependByte(chunk, commandType),
        callback
      );
    }
  };
}
function initOutboundWebsocketProxyServer(listenPort, listenHost = "127.0.0.1") {
  log(`Binding the WebSockets server to ${listenHost}:${listenPort}...`);
  const webServer = http.createServer((request, response) => {
    response.writeHead(403, { "Content-Type": "text/plain" });
    response.write(
      "403 Permission Denied\nOnly websockets are allowed here.\n"
    );
    response.end();
  });
  return new Promise((resolve) => {
    webServer.listen(listenPort, listenHost, function() {
      const wsServer = new WebSocketServer({ server: webServer });
      wsServer.on("connection", onWsConnect);
      resolve(webServer);
    });
  });
}
async function onWsConnect(client, request) {
  const clientAddr = client?._socket?.remoteAddress || client.url;
  const clientLog = function(...args) {
    log(" " + clientAddr + ": ", ...args);
  };
  clientLog(
    "WebSocket connection from : " + clientAddr + " at URL " + (request ? request.url : client.upgradeReq.url)
  );
  clientLog(
    "Version " + client.protocolVersion + ", subprotocol: " + client.protocol
  );
  const reqUrl = new URL(`ws://0.0.0.0` + request.url);
  const reqTargetPort = Number(reqUrl.searchParams.get("port"));
  const reqTargetHost = reqUrl.searchParams.get("host");
  if (!reqTargetPort || !reqTargetHost) {
    clientLog("Missing host or port information");
    client.close(3e3);
    return;
  }
  if (reqTargetPort < 0 || reqTargetPort > 65535) {
    clientLog("Invalid port number: " + reqTargetPort);
    client.send([]);
    client.close(3e3);
    return;
  }
  let target;
  const recvQueue = [];
  function flushMessagesQueue() {
    while (recvQueue.length > 0) {
      const msg = recvQueue.pop();
      const commandType = msg[0];
      clientLog("flushing", { commandType }, msg);
      if (commandType === COMMAND_CHUNK) {
        target.write(msg.slice(1));
      } else if (commandType === COMMAND_SET_SOCKETOPT) {
        const SOL_SOCKET = 1;
        const SO_KEEPALIVE = 9;
        const IPPROTO_TCP = 6;
        const TCP_NODELAY = 1;
        if (msg[1] === SOL_SOCKET && msg[2] === SO_KEEPALIVE) {
          target.setKeepAlive(msg[3]);
        } else if (msg[1] === IPPROTO_TCP && msg[2] === TCP_NODELAY) {
          target.setNoDelay(msg[3]);
        }
      } else {
        clientLog("Unknown command type: " + commandType);
        process.exit();
      }
    }
  }
  client.on("message", function(msg) {
    recvQueue.unshift(msg);
    if (target) {
      flushMessagesQueue();
    }
  });
  client.on("close", function(code, reason) {
    clientLog(
      "WebSocket client disconnected: " + code + " [" + reason + "]"
    );
    if (target) {
      target.end();
    }
  });
  client.on("error", function(a) {
    clientLog("WebSocket client error: " + a);
    target.end();
  });
  let reqTargetIp;
  if (net2.isIP(reqTargetHost) === 0) {
    clientLog("resolving " + reqTargetHost + "... ");
    try {
      reqTargetIp = await lookupIPv4(reqTargetHost);
      clientLog("resolved " + reqTargetHost + " -> " + reqTargetIp);
    } catch (e) {
      clientLog("can't resolve " + reqTargetHost + " due to:", e);
      client.send([]);
      setTimeout(() => {
        client.close(3e3);
      });
      return;
    }
  } else {
    reqTargetIp = reqTargetHost;
  }
  clientLog(
    "Opening a socket connection to " + reqTargetIp + ":" + reqTargetPort
  );
  target = net2.createConnection(reqTargetPort, reqTargetIp, function() {
    clientLog("Connected to target");
    flushMessagesQueue();
  });
  target.on("data", function(data) {
    try {
      client.send(data);
    } catch {
      clientLog("Client closed, cleaning up target");
      target.end();
    }
  });
  target.on("end", function() {
    clientLog("target disconnected");
    client.close();
  });
  target.on("error", function(e) {
    clientLog("target connection error", e);
    client.send([]);
    setTimeout(() => {
      client.close(3e3);
      try {
        target.end();
      } catch {
      }
    });
  });
}

// packages/php-wasm/node/src/lib/networking/inbound-tcp-to-ws-proxy.ts
import { createServer as createServer3 } from "net";
import { WebSocket } from "ws";
function log2(...args) {
  debugLog("[TCP Server]", ...args);
}
function addTCPServerToWebSocketServerClass(wsListenPort, WSServer) {
  return class PHPWasmWebSocketServer extends WSServer {
    constructor(options, callback) {
      const requestedPort = options.port;
      options.port = wsListenPort;
      listenTCPToWSProxy({
        tcpListenPort: requestedPort,
        wsConnectPort: wsListenPort
      });
      super(options, callback);
    }
  };
}
function listenTCPToWSProxy(options) {
  options = {
    wsConnectHost: "127.0.0.1",
    ...options
  };
  const { tcpListenPort, wsConnectHost, wsConnectPort } = options;
  const server = createServer3();
  server.on("connection", function handleConnection(tcpSource) {
    const inBuffer = [];
    const wsTarget = new WebSocket(
      `ws://${wsConnectHost}:${wsConnectPort}/`
    );
    wsTarget.binaryType = "arraybuffer";
    function wsSend(data) {
      wsTarget.send(new Uint8Array(data));
    }
    wsTarget.addEventListener("open", function() {
      log2("Outbound WebSocket connection established");
      while (inBuffer.length > 0) {
        wsSend(inBuffer.shift());
      }
    });
    wsTarget.addEventListener("message", (e) => {
      log2(
        "WS->TCP message:",
        new TextDecoder().decode(e.data)
      );
      tcpSource.write(Buffer.from(e.data));
    });
    wsTarget.addEventListener("close", () => {
      log2("WebSocket connection closed");
      tcpSource.end();
    });
    tcpSource.on("data", function(data) {
      log2("TCP->WS message:", data);
      if (wsTarget.readyState === WebSocket.OPEN) {
        while (inBuffer.length > 0) {
          wsSend(inBuffer.shift());
        }
        wsSend(data);
      } else {
        inBuffer.push(data);
      }
    });
    tcpSource.once("close", function() {
      log2("TCP connection closed");
      wsTarget.close();
    });
    tcpSource.on("error", function() {
      log2("TCP connection error");
      wsTarget.close();
    });
  });
  server.listen(tcpListenPort, function() {
    log2("TCP server listening");
  });
}

// packages/php-wasm/node/src/lib/networking/with-networking.ts
async function withNetworking(phpModuleArgs = {}) {
  const [inboundProxyWsServerPort, outboundProxyWsServerPort] = await findFreePorts(2);
  const outboundNetworkProxyServer = await initOutboundWebsocketProxyServer(
    outboundProxyWsServerPort
  );
  return {
    ...phpModuleArgs,
    outboundNetworkProxyServer,
    websocket: {
      ...phpModuleArgs["websocket"] || {},
      url: (_, host, port) => {
        const query = new URLSearchParams({
          host,
          port
        }).toString();
        return `ws://127.0.0.1:${outboundProxyWsServerPort}/?${query}`;
      },
      subprotocol: "binary",
      decorator: addSocketOptionsSupportToWebSocketClass,
      serverDecorator: addTCPServerToWebSocketServerClass.bind(
        null,
        inboundProxyWsServerPort
      )
    }
  };
}

// packages/php-wasm/node/src/lib/load-runtime.ts
import {
  loadPHPRuntime,
  FSHelpers,
  FileLockManagerComposite,
  createLegacyPhpIniPreRunStep,
  isLegacyPHPVersion,
  ProcessIdAllocator
} from "@php-wasm/universal";

// packages/php-wasm/node/src/lib/wasm-user-space.ts
import { lookup as lookup2 } from "dns/promises";
function bindUserSpace({ fileLockManager }, {
  pid,
  memory,
  constants: {
    F_RDLCK,
    F_WRLCK,
    F_UNLCK,
    F_GETFL,
    O_ACCMODE,
    O_RDONLY,
    O_WRONLY,
    O_APPEND,
    O_NONBLOCK,
    F_SETFL,
    F_GETLK,
    F_SETLK,
    F_SETLKW,
    SEEK_SET,
    SEEK_CUR,
    SEEK_END,
    LOCK_SH,
    LOCK_EX,
    LOCK_NB,
    LOCK_UN
  },
  errnoCodes: { EBADF, EINVAL, EAGAIN, EWOULDBLOCK },
  wasmImports: { builtin_fcntl64, builtin_fd_close, js_wasm_trace },
  wasmExports: { wasm_get_end_offset },
  syscalls: { getStreamFromFD },
  FS,
  PROXYFS,
  NODEFS
}) {
  class VarArgsAccessor {
    constructor(argsAddr) {
      this.argsAddr = argsAddr;
    }
    getNextAsPointer() {
      return this.getNextAsInt();
    }
    getNextAsInt() {
      const fourByteOffset = this.argsAddr >> 2;
      const value = memory.HEAP32.get(fourByteOffset);
      this.argsAddr += 4;
      return value;
    }
  }
  const locking = {
    /*
     * This is a set of possibly locked file descriptors.
     *
     * When a file descriptor is closed, we need to release any associated held by this process.
     * Instead of trying remember and forget file descriptors as they are locked and unlocked,
     * we just track file descriptors we have locked before and try an unlock when they are closed.
     */
    maybeLockedFds: /* @__PURE__ */ new Set(),
    lockStateToFcntl: {
      shared: F_RDLCK,
      exclusive: F_WRLCK,
      unlocked: F_UNLCK
    },
    fcntlToLockState: {
      [F_RDLCK]: "shared",
      [F_WRLCK]: "exclusive",
      [F_UNLCK]: "unlocked"
    },
    is_path_to_shared_fs(path3) {
      const { node } = FS.lookupPath(path3, { noent_okay: true });
      if (!node) {
        return false;
      }
      if (node.mount.type !== PROXYFS) {
        return !!node.isSharedFS;
      }
      const nodePath = PROXYFS.realPath(node);
      const backingFs = node?.mount?.opts?.["fs"];
      if (backingFs) {
        const { node: backingNode } = backingFs.lookupPath(nodePath, {
          noent_okay: true
        });
        return !!backingNode?.isSharedFS;
      }
      return false;
    },
    get_fd_access_mode(fd) {
      return builtin_fcntl64(fd, F_GETFL) & O_ACCMODE;
    },
    get_vfs_path_from_fd(fd) {
      try {
        return [FS.readlink(`/proc/self/fd/${fd}`), 0];
      } catch {
        return [null, EBADF];
      }
    },
    get_native_path_from_vfs_path(vfsPath) {
      const { node } = FS.lookupPath(vfsPath, {
        noent_okay: true
      });
      if (!node) {
        throw new Error(`No node found for VFS path ${vfsPath}`);
      }
      if (node.mount.type === NODEFS) {
        return NODEFS.realPath(node);
      } else if (node.mount.type === PROXYFS) {
        const { node: backingNode, path: backingPath } = node.mount.opts["fs"].lookupPath(vfsPath);
        js_wasm_trace(
          "backingNode for %s: %s",
          vfsPath,
          backingPath,
          backingNode
        );
        return backingNode.mount.type.realPath(backingNode);
      } else {
        throw new Error(
          `Unsupported filesystem type for path ${vfsPath}`
        );
      }
    },
    get_native_fd_from_emscripten_fd(fd) {
      try {
        const stream = getStreamFromFD(fd);
        if (stream.nfd === void 0) {
          return [null, EBADF];
        }
        return [stream.nfd, 0];
      } catch {
        return [null, EBADF];
      }
    },
    check_lock_params(fd, l_type) {
      const accessMode = locking.get_fd_access_mode(fd);
      if (l_type === F_WRLCK && accessMode === O_RDONLY || l_type === F_RDLCK && accessMode === O_WRONLY) {
        js_wasm_trace(
          "check_lock_params(%d, %d, %d) EBADF",
          fd,
          l_type,
          accessMode
        );
        return EBADF;
      }
      return 0;
    }
  };
  const emscripten_flock_l_type_offset = 0;
  const emscripten_flock_l_whence_offset = 2;
  const emscripten_flock_l_start_offset = 8;
  const emscripten_flock_l_len_offset = 16;
  const emscripten_flock_l_pid_offset = 24;
  function readFlockStruct(flockStructAddress) {
    return {
      l_type: memory.HEAP16.get(
        // Shift right by 1 to divide by 2^1.
        flockStructAddress + emscripten_flock_l_type_offset >> 1
      ),
      l_whence: memory.HEAP16.get(
        // Shift right by 1 to divide by 2^1.
        flockStructAddress + emscripten_flock_l_whence_offset >> 1
      ),
      l_start: memory.HEAP64.get(
        // Shift right by 3 to divide by 2^3.
        flockStructAddress + emscripten_flock_l_start_offset >> 3
      ),
      l_len: memory.HEAP64.get(
        // Shift right by 3 to divide by 2^3.
        flockStructAddress + emscripten_flock_l_len_offset >> 3
      ),
      l_pid: memory.HEAP32.get(
        // Shift right by 2 to divide by 2^2.
        flockStructAddress + emscripten_flock_l_pid_offset >> 2
      )
    };
  }
  function updateFlockStruct(flockStructAddress, fields) {
    if (fields.l_type !== void 0) {
      memory.HEAP16.set(
        // Shift right by 1 to divide by 2^1.
        flockStructAddress + emscripten_flock_l_type_offset >> 1,
        fields.l_type
      );
    }
    if (fields.l_whence !== void 0) {
      memory.HEAP16.set(
        // Shift right by 1 to divide by 2^1.
        flockStructAddress + emscripten_flock_l_whence_offset >> 1,
        fields.l_whence
      );
    }
    if (fields.l_start !== void 0) {
      memory.HEAP64.set(
        // Shift right by 3 to divide by 2^3.
        flockStructAddress + emscripten_flock_l_start_offset >> 3,
        fields.l_start
      );
    }
    if (fields.l_len !== void 0) {
      memory.HEAP64.set(
        // Shift right by 3 to divide by 2^3.
        flockStructAddress + emscripten_flock_l_len_offset >> 3,
        fields.l_len
      );
    }
    if (fields.l_pid !== void 0) {
      memory.HEAP32.set(
        // Shift right by 2 to divide by 2^2.
        flockStructAddress + emscripten_flock_l_pid_offset >> 2,
        fields.l_pid
      );
    }
  }
  function getBaseAddress(fd, whence, startOffset) {
    let baseAddress;
    switch (whence) {
      case SEEK_SET:
        baseAddress = 0n;
        break;
      case SEEK_CUR:
        try {
          const stream = getStreamFromFD(fd);
          baseAddress = FS.llseek(stream, 0, whence);
        } catch (e) {
          js_wasm_trace(
            "get_base_address(%d, %d, %d) getStreamFromFD error %s",
            fd,
            whence,
            startOffset,
            e
          );
          return [null, EINVAL];
        }
        break;
      case SEEK_END:
        baseAddress = wasm_get_end_offset(fd);
        break;
      default:
        return [null, EINVAL];
    }
    if (baseAddress == -1) {
      return [null, EBADF];
    }
    const resolvedOffset = baseAddress + startOffset;
    if (resolvedOffset < 0) {
      return [null, EINVAL];
    }
    return [resolvedOffset, 0];
  }
  function fcntl64(fd, cmd, varargs) {
    js_wasm_trace("fcntl64(%d, %d)", fd, cmd);
    if (!fileLockManager) {
      js_wasm_trace(
        "fcntl64(%d, %d) file lock manager is not available. delegate to Emscripten builtin fcntl64.",
        fd,
        cmd
      );
      return builtin_fcntl64(fd, cmd, varargs);
    }
    switch (cmd) {
      case F_GETLK: {
        const reportUnlockedFileByDefault = function reportUnlockedFileByDefault2() {
          updateFlockStruct(flockStructAddr, {
            l_type: F_UNLCK
          });
          return 0;
        };
        js_wasm_trace("fcntl(%d, F_GETLK)", fd);
        const [vfsPath, vfsPathErrno] = locking.get_vfs_path_from_fd(fd);
        if (vfsPathErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) %s get_vfs_path_from_fd errno %d",
            fd,
            vfsPath,
            vfsPathErrno
          );
          return -EBADF;
        }
        const varArgsAccessor = new VarArgsAccessor(varargs);
        const flockStructAddr = varArgsAccessor.getNextAsPointer();
        if (!locking.is_path_to_shared_fs(vfsPath) || fileLockManager === void 0) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) locking is not implemented for non-NodeFS path '%s'",
            fd,
            vfsPath
          );
          return reportUnlockedFileByDefault();
        }
        const flockStruct = readFlockStruct(flockStructAddr);
        if (!(flockStruct.l_type in locking.fcntlToLockState)) {
          return -EINVAL;
        }
        const paramsCheckErrno = locking.check_lock_params(
          fd,
          flockStruct.l_type
        );
        if (paramsCheckErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) %s check_lock_params errno %d",
            fd,
            vfsPath,
            paramsCheckErrno
          );
          return -EINVAL;
        }
        const requestedLockType = locking.fcntlToLockState[flockStruct.l_type];
        const [absoluteStartOffset, baseAddressErrno] = getBaseAddress(
          fd,
          flockStruct.l_whence,
          flockStruct.l_start
        );
        if (baseAddressErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) %s get_base_address errno %d",
            fd,
            vfsPath,
            baseAddressErrno
          );
          return -EINVAL;
        }
        const [nativeFd, nativeFdErrno] = locking.get_native_fd_from_emscripten_fd(fd);
        if (nativeFdErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) get_native_fd_from_emscripten_fd errno %d",
            fd,
            nativeFdErrno
          );
          return -nativeFdErrno;
        }
        try {
          const nativeFilePath = locking.get_native_path_from_vfs_path(vfsPath);
          const conflictingLock = fileLockManager.findFirstConflictingByteRangeLock(
            nativeFilePath,
            {
              type: requestedLockType,
              start: absoluteStartOffset,
              end: absoluteStartOffset + flockStruct.l_len,
              pid,
              fd: nativeFd
            }
          );
          if (conflictingLock === void 0) {
            js_wasm_trace(
              "fcntl(%d, F_GETLK) %s findFirstConflictingByteRangeLock type=unlocked start=0x%x end=0x%x",
              fd,
              vfsPath,
              absoluteStartOffset,
              absoluteStartOffset + flockStruct.l_len
            );
            updateFlockStruct(flockStructAddr, {
              l_type: F_UNLCK
            });
            return 0;
          }
          js_wasm_trace(
            "fcntl(%d, F_GETLK) %s findFirstConflictingByteRangeLock type=%s start=0x%x end=0x%x conflictingLock %d",
            fd,
            vfsPath,
            conflictingLock.type,
            conflictingLock.start,
            conflictingLock.end,
            conflictingLock.pid
          );
          const fcntlLockState = locking.lockStateToFcntl[conflictingLock.type];
          updateFlockStruct(flockStructAddr, {
            l_type: fcntlLockState,
            l_whence: SEEK_SET,
            l_start: conflictingLock.start,
            l_len: BigInt(
              conflictingLock.end - conflictingLock.start
            ),
            l_pid: conflictingLock.pid
          });
          return 0;
        } catch (e) {
          js_wasm_trace(
            "fcntl(%d, F_GETLK) %s findFirstConflictingByteRangeLock error %s",
            fd,
            vfsPath,
            e
          );
          return -EINVAL;
        }
      }
      case F_SETLKW:
      case F_SETLK: {
        js_wasm_trace("fcntl(%d, F_SETLK)", fd);
        const [vfsPath, vfsPathErrno] = locking.get_vfs_path_from_fd(fd);
        if (vfsPathErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s get_vfs_path_from_fd errno %d",
            fd,
            vfsPath,
            vfsPathErrno
          );
          return -vfsPathErrno;
        }
        if (!locking.is_path_to_shared_fs(vfsPath)) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) locking is not implemented for non-NodeFS path %s",
            fd,
            vfsPath
          );
          return 0;
        }
        const varArgsAccessor = new VarArgsAccessor(varargs);
        const flockStructAddr = varArgsAccessor.getNextAsPointer();
        const flockStruct = readFlockStruct(flockStructAddr);
        const [absoluteStartOffset, baseAddressErrno] = getBaseAddress(
          fd,
          flockStruct.l_whence,
          flockStruct.l_start
        );
        if (baseAddressErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s get_base_address errno %d",
            fd,
            vfsPath,
            baseAddressErrno
          );
          return -EINVAL;
        }
        if (!(flockStruct.l_type in locking.fcntlToLockState)) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s invalid lock type %d",
            fd,
            vfsPath,
            flockStruct.l_type
          );
          return -EINVAL;
        }
        const paramsCheckErrno = locking.check_lock_params(
          fd,
          flockStruct.l_type
        );
        if (paramsCheckErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s check_lock_params errno %d",
            fd,
            vfsPath,
            paramsCheckErrno
          );
          return -paramsCheckErrno;
        }
        const [nativeFd, nativeFdErrno] = locking.get_native_fd_from_emscripten_fd(fd);
        if (nativeFdErrno !== 0) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) get_native_fd_from_emscripten_fd errno %d",
            fd,
            nativeFdErrno
          );
          return -nativeFdErrno;
        }
        const requestedLockType = locking.fcntlToLockState[flockStruct.l_type];
        const rangeLock = {
          type: requestedLockType,
          start: absoluteStartOffset,
          end: absoluteStartOffset + flockStruct.l_len,
          pid,
          fd: nativeFd
        };
        try {
          const nativeFilePath = locking.get_native_path_from_vfs_path(vfsPath);
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s calling lockFileByteRange for range lock %s",
            fd,
            vfsPath,
            rangeLock
          );
          const waitForLock = cmd === F_SETLKW;
          const succeeded = fileLockManager.lockFileByteRange(
            nativeFilePath,
            rangeLock,
            waitForLock
          );
          if (succeeded) {
            locking.maybeLockedFds.add(nativeFd);
          }
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s lockFileByteRange returned %d for range lock %s",
            fd,
            vfsPath,
            succeeded,
            rangeLock
          );
          return succeeded ? 0 : -EAGAIN;
        } catch (e) {
          js_wasm_trace(
            "fcntl(%d, F_SETLK) %s lockFileByteRange error %s for range lock %s",
            fd,
            vfsPath,
            e,
            rangeLock
          );
          return -EINVAL;
        }
      }
      case F_SETFL: {
        let arg = 0;
        if (varargs !== void 0) {
          const varArgsAccessor = new VarArgsAccessor(varargs);
          arg = varArgsAccessor.getNextAsInt();
        }
        const stream = getStreamFromFD(fd);
        const SETFL_MASK = O_APPEND | O_NONBLOCK;
        stream.flags = arg & SETFL_MASK | stream.flags & ~SETFL_MASK;
        return 0;
      }
      default:
        return builtin_fcntl64(fd, cmd, varargs);
    }
  }
  function flock(fd, op) {
    js_wasm_trace("flock(%d, %d)", fd, op);
    if (!fileLockManager) {
      js_wasm_trace(
        "flock(%d, %d) file lock manager is not available. succeed by default as Emscripten does.",
        fd,
        op
      );
      return 0;
    }
    const flockToLockOpType = {
      [LOCK_SH]: "shared",
      [LOCK_EX]: "exclusive",
      [LOCK_UN]: "unlock"
    };
    const [vfsPath, vfsPathErrno] = locking.get_vfs_path_from_fd(fd);
    if (vfsPathErrno !== 0) {
      js_wasm_trace(
        "flock(%d, %d) get_vfs_path_from_fd errno %d",
        fd,
        op,
        vfsPath,
        vfsPathErrno
      );
      return -vfsPathErrno;
    }
    if (!locking.is_path_to_shared_fs(vfsPath)) {
      js_wasm_trace(
        "flock(%d, %d) locking is not implemented for non-NodeFS path %s",
        fd,
        op,
        vfsPath
      );
      return 0;
    }
    const paramsCheckErrno = locking.check_lock_params(fd, op);
    if (paramsCheckErrno !== 0) {
      js_wasm_trace(
        "flock(%d, %d) %s check_lock_params errno %d",
        fd,
        op,
        vfsPath,
        paramsCheckErrno
      );
      return -paramsCheckErrno;
    }
    const maskedOp = op & (LOCK_SH | LOCK_EX | LOCK_UN);
    const waitForLock = (op & LOCK_NB) === 0;
    if (maskedOp === 0) {
      js_wasm_trace("flock(%d, %d) invalid flock() operation", fd, op);
      return -EINVAL;
    }
    const lockOpType = flockToLockOpType[maskedOp];
    if (lockOpType === void 0) {
      js_wasm_trace("flock(%d, %d) invalid flock() operation", fd, op);
      return -EINVAL;
    }
    const [nativeFd, nativeFdErrno] = locking.get_native_fd_from_emscripten_fd(fd);
    if (nativeFdErrno !== 0) {
      js_wasm_trace(
        "js_flock(%d, %d) get_native_fd_from_emscripten_fd errno %d",
        fd,
        op,
        nativeFdErrno
      );
      return -nativeFdErrno;
    }
    try {
      const nativeFilePath = locking.get_native_path_from_vfs_path(vfsPath);
      const succeeded = fileLockManager.lockWholeFile(nativeFilePath, {
        type: lockOpType,
        pid,
        fd: nativeFd,
        waitForLock
      });
      js_wasm_trace(
        "flock(%d, %d) lockWholeFile %s returned %d",
        fd,
        op,
        vfsPath,
        succeeded
      );
      if (succeeded) {
        locking.maybeLockedFds.add(nativeFd);
      }
      return succeeded ? 0 : -EWOULDBLOCK;
    } catch (e) {
      js_wasm_trace("flock(%d, %d) lockWholeFile error %s", fd, op, e);
      return -EINVAL;
    }
  }
  function fd_close(fd) {
    if (!fileLockManager) {
      js_wasm_trace(
        "fd_close(%d) file lock manager is not available. delegate to Emscripten builtin fd_close.",
        fd
      );
      return builtin_fd_close(fd);
    }
    const [vfsPath, vfsPathResolutionErrno] = locking.get_vfs_path_from_fd(fd);
    const [nativeFd, nativeFdErrno] = locking.get_native_fd_from_emscripten_fd(fd);
    const fdCloseResult = builtin_fd_close(fd);
    if (fdCloseResult !== 0) {
      js_wasm_trace(
        "fd_close(%d) %s result %d",
        fd,
        vfsPath,
        fdCloseResult
      );
      return fdCloseResult;
    }
    if (!locking.maybeLockedFds.has(nativeFd)) {
      js_wasm_trace(
        "fd_close(%d) not in maybe-locked-list %s result %d",
        fd,
        vfsPath,
        fdCloseResult
      );
      return fdCloseResult;
    }
    if (vfsPathResolutionErrno !== 0) {
      js_wasm_trace(
        "fd_close(%d) get_vfs_path_from_fd error %d",
        fd,
        vfsPathResolutionErrno
      );
      return fdCloseResult;
    }
    if (nativeFdErrno !== 0) {
      js_wasm_trace(
        "fd_close(%d) %s get_native_fd_from_emscripten_fd error %d",
        fd,
        vfsPath,
        nativeFdErrno
      );
      return fdCloseResult;
    }
    if (!locking.is_path_to_shared_fs(vfsPath)) {
      return fdCloseResult;
    }
    try {
      js_wasm_trace("fd_close(%d) %s release locks", fd, vfsPath);
      const nativeFilePath = locking.get_native_path_from_vfs_path(vfsPath);
      fileLockManager.releaseLocksOnFdClose(
        pid,
        nativeFd,
        nativeFilePath
      );
      js_wasm_trace("fd_close(%d) %s release locks success", fd, vfsPath);
    } catch (e) {
      js_wasm_trace("fd_close(%d) %s error '%s'", fd, vfsPath, e);
    }
    return fdCloseResult;
  }
  function js_release_file_locks() {
    js_wasm_trace("js_release_file_locks()");
    if (pid === void 0) {
      js_wasm_trace("js_release_file_locks pid is undefined");
      return;
    }
    if (fileLockManager === void 0) {
      js_wasm_trace(
        "js_release_file_locks file lock manager is undefined"
      );
      return;
    }
    try {
      fileLockManager.releaseLocksForProcess(pid);
      js_wasm_trace("js_release_file_locks succeeded");
    } catch (e) {
      js_wasm_trace("js_release_file_locks error %s", e);
    }
  }
  async function gethostbyname(hostname) {
    const { address } = await lookup2(hostname, {
      family: 4,
      verbatim: false
    });
    return address;
  }
  return {
    fcntl64,
    flock,
    fd_close,
    js_release_file_locks,
    gethostbyname
  };
}

// packages/php-wasm/node/src/lib/load-runtime.ts
import fs2 from "fs";

// packages/php-wasm/node/src/lib/file-lock-manager-for-posix.ts
import { MAX_ADDRESSABLE_FILE_OFFSET } from "@php-wasm/universal";
import { constants, fcntlSync, flockSync } from "fs-ext-extra-prebuilt";
import { logger as logger2 } from "@php-wasm/logger";
function isLockDenialError(e) {
  if (e && typeof e === "object" && "code" in e) {
    const code = e.code;
    return code === "EWOULDBLOCK" || code === "EAGAIN" || code === "EACCES";
  }
  return false;
}
var FileLockManagerForPosix = class {
  constructor() {
    this.wholeFileLockMap = /* @__PURE__ */ new Map();
    this.rangeLockedFds = /* @__PURE__ */ new Map();
  }
  lockWholeFile(path3, op) {
    const opType = op.type === "unlock" ? "un" : op.waitForLock ? op.type === "exclusive" ? "ex" : "sh" : op.type === "exclusive" ? "exnb" : "shnb";
    try {
      flockSync(op.fd, opType);
      if (op.type === "unlock") {
        this.wholeFileLockMap.get(op.pid)?.delete(op.fd);
      } else {
        if (!this.wholeFileLockMap.has(op.pid)) {
          this.wholeFileLockMap.set(op.pid, /* @__PURE__ */ new Map());
        }
        this.wholeFileLockMap.get(op.pid).set(op.fd, {
          ...op,
          path: path3
        });
      }
      return true;
    } catch (e) {
      if (!isLockDenialError(e)) {
        logger2.warn("flock(): unexpected error", e);
      }
      return false;
    }
  }
  lockFileByteRange(path3, op, waitForLock) {
    if (op.start === op.end) {
      op = {
        ...op,
        end: MAX_ADDRESSABLE_FILE_OFFSET
      };
    }
    const fcntlCmd = waitForLock ? "setlkw" : "setlk";
    const fcntlOp = op.type === "unlocked" ? constants.F_UNLCK : op.type === "exclusive" ? constants.F_WRLCK : constants.F_RDLCK;
    try {
      fcntlSync(
        op.fd,
        fcntlCmd,
        fcntlOp,
        Number(op.start),
        Number(op.end - op.start)
      );
      if (!this.rangeLockedFds.has(op.pid)) {
        this.rangeLockedFds.set(op.pid, /* @__PURE__ */ new Map());
      }
      const pidMap = this.rangeLockedFds.get(op.pid);
      if (!pidMap.has(path3)) {
        pidMap.set(path3, /* @__PURE__ */ new Set());
      }
      pidMap.get(path3).add(op.fd);
      return true;
    } catch (e) {
      if (!isLockDenialError(e)) {
        logger2.warn("fcntl(): unexpected error", e);
      }
      return false;
    }
  }
  findFirstConflictingByteRangeLock(path3, op) {
    if (op.type === "unlocked") {
      return void 0;
    }
    const obtainedLock = this.lockFileByteRange(path3, op, false);
    if (obtainedLock) {
      this.lockFileByteRange(path3, { ...op, type: "unlocked" }, true);
      return void 0;
    }
    return {
      type: "exclusive",
      start: 0n,
      end: 0xffffffffffffffffn,
      pid: -1
    };
  }
  releaseLocksForProcess(targetPid) {
    const fdMap = this.wholeFileLockMap.get(targetPid);
    if (fdMap) {
      for (const storedLock of fdMap.values()) {
        try {
          this.lockWholeFile(storedLock.path, {
            ...storedLock,
            type: "unlock"
          });
        } catch (e) {
          logger2.error(
            `releaseLocksForProcess: failed to unlock whole-file lock for pid=${targetPid} fd=${storedLock.fd}`,
            e
          );
        }
      }
      this.wholeFileLockMap.delete(targetPid);
    }
    for (const [path3, fdSet] of this.rangeLockedFds.get(targetPid) ?? []) {
      for (const fd of fdSet) {
        try {
          this.lockFileByteRange(
            path3,
            {
              pid: targetPid,
              fd,
              type: "unlocked",
              start: 0n,
              end: MAX_ADDRESSABLE_FILE_OFFSET
            },
            false
          );
        } catch (e) {
          logger2.error(
            `releaseLocksForProcess: failed to unlock byte range for pid=${targetPid} fd=${fd} path=${path3}`,
            e
          );
        }
      }
    }
    this.rangeLockedFds.delete(targetPid);
  }
  releaseLocksOnFdClose(targetPid, targetFd, targetPath) {
    this.wholeFileLockMap.get(targetPid)?.delete(targetFd);
    this.rangeLockedFds.get(targetPid)?.delete(targetPath);
  }
};

// packages/php-wasm/node/src/lib/file-lock-manager-for-windows.ts
import {
  lockFileExSync,
  unlockFileExSync,
  constants as constants2
} from "fs-ext-extra-prebuilt";
import { logger as logger3 } from "@php-wasm/logger";
import {
  MAX_ADDRESSABLE_FILE_OFFSET as MAX_ADDRESSABLE_FILE_OFFSET2,
  FileLockIntervalTree
} from "@php-wasm/universal";
function toLowAndHigh32BitNumbers(num) {
  const low = Number(num & 0xffffffffn);
  const high = Number(num >> 32n & 0xffffffffn);
  return [low, high];
}
function isErrnoError(e) {
  return e !== null && typeof e === "object" && ("errno" in e || "code" in e || "syscall" in e);
}
function tryLockFileExSync(fd, flags, start, end) {
  const [offsetLow, offsetHigh] = toLowAndHigh32BitNumbers(start);
  const [lengthLow, lengthHigh] = toLowAndHigh32BitNumbers(end - start);
  try {
    lockFileExSync(fd, flags, offsetLow, offsetHigh, lengthLow, lengthHigh);
    return true;
  } catch (e) {
    if (!isErrnoError(e)) {
      throw e;
    }
    return false;
  }
}
function tryUnlockFileExSync(fd, start, end) {
  const [offsetLow, offsetHigh] = toLowAndHigh32BitNumbers(start);
  const [lengthLow, lengthHigh] = toLowAndHigh32BitNumbers(end - start);
  try {
    unlockFileExSync(fd, offsetLow, offsetHigh, lengthLow, lengthHigh);
    return true;
  } catch (e) {
    if (!isErrnoError(e)) {
      throw e;
    }
    return false;
  }
}
var FileLockManagerForWindows = class {
  constructor() {
    this.wholeFileLockMap = /* @__PURE__ */ new Map();
    this.rangeLockedFds = /* @__PURE__ */ new Map();
  }
  lockWholeFile(path3, op) {
    const start = 0n;
    const end = 2n ** 64n - 1n;
    if (op.type === "unlock") {
      const success2 = tryUnlockFileExSync(op.fd, start, end);
      if (success2) {
        this.wholeFileLockMap.get(op.pid)?.delete(op.fd);
        if (this.wholeFileLockMap.get(op.pid)?.size === 0) {
          this.wholeFileLockMap.delete(op.pid);
        }
      } else {
        logger3.warn(
          `lockWholeFile: unlock failed for pid=${op.pid} fd=${op.fd} path=${path3}`
        );
      }
      return success2;
    }
    const preexistingLock = this.wholeFileLockMap.get(op.pid)?.get(op.fd);
    if (op.type === preexistingLock?.type) {
      return true;
    }
    let flags = 0;
    if (!op.waitForLock) {
      flags |= constants2.LOCKFILE_FAIL_IMMEDIATELY;
    }
    let success = false;
    if (op.type === "shared") {
      success = tryLockFileExSync(op.fd, flags, start, end);
      if (success && preexistingLock?.type === "exclusive") {
        const exclusiveUnlockSuccess = tryUnlockFileExSync(
          op.fd,
          start,
          end
        );
        if (!exclusiveUnlockSuccess) {
          const message = "Failed to unlock preexisting exclusive lock after failing to obtain shared lock";
          logger3.error(message);
          throw new Error(message);
        }
      }
    } else if (op.type === "exclusive") {
      flags |= constants2.LOCKFILE_EXCLUSIVE_LOCK;
      let sharedUnlockSuccess;
      if (preexistingLock?.type === "shared") {
        sharedUnlockSuccess = tryUnlockFileExSync(op.fd, start, end);
        if (!sharedUnlockSuccess) {
          logger3.warn(
            `lockWholeFile: failed to release shared lock before exclusive upgrade for pid=${op.pid} fd=${op.fd}`
          );
        }
      }
      success = tryLockFileExSync(op.fd, flags, start, end);
      if (!success) {
        logger3.debug(
          `lockWholeFile: failed to obtain exclusive lock for pid=${op.pid} fd=${op.fd}`
        );
      }
      if (!success && sharedUnlockSuccess) {
        const sharedReLockResult = tryLockFileExSync(
          op.fd,
          // Wait to restore the shared lock.
          0,
          start,
          end
        );
        if (!sharedReLockResult) {
          const message = "Failed to re-lock preexisting shared lock after failing to obtain exclusive lock";
          logger3.error(message);
          throw new Error(message);
        }
      }
    } else {
      throw new Error(`Unexpected wholeFileLock() op: '${op.type}'`);
    }
    if (success) {
      if (!this.wholeFileLockMap.has(op.pid)) {
        this.wholeFileLockMap.set(op.pid, /* @__PURE__ */ new Map());
      }
      this.wholeFileLockMap.get(op.pid).set(op.fd, {
        ...op,
        path: path3
      });
    }
    return success;
  }
  lockFileByteRange(path3, op, waitForLock) {
    if (op.start === op.end) {
      op = {
        ...op,
        end: MAX_ADDRESSABLE_FILE_OFFSET2
      };
    }
    if (!this.rangeLockedFds.has(path3)) {
      this.rangeLockedFds.set(path3, new FileLockIntervalTree());
    }
    const lockedRangeTree = this.rangeLockedFds.get(path3);
    const overlappingLocks = lockedRangeTree.findOverlapping(op);
    let preexistingLock;
    if (overlappingLocks.length === 1 && overlappingLocks[0].pid === op.pid && // NOTE: FD shouldn't matter for fcntl() F_SETLK because it is a process-level lock,
    // but it matters for Windows where locks are fd-specific.
    overlappingLocks[0].fd === op.fd && overlappingLocks[0].start === op.start && overlappingLocks[0].end === op.end) {
      preexistingLock = overlappingLocks[0];
    }
    if (op.type === preexistingLock?.type) {
      return true;
    }
    let flags = 0;
    if (!waitForLock) {
      flags |= constants2.LOCKFILE_FAIL_IMMEDIATELY;
    }
    if (op.type === "shared") {
      const success = tryLockFileExSync(op.fd, flags, op.start, op.end);
      if (!success) {
        return false;
      }
      if (preexistingLock?.type === "exclusive") {
        const releasedPreexistingExclusiveLock = tryUnlockFileExSync(
          preexistingLock.fd,
          preexistingLock.start,
          preexistingLock.end
        );
        if (!releasedPreexistingExclusiveLock) {
          const message = "Failed to unlock preexisting exclusive lock after obtaining a shared lock";
          logger3.error(message);
          throw new Error(message);
        }
        lockedRangeTree.remove(preexistingLock);
      }
      lockedRangeTree.insert(op);
      return true;
    } else if (op.type === "exclusive") {
      let sharedUnlockSuccess;
      if (preexistingLock?.type === "shared") {
        sharedUnlockSuccess = tryUnlockFileExSync(
          op.fd,
          op.start,
          op.end
        );
      }
      if (op.type === "exclusive") {
        flags |= constants2.LOCKFILE_EXCLUSIVE_LOCK;
      }
      const success = tryLockFileExSync(op.fd, flags, op.start, op.end);
      if (!success) {
        if (preexistingLock && sharedUnlockSuccess) {
          const sharedRelockSuccess = tryLockFileExSync(
            op.fd,
            0,
            op.start,
            op.end
          );
          if (!sharedRelockSuccess) {
            const message = "Failed to re-lock preexisting shared lock after failing to obtain exclusive lock";
            logger3.error(message);
            throw new Error(message);
          }
        }
        return false;
      }
      lockedRangeTree.insert(op);
      return true;
    } else {
      const intersectingLocksForThisProcess = overlappingLocks.filter((lock) => lock.pid === op.pid).filter((lock) => lock.start >= op.start && lock.end <= op.end);
      for (const lock of intersectingLocksForThisProcess) {
        const success = tryUnlockFileExSync(
          lock.fd,
          lock.start,
          lock.end
        );
        if (!success) {
          logger3.warn(
            `lockFileByteRange: unlock failed for pid=${op.pid} fd=${lock.fd} range=[${lock.start},${lock.end}]`
          );
          return false;
        }
        lockedRangeTree.remove(lock);
      }
      return true;
    }
  }
  findFirstConflictingByteRangeLock(path3, op) {
    if (op.type === "unlocked") {
      return void 0;
    }
    const obtainedLock = !!this.lockFileByteRange(path3, op, false);
    this.lockFileByteRange(path3, { ...op, type: "unlocked" }, false);
    if (obtainedLock) {
      return void 0;
    }
    return {
      type: "exclusive",
      start: 0n,
      end: 0xffffffffffffffffn,
      pid: -1
    };
  }
  releaseLocksForProcess(targetPid) {
    const fdMap = this.wholeFileLockMap.get(targetPid);
    if (fdMap) {
      for (const storedLock of fdMap.values()) {
        try {
          this.lockWholeFile(storedLock.path, {
            ...storedLock,
            type: "unlock"
          });
        } catch (e) {
          logger3.error(
            `releaseLocksForProcess: failed to unlock whole-file lock for pid=${targetPid} fd=${storedLock.fd}`,
            e
          );
        }
      }
      this.wholeFileLockMap.delete(targetPid);
    }
    for (const [path3, lockedRangeTree] of this.rangeLockedFds.entries()) {
      const rangesLockedByTargetPid = lockedRangeTree.findLocksForProcess(targetPid);
      for (const op of rangesLockedByTargetPid) {
        try {
          this.lockFileByteRange(
            path3,
            { ...op, type: "unlocked" },
            false
          );
        } catch (e) {
          logger3.error(
            `releaseLocksForProcess: failed to unlock byte range for pid=${targetPid} fd=${op.fd} path=${path3}`,
            e
          );
        }
        lockedRangeTree.remove(op);
      }
    }
  }
  releaseLocksOnFdClose(targetPid, targetFd, targetPath) {
    const storedLock = this.wholeFileLockMap.get(targetPid)?.get(targetFd);
    if (storedLock) {
      this.lockWholeFile(storedLock.path, {
        ...storedLock,
        type: "unlock"
      });
    }
    this.wholeFileLockMap.get(targetPid)?.delete(targetFd);
    const lockedRangeTree = this.rangeLockedFds.get(targetPath);
    for (const op of lockedRangeTree?.findLocksForProcess(targetPid) ?? []) {
      this.lockFileByteRange(
        targetPath,
        {
          ...op,
          type: "unlocked",
          // Use a dummy FD because we're releasing locks for
          // all FDs on this file (POSIX semantics), not just
          // the one being closed.
          fd: -1
        },
        false
      );
      lockedRangeTree.remove(op);
    }
  }
};

// packages/php-wasm/node/src/lib/extensions/load-extensions.ts
import { DEFAULT_IDE_KEY } from "@php-wasm/cli-util";
import {
  withResolvedPHPExtensions,
  resolvePHPExtension,
  SupportedPHPVersions
} from "@php-wasm/universal";
import fs from "fs";
import path2 from "path";

// packages/php-wasm/node/src/lib/extensions/intl/get-intl-extension-module.ts
import { LatestSupportedPHPVersion as LatestSupportedPHPVersion2 } from "@php-wasm/universal";
async function getIntlExtensionModule(version = LatestSupportedPHPVersion2) {
  switch (version) {
    case "8.5":
      return (await import("@php-wasm/node-8-5")).getIntlExtensionPath();
    case "8.4":
      return (await import("@php-wasm/node-8-4")).getIntlExtensionPath();
    case "8.3":
      return (await import("@php-wasm/node-8-3")).getIntlExtensionPath();
    case "8.2":
      return (await import("@php-wasm/node-8-2")).getIntlExtensionPath();
    case "8.1":
      return (await import("@php-wasm/node-8-1")).getIntlExtensionPath();
    case "8.0":
      return (await import("@php-wasm/node-8-0")).getIntlExtensionPath();
    case "7.4":
      return (await import("@php-wasm/node-7-4")).getIntlExtensionPath();
  }
  throw new Error(`Unsupported PHP version ${version}`);
}

// packages/php-wasm/node/src/lib/extensions/memcached/get-memcached-extension-module.ts
import { LatestSupportedPHPVersion as LatestSupportedPHPVersion3 } from "@php-wasm/universal";
async function getMemcachedExtensionModule(version = LatestSupportedPHPVersion3) {
  switch (version) {
    case "8.5":
      return (await import("@php-wasm/node-8-5")).getMemcachedExtensionPath();
    case "8.4":
      return (await import("@php-wasm/node-8-4")).getMemcachedExtensionPath();
    case "8.3":
      return (await import("@php-wasm/node-8-3")).getMemcachedExtensionPath();
    case "8.2":
      return (await import("@php-wasm/node-8-2")).getMemcachedExtensionPath();
    case "8.1":
      return (await import("@php-wasm/node-8-1")).getMemcachedExtensionPath();
    case "8.0":
      return (await import("@php-wasm/node-8-0")).getMemcachedExtensionPath();
    case "7.4":
      return (await import("@php-wasm/node-7-4")).getMemcachedExtensionPath();
  }
  throw new Error(`Unsupported PHP version ${version}`);
}

// packages/php-wasm/node/src/lib/extensions/redis/get-redis-extension-module.ts
import { LatestSupportedPHPVersion as LatestSupportedPHPVersion4 } from "@php-wasm/universal";
async function getRedisExtensionModule(version = LatestSupportedPHPVersion4) {
  switch (version) {
    case "8.5":
      return (await import("@php-wasm/node-8-5")).getRedisExtensionPath();
    case "8.4":
      return (await import("@php-wasm/node-8-4")).getRedisExtensionPath();
    case "8.3":
      return (await import("@php-wasm/node-8-3")).getRedisExtensionPath();
    case "8.2":
      return (await import("@php-wasm/node-8-2")).getRedisExtensionPath();
    case "8.1":
      return (await import("@php-wasm/node-8-1")).getRedisExtensionPath();
    case "8.0":
      return (await import("@php-wasm/node-8-0")).getRedisExtensionPath();
    case "7.4":
      return (await import("@php-wasm/node-7-4")).getRedisExtensionPath();
  }
  throw new Error(`Unsupported PHP version ${version}`);
}

// packages/php-wasm/node/src/lib/extensions/xdebug/get-xdebug-extension-module.ts
import { LatestSupportedPHPVersion as LatestSupportedPHPVersion5 } from "@php-wasm/universal";
async function getXdebugExtensionModule(version = LatestSupportedPHPVersion5) {
  switch (version) {
    case "8.5":
      return (await import("@php-wasm/node-8-5")).getXdebugExtensionPath();
    case "8.4":
      return (await import("@php-wasm/node-8-4")).getXdebugExtensionPath();
    case "8.3":
      return (await import("@php-wasm/node-8-3")).getXdebugExtensionPath();
    case "8.2":
      return (await import("@php-wasm/node-8-2")).getXdebugExtensionPath();
    case "8.1":
      return (await import("@php-wasm/node-8-1")).getXdebugExtensionPath();
    case "8.0":
      return (await import("@php-wasm/node-8-0")).getXdebugExtensionPath();
    case "7.4":
      return (await import("@php-wasm/node-7-4")).getXdebugExtensionPath();
  }
  throw new Error(`Unsupported PHP version ${version}`);
}

// packages/php-wasm/node/src/lib/extensions/node-extension-resources.ts
import { readFile } from "fs/promises";
import path from "path";
import { fileURLToPath, pathToFileURL } from "url";
function normalizeNodeExtensionSource(source) {
  if (source.format === "url") {
    return {
      ...source,
      url: toNodeResourceUrl(source.url)
    };
  }
  if (source.format !== "manifest") {
    return source;
  }
  if ("manifest" in source) {
    return source.baseUrl ? {
      ...source,
      baseUrl: toNodeResourceUrl(source.baseUrl)
    } : source;
  }
  return {
    ...source,
    manifestUrl: toNodeResourceUrl(source.manifestUrl)
  };
}
async function fetchNodeExtensionResource(input) {
  const url = input instanceof Request ? new URL(input.url) : input instanceof URL ? input : toNodeResourceUrl(String(input));
  if (url.protocol === "file:") {
    try {
      return new Response(await readFile(fileURLToPath(url)));
    } catch (error) {
      return new Response(String(error), {
        status: 404,
        statusText: "Not Found"
      });
    }
  }
  return fetch(input);
}
function toNodeResourceUrl(urlOrPath) {
  if (urlOrPath instanceof URL) {
    return urlOrPath;
  }
  try {
    const url = new URL(urlOrPath);
    if (url.protocol === "http:" || url.protocol === "https:" || url.protocol === "file:") {
      return url;
    }
  } catch {
  }
  return pathToFileURL(path.resolve(urlOrPath));
}

// packages/php-wasm/node/src/lib/extensions/load-extensions.ts
async function withPHPExtensions(version, asyncMode, options, extensions = []) {
  if (!extensions.length) {
    return options;
  }
  const resolvedExtensions = await Promise.all(
    extensions.map(
      (extension) => resolveRuntimePHPExtension(version, asyncMode, extension)
    )
  );
  return withResolvedPHPExtensions(options, resolvedExtensions);
}
async function resolveRuntimePHPExtension(version, asyncMode, extension) {
  if (typeof extension === "object" && "source" in extension) {
    if (asyncMode === "asyncify") {
      throw new Error(
        "External PHP extensions require JSPI. Asyncify is only supported for PHP.wasm bundled extensions."
      );
    }
    return await resolvePHPExtension({
      ...extension,
      source: normalizeNodeExtensionSource(extension.source),
      phpVersion: version,
      fetch: extension.fetch ?? fetchNodeExtensionResource
    });
  }
  const builtIn = typeof extension === "string" ? { name: extension } : extension;
  switch (builtIn.name) {
    case "intl": {
      const extensionPath = await getIntlExtensionModule(version);
      const soBytes = new Uint8Array(fs.readFileSync(extensionPath));
      const dataName = "icu.dat";
      const moduleDir = typeof __dirname !== "undefined" ? __dirname : import.meta.dirname;
      const ICUData = fs.readFileSync(
        resolveIntlDataPath(moduleDir, dataName)
      );
      return await resolvePHPExtension({
        source: {
          format: "so",
          name: "intl",
          bytes: soBytes
        },
        phpVersion: version,
        env: {
          ICU_DATA: "/internal/shared"
        },
        extraFiles: {
          files: {
            // The Intl extension looks for the hard-coded ICU data name.
            "/internal/shared/icudt74l.dat": new Uint8Array(
              ICUData
            )
          }
        }
      });
    }
    case "redis": {
      const extensionPath = await getRedisExtensionModule(version);
      return await resolvePHPExtension({
        source: {
          format: "so",
          name: "redis",
          bytes: new Uint8Array(fs.readFileSync(extensionPath))
        },
        phpVersion: version
      });
    }
    case "memcached": {
      const extensionPath = await getMemcachedExtensionModule(version);
      return await resolvePHPExtension({
        source: {
          format: "so",
          name: "memcached",
          bytes: new Uint8Array(fs.readFileSync(extensionPath))
        },
        phpVersion: version
      });
    }
    case "xdebug": {
      const xdebugOptions = builtIn.options ?? {};
      const filePath = await getXdebugExtensionModule(version);
      const ideKey = xdebugOptions.ideKey || DEFAULT_IDE_KEY;
      return await resolvePHPExtension({
        source: {
          format: "so",
          name: "xdebug",
          bytes: new Uint8Array(fs.readFileSync(filePath))
        },
        phpVersion: version,
        loadWithIniDirective: "zend_extension",
        iniEntries: {
          "xdebug.mode": "debug,develop",
          "xdebug.start_with_request": "yes",
          "xdebug.idekey": `"${ideKey}"`,
          // Path mapping is only available starting from Xdebug 3.5,
          // which is used by PHP 8.5+. Previous versions ignore it.
          "xdebug.path_mapping": "yes"
        },
        extraFiles: resolveXdebugExtraFiles(version, xdebugOptions)
      });
    }
    default:
      throw new Error(
        `Unknown bundled PHP extension: ${String(builtIn.name)}.`
      );
  }
}
function resolveIntlDataPath(moduleDir, dataName) {
  const candidatePaths = [
    // Built package layout: dist/packages/php-wasm/node/shared/icu.dat.
    path2.join(moduleDir, "shared", dataName),
    // Source/test layout: src/lib/extensions/intl/shared/icu.dat.
    path2.join(moduleDir, "intl", "shared", dataName)
  ];
  const dataPath = candidatePaths.find(
    (candidate) => fs.existsSync(candidate)
  );
  if (!dataPath) {
    throw new Error(
      `Could not find ${dataName}. Checked: ${candidatePaths.join(", ")}`
    );
  }
  return dataPath;
}
function resolveXdebugExtraFiles(version, xdebugOptions) {
  const isPHP85orHigher = SupportedPHPVersions.indexOf(version) <= SupportedPHPVersions.indexOf("8.5");
  if (!isPHP85orHigher) {
    return void 0;
  }
  const { pathMappings, pathSkippings } = xdebugOptions;
  if (!pathMappings && !pathSkippings) {
    return void 0;
  }
  const files = {};
  if (pathMappings) {
    files["/.xdebug/path.map"] = pathMappings.map((map) => `${map.vfsPath} = ${map.hostPath}`).join("\n");
  }
  if (pathSkippings) {
    files["/.xdebug/skip.map"] = pathSkippings.map((path3) => `${path3} = SKIP`).join("\n");
  }
  return { files };
}

// packages/php-wasm/node/src/lib/load-runtime.ts
import { dirname, joinPaths, toPosixPath } from "@php-wasm/util";
import { platform } from "os";
import { jspi } from "wasm-feature-detect";
var dangerousDefaultProcessIdAllocator = process.env.VITEST ? new ProcessIdAllocator() : void 0;
async function loadNodeRuntime(phpVersion, options = {}) {
  const processId = options.emscriptenOptions?.processId ?? // !! Only assign a default process ID during test.
  // Otherwise, multiple workers with duplicate process IDs
  // could break file locking and lead to database corruption.
  (process.env.VITEST ? dangerousDefaultProcessIdAllocator.claim() : void 0);
  const isLegacy = isLegacyPHPVersion(phpVersion);
  const phpWasmAsyncMode = await jspi() ? "jspi" : "asyncify";
  const requestedExtensions = [...options.extensions ?? []];
  if (options.withIntl && !hasBuiltInExtension(requestedExtensions, "intl")) {
    requestedExtensions.push("intl");
  }
  if (options.withRedis && !hasBuiltInExtension(requestedExtensions, "redis")) {
    requestedExtensions.push("redis");
  }
  if (options.withMemcached && !hasBuiltInExtension(requestedExtensions, "memcached")) {
    requestedExtensions.push("memcached");
  }
  if (options.withXdebug && !hasBuiltInExtension(requestedExtensions, "xdebug")) {
    requestedExtensions.push(
      typeof options.withXdebug === "object" ? { name: "xdebug", options: options.withXdebug } : "xdebug"
    );
  }
  let emscriptenOptions = {
    /**
     * Emscripten default behavior is to kill the process when
     * the WASM program calls `exit()`. We want to throw an
     * exception instead.
     */
    quit: function(code, error) {
      throw error;
    },
    bindUserSpace: (userSpaceContext) => {
      const nativeFileLockManager = platform() === "win32" ? new FileLockManagerForWindows() : new FileLockManagerForPosix();
      const fileLockManager = options.fileLockManager ? new FileLockManagerComposite({
        nativeLockManager: nativeFileLockManager,
        wasmLockManager: options.fileLockManager
      }) : nativeFileLockManager;
      return bindUserSpace({ fileLockManager }, userSpaceContext);
    },
    ...options.emscriptenOptions || {},
    phpWasmAsyncMode,
    processId,
    // For legacy PHP: pre-create php.ini via a preRun step. See
    // createLegacyPhpIniPreRunStep for why this must run before
    // the PHP SAPI starts. Merge with any caller-provided preRun
    // hooks (the spread above may have set them).
    ...isLegacy ? {
      preRun: [
        createLegacyPhpIniPreRunStep(),
        ...options.emscriptenOptions?.preRun ?? []
      ]
    } : {},
    onRuntimeInitialized: (phpRuntime) => {
      if (options?.followSymlinks === true) {
        phpRuntime.FS.filesystems.NODEFS.node_ops.readlink = (node) => {
          const absoluteSourcePath = phpRuntime.FS.filesystems.NODEFS.tryFSOperation(
            () => fs2.realpathSync(
              phpRuntime.FS.filesystems.NODEFS.realPath(node)
            )
          );
          const normalizedPath = toPosixPath(absoluteSourcePath);
          const symlinkMountPath = joinPaths(
            `/internal/symlinks`,
            normalizedPath
          );
          if (fs2.existsSync(absoluteSourcePath)) {
            const sourceStat = fs2.statSync(absoluteSourcePath);
            if (!FSHelpers.fileExists(
              phpRuntime.FS,
              symlinkMountPath
            )) {
              if (sourceStat.isDirectory()) {
                phpRuntime.FS.mkdirTree(symlinkMountPath);
              } else if (sourceStat.isFile()) {
                phpRuntime.FS.mkdirTree(
                  dirname(symlinkMountPath)
                );
                phpRuntime.FS.writeFile(symlinkMountPath, "");
              } else {
                throw new Error(
                  "Unsupported file type. PHP-wasm supports only symlinks that link to files, directories, or symlinks."
                );
              }
            }
            const mountPath = sourceStat.isFile() ? dirname(symlinkMountPath) : symlinkMountPath;
            const mountRoot = sourceStat.isFile() ? dirname(normalizedPath) : absoluteSourcePath;
            const mountNode = phpRuntime.FS.lookupPath(mountPath).node;
            const isMounted = mountNode.mount.mountpoint === mountPath;
            if (!isMounted) {
              phpRuntime.FS.mount(
                phpRuntime.FS.filesystems.NODEFS,
                { root: mountRoot },
                mountPath
              );
            }
          }
          return symlinkMountPath;
        };
      }
      phpRuntime.FS.root.node_ops = {
        ...phpRuntime.FS.root.node_ops,
        statfs: phpRuntime.FS.filesystems.NODEFS.node_ops.statfs
      };
      phpRuntime.FS.root.mount.opts.root = ".";
    }
  };
  if (isLegacy && requestedExtensions.length) {
    throw new Error(
      `Extensions (xdebug, intl, redis, memcached) are not available for legacy PHP ${phpVersion}.`
    );
  }
  if (!isLegacy) {
    const modernVersion = phpVersion;
    emscriptenOptions = await withPHPExtensions(
      modernVersion,
      phpWasmAsyncMode,
      emscriptenOptions,
      requestedExtensions
    );
  }
  emscriptenOptions = await withNetworking(emscriptenOptions);
  const phpLoaderModule = await getPHPLoaderModule(phpVersion);
  const runtimeId = await loadPHPRuntime(phpLoaderModule, emscriptenOptions);
  return runtimeId;
}
function hasBuiltInExtension(extensions, name) {
  return extensions.some((extension) => {
    if (typeof extension === "string") {
      return extension === name;
    }
    return !("source" in extension) && extension.name === name;
  });
}

// packages/php-wasm/node/src/lib/use-host-filesystem.ts
import { lstatSync, readdirSync } from "node:fs";

// packages/php-wasm/node/src/lib/node-fs-mount.ts
import {
  FSHelpers as FSHelpers2
} from "@php-wasm/universal";
import { isParentOf } from "@php-wasm/util";
import { realpathSync, statSync } from "fs";
import { dirname as dirname2 } from "path";
function createNodeFsMountHandler(localPath) {
  return function(php, FS, vfsMountPoint) {
    let removeVfsNode = false;
    const mountRoot = realpathSync(localPath);
    if (!FSHelpers2.fileExists(FS, vfsMountPoint)) {
      const stat = statSync(mountRoot);
      if (stat.isFile()) {
        FS.mkdirTree(dirname2(vfsMountPoint));
        FS.writeFile(vfsMountPoint, "");
      } else if (stat.isDirectory()) {
        FS.mkdirTree(vfsMountPoint);
      } else {
        throw new Error(
          "Unsupported file type. PHP-wasm supports mounting only files and directories, including symlinks that resolve to files or directories."
        );
      }
      removeVfsNode = true;
    }
    let lookup3;
    try {
      lookup3 = FS.lookupPath(vfsMountPoint);
    } catch (e) {
      const error = e;
      if (error.errno === 44) {
        throw new Error(
          `Unable to access the mount point ${vfsMountPoint} in VFS after attempting to create it.`
        );
      }
      throw e;
    }
    FS.mount(FS.filesystems["NODEFS"], { root: mountRoot }, vfsMountPoint);
    return () => {
      FS.unmount(vfsMountPoint);
      if (removeVfsNode) {
        if (FS.isDir(lookup3.node.mode)) {
          if (isParentOf(vfsMountPoint, FS.cwd())) {
            throw new Error(
              `Cannot remove the VFS directory "${vfsMountPoint}" on umount cleanup \u2013 it is a parent of the CWD "${FS.cwd()}". Change CWD before unmounting or explicitly disable post-unmount node cleanup with createNodeFsMountHandler(path, {cleanupNodesOnUnmount: false}).`
            );
          }
          FS.rmdir(vfsMountPoint);
        } else {
          FS.unlink(vfsMountPoint);
        }
      }
    };
  };
}

// packages/php-wasm/node/src/lib/use-host-filesystem.ts
function useHostFilesystem(php) {
  const dirs = readdirSync("/").filter((file) => file !== "dev").filter((file) => file !== "proc").map((file) => `/${file}`).filter((file) => {
    try {
      return statPathFollowSymlinks(file).isDirectory();
    } catch {
      return false;
    }
  });
  for (const dir of dirs) {
    if (!php.fileExists(dir)) {
      php.mkdirTree(dir);
    }
    php.mount(dir, createNodeFsMountHandler(dir));
  }
  php.chdir(process.cwd());
}
function statPathFollowSymlinks(path3) {
  let stat = lstatSync(path3);
  if (stat.isSymbolicLink()) {
    const fs3 = __require("fs");
    let target = path3;
    const seen = /* @__PURE__ */ new Set();
    while (true) {
      if (seen.has(target)) {
        throw new Error(`Symlink loop detected: ${path3}`);
      }
      seen.add(target);
      const linkStat = lstatSync(target);
      if (linkStat.isSymbolicLink()) {
        target = fs3.realpathSync(target);
        continue;
      }
      stat = linkStat;
      break;
    }
  }
  return stat;
}
export {
  FileLockManagerForPosix,
  FileLockManagerForWindows,
  bindUserSpace,
  createNodeFsMountHandler,
  getPHPLoaderModule,
  loadNodeRuntime,
  useHostFilesystem,
  withNetworking
};
