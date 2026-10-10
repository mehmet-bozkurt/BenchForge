<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePreparationComparisonRequest extends FormRequest
{
    private ?string $uploadedSpecification = null;

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
            'specification' => ['nullable', 'required_without:specification_file', 'prohibits:specification_file', 'string', 'min:50', 'max:100000'],
            'specification_file' => ['nullable', 'required_without:specification', 'prohibits:specification', 'file', 'mimes:txt', 'extensions:txt', 'max:1024'],
            'technologies' => ['required', 'string', 'min:5', 'max:5000'],
            'provider' => ['required', Rule::in(['openrouter', 'opencode-go'])],
            'models' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'models.*' => [
                'required', 'string', 'max:200', 'distinct',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/',
                Rule::exists($this->input('provider') === 'opencode-go' ? 'open_code_go_models' : 'open_router_models', 'model_id')
                    ->where('available', true)->where('agent_compatible', true),
            ],
            'confirmed_external' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'specification', 'technologies'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $this->normalizeText($this->input($field))]);
            }
        }
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['title', 'technologies'] as $field) {
                $value = $this->input($field);

                if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                    $validator->errors()->add($field, 'Geçerli UTF-8 düz metin kullanın.');
                }
            }

            if ($validator->errors()->hasAny(['specification', 'specification_file'])) {
                return;
            }

            $field = $this->hasFile('specification_file') ? 'specification_file' : 'specification';
            $text = $this->input('specification');

            if ($field === 'specification_file') {
                $path = $this->file('specification_file')?->getRealPath();
                $text = $path !== false && $path !== null ? @file_get_contents($path) : false;
            }

            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                $validator->errors()->add($field, 'Şartname geçerli UTF-8 düz metin olmalıdır. PDF dosyasını önce TXT olarak dönüştürün.');

                return;
            }

            $text = $this->normalizeText($text);

            if (mb_strlen($text) < 50 || mb_strlen($text) > 100000) {
                $validator->errors()->add($field, 'Şartname 50 ile 100000 karakter arasında olmalıdır.');

                return;
            }

            if ($field === 'specification_file') {
                $this->uploadedSpecification = $text;
            }
        }];
    }

    /** @return array{title: string, specification: string, technologies: string, provider: string, models: list<string>} */
    public function preparationInput(): array
    {
        $data = $this->validated();

        return [
            'title' => $data['title'],
            'specification' => $this->uploadedSpecification ?? $data['specification'],
            'technologies' => $data['technologies'],
            'provider' => $data['provider'],
            'models' => array_values($data['models']),
        ];
    }

    private function normalizeText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text), " \t\n\r\v");
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Karşılaştırma için bir ad yazın.',
            'title.max' => 'Karşılaştırma adı en fazla 120 karakter olabilir.',
            'specification.required_without' => 'Şartname metni yazın veya bir TXT dosyası yükleyin.',
            'specification_file.required_without' => 'Şartname metni yazın veya bir TXT dosyası yükleyin.',
            '*.prohibits' => 'Şartnameyi metin veya dosya olarak iletin; ikisini aynı anda kullanmayın.',
            'specification.min' => 'Şartname en az 50 karakter olmalıdır.',
            'specification.max' => 'Şartname en fazla 100000 karakter olabilir.',
            'specification_file.mimes' => 'Yalnız düz metin TXT dosyası yükleyin.',
            'specification_file.extensions' => 'Dosya uzantısı .txt olmalıdır.',
            'specification_file.max' => 'TXT dosyası en fazla 1 MB olabilir.',
            'technologies.required' => 'Kullanılacak teknolojileri ve teknik sınırları yazın.',
            'technologies.min' => 'Teknoloji bilgisi en az 5 karakter olmalıdır.',
            'technologies.max' => 'Teknoloji bilgisi en fazla 5000 karakter olabilir.',
            'provider.in' => 'OpenRouter veya OpenCode Go seçin.',
            'models.required' => 'Katalogdan en az bir model seçin.',
            'models.list' => 'Geçerli bir model listesi gönderin.',
            'models.min' => 'Katalogdan en az bir model seçin.',
            'models.max' => 'Bir karşılaştırmada en fazla 5 model seçilebilir.',
            'models.*.distinct' => 'Aynı modeli birden fazla seçmeyin.',
            'models.*.exists' => 'Seçilen model artık kullanılamıyor. Kataloğu kontrol edin.',
            'models.*.regex' => 'Katalogdan geçerli bir model seçin.',
            'confirmed_external.required' => 'Şartnamenin seçili model sağlayıcısına gönderilmesini onaylayın.',
            'confirmed_external.accepted' => 'Şartnamenin seçili model sağlayıcısına gönderilmesini onaylayın.',
        ];
    }
}
