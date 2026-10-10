<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSystemSettingsRequest extends FormRequest
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
            'root_path' => ['required', 'string', 'max:4096'],
            'node_binary' => ['required', 'string', 'max:4096'],
            'shell_binary' => ['required', 'string', 'max:4096'],
            'command_path' => ['required', 'string', 'max:16384'],
            'home_path' => ['required', 'string', 'max:4096'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['root_path', 'node_binary', 'shell_binary', 'command_path', 'home_path', '_token', '_method'];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add((string) $field, 'Bu sistem ayarı değiştirilemez.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.required' => 'Bu sistem ayarı zorunludur.',
            '*.string' => 'Sistem ayarı metin olmalıdır.',
            '*.max' => 'Sistem ayarı izin verilen uzunluğu aşıyor.',
        ];
    }
}
