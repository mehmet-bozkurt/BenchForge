<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ str($modelRun->model)->after('/') }} benchmark önizlemesi">

        <title>{{ str($modelRun->model)->after('/') }} · {{ $run->name }} · BenchForge</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-screen overflow-hidden bg-[#0b1020]">
        <main class="flex h-screen min-h-0 flex-col" data-standalone-preview>
            <header class="flex shrink-0 flex-wrap items-center gap-3 border-b border-white/10 bg-[#101426] px-4 py-3 text-white sm:px-5">
                <a class="inline-flex items-center gap-2 text-xs font-extrabold text-[#aab4cc] transition hover:text-white" href="{{ route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id, 'right' => 0, 'path' => $selectedRoute]) }}">
                    <span aria-hidden="true">←</span>
                    Karşılaştırmaya dön
                </a>

                <span class="hidden h-5 w-px bg-white/10 sm:block" aria-hidden="true"></span>

                <div class="min-w-0 sm:mr-auto">
                    <p class="truncate text-sm font-black">{{ str($modelRun->model)->after('/') }}</p>
                    <p class="truncate text-[10px] text-[#8995af]">{{ $run->name }}</p>
                </div>

                <nav class="flex h-9 items-center overflow-hidden rounded-lg border border-white/10 bg-white/5" aria-label="Model sonuçları arasında gezinme">
                    @if ($previousModel !== null)
                        <a
                            class="grid h-full w-10 place-items-center text-base font-black text-[#aab4cc] transition hover:bg-white/10 hover:text-white"
                            href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $previousModel, 'path' => $selectedRoute]) }}"
                            rel="prev"
                            aria-label="Önceki model: {{ str($previousModel->model)->after('/') }}"
                            title="Önceki: {{ str($previousModel->model)->after('/') }}"
                        >←</a>
                    @else
                        <span class="grid h-full w-10 cursor-not-allowed place-items-center text-base text-white/25" aria-disabled="true">←</span>
                    @endif

                    <span class="min-w-14 border-x border-white/10 px-2 text-center text-[10px] font-extrabold tracking-[0.08em] text-[#aab4cc]" aria-label="{{ $modelCount }} modelden {{ $currentPosition }}. model">
                        {{ $currentPosition }} / {{ $modelCount }}
                    </span>

                    @if ($nextModel !== null)
                        <a
                            class="grid h-full w-10 place-items-center text-base font-black text-[#aab4cc] transition hover:bg-white/10 hover:text-white"
                            href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $nextModel, 'path' => $selectedRoute]) }}"
                            rel="next"
                            aria-label="Sonraki model: {{ str($nextModel->model)->after('/') }}"
                            title="Sonraki: {{ str($nextModel->model)->after('/') }}"
                        >→</a>
                    @else
                        <span class="grid h-full w-10 cursor-not-allowed place-items-center text-base text-white/25" aria-disabled="true">→</span>
                    @endif
                </nav>

                <span class="status-badge status-{{ $modelRun->status }}">
                    <span class="status-dot"></span>
                    İlk teslim · {{ $modelRun->status === 'pass' ? 'Başarılı' : 'Sorunlu' }}
                </span>

                @if ($hasInitialPreview && $hasRecoveredPreview)
                    <nav class="flex h-9 items-center overflow-hidden rounded-lg border border-white/10 bg-white/5 text-[10px] font-extrabold" aria-label="Önizleme sürümü">
                        <a
                            class="grid h-full place-items-center px-3 transition {{ $showingRecovered ? 'text-[#aab4cc] hover:bg-white/10 hover:text-white' : 'bg-white/15 text-white' }}"
                            href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'path' => $selectedRoute]) }}"
                            @if (! $showingRecovered) aria-current="page" @endif
                        >İlk teslim · puanlanan</a>
                        <a
                            class="grid h-full place-items-center border-l border-white/10 px-3 transition {{ $showingRecovered ? 'bg-white/15 text-white' : 'text-[#aab4cc] hover:bg-white/10 hover:text-white' }}"
                            href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'path' => $selectedRoute, 'version' => 'recovered']) }}"
                            @if ($showingRecovered) aria-current="page" @endif
                        >Kurtarılmış{{ $recoveredAttempt ? ' · Deneme '.$recoveredAttempt->attempt_number : '' }}</a>
                    </nav>
                @endif

                @if ($canEvaluate)
                    <button class="h-9 rounded-lg border border-[#67e8f9]/30 bg-[#67e8f9]/10 px-3 text-[10px] font-extrabold text-[#a5f3fc] transition hover:border-[#67e8f9] hover:bg-[#67e8f9]/15" type="button" data-evaluation-toggle aria-controls="evaluation-panel" aria-expanded="false">
                        {{ $evaluation ? $evaluation->overall_score.'/10 · Düzenle' : 'Değerlendir' }}
                    </button>
                @endif

                <form class="flex items-center gap-2" method="GET" action="{{ route('runs.models.preview', [$run, $modelRun]) }}">
                    @if ($showingRecovered && $hasInitialPreview)
                        <input type="hidden" name="version" value="recovered">
                    @endif
                    <label class="sr-only" for="preview-route">Sayfa rotası</label>
                    <select class="h-9 max-w-52 rounded-lg border border-white/10 bg-white/6 px-3 font-mono text-[11px] font-bold text-white outline-none focus:border-[#67e8f9]" id="preview-route" name="path">
                        @foreach ($availableRoutes as $routePath)
                            <option class="bg-[#101426]" value="{{ $routePath }}" @selected($selectedRoute === $routePath)>{{ $routePath }}</option>
                        @endforeach
                    </select>
                    <button class="h-9 rounded-lg bg-[#6366f1] px-3 text-[10px] font-extrabold text-white transition hover:bg-[#4f46e5]" type="submit">Rotayı aç</button>
                </form>
            </header>

            @if ($showingRecovered)
                <p class="shrink-0 border-b border-amber-300/30 bg-amber-300/10 px-4 py-2 text-[11px] font-bold text-amber-100 sm:px-5" role="note">
                    Bu görünüm{{ $recoveredAttempt ? ' Deneme '.$recoveredAttempt->attempt_number.' ile' : '' }} kurtarılmış çıktıdır ve yalnız inceleme içindir; puanlanmaz. Manuel puan ve liderlik yalnız ilk teslime aittir.
                    @unless ($hasInitialPreview)
                        İlk teslimde görüntülenebilir çıktı olmadığı için bu model için puanlama kapalıdır.
                    @endunless
                </p>
            @endif

            <div class="relative min-h-0 flex-1 bg-[#e9edf5] p-2 sm:p-3">
                <iframe
                    class="h-full w-full rounded-lg border border-black/10 bg-white shadow-[0_18px_50px_rgb(15_23_42/0.18)]"
                    src="{{ $previewUrl }}"
                    title="{{ str($modelRun->model)->after('/') }} {{ $showingRecovered ? 'kurtarılmış çıktı' : 'ilk teslim' }} önizlemesi"
                    referrerpolicy="no-referrer"
                    sandbox="allow-scripts"
                ></iframe>

                @if ($canEvaluate)
                <button class="absolute inset-0 z-10 hidden cursor-default bg-[#0b1020]/35 backdrop-blur-[1px]" type="button" data-evaluation-overlay aria-label="Değerlendirme panelini kapat"></button>

                <aside
                    class="absolute inset-y-0 right-0 z-20 hidden w-full max-w-[440px] flex-col overflow-y-auto border-l border-white/10 bg-[#f8f9fd] shadow-[-20px_0_60px_rgb(15_23_42/0.24)]"
                    id="evaluation-panel"
                    data-evaluation-panel
                    data-evaluation-open="{{ $openEvaluationPanel ? 'true' : 'false' }}"
                    aria-label="Manuel değerlendirme"
                >
                    <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-[var(--line)] bg-white/95 px-5 py-4 backdrop-blur">
                        <div>
                            <p class="eyebrow">Manuel değerlendirme</p>
                            <h2 class="mt-1 text-base font-black">{{ str($modelRun->model)->after('/') }}</h2>
                            <p class="mt-1 text-[10px] text-[var(--muted)]">İlk teslim çıktısını her ölçütte 1–10 arasında puanlayın.</p>
                        </div>
                        <button class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-[var(--line)] bg-white text-sm font-black text-[var(--muted)] transition hover:border-[var(--forest)] hover:text-[var(--forest)]" type="button" data-evaluation-close aria-label="Değerlendirme panelini kapat">×</button>
                    </header>

                    <div class="p-5">
                        @if (session('status'))
                            <div class="mb-4 rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] px-4 py-3 text-xs font-bold text-[var(--success)]" role="status">
                                {{ session('status') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="mb-4 rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] px-4 py-3 text-xs text-[var(--red)]" role="alert">
                                <strong class="font-black">Değerlendirme kaydedilemedi.</strong>
                                <ul class="mt-2 grid gap-1 pl-4">
                                    @foreach ($errors->all() as $error)
                                        <li class="list-disc">{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form class="grid gap-4" method="POST" action="{{ route('runs.models.evaluation.update', [$run, $modelRun]) }}" data-evaluation-form>
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="return_path" value="{{ $selectedRoute }}">

                            <div class="flex items-center justify-between rounded-xl border border-[#d7ccff] bg-[var(--violet-soft)] px-4 py-3">
                                <div>
                                    <p class="text-[10px] font-extrabold tracking-[0.1em] text-[var(--violet)] uppercase">Kriter ortalaması</p>
                                    <p class="mt-1 text-[10px] text-[var(--muted)]">Yedi ölçütün canlı ortalaması</p>
                                </div>
                                <strong class="text-2xl font-black text-[var(--violet)]" data-criteria-average>5,0</strong>
                            </div>

                            @foreach ($evaluationCriteria as $field => $criterion)
                                @php($score = (int) old($field, $evaluation?->{$field} ?? 5))
                                <label class="block rounded-xl border border-[var(--line)] bg-white p-4" for="{{ $field }}">
                                    <span class="flex items-start justify-between gap-4">
                                        <span>
                                            <strong class="block text-xs">{{ $criterion['label'] }}</strong>
                                            <span class="mt-1 block text-[10px] leading-4 text-[var(--muted)]">{{ $criterion['description'] }}</span>
                                        </span>
                                        <output class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[var(--violet-soft)] text-sm font-black text-[var(--violet)]" for="{{ $field }}" data-score-value>{{ $score }}</output>
                                    </span>
                                    <input class="mt-3 h-2 w-full cursor-pointer accent-[#6366f1]" id="{{ $field }}" name="{{ $field }}" type="range" min="1" max="10" step="1" value="{{ $score }}" data-score-input data-criterion-score>
                                    <span class="mt-1 flex justify-between text-[9px] font-bold text-[var(--muted)]"><span>1 · Zayıf</span><span>10 · Üstün</span></span>
                                </label>
                            @endforeach

                            @php($overallScore = (int) old('overall_score', $evaluation?->overall_score ?? 5))
                            <label class="block rounded-xl border border-[#c7d2fe] bg-[#eef2ff] p-4" for="overall_score">
                                <span class="flex items-start justify-between gap-4">
                                    <span>
                                        <strong class="block text-xs">Genel değerlendirme</strong>
                                        <span class="mt-1 block text-[10px] leading-4 text-[var(--muted)]">Sayısal ortalamadan bağımsız nihai kararınız</span>
                                    </span>
                                    <output class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--forest)] text-sm font-black text-white" for="overall_score" data-score-value>{{ $overallScore }}</output>
                                </span>
                                <input class="mt-3 h-2 w-full cursor-pointer accent-[#4f46e5]" id="overall_score" name="overall_score" type="range" min="1" max="10" step="1" value="{{ $overallScore }}" data-score-input>
                            </label>

                            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.08em] text-[var(--muted)] uppercase" for="issue_severity">
                                Hata şiddeti
                                <select class="h-11 rounded-lg border border-[var(--line)] bg-white px-3 text-xs font-bold tracking-normal text-[var(--ink)] normal-case outline-none focus:border-[var(--forest)]" id="issue_severity" name="issue_severity">
                                    @foreach (['none' => 'Hata gözlenmedi', 'minor' => 'Küçük hata', 'major' => 'Önemli hata', 'critical' => 'Kritik hata'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('issue_severity', $evaluation?->issue_severity ?? 'none') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.08em] text-[var(--muted)] uppercase" for="notes">
                                İnceleme notları
                                <textarea class="min-h-28 resize-y rounded-lg border border-[var(--line)] bg-white px-3 py-3 text-xs font-medium leading-5 tracking-normal text-[var(--ink)] normal-case outline-none placeholder:text-[#98a2b3] focus:border-[var(--forest)]" id="notes" name="notes" maxlength="5000" placeholder="Güçlü yönler, eksikler ve dikkat çeken davranışlar…">{{ old('notes', $evaluation?->notes) }}</textarea>
                            </label>

                            <div class="sticky bottom-0 -mx-5 -mb-5 mt-1 border-t border-[var(--line)] bg-white/95 px-5 py-4 backdrop-blur">
                                <button class="w-full rounded-lg bg-[var(--forest)] px-4 py-3 text-xs font-extrabold text-white shadow-[0_12px_26px_rgb(79_70_229/0.22)] transition hover:bg-[#4338ca]" type="submit">
                                    {{ $evaluation ? 'Değerlendirmeyi güncelle' : 'Değerlendirmeyi kaydet' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </aside>
                @endif
            </div>
        </main>
    </body>
</html>
