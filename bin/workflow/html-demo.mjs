// Single-file HTML demos of `code_text` steps (contract §7): extraction from the model text, static
// checks and the repair prompt. Inline scripts are only compiled with node:vm to find syntax errors,
// never run. Each issue has a stable `code`, a Turkish `message` for the UI and an English `detail` that
// the repair prompt sends back to the model.
import { Buffer } from "node:buffer";
import vm from "node:vm";
import { clipText, fencedBlocks } from "./json-output.mjs";

/** Check settings used when a step has no `checks` (contract §1.2). */
export const DEFAULT_DEMO_CHECKS = Object.freeze({
  requireListeners: Object.freeze(["pointerdown", "touchstart", "mousedown"]),
  maxBytes: 204800,
  forbidNetwork: true,
});

const MAX_PREVIOUS_HTML_BYTES = 150 * 1024;
const MAX_NETWORK_ISSUES = 10;
const MAX_SCRIPT_ISSUES = 10;
const MAX_PROBLEM_CHARS = 500;

const HTML_OPEN = /<html(?=[\s>/]|$)/i;
const HTML_CLOSE = /<\/html\s*>/i;
const DOCUMENT_START = /<!doctype\s+html|<html(?=[\s>/]|$)/i;
const TAG = /<([A-Za-z][A-Za-z0-9:-]*)((?:[^>"']|"[^"]*"|'[^']*')*)>/g;
const ATTRIBUTE = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
const COMMENT_OR_SCRIPT = /<!--|<script\b((?:[^>"']|"[^"]*"|'[^']*')*)>/gi;
const SCRIPT_CLOSE = /<\/script\s*>/gi;

const JAVASCRIPT_TYPES = new Set([
  "application/ecmascript", "application/javascript", "application/x-ecmascript", "application/x-javascript",
  "text/ecmascript", "text/javascript", "text/javascript1.0", "text/javascript1.1", "text/javascript1.2",
  "text/javascript1.3", "text/javascript1.4", "text/javascript1.5", "text/jscript", "text/livescript",
  "text/x-ecmascript", "text/x-javascript",
]);

/** Attributes whose value the browser loads or navigates to. */
const URL_ATTRIBUTES = new Set([
  "src", "href", "xlink:href", "action", "formaction", "poster", "background", "data", "manifest",
]);
const URL_LIST_ATTRIBUTES = new Set(["srcset", "imagesrcset"]);

const REMOTE_URL = /^(?:(?:https?|wss?):|[\\/]{2})/i;
const REMOTE_IN_STRING = String.raw`(?:(?:https?|wss?):|\/\/)[^'"\x60\s)]*`;

const NETWORK_APIS = [
  { pattern: /\bfetch\s*\(/, name: "fetch()" },
  { pattern: /\bXMLHttpRequest\b/, name: "XMLHttpRequest" },
  { pattern: /\bWebSocket\b/, name: "WebSocket" },
  { pattern: /\bimportScripts\b/, name: "importScripts" },
  { pattern: /\bEventSource\b/, name: "EventSource" },
  { pattern: /\bsendBeacon\b/, name: "navigator.sendBeacon" },
  // WebRTC and self-navigation reach the network even under the demo route's CSP.
  { pattern: /\b(?:webkit)?RTCPeerConnection\b/, name: "RTCPeerConnection" },
  { pattern: /\blocation\s*\.\s*(?:assign|replace)\s*\(/, name: "location.assign() / location.replace()" },
  // A restart such as `location.href = location.href` stays allowed; location.reload() is never matched.
  {
    pattern: /\blocation(?:\s*\.\s*href)?\s*=(?!=)(?!\s*(?:window\s*\.\s*|document\s*\.\s*)?location\b)/,
    name: "location.href =",
  },
];

const SCRIPT_URL_PATTERNS = [
  new RegExp(String.raw`\.(?:src|href|srcset|poster|action)\s*=\s*['"\x60]\s*(${REMOTE_IN_STRING})`, "gi"),
  new RegExp(String.raw`\bsetAttribute\s*\(\s*['"\x60](?:src|href|srcset|poster|action|data)['"\x60]\s*,\s*['"\x60]\s*(${REMOTE_IN_STRING})`, "gi"),
  new RegExp(String.raw`\bimport\s*\(\s*['"\x60]\s*(${REMOTE_IN_STRING})`, "g"),
  new RegExp(String.raw`\b(?:import|from)\s*['"\x60]\s*(${REMOTE_IN_STRING})`, "g"),
  new RegExp(String.raw`\bnew\s+(?:Audio|Worker|SharedWorker)\s*\(\s*['"\x60]\s*(${REMOTE_IN_STRING})`, "g"),
];

const CSS_URL_PATTERNS = [
  // Lower-case only, so JavaScript's `new URL("https://…")` is not mistaken for CSS.
  { pattern: /(?<![\w$.])url\(\s*['"]?\s*((?:(?:https?|wss?):|[\\/]{2})[^'")\s]*)/g, label: "CSS url()" },
  { pattern: /@import\s+(?:url\(\s*)?['"]?\s*((?:(?:https?|wss?):|[\\/]{2})[^'")\s;]*)/gi, label: "CSS @import" },
];

const NAMED_ENTITIES = {
  amp: "&", AMP: "&", lt: "<", LT: "<", gt: ">", GT: ">", quot: "\"", QUOT: "\"", apos: "'",
  colon: ":", sol: "/", Tab: "\t", NewLine: "\n", period: ".", lpar: "(", rpar: ")",
};

/**
 * Takes the HTML document out of a model answer: the first ```html block, else the first ``` block that
 * contains "<html", else the text from the first "<!doctype html" or "<html" to the last "</html>".
 * `truncated` is true when a document was found but has no "</html>".
 */
export function extractHtml(text) {
  if (typeof text !== "string") return { html: null, truncated: false };

  const blocks = fencedBlocks(text);
  let html = blocks.find((block) => block.language === "html")?.body
    ?? blocks.find((block) => /<html/i.test(block.body))?.body
    ?? null;

  if (html === null) {
    const start = text.search(DOCUMENT_START);

    if (start !== -1) {
      let end = text.length;
      for (const match of text.slice(start).matchAll(/<\/html\s*>/gi)) end = start + match.index + match[0].length;
      html = text.slice(start, end);
    }
  }

  html = html?.trim() || null;

  return { html, truncated: html !== null && !HTML_CLOSE.test(html) };
}

function isRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function normalizeChecks(checks) {
  const source = isRecord(checks) ? checks : {};

  return {
    requireListeners: Array.isArray(source.requireListeners)
      ? source.requireListeners.filter((name) => typeof name === "string" && /^[A-Za-z][A-Za-z0-9_-]*$/.test(name))
      : [...DEFAULT_DEMO_CHECKS.requireListeners],
    maxBytes: Number.isSafeInteger(source.maxBytes) && source.maxBytes > 0 ? source.maxBytes : DEFAULT_DEMO_CHECKS.maxBytes,
    forbidNetwork: typeof source.forbidNetwork === "boolean" ? source.forbidNetwork : DEFAULT_DEMO_CHECKS.forbidNetwork,
  };
}

function decodeEntities(value) {
  return value.replace(/&(?:#(\d{1,7})|#[xX]([0-9A-Fa-f]{1,6})|([A-Za-z]+));/g, (entity, decimal, hex, name) => {
    if (name !== undefined) return Object.hasOwn(NAMED_ENTITIES, name) ? NAMED_ENTITIES[name] : entity;

    const codePoint = decimal !== undefined ? Number(decimal) : Number.parseInt(hex, 16);
    return codePoint > 0 && codePoint <= 0x10ffff ? String.fromCodePoint(codePoint) : entity;
  });
}

function parseAttributes(source) {
  const attributes = new Map();

  for (const match of source.matchAll(ATTRIBUTE)) {
    const name = match[1].toLowerCase();
    if (!attributes.has(name)) attributes.set(name, decodeEntities(match[2] ?? match[3] ?? match[4] ?? ""));
  }

  return attributes;
}

/** Removes JavaScript comments; strings and template literals are kept as they are. */
function stripScriptComments(code) {
  let result = "";
  let position = 0;

  while (position < code.length) {
    const character = code[position];
    const next = code[position + 1];

    if (character === "/" && next === "/") {
      const end = code.indexOf("\n", position + 2);
      position = end === -1 ? code.length : end;
      continue;
    }

    if (character === "/" && next === "*") {
      const end = code.indexOf("*/", position + 2);
      position = end === -1 ? code.length : end + 2;
      result += " ";
      continue;
    }

    if (character === "'" || character === "\"" || character === "`") {
      let end = position + 1;
      while (end < code.length && code[end] !== character) {
        if (code[end] === "\\") end += 1;
        else if (character !== "`" && code[end] === "\n") break;
        end += 1;
      }
      result += code.slice(position, end + 1);
      position = end + 1;
      continue;
    }

    result += character;
    position += 1;
  }

  return result;
}

/** Splits the document into markup (without comments and script bodies) and its <script> elements. */
function scanDocument(html) {
  const scripts = [];
  let markup = "";
  let cursor = 0;
  let line = 1;
  let counted = 0;

  // Offsets only grow, so the newlines are counted once.
  const lineAt = (offset) => {
    let index = html.indexOf("\n", counted);
    while (index !== -1 && index < offset) {
      line += 1;
      index = html.indexOf("\n", index + 1);
    }
    counted = offset;
    return line;
  };

  while (cursor < html.length) {
    COMMENT_OR_SCRIPT.lastIndex = cursor;
    const match = COMMENT_OR_SCRIPT.exec(html);

    if (match === null) {
      markup += html.slice(cursor);
      break;
    }

    markup += html.slice(cursor, match.index);

    if (match[0] === "<!--") {
      const end = html.indexOf("-->", match.index + 4);
      cursor = end === -1 ? html.length : end + 3;
      continue;
    }

    markup += match[0];
    const bodyStart = match.index + match[0].length;
    SCRIPT_CLOSE.lastIndex = bodyStart;
    const close = SCRIPT_CLOSE.exec(html);
    const attributes = parseAttributes(match[1]);

    scripts.push({
      attributes,
      type: (attributes.get("type") ?? "").split(";")[0].trim().toLowerCase(),
      body: html.slice(bodyStart, close === null ? html.length : close.index),
      line: lineAt(bodyStart),
    });
    cursor = close === null ? html.length : close.index + close[0].length;
  }

  const tags = [];
  for (const tag of markup.matchAll(TAG)) {
    tags.push({ name: tag[1].toLowerCase(), attributes: parseAttributes(tag[2]) });
  }

  return { markup, scripts, tags };
}

function isRemoteUrl(value) {
  return REMOTE_URL.test(value.replace(/[\t\n\r]/g, "").replace(/^[\x00-\x20]+/, ""));
}

function attributeUrls(name, value) {
  if (URL_LIST_ATTRIBUTES.has(name)) {
    return value.split(",").map((candidate) => candidate.trim().split(/\s+/)[0] ?? "");
  }
  if (name === "ping") return value.trim().split(/\s+/);

  return URL_ATTRIBUTES.has(name) ? [value] : [];
}

function listOf(names, conjunction) {
  if (names.length < 2) return names.join("");

  return `${names.slice(0, -1).join(", ")} ${conjunction} ${names.at(-1)}`;
}

function issue(code, message, detail) {
  return { code, message, detail };
}

function syntaxIssues(scripts) {
  const issues = [];
  let number = 0;

  for (const script of scripts) {
    const classic = !script.attributes.has("src") && (script.type === "" || JAVASCRIPT_TYPES.has(script.type));
    if (!classic || script.body.trim() === "") continue;

    number += 1;
    try {
      new vm.Script(script.body, { filename: `inline-script-${number}.js`, lineOffset: script.line - 1 });
    } catch (error) {
      const location = /^inline-script-\d+\.js:(\d+)/.exec(String(error?.stack ?? ""));
      const line = location === null ? script.line : Number(location[1]);
      const reason = clipText(String(error?.message ?? error), 200);
      issues.push(issue(
        "script-syntax",
        `Satır içi script sözdizimi hatası: ${reason} (satır ${line})`,
        `Inline script ${number} has a syntax error at line ${line} of the document: ${reason}`,
      ));
      if (issues.length >= MAX_SCRIPT_ISSUES) break;
    }
  }

  return issues;
}

function networkIssues(tags, markup, code) {
  const found = new Map();
  const add = (key, turkish, english) => {
    if (found.size < MAX_NETWORK_ISSUES && !found.has(key)) {
      found.set(key, issue("external-network", `Dış ağ erişimi: ${turkish}.`, `External network access is not allowed: ${english}.`));
    }
  };

  for (const tag of tags) {
    for (const [name, value] of tag.attributes) {
      for (const url of attributeUrls(name, value)) {
        if (!isRemoteUrl(url)) continue;
        const shown = clipText(url.trim(), 120);
        add(`${tag.name} ${name} ${shown}`, `<${tag.name}> ${name} dış adrese gidiyor (${shown})`, `<${tag.name} ${name}="${shown}">`);
      }
    }
  }

  for (const { pattern, label } of CSS_URL_PATTERNS) {
    for (const source of [markup, code]) {
      for (const match of source.matchAll(pattern)) {
        const shown = clipText(match[1], 120);
        add(`css ${shown}`, `${label} dış adrese gidiyor (${shown})`, `${label} with ${shown}`);
      }
    }
  }

  for (const pattern of SCRIPT_URL_PATTERNS) {
    for (const match of code.matchAll(pattern)) {
      const shown = clipText(match[1], 120);
      add(`script ${shown}`, `script dış adres yüklüyor (${shown})`, `the script loads ${shown}`);
    }
  }

  for (const { pattern, name } of NETWORK_APIS) {
    if (pattern.test(code)) add(`api ${name}`, `${name} kullanılıyor`, `${name} is used`);
  }

  return [...found.values()];
}

/**
 * Static checks of a demo document (contract §7). `checks` may set `requireListeners` (at least one must
 * be listened to), `maxBytes` (warning threshold) and `forbidNetwork`; missing values use
 * DEFAULT_DEMO_CHECKS. Returns `{ critical, warnings }`, lists of `{ code, message, detail }`.
 */
export function checkDemoHtml(html, checks = {}) {
  const settings = normalizeChecks(checks);
  const critical = [];
  const warnings = [];

  if (typeof html !== "string" || html.trim() === "" || !HTML_OPEN.test(html) || !HTML_CLOSE.test(html)) {
    critical.push(issue(
      "missing-html",
      "Çıktı kesilmiş: <html> veya </html> etiketi yok.",
      "The HTML document is incomplete: the <html> or </html> tag is missing.",
    ));
  }
  if (typeof html !== "string" || html.trim() === "") return { critical, warnings };

  const { markup, scripts, tags } = scanDocument(html);
  const handlerNames = new Set();
  const codeParts = [];

  for (const script of scripts) {
    if (script.type === "" || script.type === "module" || JAVASCRIPT_TYPES.has(script.type)) {
      codeParts.push(stripScriptComments(script.body));
    }
  }
  for (const tag of tags) {
    for (const [name, value] of tag.attributes) {
      if (!name.startsWith("on")) continue;
      handlerNames.add(name);
      codeParts.push(stripScriptComments(value));
    }
  }

  const code = codeParts.join("\n");
  const usesAddEventListener = /\baddEventListener\b/.test(code);
  const listensTo = (event) => handlerNames.has(`on${event}`)
    || new RegExp(String.raw`\bon${event}\b`).test(code)
    || (usesAddEventListener && new RegExp(String.raw`(['"\x60])${event}\1`).test(code));

  critical.push(...syntaxIssues(scripts));
  if (settings.forbidNetwork) critical.push(...networkIssues(tags, markup, code));

  const listeners = settings.requireListeners;
  if (listeners.length > 0 && !listeners.some(listensTo)) {
    const pointerOnly = listeners.every((name) => /^(?:pointer|touch|mouse|click)/i.test(name));
    critical.push(issue(
      "missing-input-listener",
      pointerOnly
        ? `Dokunma/fare dinleyicisi yok (${listOf(listeners, "veya")}).`
        : `Gerekli olay dinleyicisi yok (${listOf(listeners, "veya")}).`,
      `No input listener was found: listen to ${listOf(listeners, "or")} with addEventListener.`,
    ));
  }

  if (!listensTo("resize") && !/\bResizeObserver\b/.test(code)) {
    warnings.push(issue(
      "missing-resize",
      "Ekran boyutu değişimi (resize) dinlenmiyor.",
      "There is no resize listener; the demo should adapt to the window size.",
    ));
  }
  if (!/\brequestAnimationFrame\b/.test(code)) {
    warnings.push(issue(
      "missing-raf",
      "requestAnimationFrame kullanılmıyor.",
      "requestAnimationFrame is not used.",
    ));
  }
  if (handlerNames.has("onvisibilitychange") || /\bvisibilitychange\b|\bdocument\.hidden\b|\bvisibilityState\b/.test(code)) {
    warnings.push(issue(
      "visibility-api",
      "Sayfa görünürlüğü API'si kullanılıyor (visibilitychange, document.hidden veya visibilityState).",
      "The Page Visibility API (visibilitychange, document.hidden or visibilityState) is used.",
    ));
  }

  const bytes = Buffer.byteLength(html, "utf8");
  if (bytes > settings.maxBytes) {
    const size = Math.ceil(bytes / 1024);
    const limit = Math.floor(settings.maxBytes / 1024);
    warnings.push(issue(
      "too-large",
      `Dosya ${size} KB; önerilen üst sınır ${limit} KB.`,
      `The file is ${size} KB, more than the ${limit} KB limit.`,
    ));
  }
  if (scripts.some((script) => script.type === "module")) {
    warnings.push(issue(
      "module-script",
      "type=\"module\" script var; sözdizimi denetlenmedi.",
      "A <script type=\"module\"> is used; its syntax was not checked.",
    ));
  }

  return { critical, warnings };
}

/**
 * The text appended to the rendered prompt for a repair attempt, starting with its own blank line.
 * `problems` are check issues (their English `detail` is used) or plain strings. The previous HTML is
 * included only when it is complete and at most 150 KB.
 */
export function demoRepairSuffix(problems, previousHtml, truncated) {
  const lines = (Array.isArray(problems) ? problems : [])
    .map((problem) => (typeof problem === "string" ? problem : String(problem?.detail ?? problem?.message ?? "")))
    .map((line) => clipText(line.trim(), MAX_PROBLEM_CHARS))
    .filter((line) => line !== "");

  let suffix = "\n\nYOUR PREVIOUS ANSWER HAD THESE PROBLEMS:\n";
  suffix += lines.map((line) => `- ${line}\n`).join("");

  if (!truncated && typeof previousHtml === "string" && previousHtml.trim() !== ""
    && Buffer.byteLength(previousHtml, "utf8") <= MAX_PREVIOUS_HTML_BYTES) {
    suffix += `Previous HTML:\n\`\`\`html\n${previousHtml}\n\`\`\`\n`;
  }
  if (truncated) {
    suffix += "Your answer was cut off. Write a smaller version that fits.\n";
  }

  return `${suffix}Return the complete corrected HTML document in a single \`\`\`html code block.`;
}
