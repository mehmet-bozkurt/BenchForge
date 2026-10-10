<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModelEvaluationRequest extends FormRequest
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
        $benchmarkRun = $this->route('benchmarkRun');
        $routes = is_object($benchmarkRun) && is_array($benchmarkRun->routes)
            ? $benchmarkRun->routes
            : ['/'];

        return [
            'visual_design_score' => ['required', 'integer', 'between:1,10'],
            'user_experience_score' => ['required', 'integer', 'between:1,10'],
            'task_completion_score' => ['required', 'integer', 'between:1,10'],
            'functionality_score' => ['required', 'integer', 'between:1,10'],
            'responsive_quality_score' => ['required', 'integer', 'between:1,10'],
            'content_quality_score' => ['required', 'integer', 'between:1,10'],
            'consistency_score' => ['required', 'integer', 'between:1,10'],
            'overall_score' => ['required', 'integer', 'between:1,10'],
            'issue_severity' => ['required', Rule::in(['none', 'minor', 'major', 'critical'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'return_path' => ['nullable', 'string', Rule::in($routes === [] ? ['/'] : $routes)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required' => ':attribute alanına puan verin.',
            '*.integer' => ':attribute puanı tam sayı olmalıdır.',
            '*.between' => ':attribute puanı 1 ile 10 arasında olmalıdır.',
            'issue_severity.in' => 'Geçerli bir hata seviyesi seçin.',
            'notes.max' => 'İnceleme notu en fazla 5000 karakter olabilir.',
            'return_path.in' => 'Geçerli bir benchmark rotası seçin.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'visual_design_score' => 'Görsel tasarım',
            'user_experience_score' => 'Kullanıcı deneyimi',
            'task_completion_score' => 'Görev tamamlama',
            'functionality_score' => 'İşlevsellik',
            'responsive_quality_score' => 'Responsive kalite',
            'content_quality_score' => 'İçerik kalitesi',
            'consistency_score' => 'Tutarlılık',
            'overall_score' => 'Genel değerlendirme',
        ];
    }
}
