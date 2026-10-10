<?php

namespace App\Http\Requests;

use App\Services\BenchmarkRunner;
use App\Services\OpenRouterProviderRouting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBenchmarkExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(BenchmarkRunner $benchmarkRunner): array
    {
        $options = $benchmarkRunner->options();

        return [
            'run_id' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]+$/', 'unique:benchmark_executions,run_id'],
            'prompt_mode' => ['required', Rule::in(['saved', 'custom'])],
            'prompt' => [Rule::requiredIf(fn (): bool => $this->input('prompt_mode') === 'saved'), 'nullable', 'string', Rule::in(array_column($options['prompts'], 'value'))],
            'custom_prompt_content' => [Rule::requiredIf(fn (): bool => $this->input('prompt_mode') === 'custom'), 'nullable', 'string', 'min:20', 'max:60000'],
            'custom_prompt_title' => [Rule::requiredIf(fn (): bool => $this->input('prompt_mode') === 'custom' && $this->boolean('save_prompt')), 'nullable', 'string', 'max:120'],
            'save_prompt' => ['nullable', 'boolean'],
            'model_mode' => ['required', Rule::in(['group', 'custom', 'opencode_go'])],
            'config' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'group'), 'nullable', 'string', Rule::in(array_column($options['configs'], 'value'))],
            'models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'custom'), 'nullable', 'array', 'min:1', 'max:5'],
            'models.*' => [
                'string',
                'distinct',
                Rule::exists('open_router_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'go_models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'opencode_go'), 'nullable', 'array', 'min:1', 'max:5'],
            'go_models.*' => [
                'string',
                'distinct',
                Rule::exists('open_code_go_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'routing_mode' => ['exclude_unless:model_mode,custom', 'required', Rule::in(OpenRouterProviderRouting::MODES)],
            'fixed_providers' => ['exclude_unless:model_mode,custom', Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'custom' && $this->input('routing_mode') === 'fixed'), 'nullable', 'array', 'max:5',
                function (string $attribute, mixed $value, Closure $fail): void {
                    foreach ((array) $value as $modelId => $slug) {
                        if (! is_string($modelId) || ! is_string($slug) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', $slug) !== 1) {
                            $fail('Geçerli bir sabit sağlayıcı seçin.');
                            break;
                        }
                    }
                }],
            'max_parallel' => ['required', 'integer', 'between:1,5'],
            'routes' => ['required', 'string', 'max:4000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'run_id.required' => 'Koşu adı zorunludur.',
            'run_id.min' => 'Koşu adı en az 3 karakter olmalıdır.',
            'run_id.max' => 'Koşu adı en fazla 64 karakter olabilir.',
            'run_id.regex' => 'Koşu adında yalnızca küçük harf, sayı ve tire kullanın.',
            'run_id.unique' => 'Bu koşu adı daha önce kullanılmış.',
            'prompt.required' => 'Görev promptu seçin.',
            'prompt.in' => 'Listeden geçerli bir görev promptu seçin.',
            'custom_prompt_content.required' => 'Özel görev promptunu yazın.',
            'custom_prompt_content.min' => 'Özel görev promptu en az 20 karakter olmalıdır.',
            'custom_prompt_content.max' => 'Özel görev promptu en fazla 60.000 karakter olabilir.',
            'custom_prompt_title.required' => 'Promptu kaydetmek için bir başlık yazın.',
            'custom_prompt_title.max' => 'Prompt başlığı en fazla 120 karakter olabilir.',
            'config.required' => 'Model grubu seçin.',
            'config.in' => 'Listeden geçerli bir model grubu seçin.',
            'models.required' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.min' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.max' => 'Tek koşuda en fazla 5 özel model seçebilirsiniz.',
            'models.*.exists' => 'Seçilen modellerden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'models.*.distinct' => 'Aynı model birden fazla seçilemez.',
            'go_models.required' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.min' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.max' => 'Tek koşuda en fazla 5 OpenCode Go modeli seçebilirsiniz.',
            'go_models.*.exists' => 'Seçilen OpenCode Go modellerinden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'go_models.*.distinct' => 'Aynı OpenCode Go modeli birden fazla seçilemez.',
            'routing_mode.in' => 'Geçerli bir OpenRouter sağlayıcı tercihi seçin.',
            'routing_mode.required' => 'OpenRouter sağlayıcı tercihini seçin.',
            'fixed_providers.required' => 'Seçilen her model için bir sabit sağlayıcı seçin.',
            'max_parallel.required' => 'Eşzamanlı çalışacak model sayısını seçin.',
            'max_parallel.integer' => 'Eşzamanlılık değeri tam sayı olmalıdır.',
            'max_parallel.between' => 'Eşzamanlılık değeri 1 ile 5 arasında olmalıdır.',
            'routes.required' => 'En az bir doğrulama rotası girin.',
            'routes.max' => 'Rota listesi çok uzun.',
        ];
    }

    /** @return list<string> */
    public function routeValues(): array
    {
        return collect(preg_split('/[\r\n,]+/', $this->string('routes')->toString()) ?: [])
            ->map(fn (string $route): string => trim($route))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function benchmarkInput(): array
    {
        return [
            'run_id' => $this->string('run_id')->toString(),
            'prompt_mode' => $this->string('prompt_mode')->toString(),
            'prompt' => $this->string('prompt')->toString(),
            'custom_prompt_title' => $this->string('custom_prompt_title')->toString(),
            'custom_prompt_content' => $this->string('custom_prompt_content')->toString(),
            'save_prompt' => $this->boolean('save_prompt'),
            'model_mode' => $this->string('model_mode')->toString(),
            'config' => $this->string('config')->toString(),
            'models' => array_values((array) $this->input('models', [])),
            'go_models' => array_values((array) $this->input('go_models', [])),
            ...($this->input('model_mode') === 'custom' ? [
                'routing_mode' => $this->input('routing_mode', 'auto'),
                'fixed_providers' => (array) $this->input('fixed_providers', []),
            ] : []),
            'max_parallel' => $this->integer('max_parallel'),
            'routes' => $this->routeValues(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $models = is_array($this->input('models'))
            ? array_values(array_unique(array_map(fn (mixed $model): string => trim((string) $model), $this->input('models'))))
            : $this->input('models');
        $goModels = is_array($this->input('go_models'))
            ? array_values(array_unique(array_map(fn (mixed $model): string => trim((string) $model), $this->input('go_models'))))
            : $this->input('go_models');

        $this->merge([
            'run_id' => $this->string('run_id')->trim()->lower()->toString(),
            'prompt_mode' => $this->string('prompt_mode', 'saved')->trim()->toString() ?: 'saved',
            'prompt' => $this->string('prompt')->trim()->toString(),
            'custom_prompt_title' => $this->string('custom_prompt_title')->trim()->toString(),
            'custom_prompt_content' => $this->string('custom_prompt_content')->trim()->toString(),
            'model_mode' => $this->string('model_mode', 'group')->trim()->toString() ?: 'group',
            'config' => $this->string('config')->trim()->toString(),
            'models' => $models,
            'go_models' => $goModels,
            'routing_mode' => $this->input('routing_mode', 'auto'),
            'max_parallel' => $this->integer('max_parallel', 2),
        ]);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('routes')) {
                    return;
                }

                $routes = $this->routeValues();

                if ($routes === [] || count($routes) > 20) {
                    $validator->errors()->add('routes', '1–20 arasında rota girin.');

                    return;
                }

                foreach ($routes as $route) {
                    if (mb_strlen($route) > 180 || preg_match("/^\/[A-Za-z0-9\-._~!$&'()*+,;=:@%\/]*$/", $route) !== 1 || str_contains($route, '//')) {
                        $validator->errors()->add('routes', "Geçersiz rota: {$route}");

                        return;
                    }
                }

                $modelMode = $this->string('model_mode')->toString();
                $selectedModelCount = match ($modelMode) {
                    'custom' => count((array) $this->input('models', [])),
                    'opencode_go' => count((array) $this->input('go_models', [])),
                    default => null,
                };

                if ($selectedModelCount !== null && $selectedModelCount > 0 && $this->integer('max_parallel') > $selectedModelCount) {
                    $validator->errors()->add('max_parallel', "Eşzamanlılık seçilen {$selectedModelCount} modelden fazla olamaz.");
                }
            },
        ];
    }
}
