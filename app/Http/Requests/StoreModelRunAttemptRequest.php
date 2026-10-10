<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModelRunAttemptRequest extends FormRequest
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
            'mode' => ['required', Rule::in(['continue', 'validate'])],
            'acknowledge_cost' => [
                Rule::requiredIf(fn (): bool => $this->string('mode')->toString() === 'continue'),
                Rule::excludeIf(fn (): bool => $this->string('mode')->toString() !== 'continue'),
                'accepted',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mode.required' => 'Deneme türünü seçin.',
            'mode.in' => 'Geçerli bir deneme türü seçin.',
            'acknowledge_cost.required' => 'Devam işleminin yeni token ve ücret oluşturabileceğini onaylayın.',
            'acknowledge_cost.accepted' => 'Devam işleminin yeni token ve ücret oluşturabileceğini onaylayın.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mode' => $this->string('mode')->trim()->toString()]);
    }
}
