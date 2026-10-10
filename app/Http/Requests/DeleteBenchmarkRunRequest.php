<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class DeleteBenchmarkRunRequest extends FormRequest
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
            'confirmation' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'Silme işlemini açılan onay penceresinden doğrulayın.',
            'confirmation.accepted' => 'Silme işlemini açılan onay penceresinden doğrulayın.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::notice('benchmark.storage.deletion.unconfirmed', [
            'route' => $this->route()?->getName(),
            'run_slug' => $this->route('benchmarkRun')?->slug,
        ]);

        parent::failedValidation($validator);
    }
}
