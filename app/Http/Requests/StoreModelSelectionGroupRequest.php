<?php

namespace App\Http\Requests;

use App\Services\BenchmarkRunner;
use App\Services\ModelGroupStore;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModelSelectionGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $limit = BenchmarkRunner::CUSTOM_MODEL_LIMIT;

        return [
            'group_name' => ['required', 'string', 'max:63', 'regex:'.ModelGroupStore::NAME_PATTERN],
            'model_mode' => ['required', Rule::in(['custom', 'opencode_go'])],
            'models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'custom'), 'nullable', 'array', 'max:'.$limit],
            'models.*' => ['string', 'distinct', 'max:200'],
            'go_models' => [Rule::requiredIf(fn (): bool => $this->input('model_mode') === 'opencode_go'), 'nullable', 'array', 'max:'.$limit],
            'go_models.*' => ['string', 'distinct', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['group_name' => ModelGroupStore::normalizeName($this->string('group_name')->toString())]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'group_name.required' => 'Seçimi kaydetmek için bir grup adı yazın.',
            'group_name.regex' => 'Grup adı en az 2 harf veya rakam içermelidir.',
            'model_mode.in' => 'Grup olarak yalnızca katalogdan yapılan seçim kaydedilebilir.',
            'models.required' => 'Kaydetmek için OpenRouter kataloğundan en az bir model seçin.',
            'go_models.required' => 'Kaydetmek için OpenCode Go kataloğundan en az bir model seçin.',
        ];
    }

    /**
     * The selection as provider-prefixed model ids, as they are written into a group file.
     *
     * @return list<string>
     */
    public function models(): array
    {
        return $this->validated('model_mode') === 'opencode_go'
            ? array_map(fn (string $model): string => 'opencode-go/'.$model, array_values($this->validated('go_models') ?? []))
            : array_map(fn (string $model): string => 'openrouter/'.$model, array_values($this->validated('models') ?? []));
    }
}
