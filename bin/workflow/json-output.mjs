// JSON answers of `llm_json` steps (contract §1.4 and §7): extraction from the model text, parsing with
// a jsonrepair fallback, validation against the supported JSON Schema subset, and the prompt texts.
// Validation messages are English because they are sent back to the model.
import { jsonrepair } from "jsonrepair";

const MAX_SCHEMA_ERRORS = 50;
const MAX_PROBLEM_CHARS = 2000;
const MAX_PREVIOUS_ANSWER_CHARS = 3000;

/** Cuts a text to at most `limit` UTF-16 units without splitting a surrogate pair. */
export function clipText(text, limit) {
  if (text.length <= limit) return text;

  const code = text.charCodeAt(limit - 1);
  return text.slice(0, code >= 0xd800 && code <= 0xdbff ? limit - 1 : limit);
}

/**
 * Markdown fenced code blocks in order of appearance: `{ language, body, closed }`. `language` is the
 * lower-cased first word of the info string; an unclosed last block runs to the end of the text.
 */
export function fencedBlocks(text) {
  const blocks = [];
  if (typeof text !== "string") return blocks;

  let cursor = 0;

  while (cursor < text.length) {
    const open = text.indexOf("```", cursor);
    if (open === -1) break;

    const newline = text.indexOf("\n", open + 3);
    const nextFence = text.indexOf("```", open + 3);

    // A block on a single line: ```json {"a":1}```
    if (newline === -1 || (nextFence !== -1 && nextFence < newline)) {
      const inner = text.slice(open + 3, nextFence === -1 ? text.length : nextFence);
      const [, language = "", body] = /^([A-Za-z][\w+.#-]*(?=\s))?([\s\S]*)$/.exec(inner);
      blocks.push({ language: language.toLowerCase(), body, closed: nextFence !== -1 });
      if (nextFence === -1) break;
      cursor = nextFence + 3;
      continue;
    }

    const language = (text.slice(open + 3, newline).trim().split(/\s+/)[0] ?? "").toLowerCase();
    const close = text.indexOf("```", newline + 1);
    blocks.push({
      language,
      body: text.slice(newline + 1, close === -1 ? text.length : close),
      closed: close !== -1,
    });
    if (close === -1) break;
    cursor = close + 3;
  }

  return blocks;
}

/** The JSON part of a model answer: the first ```json block, else the first ``` block, else the trimmed text. */
export function extractJsonText(text) {
  if (typeof text !== "string") return "";

  const blocks = fencedBlocks(text);
  const block = blocks.find((candidate) => candidate.language === "json") ?? blocks[0];

  return (block === undefined ? text : block.body).trim();
}

function parseStrict(source) {
  try {
    return { ok: true, value: JSON.parse(source) };
  } catch (error) {
    return { ok: false, value: null, error: error.message };
  }
}

function parseRepaired(source) {
  try {
    return { ok: true, value: JSON.parse(jsonrepair(source)) };
  } catch {
    return { ok: false, value: null };
  }
}

/** The text from the first "{" or "[" to the last "}" or "]", when that is not the whole text. */
function embeddedJson(source) {
  const start = source.search(/[{[]/);
  const end = Math.max(source.lastIndexOf("}"), source.lastIndexOf("]"));

  if (start === -1 || end <= start || end - start + 1 === source.length) return null;

  return source.slice(start, end + 1);
}

/**
 * Parses a model answer: extractJsonText, then JSON.parse, then jsonrepair. JSON wrapped in prose
 * without a fence ("Here it is: {…} Hope this helps") is taken from the first "{" or "[" to the last
 * "}" or "]": strictly before jsonrepair (which would turn the prose lines into an array), repaired after.
 * Returns `{ ok, value, repaired, error }`; `repaired` is true for anything but a plain JSON.parse of the
 * extracted text, and `error` is an English message when `ok` is false.
 */
export function parseModelJson(text) {
  const source = extractJsonText(text);

  if (source === "") {
    return { ok: false, value: null, repaired: false, error: "The answer contains no JSON." };
  }

  const strict = parseStrict(source);
  if (strict.ok) return { ok: true, value: strict.value, repaired: false, error: null };

  const embedded = embeddedJson(source);
  const attempts = [
    () => (embedded === null ? { ok: false } : parseStrict(embedded)),
    () => parseRepaired(source),
    () => (embedded === null ? { ok: false } : parseRepaired(embedded)),
  ];

  for (const attempt of attempts) {
    const result = attempt();
    if (result.ok) return { ok: true, value: result.value, repaired: true, error: null };
  }

  return { ok: false, value: null, repaired: false, error: `The answer is not valid JSON: ${strict.error}` };
}

function isRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function matchesType(value, type) {
  switch (type) {
    case "object":
      return isRecord(value);
    case "array":
      return Array.isArray(value);
    case "string":
      return typeof value === "string";
    case "integer":
      return Number.isInteger(value);
    case "number":
      return typeof value === "number" && Number.isFinite(value);
    case "boolean":
      return typeof value === "boolean";
    case "null":
      return value === null;
    default:
      return true;
  }
}

function fieldPath(path, name) {
  return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(name) ? `${path}.${name}` : `${path}[${JSON.stringify(name)}]`;
}

function isCount(value) {
  return Number.isInteger(value) && value >= 0;
}

function validateNode(value, schema, path, errors) {
  if (errors.length >= MAX_SCHEMA_ERRORS || !isRecord(schema)) return;

  const add = (message) => {
    if (errors.length < MAX_SCHEMA_ERRORS) errors.push(`${path}: ${message}`);
  };

  if (typeof schema.type === "string" && !matchesType(value, schema.type)) {
    add(`must be ${schema.type}`);
    return;
  }

  if (Array.isArray(schema.enum) && !schema.enum.some((candidate) => candidate === value)) {
    add(`must be one of ${JSON.stringify(schema.enum)}`);
  }

  if (typeof value === "string") {
    const length = [...value].length;
    if (isCount(schema.minLength) && length < schema.minLength) add(`must have at least ${schema.minLength} characters`);
    if (isCount(schema.maxLength) && length > schema.maxLength) add(`must have at most ${schema.maxLength} characters`);
  }

  if (typeof value === "number") {
    if (typeof schema.minimum === "number" && value < schema.minimum) add(`must be >= ${schema.minimum}`);
    if (typeof schema.maximum === "number" && value > schema.maximum) add(`must be <= ${schema.maximum}`);
  }

  if (Array.isArray(value)) {
    if (isCount(schema.minItems) && value.length < schema.minItems) add(`must have at least ${schema.minItems} items`);
    if (isCount(schema.maxItems) && value.length > schema.maxItems) add(`must have at most ${schema.maxItems} items`);
    if (isRecord(schema.items)) {
      for (let index = 0; index < value.length && errors.length < MAX_SCHEMA_ERRORS; index += 1) {
        validateNode(value[index], schema.items, `${path}[${index}]`, errors);
      }
    }
  }

  if (isRecord(value)) {
    const properties = isRecord(schema.properties) ? schema.properties : {};

    if (Array.isArray(schema.required)) {
      for (const name of schema.required) {
        if (typeof name === "string" && !Object.hasOwn(value, name) && errors.length < MAX_SCHEMA_ERRORS) {
          errors.push(`${fieldPath(path, name)}: required`);
        }
      }
    }

    for (const [name, propertySchema] of Object.entries(properties)) {
      if (Object.hasOwn(value, name)) validateNode(value[name], propertySchema, fieldPath(path, name), errors);
    }

    if (schema.additionalProperties === false) {
      for (const name of Object.keys(value)) {
        if (!Object.hasOwn(properties, name) && errors.length < MAX_SCHEMA_ERRORS) {
          errors.push(`${fieldPath(path, name)}: is not allowed`);
        }
      }
    }
  }
}

/**
 * Validates a value against the supported JSON Schema subset: type, properties, required, items, enum,
 * minItems, maxItems, minLength, maxLength, minimum, maximum, additionalProperties (boolean); description
 * is ignored. Returns English messages such as `$.ideas[2].title: required` (at most 50); [] = valid.
 */
export function validateJsonSchema(value, schema) {
  const errors = [];
  validateNode(value, schema, "$", errors);

  return errors;
}

/** The OUTPUT FORMAT block appended (after a blank line) to every `llm_json` prompt. */
export function jsonOutputRules(schema) {
  return [
    "OUTPUT FORMAT (mandatory):",
    "- Reply with ONE JSON value inside a single ```json code block and nothing else.",
    "- The JSON must match this JSON Schema:",
    JSON.stringify(schema, null, 2),
    "- Inside JSON string values never use double quotes (\"). Use single quotes (') instead.",
  ].join("\n");
}

/**
 * The text appended after the OUTPUT FORMAT block when the previous answer was rejected, starting with
 * its own blank line. `problem` is a message or a list of messages.
 */
export function jsonRetrySuffix(problem, previousText) {
  const message = Array.isArray(problem) ? problem.join("\n") : String(problem ?? "");
  const previous = typeof previousText === "string" ? previousText : "";

  return "\n\nYOUR PREVIOUS ANSWER WAS REJECTED.\n"
    + `Problem: ${clipText(message, MAX_PROBLEM_CHARS)}\n`
    + "Your previous answer (truncated):\n"
    + `${clipText(previous, MAX_PREVIOUS_ANSWER_CHARS)}\n`
    + "Return the corrected JSON now.";
}
