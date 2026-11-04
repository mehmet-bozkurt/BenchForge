{{--
    One step box of the graph. Expects $modelSlug, $step (a snapshot step) and $stepNumber; workflows.js updates the same
    data-* hooks. The data-*-status attributes keep the snapshot status; badges and marks show $displayStatus() of it.
--}}
@php
    $stepStatus = is_string($step['status'] ?? null) ? $step['status'] : 'pending';
    $stepDisplayStatus = $displayStatus($stepStatus);
    $stepUsage = is_array($step['usage'] ?? null) ? $step['usage'] : [];
    $stepTitle = is_string($step['title'] ?? null) && $step['title'] !== '' ? $step['title'] : $step['id'];
    $stepMessage = is_string($step['message'] ?? null) ? $step['message'] : '';
    $isForeach = ($step['type'] ?? null) === 'foreach';
    $stepChildren = $isForeach ? array_values(array_filter(is_array($step['children'] ?? null) ? $step['children'] : [], static fn (mixed $child): bool => is_array($child) && is_string($child['id'] ?? null) && $child['id'] !== '')) : [];
    $stepItems = $isForeach ? array_values(array_filter(is_array($step['items'] ?? null) ? $step['items'] : [], static fn (mixed $item): bool => is_array($item) && is_string($item['key'] ?? null))) : [];
@endphp

<article class="workflow-step" data-workflow-step="{{ $step['id'] }}" data-workflow-step-status="{{ $stepStatus }}">
    <button class="workflow-step-open" type="button" aria-haspopup="dialog" data-workflow-detail data-detail-model="{{ $modelSlug }}" data-detail-step="{{ $step['id'] }}" data-detail-title="{{ $stepTitle }}">
        <span class="workflow-step-head">
            <span class="workflow-step-number" aria-hidden="true">{{ $stepNumber }}</span>
            <span class="workflow-type-chip">{{ $stepTypeLabel($step['type'] ?? null) }}</span>
            <span class="status-badge status-{{ $statusTone($stepDisplayStatus) }}" data-workflow-step-badge><span class="status-dot"></span><span data-workflow-status-label>{{ $statusLabel($stepDisplayStatus) }}</span></span>
        </span>
        <span class="workflow-step-title">{{ $stepTitle }}</span>
        <span class="workflow-step-facts">
            <span><span class="workflow-step-fact-label">Süre</span> <span data-workflow-step-metric="duration">{{ $formatDuration($step['durationSeconds'] ?? null) }}</span></span>
            <span><span class="workflow-step-fact-label">Token</span> <span data-workflow-step-metric="tokens">{{ $formatTokens($stepUsage['inputTokens'] ?? null, $stepUsage['outputTokens'] ?? null) }}</span></span>
            @unless ($isForeach)
                <span><span class="workflow-step-fact-label">Deneme</span> <span data-workflow-step-metric="attempts">{{ $formatNumber($step['attempts'] ?? null) }}</span></span>
            @endunless
        </span>
    </button>

    <p @class(['workflow-step-message', 'hidden' => $stepMessage === '']) data-workflow-step-message>{{ $stepMessage }}</p>

    @if ($isForeach)
        @if ($stepChildren !== [])
            <ul class="workflow-step-children" aria-label="Her öğe için alt adımlar">
                @foreach ($stepChildren as $child)
                    <li><span class="workflow-type-chip">{{ $stepTypeLabel($child['type'] ?? null) }}</span> {{ is_string($child['title'] ?? null) && $child['title'] !== '' ? $child['title'] : $child['id'] }}</li>
                @endforeach
            </ul>
        @endif

        <p class="workflow-step-progress" data-workflow-step-progress>{{ $progressText($step['counts'] ?? null) }}</p>

        <ol @class(['workflow-items', 'hidden' => $stepItems === []]) aria-label="{{ $stepTitle }} öğeleri" data-workflow-step-items>
            @foreach ($stepItems as $item)
                @php
                    $itemSteps = is_array($item['steps'] ?? null) ? $item['steps'] : [];
                @endphp
                <li class="workflow-item" data-workflow-item="{{ $item['key'] }}" data-workflow-item-status="{{ is_string($item['status'] ?? null) ? $item['status'] : 'pending' }}">
                    @if ($stepChildren !== [])
                        <button class="workflow-item-label" type="button" aria-haspopup="dialog" data-workflow-detail data-detail-model="{{ $modelSlug }}" data-detail-step="{{ $step['id'] }}" data-detail-item="{{ $item['key'] }}" data-detail-child="{{ $stepChildren[0]['id'] }}">{{ $itemLabel($item) }}</button>
                    @else
                        <span class="workflow-item-label">{{ $itemLabel($item) }}</span>
                    @endif
                    <span class="workflow-item-marks">
                        @foreach ($stepChildren as $child)
                            @php
                                $markStatus = $displayStatus(is_string($itemSteps[$child['id']] ?? null) ? $itemSteps[$child['id']] : 'pending');
                                [$markTone, $markSymbol] = $statusMark($markStatus);
                                $markDescription = (is_string($child['title'] ?? null) && $child['title'] !== '' ? $child['title'] : $child['id']).': '.$statusLabel($markStatus);
                            @endphp
                            <button class="stage-mark stage-mark-{{ $markTone }} workflow-item-mark" type="button" title="{{ $markDescription }}" aria-label="{{ $itemLabel($item) }} · {{ $markDescription }}" aria-haspopup="dialog" data-workflow-detail data-detail-model="{{ $modelSlug }}" data-detail-step="{{ $step['id'] }}" data-detail-item="{{ $item['key'] }}" data-detail-child="{{ $child['id'] }}">{{ $markSymbol }}</button>
                        @endforeach
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</article>
