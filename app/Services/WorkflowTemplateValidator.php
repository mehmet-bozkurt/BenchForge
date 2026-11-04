<?php

namespace App\Services;

/**
 * Checks `benchforge-workflow/1` templates before they are saved or started, and the values of a run form.
 *
 * Template errors are Turkish and start with the JSON path of the offending value ("steps[2].steps[0].prompt: …").
 * Placeholders follow the same path grammar as `bin/workflow/template-path.mjs`; both sides are tested
 * against `tests/Fixtures/workflows/path-vectors.json`. Placeholders are plain substitutions, never code.
 */
class WorkflowTemplateValidator
{
    public const SCHEMA = 'benchforge-workflow/1';

    public const MAX_TEMPLATE_BYTES = 524_288;

    private const MAX_PROMPT_CHARACTERS = 100_000;

    private const MAX_LABEL_TEMPLATE_CHARACTERS = 500;

    private const TEMPLATE_KEYS = ['schema', 'id', 'name', 'description', 'version', 'constants', 'inputs', 'steps', 'gallery'];

    private const INPUT_KEYS = ['id', 'type', 'label', 'description', 'default', 'required', 'min', 'max', 'minLength', 'maxLength', 'options'];

    private const INPUT_TYPES = ['integer', 'number', 'string', 'text', 'boolean', 'select'];

    private const INPUT_ID_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/';

    private const DEFAULT_MAX_LENGTH = ['string' => 200, 'text' => 8000];

    private const MAX_LENGTH_CAP = 20_000;

    private const STEP_ID_PATTERN = '/^[a-z][a-zA-Z0-9_]{0,39}$/';

    private const COMMON_STEP_KEYS = ['id', 'type', 'title', 'dependsOn', 'timeoutSeconds', 'retry', 'maxOutputChars', 'maxOutputTokens'];

    /** Type-specific step fields; the keys are also the list of known step types. */
    private const STEP_KEYS = [
        'llm_text' => ['prompt'],
        'llm_json' => ['prompt', 'schema'],
        'foreach' => ['over', 'itemLabel', 'concurrency', 'steps'],
        'code_text' => ['prompt', 'modes', 'repairAttempts', 'checks'],
        'code_agent' => ['prompt', 'modes', 'repairAttempts', 'checks'],
        'browser_check' => ['target', 'interactions', 'screenshot'],
    ];

    private const CODE_STEP_TYPES = ['code_text', 'code_agent'];

    private const EVENT_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,39}$/';

    /** Fields of a foreach item record (`steps.<foreach>.items[n]`), so child steps cannot use them as ids. */
    private const ITEM_RECORD_FIELDS = ['item', 'index', 'key', 'label'];

    /** The worker never resolves these names (prototype safety), so they cannot be ids either. */
    private const HIDDEN_NAMES = ['__proto__', 'constructor', 'prototype'];

    /** Whitespace allowed around a placeholder path: ASCII only, exactly like the worker. */
    private const PLACEHOLDER_PADDING = " \t\n\r\f\v";

    private const SCHEMA_KEYWORDS = ['type', 'properties', 'required', 'items', 'enum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'description', 'additionalProperties'];

    private const SCHEMA_TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    private const SCHEMA_MAX_DEPTH = 12;

    private const PATH_PATTERN = '/^(inputs|constants|steps|item|loop)((?:\.[A-Za-z_][A-Za-z0-9_-]*|\[(?:0|[1-9][0-9]*)\]|\[\*\])*)\z/';

    private const SEGMENT_PATTERN = '/\.([A-Za-z_][A-Za-z0-9_-]*)|\[([0-9]+)\]|\[\*\]/';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<array{path: string, at: string}> */
    private array $references = [];

    /** @var array<array-key, mixed> */
    private array $constants = [];

    /** @var array<string, true> */
    private array $inputIds = [];

    /** @var array<string, string> JSON path of the step that owns each usable id */
    private array $stepPaths = [];

    /** @var array<string, array{position: int, step: array<string, mixed>}> */
    private array $topLevelSteps = [];

    /** @var array<string, array{parent: ?string, step: array<string, mixed>}> */
    private array $childSteps = [];

    /** @var array<string, array<string, mixed>> what `item` holds inside each foreach */
    private array $itemShapes = [];

    /**
     * @return list<string> Turkish errors, each prefixed with a JSON path; an empty list means the template is valid
     */
    public function validate(mixed $template): array
    {
        $this->inspect($template);

        return $this->errors;
    }

    /**
     * Every placeholder and `foreach.over` path of the template, with the JSON path of the text that holds it.
     *
     * @param  array<string, mixed>  $template
     * @return list<array{path: string, at: string}>
     */
    public function references(array $template): array
    {
        $this->inspect($template);

        return $this->references;
    }

    /**
     * Checks and converts run form values (form strings or JSON values) against the template's inputs.
     * Integers accept `^-?\d+$`, numbers any numeric string, booleans checkbox values (missing = false).
     * An empty or missing value is an error for a required input; otherwise it becomes "" (string, text) or null.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validateInputs(array $template, array $input): array
    {
        $values = [];
        $errors = [];
        $definitions = $this->isList($template['inputs'] ?? null) ? $template['inputs'] : [];

        foreach ($definitions as $definition) {
            if (! $this->isObject($definition) || ! is_string($definition['id'] ?? null) || ! in_array($definition['type'] ?? null, self::INPUT_TYPES, true)) {
                continue;
            }

            $id = $definition['id'];
            $label = $definition['label'] ?? null;
            $subject = is_string($label) && trim($label) !== '' ? $label : $id;
            $value = $this->coerceInput($definition['type'], $input[$id] ?? null);
            $error = $this->inputValueError($definition, $value, $subject);

            if ($error !== null) {
                $errors['inputs.'.$id] = $error;

                continue;
            }

            $values[$id] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Parses a path such as `steps.ideas.items[*].generate.output.ideas[*].title` (no braces, no whitespace).
     *
     * @return array{root: string, segments: list<array{type: 'field', name: string}|array{type: 'index', index: int}|array{type: 'all'}>}|null
     */
    public function parsePath(string $path): ?array
    {
        if (preg_match(self::PATH_PATTERN, $path, $matches) !== 1) {
            return null;
        }

        preg_match_all(self::SEGMENT_PATTERN, $matches[2], $parts, PREG_SET_ORDER);
        $segments = [];

        foreach ($parts as $part) {
            if (($part[1] ?? '') !== '') {
                $segments[] = ['type' => 'field', 'name' => $part[1]];
            } elseif (($part[2] ?? '') !== '') {
                $segments[] = ['type' => 'index', 'index' => (int) $part[2]];
            } else {
                $segments[] = ['type' => 'all'];
            }
        }

        return ['root' => $matches[1], 'segments' => $segments];
    }

    /**
     * Finds `{{ path }}` placeholders. Any other `{{ … }}`, an unclosed `{{` or a stray `}}` is an error.
     *
     * @return array{paths: list<string>, errors: list<string>}
     */
    public function findPlaceholders(string $text): array
    {
        $paths = [];
        $errors = [];
        $offset = 0;

        while (true) {
            $open = strpos($text, '{{', $offset);
            $close = strpos($text, '}}', $offset);

            if ($close !== false && ($open === false || $close < $open)) {
                $errors[] = 'Açılışı olmayan }} var; düz metinde }} kullanılamaz.';
                $offset = $close + 2;

                continue;
            }

            if ($open === false) {
                break;
            }

            $end = strpos($text, '}}', $open + 2);

            if ($end === false) {
                $errors[] = 'Kapanmayan {{ var; düz metinde {{ kullanılamaz.';

                break;
            }

            $content = substr($text, $open + 2, $end - $open - 2);
            $path = trim($content, self::PLACEHOLDER_PADDING);

            if ($this->parsePath($path) !== null) {
                $paths[] = $path;
            } else {
                $errors[] = 'Geçersiz yer tutucu: {{'.mb_strimwidth(mb_scrub($content), 0, 80, '…').'}}. Yalnız {{ inputs.x }} gibi yol ifadeleri kullanılabilir.';
            }

            $offset = $end + 2;
        }

        return ['paths' => $paths, 'errors' => $errors];
    }

    private function inspect(mixed $template): void
    {
        $this->errors = [];
        $this->references = [];
        $this->constants = [];
        $this->inputIds = [];
        $this->stepPaths = [];
        $this->topLevelSteps = [];
        $this->childSteps = [];
        $this->itemShapes = [];

        if (! $this->isObject($template)) {
            $this->fail('şablon', 'Şablon bir JSON nesnesi olmalı.');

            return;
        }

        $encoded = json_encode($template, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $this->fail('şablon', 'Şablon JSON olarak yazılamıyor (geçersiz karakter veya sayı).');

            return;
        }

        if (strlen($encoded) > self::MAX_TEMPLATE_BYTES) {
            $this->fail('şablon', 'Şablon 512 KB sınırını aşıyor.');

            return;
        }

        $this->rejectUnknownKeys($template, self::TEMPLATE_KEYS, '');

        if (($template['schema'] ?? null) !== self::SCHEMA) {
            $this->fail('schema', 'Değer "'.self::SCHEMA.'" olmalı.');
        }

        $id = $template['id'] ?? null;

        if (! is_string($id) || preg_match(WorkflowTemplateStore::ID_PATTERN, $id) !== 1) {
            $this->fail('id', 'Şablon kimliği 1–64 karakter olmalı; yalnız küçük harf, rakam ve tire kullanın, tireyle başlamayın ve bitirmeyin.');
        }

        $this->checkText($template, 'name', 'name', 1, 120, true);
        $this->checkText($template, 'description', 'description', 0, 2000, false);
        $this->checkOptionalInteger($template, 'version', '', 1, PHP_INT_MAX);

        if (array_key_exists('constants', $template)) {
            if ($this->isObject($template['constants'])) {
                $this->constants = $template['constants'];
            } else {
                $this->fail('constants', 'JSON nesnesi olmalı.');
            }
        }

        $this->checkInputs($template);
        $this->checkSteps($template);
        $this->checkGallery($template);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function checkInputs(array $template): void
    {
        if (! array_key_exists('inputs', $template)) {
            return;
        }

        if (! $this->isList($template['inputs'])) {
            $this->fail('inputs', 'Girdilerden oluşan bir dizi olmalı.');

            return;
        }

        if (count($template['inputs']) > 20) {
            $this->fail('inputs', 'En fazla 20 girdi tanımlanabilir.');
        }

        foreach ($template['inputs'] as $index => $input) {
            $this->checkInput($input, "inputs[{$index}]");
        }
    }

    private function checkInput(mixed $input, string $path): void
    {
        if (! $this->isObject($input)) {
            $this->fail($path, 'Girdi bir JSON nesnesi olmalı.');

            return;
        }

        $this->rejectUnknownKeys($input, self::INPUT_KEYS, $path);
        $id = $input['id'] ?? null;

        if (! is_string($id) || preg_match(self::INPUT_ID_PATTERN, $id) !== 1) {
            $this->fail("{$path}.id", 'Girdi kimliği harfle başlamalı; yalnız harf, rakam ve alt çizgi kullanın (en fazla 40 karakter).');
        } elseif (in_array($id, self::HIDDEN_NAMES, true)) {
            $this->fail("{$path}.id", "“{$id}” ayrılmış bir ad; başka bir kimlik seçin.");
        } elseif (isset($this->inputIds[$id])) {
            $this->fail("{$path}.id", "“{$id}” kimliği birden fazla girdide kullanılmış.");
        } else {
            $this->inputIds[$id] = true;
        }

        $type = $input['type'] ?? null;

        if (! in_array($type, self::INPUT_TYPES, true)) {
            $this->fail("{$path}.type", 'Girdi türü integer, number, string, text, boolean veya select olmalı.');

            return;
        }

        $this->checkText($input, 'label', "{$path}.label", 1, 120, true);
        $this->checkText($input, 'description', "{$path}.description", 0, 500, false);

        if (array_key_exists('required', $input) && ! is_bool($input['required'])) {
            $this->fail("{$path}.required", 'true veya false olmalı.');
        }

        $this->checkInputBounds($input, $type, $path);
        $this->checkInputLengths($input, $type, $path);

        if ($type === 'select') {
            if (array_key_exists('options', $input)) {
                $this->checkOptions($input['options'], "{$path}.options");
            } else {
                $this->fail("{$path}.options", 'Zorunlu alan eksik.');
            }
        } elseif (array_key_exists('options', $input)) {
            $this->fail("{$path}.options", 'Yalnız select girdilerinde kullanılabilir.');
        }

        $this->checkInputDefault($input, $type, $path);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkInputBounds(array $input, string $type, string $path): void
    {
        $numeric = in_array($type, ['integer', 'number'], true);
        $bounds = [];

        foreach (['min', 'max'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if (! $numeric) {
                $this->fail("{$path}.{$key}", 'Yalnız integer ve number girdilerinde kullanılabilir.');
            } elseif ($type === 'integer' ? ! $this->isInteger($input[$key]) : ! $this->isNumber($input[$key])) {
                $this->fail("{$path}.{$key}", $type === 'integer' ? 'Tam sayı olmalı.' : 'Sayı olmalı.');
            } else {
                $bounds[$key] = $input[$key];
            }
        }

        if (isset($bounds['min'], $bounds['max']) && $bounds['min'] > $bounds['max']) {
            $this->fail("{$path}.min", 'max değerinden büyük olamaz.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkInputLengths(array $input, string $type, string $path): void
    {
        $textual = in_array($type, ['string', 'text'], true);
        $lengths = [];

        foreach (['minLength', 'maxLength'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if (! $textual) {
                $this->fail("{$path}.{$key}", 'Yalnız string ve text girdilerinde kullanılabilir.');
            } elseif (! $this->isInteger($input[$key]) || $input[$key] < 0 || $input[$key] > self::MAX_LENGTH_CAP) {
                $this->fail("{$path}.{$key}", '0–'.$this->formatNumber(self::MAX_LENGTH_CAP).' arasında bir tam sayı olmalı.');
            } else {
                $lengths[$key] = (int) $input[$key];
            }
        }

        if ($textual && isset($lengths['minLength']) && $lengths['minLength'] > ($lengths['maxLength'] ?? self::DEFAULT_MAX_LENGTH[$type])) {
            $this->fail("{$path}.minLength", 'maxLength değerinden büyük olamaz.');
        }
    }

    private function checkOptions(mixed $options, string $path): void
    {
        if (! $this->isList($options) || $options === [] || count($options) > 50) {
            $this->fail($path, '1–50 seçenekten oluşan bir dizi olmalı.');

            return;
        }

        $seen = [];

        foreach ($options as $index => $option) {
            $optionPath = "{$path}[{$index}]";

            if (! $this->isObject($option)) {
                $this->fail($optionPath, 'Seçenek bir JSON nesnesi olmalı.');

                continue;
            }

            $this->rejectUnknownKeys($option, ['value', 'label'], $optionPath);
            $value = $option['value'] ?? null;

            if (! is_string($value) || $value === '' || mb_strlen($value) > 200) {
                $this->fail("{$optionPath}.value", '1–200 karakterlik bir metin olmalı.');
            } elseif (isset($seen[$value])) {
                $this->fail("{$optionPath}.value", "“{$value}” değeri birden fazla seçenekte kullanılmış.");
            } else {
                $seen[$value] = true;
            }

            $this->checkText($option, 'label', "{$optionPath}.label", 1, 120, true);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkInputDefault(array $input, string $type, string $path): void
    {
        $required = ($input['required'] ?? true) !== false;

        if (! array_key_exists('default', $input)) {
            if ($required || ! in_array($type, ['string', 'text'], true)) {
                $this->fail("{$path}.default", 'Zorunlu alan eksik.');
            }

            return;
        }

        $default = $input['default'];

        if ($type !== 'boolean' && $required && ($default === null || $default === '')) {
            $this->fail("{$path}.default", 'Zorunlu bir girdinin varsayılan değeri boş olamaz.');

            return;
        }

        $default = $type === 'integer' && $this->isInteger($default) ? (int) $default : $default;
        $error = $this->inputValueError($input, $default, 'Varsayılan değer');

        if ($error !== null) {
            $this->fail("{$path}.default", $error);
        }
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function checkSteps(array $template): void
    {
        if (! array_key_exists('steps', $template)) {
            $this->fail('steps', 'Zorunlu alan eksik.');

            return;
        }

        $steps = $template['steps'];

        if (! $this->isList($steps) || $steps === [] || count($steps) > 30) {
            $this->fail('steps', '1–30 adımdan oluşan bir dizi olmalı.');

            if (! $this->isList($steps)) {
                return;
            }
        }

        $this->registerSteps($steps);
        $levelIds = $this->usableIds($steps, false);
        $earlier = [];

        foreach ($steps as $index => $step) {
            $path = "steps[{$index}]";
            $this->checkStep($step, $path, $index, null, $earlier, $levelIds);

            if ($this->ownsId($step, $path)) {
                $earlier[$step['id']] = $this->stepType($step);
            }
        }
    }

    /**
     * First pass: remembers every usable step id (first occurrence wins), so references can look ahead.
     *
     * @param  list<mixed>  $steps
     */
    private function registerSteps(array $steps): void
    {
        foreach ($steps as $index => $step) {
            if (! $this->isObject($step)) {
                continue;
            }

            $path = "steps[{$index}]";
            $id = $this->registerStepId($step, $path, false);

            if ($id !== null) {
                $this->topLevelSteps[$id] = ['position' => $index, 'step' => $step];
            }

            if (($step['type'] ?? null) !== 'foreach' || ! $this->isList($step['steps'] ?? null)) {
                continue;
            }

            foreach ($step['steps'] as $childIndex => $child) {
                if ($this->isObject($child)) {
                    $childId = $this->registerStepId($child, "{$path}.steps[{$childIndex}]", true);

                    if ($childId !== null) {
                        $this->childSteps[$childId] = ['parent' => $id, 'step' => $child];
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function registerStepId(array $step, string $path, bool $child): ?string
    {
        $id = $step['id'] ?? null;

        if (! $this->isUsableStepId($id, $child) || isset($this->stepPaths[$id])) {
            return null;
        }

        $this->stepPaths[$id] = $path;

        return $id;
    }

    /**
     * @param  list<mixed>  $steps
     * @return list<string>
     */
    private function usableIds(array $steps, bool $child): array
    {
        $ids = [];

        foreach ($steps as $step) {
            $id = $this->isObject($step) ? ($step['id'] ?? null) : null;

            if ($this->isUsableStepId($id, $child)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function isUsableStepId(mixed $id, bool $child): bool
    {
        return is_string($id)
            && preg_match(self::STEP_ID_PATTERN, $id) === 1
            && ! in_array($id, self::HIDDEN_NAMES, true)
            && ! ($child && in_array($id, self::ITEM_RECORD_FIELDS, true));
    }

    private function ownsId(mixed $step, string $path): bool
    {
        return $this->isObject($step) && is_string($step['id'] ?? null) && ($this->stepPaths[$step['id']] ?? null) === $path;
    }

    /**
     * @param  ?string  $foreachId  null for a top-level step; the parent foreach id for a child ('' when the parent has no usable id)
     * @param  array<string, string>  $earlier  ids and types of the earlier steps at the same level
     * @param  list<string>  $levelIds  every usable id at the same level
     */
    private function checkStep(mixed $step, string $path, int $position, ?string $foreachId, array $earlier, array $levelIds): void
    {
        if (! $this->isObject($step)) {
            $this->fail($path, 'Adım bir JSON nesnesi olmalı.');

            return;
        }

        $type = $step['type'] ?? null;
        $nestedForeach = $foreachId !== null && $type === 'foreach';
        $knownType = is_string($type) && array_key_exists($type, self::STEP_KEYS) && ! $nestedForeach;

        if ($knownType) {
            $this->rejectUnknownKeys($step, [...self::COMMON_STEP_KEYS, ...self::STEP_KEYS[$type]], $path);
        }

        $this->checkStepId($step, $path, $foreachId !== null);

        if ($nestedForeach) {
            $this->fail("{$path}.type", 'İç içe foreach desteklenmiyor.');
        } elseif (! $knownType) {
            $this->fail("{$path}.type", is_string($type) ? "Bilinmeyen adım türü: {$type}." : 'Adım türü zorunludur.');
        }

        $this->checkText($step, 'title', "{$path}.title", 1, 120, true);
        $this->checkDependsOn($step, $path, $earlier, $levelIds);
        $this->checkOptionalInteger($step, 'timeoutSeconds', $path, 30, 7200);
        $this->checkOptionalInteger($step, 'maxOutputChars', $path, 1000, 1_000_000);
        $this->checkOptionalInteger($step, 'maxOutputTokens', $path, 1000, 200_000);
        $this->checkRetry($step, $path);

        if (! $knownType) {
            return;
        }

        $scope = ['position' => $position, 'foreach' => $foreachId, 'label' => false, 'over' => false];

        match ($type) {
            'foreach' => $this->checkForeach($step, $path, $position),
            'browser_check' => $this->checkBrowserCheck($step, $path, $earlier),
            default => $this->checkPromptStep($step, $type, $path, $scope),
        };
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function checkStepId(array $step, string $path, bool $child): void
    {
        $id = $step['id'] ?? null;

        if (! array_key_exists('id', $step)) {
            $this->fail("{$path}.id", 'Zorunlu alan eksik.');
        } elseif (! is_string($id) || preg_match(self::STEP_ID_PATTERN, $id) !== 1) {
            $this->fail("{$path}.id", 'Adım kimliği küçük harfle başlamalı; yalnız harf, rakam ve alt çizgi kullanın (en fazla 40 karakter).');
        } elseif (! $this->isUsableStepId($id, $child)) {
            $this->fail("{$path}.id", "“{$id}” ayrılmış bir ad; başka bir kimlik seçin.");
        } elseif (($this->stepPaths[$id] ?? null) !== $path) {
            $this->fail("{$path}.id", "“{$id}” kimliği şablonda birden fazla adımda kullanılmış.");
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $earlier
     * @param  list<string>  $levelIds
     */
    private function checkDependsOn(array $step, string $path, array $earlier, array $levelIds): void
    {
        if (! array_key_exists('dependsOn', $step)) {
            return;
        }

        if (! $this->isList($step['dependsOn'])) {
            $this->fail("{$path}.dependsOn", 'Adım kimliklerinden oluşan bir dizi olmalı.');

            return;
        }

        $ownId = $step['id'] ?? null;
        $seen = [];

        foreach ($step['dependsOn'] as $index => $dependency) {
            $dependencyPath = "{$path}.dependsOn[{$index}]";

            if (! is_string($dependency)) {
                $this->fail($dependencyPath, 'Adım kimliği olmalı.');
            } elseif ($dependency === $ownId) {
                $this->fail($dependencyPath, 'Adım kendisine bağlı olamaz.');
            } elseif (isset($seen[$dependency])) {
                $this->fail($dependencyPath, "“{$dependency}” birden fazla kez yazılmış.");
            } elseif (isset($earlier[$dependency])) {
                $seen[$dependency] = true;
            } elseif (in_array($dependency, $levelIds, true)) {
                $this->fail($dependencyPath, "“{$dependency}” bu adımdan sonra geliyor; yalnız daha önceki adımlara bağlanılabilir.");
            } elseif (isset($this->stepPaths[$dependency])) {
                $this->fail($dependencyPath, "“{$dependency}” başka bir düzeyde; dependsOn yalnız aynı düzeydeki önceki adımları gösterebilir.");
            } else {
                $this->fail($dependencyPath, "“{$dependency}” adında bir adım yok.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function checkRetry(array $step, string $path): void
    {
        if (! array_key_exists('retry', $step)) {
            return;
        }

        if (! $this->isObject($step['retry'])) {
            $this->fail("{$path}.retry", 'JSON nesnesi olmalı.');

            return;
        }

        $this->rejectUnknownKeys($step['retry'], ['attempts', 'backoffSeconds'], "{$path}.retry");
        $this->checkOptionalInteger($step['retry'], 'attempts', "{$path}.retry", 1, 5);
        $this->checkOptionalInteger($step['retry'], 'backoffSeconds', "{$path}.retry", 0, 600);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}  $scope
     */
    private function checkPromptStep(array $step, string $type, string $path, array $scope): void
    {
        if (array_key_exists('prompt', $step)) {
            $this->checkTemplateText($step['prompt'], "{$path}.prompt", self::MAX_PROMPT_CHARACTERS, $scope);
        } else {
            $this->fail("{$path}.prompt", 'Zorunlu alan eksik.');
        }

        if ($type === 'llm_json') {
            if (array_key_exists('schema', $step)) {
                $this->checkSchema($step['schema'], "{$path}.schema", '$', 1);
            } else {
                $this->fail("{$path}.schema", 'Zorunlu alan eksik.');
            }
        }

        if (in_array($type, self::CODE_STEP_TYPES, true)) {
            $this->checkModes($step, $path);
            $this->checkOptionalInteger($step, 'repairAttempts', $path, 0, 3);
            $this->checkCodeChecks($step, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function checkModes(array $step, string $path): void
    {
        if (! array_key_exists('modes', $step)) {
            return;
        }

        $modes = $step['modes'];
        $valid = $this->isList($modes) && ($modes[0] ?? null) === 'code_text';

        foreach ($valid ? $modes : [] as $index => $mode) {
            if (! in_array($mode, self::CODE_STEP_TYPES, true) || array_search($mode, $modes, true) !== $index) {
                $valid = false;
            }
        }

        if (! $valid) {
            $this->fail("{$path}.modes", 'code_text ile başlayan ve yalnız code_text ile code_agent içeren bir dizi olmalı.');
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function checkCodeChecks(array $step, string $path): void
    {
        if (! array_key_exists('checks', $step)) {
            return;
        }

        $checks = $step['checks'];
        $checksPath = "{$path}.checks";

        if (! $this->isObject($checks)) {
            $this->fail($checksPath, 'JSON nesnesi olmalı.');

            return;
        }

        $this->rejectUnknownKeys($checks, ['requireListeners', 'maxBytes', 'forbidNetwork'], $checksPath);

        if (array_key_exists('requireListeners', $checks)) {
            $listeners = $checks['requireListeners'];
            $valid = $this->isList($listeners) && $listeners !== [] && count($listeners) <= 10;

            foreach ($valid ? $listeners : [] as $listener) {
                $valid = $valid && is_string($listener) && preg_match(self::EVENT_NAME_PATTERN, $listener) === 1;
            }

            if (! $valid) {
                $this->fail("{$checksPath}.requireListeners", '1–10 olay adından oluşan bir dizi olmalı (ör. pointerdown).');
            }
        }

        $this->checkOptionalInteger($checks, 'maxBytes', $checksPath, 1024, 10_485_760);

        if (array_key_exists('forbidNetwork', $checks) && ! is_bool($checks['forbidNetwork'])) {
            $this->fail("{$checksPath}.forbidNetwork", 'true veya false olmalı.');
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $earlier
     */
    private function checkBrowserCheck(array $step, string $path, array $earlier): void
    {
        $target = $step['target'] ?? null;

        if (! array_key_exists('target', $step)) {
            $this->fail("{$path}.target", 'Zorunlu alan eksik.');
        } elseif (! is_string($target) || ! in_array($earlier[$target] ?? null, self::CODE_STEP_TYPES, true)) {
            $this->fail("{$path}.target", (is_string($target) ? "“{$target}”" : 'Hedef').' bu adımdan önce gelen bir code_text veya code_agent kardeş adımı değil.');
        }

        if (array_key_exists('screenshot', $step) && ! is_bool($step['screenshot'])) {
            $this->fail("{$path}.screenshot", 'true veya false olmalı.');
        }

        if (! array_key_exists('interactions', $step)) {
            return;
        }

        if (! $this->isList($step['interactions']) || count($step['interactions']) > 20) {
            $this->fail("{$path}.interactions", 'En fazla 20 etkileşimden oluşan bir dizi olmalı.');

            return;
        }

        foreach ($step['interactions'] as $index => $interaction) {
            $this->checkInteraction($interaction, "{$path}.interactions[{$index}]");
        }
    }

    private function checkInteraction(mixed $interaction, string $path): void
    {
        if (! $this->isObject($interaction)) {
            $this->fail($path, 'Etkileşim bir JSON nesnesi olmalı.');

            return;
        }

        $pointKeys = match ($interaction['type'] ?? null) {
            'drag' => ['from', 'to'],
            'tap' => ['at'],
            default => null,
        };

        if ($pointKeys === null) {
            $this->fail("{$path}.type", 'drag veya tap olmalı.');

            return;
        }

        $this->rejectUnknownKeys($interaction, ['type', ...$pointKeys], $path);

        foreach ($pointKeys as $key) {
            if (! array_key_exists($key, $interaction)) {
                $this->fail("{$path}.{$key}", 'Zorunlu alan eksik.');

                continue;
            }

            $point = $interaction[$key];
            $valid = $this->isList($point) && count($point) === 2;

            foreach ($valid ? $point : [] as $coordinate) {
                $valid = $valid && $this->isNumber($coordinate) && $coordinate >= 0 && $coordinate <= 1;
            }

            if (! $valid) {
                $this->fail("{$path}.{$key}", '0 ile 1 arasında iki sayıdan oluşan bir dizi olmalı (ör. [0.5, 0.5]).');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function checkForeach(array $step, string $path, int $position): void
    {
        $id = $this->ownsId($step, $path) ? $step['id'] : null;
        $itemShape = $this->checkOver($step, $path, $position);

        if ($id !== null) {
            $this->itemShapes[$id] = $itemShape;
        }

        $this->checkOptionalInteger($step, 'concurrency', $path, 1, 5);
        $labelScope = ['position' => $position, 'foreach' => $id, 'label' => true, 'over' => false];

        if (array_key_exists('itemLabel', $step)) {
            $this->checkTemplateText($step['itemLabel'], "{$path}.itemLabel", self::MAX_LABEL_TEMPLATE_CHARACTERS, $id === null ? null : $labelScope);
        }

        if (! array_key_exists('steps', $step)) {
            $this->fail("{$path}.steps", 'Zorunlu alan eksik.');

            return;
        }

        $children = $step['steps'];

        if (! $this->isList($children) || $children === [] || count($children) > 10) {
            $this->fail("{$path}.steps", '1–10 alt adımdan oluşan bir dizi olmalı.');

            if (! $this->isList($children)) {
                return;
            }
        }

        $levelIds = $this->usableIds($children, true);
        $earlier = [];

        foreach ($children as $index => $child) {
            $childPath = "{$path}.steps[{$index}]";
            $this->checkStep($child, $childPath, $position, $id ?? '', $earlier, $levelIds);

            if ($this->ownsId($child, $childPath)) {
                $earlier[$child['id']] = $this->stepType($child);
            }
        }
    }

    /**
     * Checks `foreach.over` and returns what `item` holds inside the foreach.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function checkOver(array $step, string $path, int $position): array
    {
        $unknown = ['kind' => 'any'];

        if (! array_key_exists('over', $step)) {
            $this->fail("{$path}.over", 'Zorunlu alan eksik.');

            return $unknown;
        }

        $over = $step['over'];
        $parsed = is_string($over) ? $this->parsePath($over) : null;

        if ($parsed === null) {
            $this->fail("{$path}.over", 'Süslü parantezsiz geçerli bir yol ifadesi olmalı (ör. constants.categories).');

            return $unknown;
        }

        $this->references[] = ['path' => $over, 'at' => "{$path}.over"];
        $result = $this->resolveReference($parsed, ['position' => $position, 'foreach' => null, 'label' => false, 'over' => true]);

        if ($result['error'] !== null) {
            $this->fail("{$path}.over", "{$over}: {$result['error']}");

            return $unknown;
        }

        $itemShape = $result['flattened'] ? $this->firstValueOnly($result['shape']) : $this->elementShape($result['shape']);

        if ($itemShape === null) {
            $this->fail("{$path}.over", "{$over}: Bir diziye çözümlenmeli.");

            return $unknown;
        }

        return $itemShape;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function checkGallery(array $template): void
    {
        if (! array_key_exists('gallery', $template)) {
            return;
        }

        $gallery = $template['gallery'];

        if (! $this->isObject($gallery)) {
            $this->fail('gallery', 'JSON nesnesi olmalı.');

            return;
        }

        $this->rejectUnknownKeys($gallery, ['from', 'title', 'file', 'check'], 'gallery');
        $from = $gallery['from'] ?? null;
        $foreach = is_string($from) ? ($this->topLevelSteps[$from] ?? null) : null;

        if (! array_key_exists('from', $gallery)) {
            $this->fail('gallery.from', 'Zorunlu alan eksik.');
        } elseif ($foreach === null || ($foreach['step']['type'] ?? null) !== 'foreach') {
            $this->fail('gallery.from', (is_string($from) ? "“{$from}”" : 'Değer').' üst düzeydeki bir foreach adımının kimliği değil.');
            $foreach = null;
        }

        if (array_key_exists('title', $gallery)) {
            $scope = $foreach === null ? null : ['position' => $foreach['position'], 'foreach' => $from, 'label' => true, 'over' => false];
            $this->checkTemplateText($gallery['title'], 'gallery.title', self::MAX_LABEL_TEMPLATE_CHARACTERS, $scope);
        } else {
            $this->fail('gallery.title', 'Zorunlu alan eksik.');
        }

        if (! array_key_exists('file', $gallery)) {
            $this->fail('gallery.file', 'Zorunlu alan eksik.');
        } elseif ($foreach !== null && ! $this->isChildOfType($gallery['file'], $from, self::CODE_STEP_TYPES)) {
            $this->fail('gallery.file', "“{$from}” adımının code_text veya code_agent alt adımlarından birinin kimliği olmalı.");
        }

        if (array_key_exists('check', $gallery) && $foreach !== null && ! $this->isChildOfType($gallery['check'], $from, ['browser_check'])) {
            $this->fail('gallery.check', "“{$from}” adımının browser_check alt adımlarından birinin kimliği olmalı.");
        }
    }

    /**
     * @param  list<string>  $types
     */
    private function isChildOfType(mixed $id, string $foreachId, array $types): bool
    {
        $child = is_string($id) ? ($this->childSteps[$id] ?? null) : null;

        return $child !== null && $child['parent'] === $foreachId && in_array($child['step']['type'] ?? null, $types, true);
    }

    /**
     * Checks a text with placeholders. A null scope checks only the placeholder syntax.
     *
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}|null  $scope
     */
    private function checkTemplateText(mixed $value, string $path, int $maxCharacters, ?array $scope): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($path, 'Boş olmayan bir metin olmalı.');

            return;
        }

        if (mb_strlen($value) > $maxCharacters) {
            $this->fail($path, 'En fazla '.$this->formatNumber($maxCharacters).' karakter olabilir.');

            return;
        }

        $placeholders = $this->findPlaceholders($value);

        foreach ($placeholders['errors'] as $error) {
            $this->fail($path, $error);
        }

        foreach ($placeholders['paths'] as $placeholder) {
            $this->references[] = ['path' => $placeholder, 'at' => $path];
            $parsed = $this->parsePath($placeholder);
            $error = $scope === null || $parsed === null ? null : $this->resolveReference($parsed, $scope)['error'];

            if ($error !== null) {
                $this->fail($path, '{{'.$placeholder.'}}: '.$error);
            }
        }
    }

    /**
     * Statically follows a path and returns what it points at, or a Turkish error.
     *
     * @param  array{root: string, segments: list<array<string, mixed>>}  $path
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}  $scope
     * @return array{error: ?string, shape: array<string, mixed>, flattened: bool}
     */
    private function resolveReference(array $path, array $scope): array
    {
        $segments = $path['segments'];
        [$shape, $consumed] = match ($path['root']) {
            'inputs' => [$this->inputsShape($segments), 1],
            'constants' => [['kind' => 'values', 'values' => [$this->constants]], 0],
            'steps' => [$this->stepsShape($segments, $scope), 1],
            'item' => [$this->itemRootShape($scope), 0],
            default => [$this->loopShape($segments, $scope), 1],
        };

        return $this->walk($shape, array_slice($segments, $consumed));
    }

    /**
     * @param  array<string, mixed>|string  $shape
     * @param  list<array<string, mixed>>  $segments
     * @return array{error: ?string, shape: array<string, mixed>, flattened: bool}
     */
    private function walk(array|string $shape, array $segments): array
    {
        $flattened = false;

        foreach ($segments as $segment) {
            if (is_string($shape)) {
                break;
            }

            $shape = $this->advance($shape, $segment);
            $flattened = $flattened || $segment['type'] === 'all';
        }

        if (is_array($shape) && $shape['kind'] === 'step') {
            $shape = "“{$shape['id']}” adımından sonra .output gelmeli.";
        } elseif (is_array($shape) && $shape['kind'] === 'foreach') {
            $shape = "“{$shape['id']}” bir foreach adımı; sonrasında .items gelmeli.";
        }

        return is_string($shape)
            ? ['error' => $shape, 'shape' => ['kind' => 'any'], 'flattened' => $flattened]
            : ['error' => null, 'shape' => $shape, 'flattened' => $flattened];
    }

    /**
     * @param  array<string, mixed>  $shape
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>|string
     */
    private function advance(array $shape, array $segment): array|string
    {
        $name = $segment['type'] === 'field' ? $segment['name'] : null;
        $display = match ($segment['type']) {
            'field' => ".{$segment['name']}",
            'index' => "[{$segment['index']}]",
            default => '[*]',
        };

        return match ($shape['kind']) {
            'any' => $shape,
            'values' => $this->advanceValues($shape['values'], $segment),
            'schema' => $this->advanceSchema($shape['node'], $segment, $display),
            'step' => $this->stepOutputShape($shape, $name),
            'foreach' => $name === 'items'
                ? ['kind' => 'records', 'foreach' => $shape['id']]
                : "“{$shape['id']}” bir foreach adımı; sonrasında .items gelmeli.",
            'records' => $name === null
                ? ['kind' => 'record', 'foreach' => $shape['foreach']]
                : "Öğe listesinden sonra {$display} yerine [n] veya [*] gelmeli.",
            'record' => $this->advanceRecord($shape['foreach'], $name),
            'code' => match ($name) {
                'file', 'bytes' => ['kind' => 'scalar'],
                'checks' => ['kind' => 'any'],
                default => 'Kod adımının çıktısında yalnız file, bytes ve checks alanları var.',
            },
            'text' => "Metin çıktısından sonra {$display} gelemez.",
            default => "Bu değer tek bir değer; sonrasına {$display} eklenemez.",
        };
    }

    /**
     * Walks concrete values (the template's constants). With `[*]` at least one element must have the rest of the path.
     *
     * @param  list<mixed>  $values
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>|string
     */
    private function advanceValues(array $values, array $segment): array|string
    {
        if ($values === []) {
            return ['kind' => 'values', 'values' => []];
        }

        $next = [];
        $matched = false;

        foreach ($values as $value) {
            if ($segment['type'] === 'field') {
                if ($this->isObject($value) && array_key_exists($segment['name'], $value) && ! in_array($segment['name'], self::HIDDEN_NAMES, true)) {
                    $next[] = $value[$segment['name']];
                    $matched = true;
                }
            } elseif ($segment['type'] === 'index') {
                if ($this->isList($value) && $segment['index'] < count($value)) {
                    $next[] = $value[$segment['index']];
                    $matched = true;
                }
            } elseif ($this->isList($value)) {
                array_push($next, ...$value);
                $matched = true;
            }
        }

        if (! $matched) {
            return match ($segment['type']) {
                'field' => "“{$segment['name']}” alanı bulunamadı.",
                'index' => "[{$segment['index']}] bulunamadı; değer bir dizi değil ya da dizi daha kısa.",
                default => '[*] yalnız dizilerde kullanılabilir.',
            };
        }

        return ['kind' => 'values', 'values' => $next];
    }

    /**
     * Walks an `llm_json` schema: listed properties of objects, items of arrays; nodes without a type,
     * properties or items accept any further path.
     *
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>|string
     */
    private function advanceSchema(mixed $node, array $segment, string $display): array|string
    {
        if (! $this->isObject($node) || ! is_string($node['type'] ?? null)) {
            return ['kind' => 'any'];
        }

        $type = $node['type'];

        if ($type === 'object') {
            if ($segment['type'] !== 'field') {
                return "Bu değer bir nesne; {$display} yerine alan adı gelmeli.";
            }

            if (! $this->isObject($node['properties'] ?? null)) {
                return ['kind' => 'any'];
            }

            return array_key_exists($segment['name'], $node['properties']) && ! in_array($segment['name'], self::HIDDEN_NAMES, true)
                ? ['kind' => 'schema', 'node' => $node['properties'][$segment['name']]]
                : "Şemada “{$segment['name']}” alanı yok.";
        }

        if ($type === 'array') {
            if ($segment['type'] === 'field') {
                return "Bu değer bir dizi; {$display} yerine [n] veya [*] gelmeli.";
            }

            return $this->isObject($node['items'] ?? null) ? ['kind' => 'schema', 'node' => $node['items']] : ['kind' => 'any'];
        }

        return "Bu değer {$type} türünde; sonrasına {$display} eklenemez.";
    }

    /**
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>|string
     */
    private function stepOutputShape(array $shape, ?string $name): array|string
    {
        if ($name !== 'output') {
            return "“{$shape['id']}” adımından sonra .output gelmeli.";
        }

        return match ($shape['type']) {
            'llm_text' => ['kind' => 'text'],
            'llm_json' => ['kind' => 'schema', 'node' => $shape['step']['schema'] ?? null],
            'code_text', 'code_agent' => ['kind' => 'code'],
            default => "“{$shape['id']}” adımının ({$shape['type']}) çıktısına başvurulamaz.",
        };
    }

    /**
     * @return array<string, mixed>|string
     */
    private function advanceRecord(string $foreachId, ?string $name): array|string
    {
        if ($name === null) {
            return 'Öğe kaydı bir nesne; [n] veya [*] yerine alan adı gelmeli.';
        }

        if ($name === 'item') {
            return $this->itemShapes[$foreachId] ?? ['kind' => 'any'];
        }

        if (in_array($name, ['index', 'key', 'label'], true)) {
            return ['kind' => 'scalar'];
        }

        $child = $this->childSteps[$name] ?? null;

        if ($child === null || $child['parent'] !== $foreachId) {
            return "Öğe kaydında “{$name}” yok; item, index, key, label veya “{$foreachId}” adımının bir alt adım kimliğini kullanın.";
        }

        return ['kind' => 'step', 'id' => $name, 'type' => $this->stepType($child['step']), 'step' => $child['step']];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return array<string, mixed>|string
     */
    private function inputsShape(array $segments): array|string
    {
        $first = $segments[0] ?? null;

        if ($first === null || $first['type'] !== 'field') {
            return 'inputs sonrasında girdi kimliği gelmeli (ör. inputs.ideasPerCategory).';
        }

        if (! isset($this->inputIds[$first['name']])) {
            return "“{$first['name']}” adında bir girdi yok.";
        }

        return count($segments) > 1 ? 'Girdi değerinden sonra başka bir yol parçası gelemez.' : ['kind' => 'scalar'];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}  $scope
     * @return array<string, mixed>|string
     */
    private function stepsShape(array $segments, array $scope): array|string
    {
        $first = $segments[0] ?? null;

        if ($first === null || $first['type'] !== 'field') {
            return 'steps sonrasında adım kimliği gelmeli (ör. steps.ideas).';
        }

        $id = $first['name'];
        $step = $this->topLevelSteps[$id] ?? null;

        if ($step === null) {
            $child = $this->childSteps[$id] ?? null;

            return $child !== null && $child['parent'] !== null
                ? "“{$id}” bir foreach alt adımı; steps.{$child['parent']}.items[*].{$id}.output biçimini kullanın."
                : "“{$id}” adında bir üst düzey adım yok.";
        }

        if ($step['position'] >= $scope['position']) {
            return match (true) {
                $id === $scope['foreach'] => 'İçinde bulunulan foreach adımına steps ile başvurulamaz; önceki öğeler için loop.previous kullanın.',
                $step['position'] === $scope['position'] => 'Adım kendi çıktısına başvuramaz.',
                default => "“{$id}” adımı daha sonra geliyor; yalnız önceki adımlara başvurulabilir.",
            };
        }

        return ($step['step']['type'] ?? null) === 'foreach'
            ? ['kind' => 'foreach', 'id' => $id]
            : ['kind' => 'step', 'id' => $id, 'type' => $this->stepType($step['step']), 'step' => $step['step']];
    }

    /**
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}  $scope
     * @return array<string, mixed>|string
     */
    private function itemRootShape(array $scope): array|string
    {
        if ($scope['over']) {
            return 'foreach.over yalnız inputs, constants veya steps ile başlayabilir.';
        }

        if ($scope['foreach'] === null) {
            return 'item yalnız foreach alt adımlarında kullanılabilir.';
        }

        return $this->itemShapes[$scope['foreach']] ?? ['kind' => 'any'];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  array{position: int, foreach: ?string, label: bool, over: bool}  $scope
     * @return array<string, mixed>|string
     */
    private function loopShape(array $segments, array $scope): array|string
    {
        if ($scope['over']) {
            return 'foreach.over yalnız inputs, constants veya steps ile başlayabilir.';
        }

        if ($scope['foreach'] === null) {
            return 'loop yalnız foreach alt adımlarında kullanılabilir.';
        }

        $first = $segments[0] ?? null;
        $name = $first !== null && $first['type'] === 'field' ? $first['name'] : null;

        if (in_array($name, ['index', 'number', 'total'], true)) {
            return ['kind' => 'scalar'];
        }

        if ($name !== 'previous') {
            return 'loop sonrasında index, number, total veya previous gelmeli.';
        }

        if ($scope['label']) {
            return 'loop.previous öğe etiketinde ve galeri başlığında kullanılamaz.';
        }

        $concurrency = $this->topLevelSteps[$scope['foreach']]['step']['concurrency'] ?? 1;

        if (! $this->isInteger($concurrency) || (int) $concurrency !== 1) {
            return 'loop.previous yalnız concurrency değeri 1 olan foreach içinde kullanılabilir.';
        }

        return ['kind' => 'records', 'foreach' => $scope['foreach']];
    }

    /**
     * What one element of an array-valued shape holds, or null when the shape is not an array.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>|null
     */
    private function elementShape(array $shape): ?array
    {
        if ($shape['kind'] === 'values') {
            $value = $shape['values'][0] ?? null;

            return $this->isList($value) ? ['kind' => 'values', 'values' => array_slice($value, 0, 1)] : null;
        }

        if ($shape['kind'] === 'schema') {
            $node = $shape['node'];

            if (! $this->isObject($node) || ! is_string($node['type'] ?? null)) {
                return ['kind' => 'any'];
            }

            if ($node['type'] !== 'array') {
                return null;
            }

            return $this->isObject($node['items'] ?? null) ? ['kind' => 'schema', 'node' => $node['items']] : ['kind' => 'any'];
        }

        return match ($shape['kind']) {
            'any' => $shape,
            'records' => ['kind' => 'record', 'foreach' => $shape['foreach']],
            default => null,
        };
    }

    /**
     * Items of a flattened (`[*]`) constants path are checked against the first element only.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function firstValueOnly(array $shape): array
    {
        return $shape['kind'] === 'values' ? ['kind' => 'values', 'values' => array_slice($shape['values'], 0, 1)] : $shape;
    }

    private function checkSchema(mixed $node, string $path, string $pointer, int $depth): void
    {
        if ($depth > self::SCHEMA_MAX_DEPTH) {
            $this->fail($path, 'Şema en fazla '.self::SCHEMA_MAX_DEPTH." düzey derin olabilir ({$pointer}).");

            return;
        }

        if (! $this->isObject($node)) {
            $this->fail($path, "Şema düğümü bir JSON nesnesi olmalı ({$pointer}).");

            return;
        }

        foreach (array_keys($node) as $keyword) {
            if (! in_array($keyword, self::SCHEMA_KEYWORDS, true)) {
                $this->fail($path, "Desteklenmeyen şema anahtarı: {$keyword} ({$pointer})");
            }
        }

        if (array_key_exists('type', $node) && ! in_array($node['type'], self::SCHEMA_TYPES, true)) {
            $this->fail($path, "type tek bir değer olmalı: object, array, string, integer, number, boolean veya null ({$pointer}).");
        }

        if (array_key_exists('description', $node) && ! is_string($node['description'])) {
            $this->fail($path, "description bir metin olmalı ({$pointer}).");
        }

        if (array_key_exists('additionalProperties', $node) && ! is_bool($node['additionalProperties'])) {
            $this->fail($path, "additionalProperties yalnız true veya false olabilir ({$pointer}).");
        }

        $this->checkSchemaProperties($node, $path, $pointer, $depth);

        if (array_key_exists('items', $node)) {
            $this->checkSchema($node['items'], "{$path}.items", "{$pointer}[*]", $depth + 1);
        }

        if (array_key_exists('enum', $node)) {
            $valid = $this->isList($node['enum']) && $node['enum'] !== [];

            foreach ($valid ? $node['enum'] : [] as $value) {
                $valid = $valid && ($value === null || is_scalar($value));
            }

            if (! $valid) {
                $this->fail($path, "enum boş olmayan bir dizi olmalı ve yalnız metin, sayı, true/false veya null içermeli ({$pointer}).");
            }
        }

        foreach ([['minItems', 'maxItems'], ['minLength', 'maxLength']] as [$minimumKey, $maximumKey]) {
            foreach ([$minimumKey, $maximumKey] as $key) {
                if (array_key_exists($key, $node) && (! $this->isInteger($node[$key]) || $node[$key] < 0)) {
                    $this->fail($path, "{$key} negatif olmayan bir tam sayı olmalı ({$pointer}).");
                }
            }

            if ($this->isInteger($node[$minimumKey] ?? null) && $this->isInteger($node[$maximumKey] ?? null) && $node[$minimumKey] > $node[$maximumKey]) {
                $this->fail($path, "{$minimumKey}, {$maximumKey} değerinden büyük olamaz ({$pointer}).");
            }
        }

        foreach (['minimum', 'maximum'] as $key) {
            if (array_key_exists($key, $node) && ! $this->isNumber($node[$key])) {
                $this->fail($path, "{$key} bir sayı olmalı ({$pointer}).");
            }
        }

        if ($this->isNumber($node['minimum'] ?? null) && $this->isNumber($node['maximum'] ?? null) && $node['minimum'] > $node['maximum']) {
            $this->fail($path, "minimum, maximum değerinden büyük olamaz ({$pointer}).");
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function checkSchemaProperties(array $node, string $path, string $pointer, int $depth): void
    {
        $properties = $node['properties'] ?? null;

        if (array_key_exists('properties', $node)) {
            if ($this->isObject($properties)) {
                foreach ($properties as $name => $property) {
                    $this->checkSchema($property, "{$path}.properties.{$name}", "{$pointer}.{$name}", $depth + 1);
                }
            } else {
                $this->fail($path, "properties bir JSON nesnesi olmalı ({$pointer}).");
            }
        }

        if (! array_key_exists('required', $node)) {
            return;
        }

        $required = $node['required'];
        $valid = $this->isList($required);

        foreach ($valid ? $required : [] as $index => $name) {
            $valid = $valid && is_string($name) && array_search($name, $required, true) === $index;
        }

        if (! $valid) {
            $this->fail($path, "required tekrarsız alan adlarından oluşan bir dizi olmalı ({$pointer}).");

            return;
        }

        foreach ($this->isObject($properties) ? $required : [] as $name) {
            if (! array_key_exists($name, $properties)) {
                $this->fail($path, "required içindeki “{$name}” properties içinde tanımlı değil ({$pointer}).");
            }
        }
    }

    /**
     * @return mixed the converted value, or the raw value when it cannot be converted (inputValueError reports it)
     */
    private function coerceInput(string $type, mixed $raw): mixed
    {
        if ($type === 'boolean') {
            if ($raw === null || $raw === '') {
                return false;
            }

            $value = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

            return $value ?? $raw;
        }

        if (is_string($raw)) {
            $raw = trim($type === 'text' ? str_replace(["\r\n", "\r"], "\n", $raw) : $raw);
        }

        if ($raw === null || $raw === '') {
            return in_array($type, ['string', 'text'], true) ? '' : null;
        }

        if ($type === 'integer') {
            if ($this->isInteger($raw)) {
                return (int) $raw;
            }

            if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
                $value = filter_var(preg_replace('/^(-?)0+(?=\d)/', '$1', $raw), FILTER_VALIDATE_INT);

                return $value === false ? $raw : $value;
            }

            return $raw;
        }

        if ($type === 'number' && is_string($raw) && is_numeric($raw)) {
            return $raw + 0;
        }

        return $type === 'select' && is_int($raw) ? (string) $raw : $raw;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function inputValueError(array $definition, mixed $value, string $subject): ?string
    {
        $type = $definition['type'];

        if ($type !== 'boolean' && ($value === null || $value === '')) {
            return ($definition['required'] ?? true) === false ? null : "{$subject} zorunludur.";
        }

        return match ($type) {
            'integer' => is_int($value) ? $this->rangeError($definition, $value, $subject) : "{$subject} bir tam sayı olmalıdır.",
            'number' => $this->isNumber($value) ? $this->rangeError($definition, $value, $subject) : "{$subject} bir sayı olmalıdır.",
            'boolean' => is_bool($value) ? null : "{$subject} evet/hayır değeri olmalıdır.",
            'select' => is_string($value) && in_array($value, $this->optionValues($definition), true) ? null : "{$subject} için listeden geçerli bir seçenek seçin.",
            default => is_string($value) ? $this->lengthError($definition, $value, $subject) : "{$subject} bir metin olmalıdır.",
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function rangeError(array $definition, int|float $value, string $subject): ?string
    {
        $minimum = $definition['min'] ?? null;
        $maximum = $definition['max'] ?? null;

        if ($this->isNumber($minimum) && $value < $minimum) {
            return "{$subject} en az {$this->formatNumber($minimum)} olmalıdır.";
        }

        if ($this->isNumber($maximum) && $value > $maximum) {
            return "{$subject} en fazla {$this->formatNumber($maximum)} olabilir.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function lengthError(array $definition, string $value, string $subject): ?string
    {
        $minimum = $this->isInteger($definition['minLength'] ?? null) ? (int) $definition['minLength'] : 0;
        $maximum = $definition['maxLength'] ?? null;
        $maximum = $this->isInteger($maximum) && $maximum >= 0 && $maximum <= self::MAX_LENGTH_CAP
            ? (int) $maximum
            : self::DEFAULT_MAX_LENGTH[$definition['type']];
        $length = mb_strlen($value);

        if ($length < $minimum) {
            return "{$subject} en az {$this->formatNumber($minimum)} karakter olmalıdır.";
        }

        if ($length > $maximum) {
            return "{$subject} en fazla {$this->formatNumber($maximum)} karakter olabilir.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function optionValues(array $definition): array
    {
        $options = $this->isList($definition['options'] ?? null) ? $definition['options'] : [];

        return array_values(array_filter(array_map(
            fn (mixed $option): ?string => $this->isObject($option) && is_string($option['value'] ?? null) ? $option['value'] : null,
            $options,
        ), fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function checkText(array $object, string $key, string $path, int $minimum, int $maximum, bool $required): void
    {
        if (! array_key_exists($key, $object)) {
            if ($required) {
                $this->fail($path, 'Zorunlu alan eksik.');
            }

            return;
        }

        $value = $object[$key];

        if (! is_string($value) || ($minimum > 0 && trim($value) === '') || mb_strlen($value) > $maximum) {
            $this->fail($path, $minimum > 0
                ? "{$minimum}–{$this->formatNumber($maximum)} karakterlik bir metin olmalı."
                : "En fazla {$this->formatNumber($maximum)} karakterlik bir metin olmalı.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function checkOptionalInteger(array $object, string $key, string $parentPath, int $minimum, int $maximum): void
    {
        if (! array_key_exists($key, $object)) {
            return;
        }

        $value = $object[$key];

        if (! $this->isInteger($value) || $value < $minimum || $value > $maximum) {
            $this->fail($this->join($parentPath, $key), $maximum === PHP_INT_MAX
                ? "{$this->formatNumber($minimum)} veya daha büyük bir tam sayı olmalı."
                : "{$this->formatNumber($minimum)}–{$this->formatNumber($maximum)} arasında bir tam sayı olmalı.");
        }
    }

    /**
     * Typo protection: every key outside the allowed list is an error.
     *
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail($this->join($path, (string) $key), 'Bilinmeyen alan.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function stepType(array $step): string
    {
        return is_string($step['type'] ?? null) ? $step['type'] : '';
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[] = "{$path}: {$message}";
    }

    private function join(string $parentPath, string $key): string
    {
        return $parentPath === '' ? $key : "{$parentPath}.{$key}";
    }

    private function formatNumber(int|float $number): string
    {
        return is_int($number) || floor($number) === $number
            ? number_format($number, 0, ',', '.')
            : str_replace('.', ',', (string) $number);
    }

    /** A decoded JSON object. `{}` decodes to `[]`, so an empty array counts as an (empty) object too. */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** JSON does not tell 5 from 5.0, so an integral float counts as an integer (as in the worker). */
    private function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }
}
