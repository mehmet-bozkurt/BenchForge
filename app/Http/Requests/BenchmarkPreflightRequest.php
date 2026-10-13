<?php

namespace App\Http\Requests;

use App\Services\BenchmarkRunner;
use App\Services\OpenRouterProviderRouting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BenchmarkPreflightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(BenchmarkRunner $benchmarkRunner): array
    {
        return [
            'model_mode' => ['required', Rule::in(['group', 'custom', 'opencode_go'])],
            'config' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'group'), 'nullable', 'string', Rule::in(array_column($benchmarkRunner->options()['configs'], 'value'))],
            'models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'custom'), 'nullable', 'array', 'min:1', 'max:'.BenchmarkRunner::CUSTOM_MODEL_LIMIT],
            'models.*' => [
                'string',
                'distinct',
                Rule::exists('open_router_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'go_models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'opencode_go'), 'nullable', 'array', 'min:1', 'max:'.BenchmarkRunner::CUSTOM_MODEL_LIMIT],
            'go_models.*' => [
                'string',
                'distinct',
                Rule::exists('open_code_go_models', 'model_id')->where(fn ($query) => $query
                    ->where('available', true)
                    ->where('agent_compatible', true)),
            ],
            'routing_mode' => ['exclude_unless:model_mode,custom', 'required', Rule::in(OpenRouterProviderRouting::MODES)],
            'fixed_providers' => ['exclude_unless:model_mode,custom', Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'custom' && $this->input('routing_mode') === 'fixed'), 'nullable', 'array', 'max:'.BenchmarkRunner::CUSTOM_MODEL_LIMIT,
                function (string $attribute, mixed $value, Closure $fail): void {
                    foreach ((array) $value as $modelId => $slug) {
                        if (! is_string($modelId) || ! is_string($slug) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', $slug) !== 1) {
                            $fail('Geçerli bir sabit sağlayıcı seçin.');
                            break;
                        }
                    }
                }],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'model_mode' => $this->string('model_mode', 'group')->trim()->toString() ?: 'group',
            'config' => $this->string('config')->trim()->toString(),
            'routing_mode' => $this->input('routing_mode', 'auto'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'config.required' => 'Model grubu seçin.',
            'config.in' => 'Listeden geçerli bir model grubu seçin.',
            'models.required' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.min' => 'OpenRouter kataloğundan en az bir model seçin.',
            'models.max' => 'Tek koşuda en fazla '.BenchmarkRunner::CUSTOM_MODEL_LIMIT.' özel model seçebilirsiniz.',
            'models.*.exists' => 'Seçilen modellerden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'go_models.required' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.min' => 'OpenCode Go kataloğundan en az bir model seçin.',
            'go_models.max' => 'Tek koşuda en fazla '.BenchmarkRunner::CUSTOM_MODEL_LIMIT.' OpenCode Go modeli seçebilirsiniz.',
            'go_models.*.exists' => 'Seçilen OpenCode Go modellerinden biri artık kullanılamıyor. Kataloğu yenileyin.',
            'routing_mode.in' => 'Geçerli bir OpenRouter sağlayıcı tercihi seçin.',
            'routing_mode.required' => 'OpenRouter sağlayıcı tercihini seçin.',
            'fixed_providers.required' => 'Seçilen her model için bir sabit sağlayıcı seçin.',
        ];
    }

    /** @return list<string> */
    public function selectedModels(): array
    {
        return array_values((array) $this->input('models', []));
    }

    /** @return list<string> */
    public function selectedGoModels(): array
    {
        return array_values((array) $this->input('go_models', []));
    }
}
