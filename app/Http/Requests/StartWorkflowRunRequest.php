<?php

namespace App\Http\Requests;

use App\Exceptions\BenchmarkRunnerException;
use App\Services\ModelSelection;
use App\Services\OpenRouterProviderRouting;
use App\Services\WorkflowTemplateStore;
use App\Services\WorkflowTemplateValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartWorkflowRunRequest extends FormRequest
{
    private const MAX_PARALLEL_MODELS = 5;

    private const MAX_CONCURRENT_CALLS = 10;

    private const MAX_TIMEOUT_MINUTES = 120;

    /** @var array{template: array<string, mixed>, raw: string, sha256: string, version: int, source: string}|null */
    private ?array $workflowTemplate = null;

    /** @var list<array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}> */
    private array $groupOptions = [];

    /** @var array<string, mixed>|null */
    private ?array $inputValues = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true);
    }

    /**
     * Get the validation rules that apply to the request. Inputs of the model panels that are not selected are excluded,
     * so values left behind in a hidden panel never fail validation or reach the runner.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ModelSelection $modelSelection, WorkflowTemplateStore $templateStore): array
    {
        $version = filter_var($this->input('template_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->workflowTemplate = $version === false ? null : $templateStore->find($this->templateId(), $version);
        $groupError = null;

        try {
            $this->groupOptions = $modelSelection->groupOptions();
        } catch (BenchmarkRunnerException $exception) {
            $this->groupOptions = [];
            $groupError = $exception->getMessage();
        }

        $modelLimit = ModelSelection::WORKFLOW_MODEL_LIMIT;

        return [
            'template_version' => ['bail', 'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->workflowTemplate === null) {
                        $fail('Bu şablon sürümü bulunamadı ya da geçersiz. İş akışı listesini yenileyin.');
                    }
                }],
            'inputs' => ['nullable', 'array'],
            'model_mode' => ['required', Rule::in(ModelSelection::MODES)],
            'config' => ['exclude_unless:model_mode,group', 'required', 'string', $groupError === null
                ? Rule::in(array_column($this->groupOptions, 'value'))
                : function (string $attribute, mixed $value, Closure $fail) use ($groupError): void {
                    $fail("Kayıtlı model grupları okunamadı: {$groupError}");
                }],
            'models' => ['exclude_unless:model_mode,custom', 'required', 'array', 'list', 'min:1', 'max:'.$modelLimit],
            'models.*' => [
                'exclude_unless:model_mode,custom',
                'string',
                'distinct',
                Rule::exists('open_router_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'go_models' => ['exclude_unless:model_mode,opencode_go', 'required', 'array', 'list', 'min:1', 'max:'.$modelLimit],
            'go_models.*' => [
                'exclude_unless:model_mode,opencode_go',
                'string',
                'distinct',
                Rule::exists('open_code_go_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'routing_mode' => ['exclude_unless:model_mode,custom', 'required', Rule::in(OpenRouterProviderRouting::MODES)],
            'fixed_providers' => ['exclude_unless:model_mode,custom', Rule::requiredIf(fn (): bool => $this->input('routing_mode') === 'fixed'), 'nullable', 'array', 'max:'.$modelLimit,
                function (string $attribute, mixed $value, Closure $fail): void {
                    foreach ((array) $value as $modelId => $slug) {
                        if (! is_string($modelId) || ! is_string($slug) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', $slug) !== 1) {
                            $fail('Geçerli bir sabit sağlayıcı seçin.');
                            break;
                        }
                    }
                }],
            'max_parallel' => ['required', 'integer', 'between:1,'.self::MAX_PARALLEL_MODELS],
            'max_concurrent_calls' => ['required', 'integer', 'between:1,'.self::MAX_CONCURRENT_CALLS],
            'timeouts' => ['nullable', 'array'],
            'timeouts.llm' => ['nullable', 'integer', 'between:1,'.self::MAX_TIMEOUT_MINUTES],
            'timeouts.code' => ['nullable', 'integer', 'between:1,'.self::MAX_TIMEOUT_MINUTES],
            'confirmed_external' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $modelLimit = ModelSelection::WORKFLOW_MODEL_LIMIT;

        return [
            'template_version.required' => 'Şablon sürümü eksik. Sayfayı yenileyip yeniden deneyin.',
            'template_version.integer' => 'Geçerli bir şablon sürümü seçin.',
            'template_version.min' => 'Geçerli bir şablon sürümü seçin.',
            'inputs.array' => 'İş akışı girdileri geçerli biçimde gönderilmedi.',
            'model_mode.required' => 'Model seçim yöntemini seçin.',
            'model_mode.in' => 'Kayıtlı grup, OpenRouter veya OpenCode Go seçin.',
            'config.required' => 'Model grubu seçin.',
            'config.in' => 'Listeden geçerli bir model grubu seçin.',
            'models.required' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.list' => 'Geçerli bir model listesi gönderin.',
            'models.min' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.max' => "Bir iş akışında en fazla {$modelLimit} model seçilebilir.",
            'models.*.string' => 'Seçilen modellerden biri geçerli değil.',
            'models.*.exists' => 'Seçilen modellerden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'models.*.distinct' => 'Aynı model birden fazla seçilemez.',
            'go_models.required' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.list' => 'Geçerli bir model listesi gönderin.',
            'go_models.min' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.max' => "Bir iş akışında en fazla {$modelLimit} OpenCode Go modeli seçilebilir.",
            'go_models.*.string' => 'Seçilen OpenCode Go modellerinden biri geçerli değil.',
            'go_models.*.exists' => 'Seçilen OpenCode Go modellerinden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'go_models.*.distinct' => 'Aynı OpenCode Go modeli birden fazla seçilemez.',
            'routing_mode.required' => 'OpenRouter sağlayıcı tercihini seçin.',
            'routing_mode.in' => 'Geçerli bir OpenRouter sağlayıcı tercihi seçin.',
            'fixed_providers.required' => 'Seçilen her model için bir sabit sağlayıcı seçin.',
            'fixed_providers.array' => 'Geçerli bir sabit sağlayıcı seçin.',
            'fixed_providers.max' => 'Seçilen her model için bir sabit sağlayıcı seçin.',
            'max_parallel.required' => 'Aynı anda çalışacak model sayısını seçin.',
            'max_parallel.integer' => 'Eşzamanlılık değeri tam sayı olmalıdır.',
            'max_parallel.between' => 'Eşzamanlılık değeri 1 ile 5 arasında olmalıdır.',
            'max_concurrent_calls.required' => 'Eşzamanlı model çağrısı sayısını seçin.',
            'max_concurrent_calls.integer' => 'Eşzamanlı model çağrısı sayısı tam sayı olmalıdır.',
            'max_concurrent_calls.between' => 'Eşzamanlı model çağrısı sayısı 1 ile 10 arasında olmalıdır.',
            'timeouts.array' => 'Süre sınırlarını geçerli biçimde girin.',
            'timeouts.*.integer' => 'Süre sınırı dakika cinsinden tam sayı olmalıdır.',
            'timeouts.*.between' => 'Süre sınırı 1 ile 120 dakika arasında olmalıdır.',
            'confirmed_external.required' => 'Girdilerin ve şablon promptlarının seçili model sağlayıcılarına gönderilmesini onaylayın.',
            'confirmed_external.accepted' => 'Girdilerin ve şablon promptlarının seçili model sağlayıcılarına gönderilmesini onaylayın.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(WorkflowTemplateValidator $templateValidator): array
    {
        return [
            function (Validator $validator) use ($templateValidator): void {
                $this->validateTemplateInputs($validator, $templateValidator);
                $this->validateGroupSelection($validator);
                $this->validateMaxParallel($validator);
            },
        ];
    }

    /**
     * The validated start request in the shape `WorkflowRunner::start()` reads. Only the selected model mode's keys are
     * included; timeouts are converted from minutes to seconds.
     *
     * @return array{template_id: string, template_version: int, inputs: array<string, mixed>, model_mode: string, config?: string, models?: list<string>, go_models?: list<string>, routing_mode?: string, fixed_providers?: array<string, string>, max_parallel: int, max_concurrent_calls: int, timeouts: array{llm: ?int, code: ?int}}
     */
    public function workflowInput(): array
    {
        $data = $this->validated();
        $modelMode = (string) $data['model_mode'];

        return [
            'template_id' => $this->templateId(),
            'template_version' => (int) $data['template_version'],
            'inputs' => $this->inputValues ?? [],
            'model_mode' => $modelMode,
            ...match ($modelMode) {
                'custom' => [
                    'models' => array_values($data['models']),
                    'routing_mode' => (string) $data['routing_mode'],
                    'fixed_providers' => $data['routing_mode'] === 'fixed' ? (array) ($data['fixed_providers'] ?? []) : [],
                ],
                'opencode_go' => ['go_models' => array_values($data['go_models'])],
                default => ['config' => (string) $data['config']],
            },
            'max_parallel' => (int) $data['max_parallel'],
            'max_concurrent_calls' => (int) $data['max_concurrent_calls'],
            'timeouts' => [
                'llm' => $this->timeoutSeconds($data['timeouts']['llm'] ?? null),
                'code' => $this->timeoutSeconds($data['timeouts']['code'] ?? null),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $modelMode = $this->input('model_mode');
        $config = $this->input('config');

        $this->merge([
            'model_mode' => is_string($modelMode) && trim($modelMode) !== '' ? trim($modelMode) : ($modelMode ?? 'group'),
            'config' => is_string($config) ? trim($config) : $config,
            'models' => $this->normalizedModelList($this->input('models')),
            'go_models' => $this->normalizedModelList($this->input('go_models')),
            'routing_mode' => $this->input('routing_mode', 'auto'),
            'max_parallel' => $this->filled('max_parallel') ? $this->input('max_parallel') : 2,
            'max_concurrent_calls' => $this->filled('max_concurrent_calls') ? $this->input('max_concurrent_calls') : 2,
        ]);
    }

    /**
     * Converts the template's input fields one by one, so every field gets its own `inputs.<id>` error.
     */
    private function validateTemplateInputs(Validator $validator, WorkflowTemplateValidator $templateValidator): void
    {
        if ($this->workflowTemplate === null || $validator->errors()->hasAny(['template_version', 'inputs'])) {
            return;
        }

        $inputs = $this->input('inputs', []);
        $result = $templateValidator->validateInputs($this->workflowTemplate['template'], is_array($inputs) ? $inputs : []);
        $errors = $result['errors'];

        foreach ($result['values'] as $id => $value) {
            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                $errors['inputs.'.$id] = 'Geçerli UTF-8 düz metin kullanın.';
            }
        }

        foreach ($errors as $key => $message) {
            $validator->errors()->add($key, $message);
        }

        $this->inputValues = $errors === [] ? $result['values'] : null;
    }

    /**
     * A saved group must fit the workflow model limit and use only the providers a workflow can call.
     */
    private function validateGroupSelection(Validator $validator): void
    {
        if ($this->input('model_mode') !== 'group' || $validator->errors()->has('config')) {
            return;
        }

        $group = $this->selectedGroup();

        if ($group === null) {
            return;
        }

        $modelLimit = ModelSelection::WORKFLOW_MODEL_LIMIT;

        if ($group['modelCount'] > $modelLimit) {
            $validator->errors()->add('config', "Bu grupta {$group['modelCount']} model var; iş akışında en fazla {$modelLimit} model seçilebilir.");

            return;
        }

        foreach ($group['providers'] as $provider) {
            if (! in_array($provider, ModelSelection::WORKFLOW_PROVIDERS, true)) {
                $validator->errors()->add('config', "Bu gruptaki {$provider} sağlayıcısı iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).");

                return;
            }
        }
    }

    private function validateMaxParallel(Validator $validator): void
    {
        if ($validator->errors()->has('max_parallel')) {
            return;
        }

        $selectedModelCount = match ($this->input('model_mode')) {
            'custom' => count((array) $this->input('models', [])),
            'opencode_go' => count((array) $this->input('go_models', [])),
            'group' => $this->selectedGroup()['modelCount'] ?? 0,
            default => 0,
        };

        if ($selectedModelCount > 0 && (int) $this->input('max_parallel') > $selectedModelCount) {
            $validator->errors()->add('max_parallel', "Eşzamanlılık seçilen {$selectedModelCount} modelden fazla olamaz.");
        }
    }

    /**
     * @return array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}|null
     */
    private function selectedGroup(): ?array
    {
        $config = $this->input('config');

        foreach ($this->groupOptions as $option) {
            if ($option['value'] === $config) {
                return $option;
            }
        }

        return null;
    }

    private function templateId(): string
    {
        $template = $this->route('template');

        return is_string($template) ? $template : '';
    }

    private function timeoutSeconds(mixed $minutes): ?int
    {
        return $minutes === null || $minutes === '' ? null : ((int) $minutes) * 60;
    }

    /**
     * Trims and de-duplicates a list of model ids; anything that is not a list of strings is left for the rules to reject.
     */
    private function normalizedModelList(mixed $models): mixed
    {
        if (! is_array($models)) {
            return $models;
        }

        $models = array_map(fn (mixed $model): mixed => is_string($model) ? trim($model) : $model, array_values($models));

        return array_filter($models, 'is_string') === $models ? array_values(array_unique($models)) : $models;
    }
}
