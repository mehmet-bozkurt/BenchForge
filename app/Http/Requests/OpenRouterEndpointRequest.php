<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenRouterEndpointRequest extends FormRequest
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
            'refresh' => ['sometimes', 'boolean'],
            'model' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*(?:\/[A-Za-z0-9][A-Za-z0-9._:-]*)+$/',
                Rule::exists('open_router_models', 'model_id')->where(fn ($query) => $query->where('available', true)->where('agent_compatible', true))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['model.required' => 'Bir OpenRouter modeli seçin.', 'model.exists' => 'Model katalogda bulunamadı veya araç desteği sunmuyor.'];
    }
}
