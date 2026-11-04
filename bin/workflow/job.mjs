// Strict reading of a workflow run directory (contract §3): job.json, the template snapshot and the
// restricted per-model OpenCode configs. Every file named by the job must stay inside the run directory
// and pass the private-file guards. Error messages are English and never leave the worker.
import { constants } from "node:fs";
import { access, lstat, realpath } from "node:fs/promises";
import { basename, dirname, isAbsolute, join, resolve } from "node:path";
import { assertRestrictedConfig } from "../opencode-text.mjs";
import { checkPath, readBounded, withinRoot } from "../private-files.mjs";
import { findPlaceholders, parsePath } from "./template-path.mjs";

export const JOB_SCHEMA = "benchforge-workflow-job/1";
export const TEMPLATE_SCHEMA = "benchforge-workflow/1";
export const WORKFLOW_AGENT = "benchforge-workflow";
export const RUN_ID_PATTERN = /^[a-z0-9][a-z0-9-]{2,80}$/;
export const MODEL_SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/;

// PHP validates the decoded template at 512 KB and every input against its own limits; the raw files
// (pretty-printed, possibly with \u escapes) can be larger, so these caps only stop absurd files.
const MAX_JOB_BYTES = 4 * 1024 * 1024;
const MAX_TEMPLATE_BYTES = 2 * 1024 * 1024;
const MAX_CONFIG_BYTES = 128 * 1024;
const MAX_INPUT_CHARS = 20000;
const MODEL_PATTERN = /^(?:openrouter\/[A-Za-z0-9._:-]+\/[A-Za-z0-9._:/-]+|opencode-go\/[A-Za-z0-9._:/-]+)$/;
const PROVIDERS = new Set(["openrouter", "opencode-go"]);
const STEP_ID_PATTERN = /^[a-z][a-zA-Z0-9_]{0,39}$/;
const INPUT_ID_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]{0,39}$/;
const STEP_TYPES = new Set(["llm_text", "llm_json", "foreach", "code_text", "code_agent", "browser_check"]);
const PROMPT_TYPES = new Set(["llm_text", "llm_json", "code_text", "code_agent"]);
const CODE_TYPES = new Set(["code_text", "code_agent"]);
const CODE_MODES = new Set(["code_text", "code_agent"]);
const RESERVED_CHILD_IDS = new Set(["item", "index", "key", "label"]);
const MAX_PROMPT_CHARS = 100000;
const MAX_SCHEMA_NORMALIZE_DEPTH = 64;
const PHP_TRIM_EDGES = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

function invalid(message) {
  return new Error(`Invalid workflow job: ${message}`);
}

function isRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

/** PHP's trim(): strips only " \t\n\r\0\x0B" at both ends, so a lone U+00A0 or U+3000 is not blank. */
export function phpTrim(text) {
  return text.replace(PHP_TRIM_EDGES, "");
}

function isBlank(text) {
  return phpTrim(text) === "";
}

/** Whether a text has at most `maximum` Unicode code points (PHP's mb_strlen), not UTF-16 units. */
export function fitsCodePoints(text, maximum) {
  if (text.length <= maximum) return true;
  if (text.length > maximum * 2) return false;

  let count = 0;
  for (const _codePoint of text) {
    count += 1;
    if (count > maximum) return false;
  }

  return true;
}

function isIntegerIn(value, minimum, maximum) {
  return Number.isSafeInteger(value) && value >= minimum && value <= maximum;
}

function optionalIntegerIn(container, key, minimum, maximum, where) {
  if (container[key] === undefined) return;
  if (!isIntegerIn(container[key], minimum, maximum)) {
    throw invalid(`${where}.${key} must be an integer from ${minimum} to ${maximum}`);
  }
}

/**
 * The run directory is the parent of `job.json`: a real directory (no symlink, canonical path) that
 * holds a regular job file. Returns the directory; throws when it cannot be trusted.
 */
export async function resolveRunDirectory(jobPath) {
  if (typeof jobPath !== "string" || !isAbsolute(jobPath) || basename(jobPath) !== "job.json") {
    throw invalid("the argument must be the absolute path of job.json");
  }

  const root = dirname(jobPath);
  const rootInfo = await lstat(root);
  if (!rootInfo.isDirectory() || rootInfo.isSymbolicLink() || await realpath(root) !== root) {
    throw invalid("the run directory must be a canonical directory");
  }
  await checkPath(root, jobPath, "file");

  return root;
}

/** A file named relative to the run directory; it must resolve inside it without symlinks. */
async function privateFile(root, relativePath, what) {
  if (typeof relativePath !== "string" || relativePath === "" || relativePath.includes("\0") || isAbsolute(relativePath)) {
    throw invalid(`${what} must be a path relative to the run directory`);
  }

  const path = resolve(root, relativePath);
  withinRoot(root, path);
  await checkPath(root, path, "file");

  return path;
}

function validateSettings(settings, modelCount) {
  if (!isRecord(settings)) throw invalid("settings must be an object");

  if (!isIntegerIn(settings.maxParallelModels, 1, 5)) throw invalid("settings.maxParallelModels must be 1-5");
  if (!isIntegerIn(settings.maxConcurrentCalls, 1, 10)) throw invalid("settings.maxConcurrentCalls must be 1-10");

  const stepModes = settings.stepModes ?? {};
  if (!isRecord(stepModes)) throw invalid("settings.stepModes must be an object");

  const timeouts = settings.timeouts ?? {};
  if (!isRecord(timeouts)) throw invalid("settings.timeouts must be an object");
  for (const key of Object.keys(timeouts)) {
    if (key !== "llm" && key !== "code") throw invalid(`settings.timeouts.${key} is not supported`);
    if (timeouts[key] !== null && !isIntegerIn(timeouts[key], 30, 7200)) {
      throw invalid(`settings.timeouts.${key} must be null or 30-7200 seconds`);
    }
  }

  // Test hooks: scale every backoff wait (default 1) and force a short call timeout. Never set by the app.
  const testing = settings.testing ?? {};
  if (!isRecord(testing)) throw invalid("settings.testing must be an object");
  for (const key of Object.keys(testing)) {
    if (key !== "backoffScale" && key !== "timeoutSeconds") throw invalid(`settings.testing.${key} is not supported`);
  }
  const backoffScale = testing.backoffScale ?? 1;
  if (typeof backoffScale !== "number" || !Number.isFinite(backoffScale) || backoffScale < 0 || backoffScale > 1) {
    throw invalid("settings.testing.backoffScale must be a number from 0 to 1");
  }
  const timeoutOverride = testing.timeoutSeconds ?? null;
  if (timeoutOverride !== null && !isIntegerIn(timeoutOverride, 1, 7200)) {
    throw invalid("settings.testing.timeoutSeconds must be 1-7200");
  }

  return {
    maxParallelModels: Math.min(settings.maxParallelModels, modelCount),
    maxConcurrentCalls: settings.maxConcurrentCalls,
    stepModes: { ...stepModes },
    timeouts: { llm: timeouts.llm ?? null, code: timeouts.code ?? null },
    backoffScale,
    timeoutOverride,
  };
}

function validateCommonStep(step, where, earlierIds) {
  if (typeof step.title !== "string" || isBlank(step.title)) throw invalid(`${where}.title is required`);

  if (step.dependsOn !== undefined) {
    if (!Array.isArray(step.dependsOn)) throw invalid(`${where}.dependsOn must be a list`);
    for (const dependency of step.dependsOn) {
      if (typeof dependency !== "string" || !earlierIds.has(dependency)) {
        throw invalid(`${where}.dependsOn must name earlier steps at the same level`);
      }
    }
  }

  optionalIntegerIn(step, "timeoutSeconds", 30, 7200, where);
  optionalIntegerIn(step, "maxOutputChars", 1000, 1000000, where);
  optionalIntegerIn(step, "maxOutputTokens", 1000, 200000, where);

  if (step.retry !== undefined) {
    if (!isRecord(step.retry)) throw invalid(`${where}.retry must be an object`);
    optionalIntegerIn(step.retry, "attempts", 1, 5, `${where}.retry`);
    optionalIntegerIn(step.retry, "backoffSeconds", 0, 600, `${where}.retry`);
  }
}

function validateTemplateText(text, where, maxChars) {
  if (typeof text !== "string" || !fitsCodePoints(text, maxChars)) throw invalid(`${where} must be a string of at most ${maxChars} characters`);
  if (findPlaceholders(text).errors.length > 0) throw invalid(`${where} has an invalid placeholder`);
}

function validatePromptStep(step, where) {
  validateTemplateText(step.prompt, `${where}.prompt`, MAX_PROMPT_CHARS);
  if (isBlank(step.prompt)) throw invalid(`${where}.prompt is empty`);

  if (step.type === "llm_json" && !isRecord(step.schema)) throw invalid(`${where}.schema must be an object`);

  if (CODE_TYPES.has(step.type)) {
    optionalIntegerIn(step, "repairAttempts", 0, 3, where);
    if (step.checks !== undefined && !isRecord(step.checks)) throw invalid(`${where}.checks must be an object`);
    if (step.modes !== undefined && (!Array.isArray(step.modes) || step.modes.length === 0
      || step.modes.some((mode) => !CODE_MODES.has(mode)))) {
      throw invalid(`${where}.modes must list code_text and/or code_agent`);
    }
  }
}

function validateStep(step, where, ids, earlierIds, child) {
  if (!isRecord(step)) throw invalid(`${where} must be an object`);
  if (typeof step.id !== "string" || !STEP_ID_PATTERN.test(step.id) || ids.has(step.id)
    || (child && RESERVED_CHILD_IDS.has(step.id))) {
    throw invalid(`${where}.id must be a unique step id`);
  }
  if (!STEP_TYPES.has(step.type) || (child && step.type === "foreach")) throw invalid(`${where}.type is not supported`);

  ids.add(step.id);
  validateCommonStep(step, where, earlierIds);

  if (PROMPT_TYPES.has(step.type)) {
    validatePromptStep(step, where);
  } else if (step.type === "browser_check") {
    if (typeof step.target !== "string" || !earlierIds.has(step.target)) throw invalid(`${where}.target must name an earlier step`);
  } else {
    let over;
    try {
      over = parsePath(step.over);
    } catch {
      throw invalid(`${where}.over must be a path expression`);
    }
    if (!["inputs", "constants", "steps"].includes(over.root)) throw invalid(`${where}.over has an unsupported root`);
    if (step.itemLabel !== undefined) validateTemplateText(step.itemLabel, `${where}.itemLabel`, 500);
    optionalIntegerIn(step, "concurrency", 1, 5, where);
    if (!Array.isArray(step.steps) || step.steps.length < 1 || step.steps.length > 10) {
      throw invalid(`${where}.steps must hold 1-10 steps`);
    }

    const siblings = new Set();
    step.steps.forEach((childStep, index) => {
      validateStep(childStep, `${where}.steps[${index}]`, ids, siblings, true);
      siblings.add(childStep.id);
    });
  }
}

/** PHP's json_encode writes an empty object as `[]`; at an object position that means `{}`. */
function emptyListAsObject(value) {
  return Array.isArray(value) && value.length === 0 ? {} : value;
}

/** Replaces `container[key]` with `{}` when it is an empty list, as an own property (safe for "__proto__"). */
function normalizeObjectAt(container, key) {
  if (!Object.hasOwn(container, key)) return;
  const value = emptyListAsObject(container[key]);
  if (value !== container[key]) {
    Object.defineProperty(container, key, { value, writable: true, enumerable: true, configurable: true });
  }
}

/** An llm_json schema node, its `properties` map, every property schema and its `items` schema. */
function normalizeSchemaNode(container, key, depth) {
  normalizeObjectAt(container, key);
  const node = container[key];
  if (!isRecord(node) || depth > MAX_SCHEMA_NORMALIZE_DEPTH) return;

  normalizeObjectAt(node, "properties");
  if (isRecord(node.properties)) {
    for (const name of Object.keys(node.properties)) normalizeSchemaNode(node.properties, name, depth + 1);
  }
  normalizeSchemaNode(node, "items", depth + 1);
}

function normalizeStepObjects(step) {
  if (!isRecord(step)) return;

  normalizeObjectAt(step, "retry");
  normalizeObjectAt(step, "checks");
  if (step.type === "llm_json") normalizeSchemaNode(step, "schema", 0);
  if (Array.isArray(step.steps)) step.steps.forEach(normalizeStepObjects);
}

/**
 * A template that went through PHP (json_decode as arrays, json_encode back) can hold `[]` wherever an
 * empty object was meant. Turns those empty lists back into `{}` in place: `constants`, `gallery`, every
 * step's `retry` and `checks`, and each llm_json `schema` node with its `properties` and `items`. A
 * non-empty list at such a position is left alone, so validation still rejects it.
 */
export function normalizeTemplateObjects(template) {
  if (!isRecord(template)) return template;

  normalizeObjectAt(template, "constants");
  normalizeObjectAt(template, "gallery");
  if (Array.isArray(template.steps)) template.steps.forEach(normalizeStepObjects);

  return template;
}

/**
 * Structural check of the template snapshot: enough for the worker to run it safely. The full
 * validation (references, schema subset, input constraints) happened in PHP before the run started.
 * Empty lists at object positions are first normalized to `{}` (normalizeTemplateObjects, in place).
 */
export function validateTemplate(template) {
  normalizeTemplateObjects(template);
  if (!isRecord(template) || template.schema !== TEMPLATE_SCHEMA || typeof template.id !== "string") {
    throw invalid("the template has an unsupported schema");
  }
  if (template.constants !== undefined && !isRecord(template.constants)) throw invalid("template.constants must be an object");

  if (template.inputs !== undefined) {
    if (!Array.isArray(template.inputs) || template.inputs.length > 20) throw invalid("template.inputs must be a list");
    const inputIds = new Set();
    for (const input of template.inputs) {
      if (!isRecord(input) || typeof input.id !== "string" || !INPUT_ID_PATTERN.test(input.id) || inputIds.has(input.id)) {
        throw invalid("template inputs need unique ids");
      }
      inputIds.add(input.id);
    }
  }

  if (!Array.isArray(template.steps) || template.steps.length < 1 || template.steps.length > 30) {
    throw invalid("template.steps must hold 1-30 steps");
  }

  const ids = new Set();
  const earlier = new Set();
  template.steps.forEach((step, index) => {
    validateStep(step, `steps[${index}]`, ids, earlier, false);
    earlier.add(step.id);
  });

  if (template.gallery !== undefined) {
    const { gallery } = template;
    const source = template.steps.find((step) => step.id === gallery?.from && step.type === "foreach");
    if (!isRecord(gallery) || source === undefined || typeof gallery.title !== "string"
      || !source.steps.some((step) => step.id === gallery.file && CODE_TYPES.has(step.type))) {
      throw invalid("template.gallery must name a foreach and one of its code steps");
    }
    validateTemplateText(gallery.title, "gallery.title", 500);
  }

  return template;
}

function codeSteps(template) {
  const steps = new Map();
  for (const step of template.steps) {
    for (const candidate of step.type === "foreach" ? step.steps : [step]) {
      if (CODE_TYPES.has(candidate.type)) steps.set(candidate.id, candidate);
    }
  }

  return steps;
}

function validateStepModes(stepModes, template) {
  const steps = codeSteps(template);

  for (const [id, mode] of Object.entries(stepModes)) {
    const step = steps.get(id);
    const allowed = Array.isArray(step?.modes) ? step.modes : [step?.type];
    if (step === undefined || !CODE_MODES.has(mode) || !allowed.includes(mode)) {
      throw invalid(`settings.stepModes.${id} is not a mode of that code step`);
    }
  }
}

function validateInputs(inputs, template) {
  if (!isRecord(inputs)) throw invalid("inputs must be an object");

  const declared = new Map((template.inputs ?? []).map((input) => [input.id, input]));
  const values = {};

  for (const [key, value] of Object.entries(inputs)) {
    if (!declared.has(key)) throw invalid(`inputs.${key} is not declared by the template`);
    // An optional input left empty arrives as null and renders as empty text.
    const valid = value === null
      || typeof value === "boolean"
      || (typeof value === "number" && Number.isFinite(value))
      || (typeof value === "string" && fitsCodePoints(value, MAX_INPUT_CHARS));
    if (!valid) throw invalid(`inputs.${key} must be a string, number, boolean or null`);
    values[key] = value;
  }

  for (const [id, input] of declared) {
    if (!Object.hasOwn(values, id)) values[id] = input.default ?? "";
  }

  return values;
}

async function validateModels(root, models, occupied) {
  if (!Array.isArray(models) || models.length < 1 || models.length > 5) throw invalid("models must hold 1-5 models");

  const slugs = new Set();
  const names = new Set();
  const prepared = [];

  for (const model of models) {
    if (!isRecord(model)
      || typeof model.slug !== "string" || !MODEL_SLUG_PATTERN.test(model.slug) || slugs.has(model.slug)
      || typeof model.model !== "string" || !MODEL_PATTERN.test(model.model) || names.has(model.model)
      || typeof model.name !== "string" || isBlank(model.name) || !fitsCodePoints(model.name, 256)
      || !PROVIDERS.has(model.provider) || !model.model.startsWith(`${model.provider}/`)) {
      throw invalid("every model needs a unique slug, a supported model id, a name and its provider");
    }

    slugs.add(model.slug);
    names.add(model.model);
    const configPath = await privateFile(root, model.configFile, "configFile");
    if (occupied.has(configPath)) throw invalid("configFile must be a separate file per model");
    occupied.add(configPath);

    const configContent = await readBounded(configPath, MAX_CONFIG_BYTES);
    assertRestrictedConfig(JSON.parse(configContent), WORKFLOW_AGENT);

    const directory = join(root, "models", model.slug);
    await checkPath(root, directory, "directory", true);

    prepared.push({
      slug: model.slug,
      model: model.model,
      name: model.name,
      provider: model.provider,
      configPath,
      configContent,
      directory,
      workspace: join(directory, "workspace"),
      privateHome: join(directory, ".benchforge-home"),
    });
  }

  return prepared;
}

/** Reads and validates job.json and everything it names. Throws on the first problem. */
export async function loadWorkflowJob(root, jobPath) {
  const job = JSON.parse(await readBounded(jobPath, MAX_JOB_BYTES));

  if (!isRecord(job) || job.schema !== JOB_SCHEMA) throw invalid("unsupported schema");
  if (typeof job.runId !== "string" || !RUN_ID_PATTERN.test(job.runId)) throw invalid("runId is invalid");
  if (job.runDirectory !== root) throw invalid("runDirectory must be the directory of job.json");
  if (typeof job.createdAt !== "string" || !Number.isFinite(Date.parse(job.createdAt))) throw invalid("createdAt is invalid");

  if (!isRecord(job.opencode) || typeof job.opencode.binary !== "string" || !isAbsolute(job.opencode.binary)) {
    throw invalid("opencode.binary must be an absolute path");
  }
  await access(job.opencode.binary, constants.X_OK);

  const templatePath = await privateFile(root, job.templateFile, "templateFile");
  if (templatePath === jobPath) throw invalid("templateFile must not be job.json");
  const template = validateTemplate(JSON.parse(await readBounded(templatePath, MAX_TEMPLATE_BYTES)));

  const inputs = validateInputs(job.inputs, template);
  const models = await validateModels(root, job.models, new Set([jobPath, templatePath]));
  const settings = validateSettings(job.settings, models.length);
  validateStepModes(settings.stepModes, template);

  return {
    root,
    jobPath,
    runId: job.runId,
    createdAt: job.createdAt,
    opencodeBinary: job.opencode.binary,
    template,
    inputs,
    models,
    settings,
  };
}

/** The runId of a job file that failed validation, when it can still be read; otherwise null. */
export async function readRunIdQuietly(jobPath) {
  try {
    const job = JSON.parse(await readBounded(jobPath, MAX_JOB_BYTES));
    return typeof job?.runId === "string" && RUN_ID_PATTERN.test(job.runId) ? job.runId : null;
  } catch {
    return null;
  }
}
