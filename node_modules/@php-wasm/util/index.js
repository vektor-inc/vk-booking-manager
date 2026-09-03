const d = Symbol("SleepFinished");
function g(i) {
  return new Promise((e) => {
    setTimeout(() => e(d), i);
  });
}
class p extends Error {
  constructor() {
    super("Acquiring lock timed out");
  }
}
class A {
  constructor({ concurrency: e, timeout: t }) {
    this._running = 0, this.concurrency = e, this.timeout = t, this.queue = [];
  }
  get remaining() {
    return this.concurrency - this.running;
  }
  get running() {
    return this._running;
  }
  async acquire() {
    if (this._running >= this.concurrency) {
      const t = new Promise((r) => {
        this.queue.push(r);
      });
      if (this.timeout !== void 0) {
        const r = this.queue.at(-1);
        if (await Promise.race([
          t,
          g(this.timeout)
        ]) === d)
          throw this.queue.splice(this.queue.indexOf(r), 1), new p();
      } else
        await t;
    }
    this._running++;
    let e = !1;
    return () => {
      e || (e = !0, this._running--, this.queue.length > 0 && this.queue.shift()());
    };
  }
  async run(e) {
    const t = await this.acquire();
    try {
      return await e();
    } finally {
      t();
    }
  }
}
class O extends Error {
  constructor(e, t) {
    super(e), this.userFriendlyMessage = t ?? e;
  }
}
function w(...i) {
  function e(o) {
    return o.substring(o.length - 1) === "/";
  }
  let t = i.join("/");
  const r = t[0] === "/", s = e(t);
  return t = f(t), !t && !r && (t = "."), t && s && !e(t) && (t += "/"), t;
}
function B(i) {
  if (i === "/")
    return "/";
  i = f(i);
  const e = i.lastIndexOf("/");
  return e === -1 ? "" : e === 0 ? "/" : i.substr(0, e);
}
function k(i) {
  if (i === "/")
    return "/";
  i = f(i);
  const e = i.lastIndexOf("/");
  return e === -1 ? i : i.substr(e + 1);
}
function f(i) {
  const e = i[0] === "/";
  return i = y(
    i.split("/").filter((t) => !!t),
    !e
  ).join("/"), (e ? "/" : "") + i.replace(/\/$/, "");
}
function y(i, e) {
  let t = 0;
  for (let r = i.length - 1; r >= 0; r--) {
    const s = i[r];
    s === "." ? i.splice(r, 1) : s === ".." ? (i.splice(r, 1), t++) : t && (i.splice(r, 1), t--);
  }
  if (e)
    for (; t; t--)
      i.unshift("..");
  return i;
}
function q(i, e) {
  return i === "/" ? !0 : (i = f(i), e = f(e), e.startsWith(i + "/") || e === i);
}
function U(i) {
  return w("/", f(i || "/"));
}
function D(i) {
  let e = i.replaceAll("\\", "/");
  const t = e.match(/^([A-Za-z]):\//);
  return t && (e = "/" + t[1] + e.slice(2)), e;
}
class a {
  constructor() {
    this.listeners = {};
  }
  emit(e, t) {
    this.listeners[e] && this.listeners[e].forEach(function(r) {
      r(t);
    });
  }
  on(e, t) {
    this.listeners[e] || (this.listeners[e] = []), this.listeners[e].push(t);
  }
  once(e, t) {
    const r = (...s) => {
      this.off(e, r), t(...s);
    };
    this.on(e, r);
  }
  off(e, t) {
    this.listeners[e] && (this.listeners[e] = this.listeners[e].filter(
      (r) => r !== t
    ));
  }
}
function m(i) {
  let r = 0, s = "";
  const o = [];
  let n = "";
  for (let l = 0; l < i.length; l++) {
    const u = i[l];
    u === "\\" ? ((i[l + 1] === '"' || i[l + 1] === "'") && l++, n += i[l]) : r === 0 ? u === '"' || u === "'" ? (r = 1, s = u) : u.match(/\s/) ? (n.trim().length && o.push(n.trim()), n = u) : o.length && !n ? n = o.pop() + u : n += u : r === 1 && (u === s ? (r = 0, s = "") : n += u);
  }
  return n && o.push(n.trim()), o;
}
class h extends a {
  constructor(e) {
    if (super(), this.buffer = [], this.writing = !1, this.ended = !1, this.length = 0, !e.write)
      throw new Error("WritablePolyfill requires write option");
    this._write = e.write, this.highWaterMark = e.highWaterMark ?? 16 * 1024, this.decodeStrings = e.decodeStrings ?? !0, this.defaultEncoding = e.defaultEncoding ?? "utf8", this.defer = typeof queueMicrotask == "function" ? queueMicrotask : (t) => setTimeout(t, 0);
  }
  write(e, t = this.defaultEncoding, r = () => {
  }) {
    if (typeof t == "function" && (r = t, t = this.defaultEncoding), this.ended) {
      const o = new Error("write after end"), n = this.defer;
      return n(() => r(o)), this.emit("error", o), !1;
    }
    if (this.decodeStrings && typeof e == "string") {
      if (typeof Buffer < "u" && typeof Buffer.from == "function")
        e = Buffer.from(e, t);
      else if (typeof TextEncoder < "u")
        e = new TextEncoder().encode(e);
      else
        throw new Error(
          "String chunks are not supported in this environment: Buffer and TextEncoder are unavailable."
        );
      t = "buffer";
    }
    this.length += e.length ?? 1;
    const s = this.length >= this.highWaterMark;
    return this.buffer.push({ chunk: e, encoding: t, cb: r }), this.writing || this._clearBuffer(), !s;
  }
  end(e, t, r) {
    typeof e == "function" ? (r = e, e = void 0) : typeof t == "function" && (r = t, t = void 0), e !== void 0 && this.write(e, t, () => {
    }), this.ended = !0, this.writing || this._clearBuffer(), r && this.defer(r);
  }
  // Stubs kept for API parity; add logic if you depend on corking.
  cork() {
  }
  uncork() {
  }
  setDefaultEncoding(e) {
    return this.defaultEncoding = e, this;
  }
  _clearBuffer() {
    const e = this.buffer.shift();
    if (!e) {
      this.ended && this.emit("finish");
      return;
    }
    this.writing = !0, this._write(e.chunk, e.encoding, (t) => {
      this.writing = !1, this.length -= e.chunk.length ?? 1, t && this.emit("error", t), e.cb(t), this.buffer.length ? this._clearBuffer() : (this.length < this.highWaterMark && this.emit("drain"), this.ended && this.emit("finish"));
    });
  }
}
function I(i) {
  return function(e, t = [], r = {}) {
    const s = new P(), o = new b(s);
    return setTimeout(async () => {
      let n = [];
      if (t.length)
        n = [e, ...t];
      else if (typeof e == "string")
        n = m(e);
      else if (Array.isArray(e))
        n = e;
      else
        throw new Error("Invalid command ", e);
      try {
        const l = i(n, o, r);
        if (typeof l != "object" || l === null || !("then" in l))
          throw new Error(
            `The program callback passed to createSpawnHandler() did not return a promise. It indicates there's a bug in your code. The callback must return a promise. PHP cannot interact with program that synchronously exists at the end of the proc_open() call. All the streams would be closed already. Make sure to put an "await new Promise(resolve => setTimeout(resolve, 1))before calling processApi.exit(0) in your callback to let PHP catch up with the stdout data.`
          );
        if (o.exited)
          throw new Error(
            `The program callback passed to createSpawnHandler() exited synchronously. It indicates there's a bug in your code. The callback must return a promise. PHP cannot interact with program that synchronously exists at the end of the proc_open() call. All the streams would be closed already. Make sure to put an "await new Promise(resolve => setTimeout(resolve, 1))before calling processApi.exit(0) in your callback to let PHP catch up with the stdout data.`
          );
        s.emit("spawn", !0), await l;
      } catch (l) {
        s.emit("error", l), typeof l == "object" && l !== null && "message" in l && typeof l.message == "string" && o.stderr(l.message), o.exit(1);
      }
    }), s;
  };
}
class b extends a {
  constructor(e) {
    super(), this.exited = !1, this.stdinBuffer = [], this.childProcess = e, e.on("stdin", (t) => {
      this.stdinBuffer ? this.stdinBuffer.push(t.slice()) : this.emit("stdin", t);
    });
  }
  stdinEnd() {
    this.childProcess.stdin.ended || this.childProcess.stdin.end();
  }
  stdout(e) {
    this.childProcess.stdout.write(e);
  }
  stdoutEnd() {
    this.childProcess.stdout.ended || this.childProcess.stdout.end();
  }
  stderr(e) {
    this.childProcess.stderr.write(e);
  }
  stderrEnd() {
    this.childProcess.stderr.ended || this.childProcess.stderr.end();
  }
  notifySpawn() {
    this.childProcess.emit("spawn", !0);
  }
  exit(e) {
    this.exited || (this.exited = !0, this.stdinEnd(), this.stdoutEnd(), this.stderrEnd(), this.childProcess.emit("exit", e));
  }
  on(e, t) {
    if (super.on(e, t), e === "stdin" && this.stdinBuffer) {
      for (let r = 0; r < this.stdinBuffer.length; r++)
        this.emit("stdin", this.stdinBuffer[r]);
      this.stdinBuffer = null;
    }
  }
}
let E = 9743;
class P extends a {
  constructor(e = E++) {
    super(), this.pid = e;
    const t = this;
    this.stdout = new h({
      write(r, s, o) {
        t.stdout.emit("data", r), o();
      }
    }), this.stderr = new h({
      write: (r, s, o) => {
        t.stderr.emit("data", r), o();
      }
    }), this.stdin = new h({
      write: (r, s, o) => {
        t.emit("stdin", r), o();
      }
    });
  }
}
function x(i = 36, e = "!@#$%^&*()_+=-[]/.,<>?") {
  const t = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ" + e;
  let r = "";
  for (let s = i; s > 0; --s)
    r += t[Math.floor(Math.random() * t.length)];
  return r;
}
function W() {
  return x(36, "-_");
}
function S(i) {
  return `json_decode(base64_decode('${_(
    JSON.stringify(i)
  )}'), true)`;
}
function j(i) {
  const e = {};
  for (const t in i)
    e[t] = S(i[t]);
  return e;
}
function _(i) {
  return T(new TextEncoder().encode(i));
}
function T(i) {
  const e = String.fromCodePoint(...i);
  return btoa(e);
}
function H(i, ...e) {
  let t = "", r = 0;
  for (let s = 0; s < i.length; s++)
    if (i[s] === "%" && s + 1 < i.length) {
      s++;
      const o = i[s];
      switch (o) {
        case "s": {
          const n = e[r++];
          let l;
          if (typeof n == "object")
            try {
              l = JSON.stringify(
                n,
                // Represent bigint values as strings in JSON.stringify().
                (u, c) => typeof c == "bigint" ? `0x${c.toString(16)}` : c,
                2
              );
            } catch {
            }
          else
            l = String(n);
          t += l;
          break;
        }
        case "d": {
          const n = e[r++];
          typeof n == "bigint" ? t += n.toString() : t += Math.floor(Number(n));
          break;
        }
        case "f": {
          const n = e[r++];
          t += Number(n);
          break;
        }
        case "x": {
          const n = e[r++];
          typeof n == "bigint" ? t += n.toString(16) : t += Math.floor(Number(n)).toString(16);
          break;
        }
        case "%": {
          t += "%";
          break;
        }
        default:
          t += "%" + o;
      }
    } else
      t += i[s];
  return t;
}
function M(i) {
  let e = 0;
  i.forEach((s) => e += s.length);
  const t = new Uint8Array(e);
  let r = 0;
  return i.forEach((s) => {
    t.set(s, r), r += s.length;
  }), t;
}
function Q(i) {
  return M(i.map((e) => new Uint8Array(e))).buffer;
}
export {
  p as AcquireTimeoutError,
  a as EventEmitterPolyfill,
  O as PhpWasmError,
  A as Semaphore,
  h as WritablePolyfill,
  k as basename,
  Q as concatArrayBuffers,
  M as concatUint8Arrays,
  I as createSpawnHandler,
  B as dirname,
  U as ensureAbsolutePath,
  q as isParentOf,
  w as joinPaths,
  f as normalizePath,
  S as phpVar,
  j as phpVars,
  W as randomFilename,
  x as randomString,
  m as splitShellCommand,
  H as sprintf,
  D as toPosixPath
};
//# sourceMappingURL=index.js.map
