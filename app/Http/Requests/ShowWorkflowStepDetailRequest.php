<?php

namespace App\Http\Requests;

use App\Services\WorkflowRunFiles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowWorkflowStepDetailRequest extends FormRequest
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
            'model' => ['required', 'string', 'regex:'.WorkflowRunFiles::MODEL_SLUG_PATTERN],
            'step' => ['required', 'string', 'regex:'.WorkflowRunFiles::STEP_ID_PATTERN],
            'item' => ['nullable', 'required_with:child', 'string', 'regex:'.WorkflowRunFiles::ITEM_KEY_PATTERN],
            'child' => ['nullable', 'required_with:item', 'string', 'regex:'.WorkflowRunFiles::STEP_ID_PATTERN],
        ];
    }

    /**
     * @return array{model: string, step: string, item: ?string, child: ?string}
     */
    public function stepReference(): array
    {
        $data = $this->validated();

        return [
            'model' => $data['model'],
            'step' => $data['step'],
            'item' => $data['item'] ?? null,
            'child' => $data['child'] ?? null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'model.required' => 'Bir model seçin.',
            'model.regex' => 'Geçerli bir model seçin.',
            'step.required' => 'Bir adım seçin.',
            'step.regex' => 'Geçerli bir adım seçin.',
            'item.required_with' => 'Alt adım için bir öğe seçin.',
            'item.regex' => 'Geçerli bir öğe seçin.',
            'child.required_with' => 'Öğe için bir alt adım seçin.',
            'child.regex' => 'Geçerli bir alt adım seçin.',
            '*.string' => 'Adım ayrıntısı için geçerli bir değer gönderin.',
        ];
    }
}
