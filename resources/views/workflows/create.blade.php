@extends('layouts.app')

@section('content')
@php
    $definition = $workflowTemplate['template'];
    $templateInputs = is_array($definition['inputs'] ?? null) ? $definition['inputs'] : [];
    $hasOldForm = session()->hasOldInput('template_version');
    $oldInputs = old('inputs');
    $oldInputs = is_array($oldInputs) ? $oldInputs : [];
    $stepTypeLabels = [
        'llm_text' => 'Metin yanıtı',
        'llm_json' => 'Yapılandırılmış JSON',
        'foreach' => 'Her öğe için',
        'code_text' => 'Kod · tek dosya',
        'code_agent' => 'Kod · araçlarla',
        'browser_check' => 'Tarayıcı testi',
    ];
    $codeSteps = [];

    foreach ($definition['steps'] as $step) {
        foreach ([$step, ...(($step['type'] ?? null) === 'foreach' ? ($step['steps'] ?? []) : [])] as $candidate) {
            if (in_array($candidate['type'] ?? null, ['code_text', 'code_agent'], true)) {
                $codeSteps[] = [
                    'id' => $candidate['id'],
                    'label' => $candidate['title'] ?? $candidate['id'],
                    'type' => $candidate['type'],
                    'parent' => $candidate === $step ? null : ($step['title'] ?? $step['id']),
                ];
            }
        }
    }

    $oldMaxParallel = filter_var(old('max_parallel', 2), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) ?: 2;
    $oldMaxConcurrentCalls = filter_var(old('max_concurrent_calls', 2), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]) ?: 2;
    $oldTimeouts = old('timeouts');
    $oldTimeouts = is_array($oldTimeouts) ? $oldTimeouts : [];
    $timeoutValue = fn (string $kind): string => is_scalar($oldTimeouts[$kind] ?? null) ? (string) $oldTimeouts[$kind] : '';
    $fieldClass = 'h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm font-medium outline-none focus:border-[var(--forest)]';
@endphp

<div class="content-frame">
    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="hover:text-[var(--forest)]" href="{{ route('workflows.index') }}">İş Akışları</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $definition['name'] }}</span>
    </nav>

    <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Yeni çalıştırma · {{ $workflowTemplate['source'] === 'builtin' ? 'Yerleşik' : 'Kullanıcı' }} şablon · Sürüm {{ $workflowTemplate['version'] }}</p>
            <h1 class="mt-2 break-words text-3xl font-black tracking-[-0.05em] sm:text-[38px]">{{ $definition['name'] }}</h1>
            @if (is_string($definition['description'] ?? null) && trim($definition['description']) !== '')
                <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">{{ $definition['description'] }}</p>
            @endif
        </div>
        <a class="inline-flex shrink-0 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('workflows.index') }}">Tüm iş akışları →</a>
    </header>

    @if (count($versions) > 1)
        <nav class="mt-5 flex flex-wrap items-center gap-2 text-[11px] font-bold" aria-label="Şablon sürümleri">
            <span class="text-[var(--muted)]">Sürüm:</span>
            @foreach ($versions as $availableVersion)
                <a @class([
                    'rounded-lg border px-2.5 py-1',
                    'border-[var(--forest)] bg-[var(--mint-soft)] text-[var(--forest)]' => $availableVersion['version'] === $workflowTemplate['version'],
                    'border-[var(--line)] text-[var(--muted)] hover:border-[var(--forest)]' => $availableVersion['version'] !== $workflowTemplate['version'],
                ]) href="{{ route('workflows.create', [$definition['id'], 'version' => $availableVersion['version']]) }}" @if ($availableVersion['version'] === $workflowTemplate['version']) aria-current="page" @endif>v{{ $availableVersion['version'] }} · {{ $availableVersion['source'] === 'builtin' ? 'yerleşik' : 'kullanıcı' }}</a>
            @endforeach
        </nav>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">İş akışı başlatılamadı.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($activeRun)
        <div class="mt-6 rounded-xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] px-4 py-3 text-xs leading-5 text-[var(--forest)]" role="status">
            <strong>Başka bir iş akışı sürüyor:</strong> {{ $activeRun->title }}. Aynı anda yalnızca bir iş akışı çalışır.
            <a class="ml-1 underline" href="{{ route('workflows.runs.show', $activeRun) }}">Canlı durumu aç</a>
        </div>
    @endif

    <div class="mt-7 grid gap-6 2xl:grid-cols-[minmax(0,1.2fr)_minmax(300px,0.8fr)]">
        <form class="runner-card min-w-0 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-7" method="POST" action="{{ route('workflows.runs.store', $definition['id']) }}" data-workflow-create>
            @csrf
            <input type="hidden" name="template_version" value="{{ $workflowTemplate['version'] }}">
            <fieldset class="grid min-w-0 gap-7" @disabled($activeRun)>
                <legend class="sr-only">İş akışı çalıştırma bilgileri</legend>

                <section class="grid gap-4" aria-labelledby="workflow-inputs-title">
                    <div>
                        <h2 id="workflow-inputs-title" class="text-xs font-extrabold">Girdiler</h2>
                        <p class="mt-1 text-[11px] leading-5 text-[var(--muted)]">Şablonun promptlarına yerleştirilecek değerler. Her seçilen model aynı girdilerle çalışır.</p>
                    </div>

                    @forelse ($templateInputs as $templateInput)
                        @php
                            $inputId = (string) $templateInput['id'];
                            $inputType = (string) $templateInput['type'];
                            $fieldId = 'workflow-input-'.$inputId;
                            $fieldName = 'inputs['.$inputId.']';
                            $isRequired = ($templateInput['required'] ?? true) !== false && $inputType !== 'boolean';
                            $defaultValue = $templateInput['default'] ?? ($inputType === 'boolean' ? false : '');
                            $oldValue = $oldInputs[$inputId] ?? null;
                            $fieldValue = $hasOldForm
                                ? (is_scalar($oldValue) ? (string) $oldValue : '')
                                : (is_scalar($defaultValue) && ! is_bool($defaultValue) ? (string) $defaultValue : '');
                            $isChecked = $hasOldForm ? filter_var($oldValue, FILTER_VALIDATE_BOOLEAN) : $defaultValue === true;
                            $hasError = $errors->has('inputs.'.$inputId);
                            $maxLength = $templateInput['maxLength'] ?? ($inputType === 'text' ? 8000 : 200);
                        @endphp
                        <div class="grid gap-2">
                            @if ($inputType === 'boolean')
                                <label class="flex items-start gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3 text-xs font-extrabold" for="{{ $fieldId }}">
                                    <input class="mt-0.5" id="{{ $fieldId }}" type="checkbox" name="{{ $fieldName }}" value="1" @checked($isChecked) @if ($hasError) aria-invalid="true" @endif>
                                    <span>{{ $templateInput['label'] }}</span>
                                </label>
                            @else
                                <label class="text-xs font-extrabold" for="{{ $fieldId }}">
                                    {{ $templateInput['label'] }}
                                    @unless ($isRequired)<span class="font-semibold text-[var(--muted)]">(isteğe bağlı)</span>@endunless
                                </label>
                                @if ($inputType === 'text')
                                    <textarea class="min-h-28 resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-3 text-sm leading-6 outline-none focus:border-[var(--forest)]" id="{{ $fieldId }}" name="{{ $fieldName }}" maxlength="{{ $maxLength }}" @if (isset($templateInput['minLength'])) minlength="{{ $templateInput['minLength'] }}" @endif @required($isRequired) @if ($hasError) aria-invalid="true" @endif>{{ $fieldValue }}</textarea>
                                @elseif ($inputType === 'select')
                                    <select class="{{ $fieldClass }}" id="{{ $fieldId }}" name="{{ $fieldName }}" @required($isRequired) @if ($hasError) aria-invalid="true" @endif>
                                        @unless ($isRequired)<option value="">Seçilmedi</option>@endunless
                                        @foreach ($templateInput['options'] ?? [] as $option)
                                            <option value="{{ $option['value'] }}" @selected($fieldValue === (string) $option['value'])>{{ $option['label'] ?? $option['value'] }}</option>
                                        @endforeach
                                    </select>
                                @elseif (in_array($inputType, ['integer', 'number'], true))
                                    <input class="{{ $fieldClass }} font-mono" id="{{ $fieldId }}" type="number" name="{{ $fieldName }}" value="{{ $fieldValue }}" step="{{ $inputType === 'integer' ? '1' : 'any' }}" inputmode="{{ $inputType === 'integer' ? 'numeric' : 'decimal' }}" @if (isset($templateInput['min'])) min="{{ $templateInput['min'] }}" @endif @if (isset($templateInput['max'])) max="{{ $templateInput['max'] }}" @endif @required($isRequired) @if ($hasError) aria-invalid="true" @endif>
                                @else
                                    <input class="{{ $fieldClass }}" id="{{ $fieldId }}" type="text" name="{{ $fieldName }}" value="{{ $fieldValue }}" maxlength="{{ $maxLength }}" @if (isset($templateInput['minLength'])) minlength="{{ $templateInput['minLength'] }}" @endif @required($isRequired) @if ($hasError) aria-invalid="true" @endif>
                                @endif
                            @endif
                            @if (is_string($templateInput['description'] ?? null) && $templateInput['description'] !== '')
                                <p class="text-[10px] leading-4 text-[var(--muted)]">{{ $templateInput['description'] }}</p>
                            @endif
                            @error('inputs.'.$inputId)
                                <p class="text-[10px] font-semibold text-[var(--red)]" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-[var(--line)] p-4 text-xs text-[var(--muted)]">Bu şablon girdi istemiyor.</p>
                    @endforelse
                </section>

                <x-model-picker :options="$pickerOptions" :limit="\App\Services\ModelSelection::WORKFLOW_MODEL_LIMIT" :group-error="$groupError" id="workflow-model-picker" />

                <section class="grid gap-4" aria-labelledby="workflow-settings-title">
                    <div>
                        <h2 id="workflow-settings-title" class="text-xs font-extrabold">Çalıştırma ayarları</h2>
                        <p class="mt-1 text-[11px] leading-5 text-[var(--muted)]">Ücretsiz modellerin dakika başına istek sınırına takılmamak için eşzamanlı çağrı sayısını düşük tutun.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-2 text-xs font-extrabold" for="workflow-max-parallel">
                            Aynı anda çalışacak model sayısı
                            <select class="{{ $fieldClass }} font-semibold" id="workflow-max-parallel" name="max_parallel" data-model-picker-parallel required>
                                @foreach ([1 => 'Sırayla', 2 => 'Dengeli, önerilen', 3 => 'Hızlı', 4 => 'Çok hızlı', 5 => 'Tüm modeller aynı anda'] as $parallelValue => $parallelLabel)
                                    <option value="{{ $parallelValue }}" @selected($oldMaxParallel === $parallelValue)>{{ $parallelValue }} · {{ $parallelLabel }}</option>
                                @endforeach
                            </select>
                            <span class="text-[10px] font-normal leading-4 text-[var(--muted)]">Seçilen model sayısından fazla olamaz.</span>
                        </label>
                        <label class="grid gap-2 text-xs font-extrabold" for="workflow-max-concurrent-calls">
                            Eşzamanlı model çağrısı
                            <select class="{{ $fieldClass }} font-semibold" id="workflow-max-concurrent-calls" name="max_concurrent_calls" required>
                                @foreach (range(1, 10) as $callLimit)
                                    <option value="{{ $callLimit }}" @selected($oldMaxConcurrentCalls === $callLimit)>{{ $callLimit }}{{ $callLimit === 2 ? ' · önerilen' : '' }}</option>
                                @endforeach
                            </select>
                            <span class="text-[10px] font-normal leading-4 text-[var(--muted)]">Bütün modellerin aynı anda yapabileceği toplam çağrı sayısı.</span>
                        </label>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-2 text-xs font-extrabold" for="workflow-timeout-llm">
                            Metin ve JSON adımları için süre sınırı
                            <input class="{{ $fieldClass }} font-mono" id="workflow-timeout-llm" type="number" name="timeouts[llm]" value="{{ $timeoutValue('llm') }}" min="1" max="120" step="1" inputmode="numeric" placeholder="Şablon varsayılanı">
                            <span class="text-[10px] font-normal leading-4 text-[var(--muted)]">Dakika, 1–120. Boş bırakılırsa şablonun süresi kullanılır.</span>
                        </label>
                        <label class="grid gap-2 text-xs font-extrabold" for="workflow-timeout-code">
                            Kod adımları için süre sınırı
                            <input class="{{ $fieldClass }} font-mono" id="workflow-timeout-code" type="number" name="timeouts[code]" value="{{ $timeoutValue('code') }}" min="1" max="120" step="1" inputmode="numeric" placeholder="Şablon varsayılanı">
                            <span class="text-[10px] font-normal leading-4 text-[var(--muted)]">Dakika, 1–120. Boş bırakılırsa şablonun süresi kullanılır.</span>
                        </label>
                    </div>
                </section>

                @if ($codeSteps !== [])
                    <section class="grid gap-3" aria-labelledby="workflow-code-steps-title">
                        <div>
                            <h2 id="workflow-code-steps-title" class="text-xs font-extrabold">Kod adımlarının çalışma biçimi</h2>
                            <p class="mt-1 text-[11px] leading-5 text-[var(--muted)]">Bu aşamada kod adımları “Tek seferde yaz (code_text)” biçiminde çalışır: model demoyu tek yanıtta yazar, BenchForge statik kontrolleri yapar ve gerekirse onarım ister. “Araçlarla yaz ve test et” biçimi Aşama 2’de gelecek.</p>
                        </div>
                        <ul class="grid gap-2">
                            @foreach ($codeSteps as $codeStep)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] px-3 py-2.5 text-xs">
                                    <span class="min-w-0">
                                        <strong class="break-words">{{ $codeStep['label'] }}</strong>
                                        <code class="ml-1 font-mono text-[10px] text-[var(--muted)]">{{ $codeStep['id'] }}</code>
                                        @if ($codeStep['parent'])
                                            <span class="block text-[10px] text-[var(--muted)]">{{ $codeStep['parent'] }} içinde, her öğe için</span>
                                        @endif
                                    </span>
                                    @if ($codeStep['type'] === 'code_text')
                                        <span class="rounded-lg bg-[var(--mint-soft)] px-2.5 py-1 text-[10px] font-black text-[var(--forest)]">Tek seferde yaz (code_text)</span>
                                    @else
                                        <span class="rounded-lg bg-[var(--amber-soft)] px-2.5 py-1 text-[10px] font-black text-[var(--amber-ink)]">Araçlarla yaz ve test et · Aşama 2’de etkinleşecek</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <label class="flex items-start gap-3 rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] p-4 text-xs leading-5 text-[var(--amber-ink)]">
                    <input class="mt-1" type="checkbox" name="confirmed_external" value="1" required @checked(old('confirmed_external'))>
                    <span><strong>Harici model çağrısını ve ücret oluşabileceğini onaylıyorum.</strong> Girdiler ve şablon promptları seçilen sağlayıcılar üzerinden modellere gönderilir. Her model iş akışının tüm adımlarını ayrı ayrı çalıştırır; ücretli modellerde her çağrı ek maliyet oluşturabilir.</span>
                </label>

                <button class="runner-primary-action flex min-h-12 w-full items-center justify-center rounded-xl px-5 text-sm font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-model-picker-submit @disabled($activeRun)>İş akışını başlat →</button>
            </fieldset>
        </form>

        <aside class="self-start rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="workflow-plan-title">
            <p class="eyebrow">Çalıştırma planı</p>
            <h2 id="workflow-plan-title" class="mt-2 text-xl font-black tracking-[-0.03em]">Ne olacak?</h2>
            <ol class="mt-5 grid gap-3 text-xs leading-5">
                @foreach ($definition['steps'] as $stepNumber => $step)
                    <li class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3">
                        <div class="flex items-center gap-3">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-[var(--violet-soft)] font-mono font-black text-[var(--violet)]">{{ $stepNumber + 1 }}</span>
                            <span class="min-w-0">
                                <strong class="block break-words">{{ $step['title'] ?? $step['id'] }}</strong>
                                <span class="text-[10px] text-[var(--muted)]">{{ $stepTypeLabels[$step['type'] ?? ''] ?? 'Adım' }}</span>
                            </span>
                        </div>
                        @if (($step['type'] ?? null) === 'foreach' && is_array($step['steps'] ?? null))
                            <ul class="mt-2 grid gap-1 pl-10 text-[11px] text-[var(--muted)]">
                                @foreach ($step['steps'] as $childStep)
                                    <li>↳ {{ $childStep['title'] ?? $childStep['id'] }} · {{ $stepTypeLabels[$childStep['type'] ?? ''] ?? 'Adım' }}{{ ($childStep['type'] ?? null) === 'browser_check' ? ' (Aşama 2’de)' : '' }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
            <div class="mt-5 grid gap-3 rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] p-4 text-[11px] leading-5 text-[var(--muted)]">
                <p>Seçilen her model bu adımları kendi başına, sırayla yürütür. “Her öğe için” adımları önceki adımın ürettiği her öğe için ayrı model çağrıları yapar; kategori ve fikir sayısı arttıkça çağrı sayısı ve süre de artar.</p>
                <p>Model çağrıları seçilen sağlayıcıya gider ve ücretli modellerde maliyet oluşturabilir. Token ve maliyet bilgisi sağlayıcı bildirdiği ölçüde gösterilir; bilinmeyen değerler sıfır sayılmaz.</p>
                <p>Başlattıktan sonra canlı sayfada adımları, günlüğü ve üretilen demoları izleyebilir, çalıştırmayı istediğiniz an durdurabilirsiniz. Tamamlanan adımlar korunur.</p>
            </div>
        </aside>
    </div>
</div>
@endsection
