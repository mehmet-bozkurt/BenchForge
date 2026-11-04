// Workflow template placeholders and path expressions (`benchforge-workflow/1`, contract §1.3).
// The PHP validator (WorkflowTemplateValidator) implements the same grammar; both are tested against
// tests/Fixtures/workflows/path-vectors.json. Rendering is plain substitution: nothing is ever evaluated.

/** The five roots a path may start with. */
export const PATH_ROOTS = Object.freeze(["inputs", "constants", "steps", "item", "loop"]);

/** Names that never resolve, even as own properties, so a path cannot reach prototype machinery. */
const BLOCKED_NAMES = new Set(["__proto__", "constructor", "prototype"]);

const IDENTIFIER = /[A-Za-z_][A-Za-z0-9_-]*/y;
const INDEX = /\[(0|[1-9][0-9]*)\]/y;

// Whitespace allowed around a path inside the braces. ASCII only, like PCRE's \s without the u flag.
const PADDING = new Set([" ", "\t", "\n", "\r", "\f", "\v"]);

function matchAt(pattern, text, position) {
  pattern.lastIndex = position;
  return pattern.exec(text);
}

function describe(text) {
  return JSON.stringify(text.length > 80 ? `${text.slice(0, 80)}…` : text);
}

/**
 * Parses a path expression without braces: `root ("." ident | "[" digits "]" | "[*]")*`.
 * Returns `{ root, segments }` with segments `{type:"field",name}`, `{type:"index",index}` or `{type:"all"}`.
 * Throws an Error with an English message when the text does not follow the grammar.
 */
export function parsePath(text) {
  if (typeof text !== "string" || text === "") {
    throw new Error("A path must not be empty.");
  }

  const root = matchAt(IDENTIFIER, text, 0)?.[0] ?? null;
  if (root === null || !PATH_ROOTS.includes(root)) {
    throw new Error(`Path ${describe(text)} must start with one of: ${PATH_ROOTS.join(", ")}.`);
  }

  const segments = [];
  let position = root.length;

  while (position < text.length) {
    if (text[position] === ".") {
      const name = matchAt(IDENTIFIER, text, position + 1);
      if (name === null) {
        throw new Error(`Path ${describe(text)} needs a field name after "." at offset ${position}.`);
      }
      segments.push({ type: "field", name: name[0] });
      position += 1 + name[0].length;
      continue;
    }

    if (text.startsWith("[*]", position)) {
      segments.push({ type: "all" });
      position += 3;
      continue;
    }

    if (text[position] === "[") {
      const index = matchAt(INDEX, text, position);
      if (index === null) {
        throw new Error(`Path ${describe(text)} needs "[*]" or an index without leading zeros at offset ${position}.`);
      }
      segments.push({ type: "index", index: Number(index[1]) });
      position += index[0].length;
      continue;
    }

    throw new Error(`Path ${describe(text)} has an unexpected character at offset ${position}.`);
  }

  return { root, segments };
}

function trimPadding(text) {
  let start = 0;
  let end = text.length;

  while (start < end && PADDING.has(text[start])) start += 1;
  while (end > start && PADDING.has(text[end - 1])) end -= 1;

  return text.slice(start, end);
}

/** Splits a template into placeholder tokens; every problem becomes an English error. */
function scanPlaceholders(text) {
  const tokens = [];
  const errors = [];

  if (typeof text !== "string") {
    return { tokens, errors: ["A template must be a string."] };
  }

  let cursor = 0;
  let open = text.indexOf("{{");

  while (cursor < text.length) {
    // The next "{{" is only searched again once the cursor has passed it, so long texts stay linear.
    if (open !== -1 && open < cursor) open = text.indexOf("{{", cursor);
    const close = text.indexOf("}}", cursor);

    if (close !== -1 && (open === -1 || close < open)) {
      errors.push(`Unexpected "}}" at offset ${close} without a matching "{{".`);
      cursor = close + 2;
      continue;
    }
    if (open === -1) break;

    const end = text.indexOf("}}", open + 2);
    if (end === -1) {
      errors.push(`Unclosed "{{" at offset ${open}.`);
      break;
    }

    const path = trimPadding(text.slice(open + 2, end));

    if (path === "") {
      errors.push(`Empty placeholder at offset ${open}.`);
    } else {
      try {
        tokens.push({ start: open, end: end + 2, path, parsed: parsePath(path) });
      } catch (error) {
        errors.push(`Invalid placeholder at offset ${open}: ${error.message}`);
      }
    }

    cursor = end + 2;
  }

  return { tokens, errors };
}

/**
 * Lists the paths of every `{{ path }}` placeholder in a template string, in order of appearance.
 * Any other use of "{{" or "}}" is an error; a template is valid when `errors` is empty.
 */
export function findPlaceholders(text) {
  const { tokens, errors } = scanPlaceholders(text);

  return { paths: tokens.map((token) => token.path), errors };
}

function isRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasOwnValue(container, key) {
  return Object.hasOwn(container, key) && container[key] !== undefined;
}

function walk(value, segments, position) {
  if (position === segments.length) return { found: true, value };

  const segment = segments[position];

  if (segment.type === "field") {
    if (!isRecord(value) || BLOCKED_NAMES.has(segment.name) || !hasOwnValue(value, segment.name)) {
      return { found: false, value: undefined };
    }
    return walk(value[segment.name], segments, position + 1);
  }

  if (!Array.isArray(value)) return { found: false, value: undefined };

  if (segment.type === "index") {
    if (segment.index >= value.length || !hasOwnValue(value, segment.index)) {
      return { found: false, value: undefined };
    }
    return walk(value[segment.index], segments, position + 1);
  }

  // "[*]": map the rest of the path over every element, drop the elements where it is missing, and
  // concatenate one level when the rest of the path flattens again.
  const flattensAgain = segments.slice(position + 1).some((next) => next.type === "all");
  const values = [];

  for (let index = 0; index < value.length; index += 1) {
    if (!hasOwnValue(value, index)) continue;

    const result = walk(value[index], segments, position + 1);
    if (!result.found) continue;

    if (flattensAgain) {
      for (const element of result.value) values.push(element);
    } else {
      values.push(result.value);
    }
  }

  return { found: true, value: values };
}

/**
 * Resolves a path (text or the result of parsePath) against `context = { inputs, constants, steps,
 * item, loop }` using own properties only. Returns `{ found, value, flattened }`; `flattened` says
 * the path contains "[*]", in which case a found value is always an array.
 */
export function resolvePath(context, path) {
  const parsed = typeof path === "string" ? parsePath(path) : path;
  const flattened = parsed.segments.some((segment) => segment.type === "all");

  if (!isRecord(context) || !hasOwnValue(context, parsed.root)) {
    return { found: false, value: undefined, flattened };
  }

  const result = walk(context[parsed.root], parsed.segments, 0);

  return { found: result.found, value: result.found ? result.value : undefined, flattened };
}

function renderResolved({ found, value, flattened }) {
  if (!found || value === null || value === undefined) return "";
  if (typeof value === "string") return value;
  if (typeof value === "number" || typeof value === "boolean") return JSON.stringify(value);

  if (flattened && Array.isArray(value)) {
    if (value.length === 0) return "";
    if (value.every((element) => typeof element === "string" || typeof element === "number")) {
      return value.map((element) => `- ${typeof element === "string" ? element : JSON.stringify(element)}`).join("\n");
    }
  }

  return JSON.stringify(value, null, 2);
}

/**
 * Replaces every placeholder with its resolved value (contract §1.3 rendering rules). Throws an Error
 * when the template has an invalid placeholder. Size limits are the caller's concern.
 */
export function renderTemplate(text, context) {
  const { tokens, errors } = scanPlaceholders(text);

  if (errors.length > 0) {
    throw new Error(errors[0]);
  }

  let rendered = "";
  let cursor = 0;

  for (const token of tokens) {
    rendered += text.slice(cursor, token.start) + renderResolved(resolvePath(context, token.parsed));
    cursor = token.end;
  }

  return rendered + text.slice(cursor);
}
