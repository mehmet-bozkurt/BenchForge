<?php

namespace App\Http\Requests;

use App\Services\PromptLibrary;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePromptRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'min:'.PromptLibrary::MIN_LENGTH, 'max:'.PromptLibrary::MAX_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Başlık zorunludur.',
            'title.max' => 'Başlık en fazla 120 karakter olabilir.',
            'content.required' => 'Prompt metnini yazın.',
            'content.min' => 'Prompt en az '.PromptLibrary::MIN_LENGTH.' karakter olmalıdır.',
            'content.max' => 'Prompt en fazla '.number_format(PromptLibrary::MAX_LENGTH, 0, ',', '.').' karakter olabilir.',
        ];
    }
}
