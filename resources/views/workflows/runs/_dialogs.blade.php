{{-- The step detail side panel and the demo player. workflows.js fills both; [data-dialog-close] is handled globally. --}}
<dialog class="workflow-detail-panel" id="workflow-detail-panel" aria-labelledby="workflow-detail-title" data-workflow-detail-panel>
    <div class="workflow-detail-inner">
        <div class="workflow-detail-header">
            <div class="min-w-0">
                <p class="eyebrow" data-workflow-detail-eyebrow>Adım ayrıntısı</p>
                <h2 id="workflow-detail-title" class="mt-1 break-words text-lg font-black tracking-[-0.03em]" data-workflow-detail-title>Adım</h2>
            </div>
            <div class="flex shrink-0 gap-2">
                <button class="btn-ghost btn-xs" type="button" data-workflow-detail-reload>Yenile</button>
                <button class="btn-ghost btn-xs" type="button" data-dialog-close>Kapat</button>
            </div>
        </div>
        <div class="workflow-detail-body" aria-live="polite" data-workflow-detail-body>
            <p class="workflow-detail-note">Ayrıntılar yükleniyor…</p>
        </div>
    </div>
</dialog>

<dialog class="workflow-demo-dialog" id="workflow-demo-dialog" aria-labelledby="workflow-demo-title" data-workflow-demo-dialog>
    <div class="workflow-demo-shell">
        <div class="workflow-demo-bar">
            <div class="min-w-0" data-workflow-demo-heading></div>
            <button class="btn-ghost btn-xs shrink-0" type="button" data-dialog-close>Kapat</button>
        </div>
        <div class="workflow-demo-stage" data-workflow-demo-stage></div>
    </div>
</dialog>
