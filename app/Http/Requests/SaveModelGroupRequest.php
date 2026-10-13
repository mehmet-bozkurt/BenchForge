<?php

namespace App\Http\Requests;

use App\Services\ModelGroupStore;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveModelGroupRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:63', 'regex:'.ModelGroupStore::NAME_PATTERN],
            'models' => ['required', 'string', 'max:8000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Grup adı zorunludur.',
            'name.regex' => 'Grup adı 2–63 karakter olmalı; yalnızca küçük harf, sayı ve tire kullanın.',
            'models.required' => 'En az bir model yazın.',
        ];
    }

    /**
     * One model per line; blank lines and surrounding spaces are ignored.
     *
     * @return list<string>
     */
    public function models(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->validated('models')) ?: [])));
    }
}
