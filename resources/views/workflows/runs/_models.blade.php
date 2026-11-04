{{--
    One tab per model: its summary, the step graph in template order, the calls running right now and the end of the
    model's last finished answer (snapshot models[].lastOutput). OpenCode reports text only when a reply part is done,
    so a running call usually has no text yet; the page says so instead of pretending to stream.
--}}
@php
    $tailPendingNote = 'OpenCode metni yalnız bir yanıt parçası tamamlanınca bildirir; uzun yanıtlarda metin çağrı bitince görünür.';
    $lastOutputKey = static fn (array $output): string => implode('|', array_map(
        static fn (mixed $part): string => is_string($part) ? $part : '',
        [$output['step'] ?? null, $output['child'] ?? null, $output['item'] ?? null, $output['finishedAt'] ?? null],
    ));
    $lastOutputText = static fn (?array $output): string => $output === null
        ? ''
        : (is_string($output['tail'] ?? null) && $output['tail'] !== '' ? $output['tail'] : 'Yanıt metni boş.');

    $activeCallTitle = static function (array $call, array $steps) use ($itemLabel): string {
        $step = null;

        foreach ($steps as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === ($call['step'] ?? null)) {
                $step = $candidate;
                break;
            }
        }

        $path = [($step['title'] ?? '') !== '' ? $step['title'] : ($call['step'] ?? 'Adım')];

        if (is_string($call['child'] ?? null) && $call['child'] !== '') {
            $childTitle = $call['child'];

            foreach (is_array($step['children'] ?? null) ? $step['children'] : [] as $child) {
                if (is_array($child) && ($child['id'] ?? null) === $call['child'] && ($child['title'] ?? '') !== '') {
                    $childTitle = $child['title'];
                }
            }

            $path[] = $childTitle;
        }

        $parts = [implode(' › ', $path)];
        $label = is_string($call['label'] ?? null) ? trim($call['label']) : '';

        if ($label !== '') {
            $parts[] = $label;
        } elseif (is_string($call['item'] ?? null) && $call['item'] !== '') {
            $parts[] = $itemLabel(['key' => $call['item']]);
        }

        if (is_int($call['attempt'] ?? null) && $call['attempt'] > 0) {
            $parts[] = 'Deneme '.$call['attempt'];
        }

        return implode(' · ', $parts);
    };
@endphp

<section class="mt-8" aria-labelledby="workflow-models-title">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="eyebrow">Model başına adımlar</p>
            <h2 id="workflow-models-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Adım akışı</h2>
            <p class="mt-1 max-w-3xl text-xs leading-5 text-[var(--muted)]">Her model iş akışını kendi başına yürütür. Bir adıma ya da öğeye tıklayınca prompt, çıktı ve denemeler yan panelde açılır.</p>
        </div>
    </div>

    @if ($runModels === [])
        <div class="rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] px-6 py-10 text-center">
            <h3 class="text-base font-black">Bu çalıştırmada model kaydı yok</h3>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">Modeller çalıştırma başlarken kaydedilir. Bu kayıt eksikse çalıştırma başlatılamamış olabilir.</p>
        </div>
    @else
        <div class="workflow-tabs" role="tablist" aria-label="Modeller">
            @foreach ($runModels as $model)
                <button class="workflow-tab" type="button" role="tab" id="workflow-tab-{{ $model['slug'] }}" aria-controls="workflow-panel-{{ $model['slug'] }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-workflow-model-tab="{{ $model['slug'] }}">
                    <span class="workflow-tab-name">{{ $modelNames[$model['slug']] }}</span>
                    <span class="status-badge status-{{ $statusTone($model['status'] ?? null) }}" data-workflow-tab-status><span class="status-dot"></span><span data-workflow-status-label>{{ $statusLabel($model['status'] ?? null) }}</span></span>
                </button>
            @endforeach
        </div>

        @foreach ($runModels as $model)
            @php
                $modelUsage = is_array($model['usage'] ?? null) ? $model['usage'] : [];
                $modelItems = is_array($model['items'] ?? null) ? $model['items'] : [];
                $modelSteps = array_values(array_filter(is_array($model['steps'] ?? null) ? $model['steps'] : [], static fn (mixed $step): bool => is_array($step) && is_string($step['id'] ?? null)));
                $modelCalls = array_values(array_filter(is_array($model['active'] ?? null) ? $model['active'] : [], static fn (mixed $call): bool => is_array($call) && is_string($call['step'] ?? null)));
                $modelMessage = is_string($model['message'] ?? null) ? $model['message'] : '';
                $lastOutput = is_array($model['lastOutput'] ?? null) && is_string($model['lastOutput']['step'] ?? null) ? $model['lastOutput'] : null;
            @endphp
            <section class="workflow-panel runner-card" role="tabpanel" id="workflow-panel-{{ $model['slug'] }}" aria-labelledby="workflow-tab-{{ $model['slug'] }}" data-workflow-model-panel="{{ $model['slug'] }}" @if (! $loop->first) hidden @endif>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="break-words text-lg font-black tracking-[-0.03em]">{{ $modelNames[$model['slug']] }}</h3>
                        <p class="mt-1 break-all font-mono text-[10px] text-[var(--muted)]">{{ $model['model'] ?? $model['slug'] }}</p>
                        <p @class(['mt-2 max-w-3xl break-words text-xs leading-5 text-[var(--red)]', 'hidden' => $modelMessage === '']) data-workflow-model-message>{{ $modelMessage }}</p>
                    </div>
                    <span class="status-badge status-{{ $statusTone($model['status'] ?? null) }}" data-workflow-model-status><span class="status-dot"></span><span data-workflow-status-label>{{ $statusLabel($model['status'] ?? null) }}</span></span>
                </div>

                <dl class="workflow-model-facts">
                    <div><dt>Süre</dt><dd data-workflow-model-metric="duration">{{ $formatDuration($model['durationSeconds'] ?? null) }}</dd></div>
                    <div><dt>Token</dt><dd data-workflow-model-metric="tokens">{{ $formatTokens($modelUsage['inputTokens'] ?? null, $modelUsage['outputTokens'] ?? null) }}</dd></div>
                    <div><dt>Maliyet</dt><dd data-workflow-model-metric="cost">{{ $formatCost($modelUsage['costUsd'] ?? null) }}</dd></div>
                    <div><dt>Çağrı</dt><dd data-workflow-model-metric="calls">{{ $formatNumber($modelUsage['calls'] ?? null) }}</dd></div>
                    <div><dt>Öğeler</dt><dd data-workflow-model-metric="items">{{ number_format((int) ($modelItems['completed'] ?? 0), 0, ',', '.') }}/{{ number_format((int) ($modelItems['total'] ?? 0), 0, ',', '.') }}</dd></div>
                </dl>

                @if ($modelSteps === [])
                    <p class="mt-5 rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] px-4 py-3 text-xs leading-5 text-[var(--amber-ink)]">Şablonun anlık kopyası okunamadığı için adımlar gösterilemiyor.</p>
                @else
                    <ol class="workflow-graph" aria-label="{{ $modelNames[$model['slug']] }} adımları">
                        @foreach ($modelSteps as $step)
                            <li class="workflow-node">
                                @include('workflows.runs._step', ['modelSlug' => $model['slug'], 'step' => $step, 'stepNumber' => $loop->iteration])
                                @if (! $loop->last)
                                    <span class="workflow-arrow" aria-hidden="true">→</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="mt-6">
                    <h4 class="text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">Etkin çağrılar</h4>
                    <div class="workflow-tails" data-workflow-tails>
                        @foreach ($modelCalls as $call)
                            @php
                                $callTail = is_string($call['tail'] ?? null) ? $call['tail'] : '';
                            @endphp
                            <article class="workflow-tail" data-workflow-tail="{{ implode('|', [$call['step'], is_string($call['child'] ?? null) ? $call['child'] : '', is_string($call['item'] ?? null) ? $call['item'] : '']) }}">
                                <div class="workflow-tail-head">
                                    <p class="workflow-tail-title" data-workflow-tail-title>{{ $activeCallTitle($call, $modelSteps) }}</p>
                                    <span class="workflow-tail-meta"><span>Geçen süre</span> <span data-workflow-tail-elapsed>{{ $formatDuration($secondsSince($call['startedAt'] ?? null)) }}</span></span>
                                </div>
                                <p @class(['workflow-tail-note', 'hidden' => $callTail !== '']) data-workflow-tail-note>{{ $tailPendingNote }}</p>
                                <pre @class(['workflow-tail-text', 'hidden' => $callTail === '']) tabindex="0" data-workflow-tail-text>{{ $callTail }}</pre>
                            </article>
                        @endforeach
                    </div>
                    <p @class(['mt-2 text-xs text-[var(--muted)]', 'hidden' => $modelCalls !== []]) data-workflow-tails-empty>Şu anda etkin model çağrısı yok.</p>
                </div>

                <div class="mt-6">
                    <h4 class="text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">Son yanıt</h4>
                    <article @class(['workflow-tail mt-2', 'hidden' => $lastOutput === null]) data-workflow-last-output data-last-output-key="{{ $lastOutput === null ? '' : $lastOutputKey($lastOutput) }}">
                        <div class="workflow-tail-head">
                            <p class="workflow-tail-title" data-workflow-last-output-title>{{ $lastOutput === null ? '' : $activeCallTitle(array_intersect_key($lastOutput, array_flip(['step', 'child', 'item', 'label'])), $modelSteps) }}</p>
                            <span class="workflow-tail-meta">
                                <span class="status-badge status-{{ $statusTone($lastOutput['status'] ?? null) }}" data-workflow-last-output-status><span class="status-dot"></span><span data-workflow-status-label>{{ $statusLabel($lastOutput['status'] ?? null) }}</span></span>
                                <span data-workflow-last-output-time>{{ $clockTime($lastOutput['finishedAt'] ?? null) }}</span>
                            </span>
                        </div>
                        <pre class="workflow-tail-text" tabindex="0" data-workflow-last-output-text>{{ $lastOutputText($lastOutput) }}</pre>
                    </article>
                    <p @class(['mt-2 text-xs text-[var(--muted)]', 'hidden' => $lastOutput !== null]) data-workflow-last-output-empty>Henüz biten bir model yanıtı yok.</p>
                </div>
            </section>
        @endforeach
    @endif
</section>
