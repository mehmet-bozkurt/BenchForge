<?php

namespace App\Http\Requests;

use App\Models\PreparationPackage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreparationEvaluationRequest extends FormRequest
{
    /**
     * Validation redirects flash input from the original request, not the FormRequest clone.
     */
    protected function prepareForValidation(): void
    {
        $package = $this->route('preparationPackage');

        if ($package instanceof PreparationPackage) {
            $this->merge(['review_package_id' => $package->id]);
            $this->container->make('request')->merge(['review_package_id' => $package->id]);
        }
    }

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
            ...array_fill_keys(array_keys(PreparationPackage::EVALUATION_LABELS), ['required', 'integer', 'between:1,10']),
            'review_notes' => ['nullable', 'string', 'max:5000'],
            'issue_severity' => ['required', Rule::in(['none', 'minor', 'major', 'critical'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.required' => ':attribute alanını doldurun.',
            '*.integer' => ':attribute puanı tam sayı olmalıdır.',
            '*.between' => ':attribute puanı 1 ile 10 arasında olmalıdır.',
            'issue_severity.in' => 'Geçerli bir sorun seviyesi seçin.',
            'review_notes.max' => 'İnceleme notu en fazla 5000 karakter olabilir.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...PreparationPackage::EVALUATION_LABELS, 'issue_severity' => 'Sorun seviyesi'];
    }
}
