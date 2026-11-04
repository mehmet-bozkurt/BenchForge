<?php

use App\Models\WorkflowRun;
use App\Services\WorkflowRunFiles;
use App\Services\WorkflowRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/**
 * A snapshot step (§12.1) with every field the run page reads.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunPageStep(string $id, string $type, string $title, array $overrides = []): array
{
    $isForeach = $type === 'foreach';

    return array_replace([
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'status' => 'pending',
        'message' => null,
        'durationSeconds' => null,
        'attempts' => 0,
        'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 0],
        'counts' => $isForeach ? ['total' => 0, 'completed' => 0, 'failed' => 0, 'running' => 0, 'skipped' => 0, 'stopped' => 0] : null,
        'children' => $isForeach ? [] : null,
        'items' => $isForeach ? [] : null,
    ], $overrides);
}

/**
 * One running model of the Game Idea Factory: idea generation half done, scoring and demos still waiting.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunPageModel(array $overrides = []): array
{
    return array_replace([
        'slug' => 'or-acme-alpha',
        'model' => 'openrouter/acme/alpha',
        'name' => 'Alpha',
        'status' => 'running',
        'message' => null,
        'durationSeconds' => 75.5,
        'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 3],
        'items' => ['total' => 4, 'completed' => 2, 'failed' => 0],
        'steps' => [
            workflowRunPageStep('ideas', 'foreach', 'Fikir üret', [
                'status' => 'running',
                'durationSeconds' => 10.1,
                'counts' => ['total' => 4, 'completed' => 2, 'failed' => 0, 'running' => 1, 'skipped' => 0, 'stopped' => 0],
                'children' => [['id' => 'generate', 'type' => 'llm_json', 'title' => 'Kategori fikirleri']],
                'items' => [
                    ['key' => '001', 'label' => 'Var olan oyun', 'status' => 'completed', 'steps' => ['generate' => 'completed']],
                    ['key' => '002', 'label' => 'Özgün oyun', 'status' => 'completed', 'steps' => ['generate' => 'completed']],
                    ['key' => '003', 'label' => null, 'status' => 'running', 'steps' => ['generate' => 'running']],
                    ['key' => '004', 'label' => 'Bulmaca', 'status' => 'pending', 'steps' => []],
                ],
            ]),
            workflowRunPageStep('score', 'llm_json', 'Fikirleri puanla'),
            workflowRunPageStep('demos', 'foreach', 'Demolar', [
                'children' => [
                    ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz'],
                    ['id' => 'check', 'type' => 'browser_check', 'title' => 'Tarayıcı kontrolü'],
                ],
            ]),
        ],
        'active' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunPageSnapshot(array $overrides = []): array
{
    return array_replace([
        'status' => 'running',
        'active' => true,
        'message' => null,
        'startedAt' => '2026-10-22T18:30:12+03:00',
        'finishedAt' => null,
        'durationSeconds' => 75.5,
        'totals' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 3, 'itemsTotal' => 4, 'itemsCompleted' => 2, 'itemsFailed' => 0],
        'models' => [workflowRunPageModel()],
        'events' => [],
    ], $overrides);
}

/**
 * The template snapshot as the page reads it: its name and its gallery definition.
 *
 * @param  array<string, string>|null  $gallery
 * @return array<string, mixed>
 */
function workflowRunPageTemplate(?array $gallery = ['from' => 'demos', 'title' => '{{item.title}}', 'file' => 'build', 'check' => 'check']): array
{
    return array_filter([
        'schema' => 'benchforge-workflow/1',
        'id' => 'game-idea-factory',
        'name' => 'Oyun Fikir Fabrikası',
        'version' => 1,
        'steps' => [['id' => 'score', 'type' => 'llm_text', 'title' => 'Fikirleri puanla', 'prompt' => 'Score.']],
        'gallery' => $gallery,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * One gallery card (§12.3) with a playable demo, one critical check and two warnings.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunPageCard(WorkflowRun $run, array $overrides = []): array
{
    return array_replace([
        'model' => 'or-acme-alpha',
        'modelName' => 'Alpha',
        'step' => 'demos',
        'child' => 'build',
        'item' => '001',
        'title' => 'Zıplayan top',
        'status' => 'completed',
        'checks' => [
            'critical' => [['code' => 'no-touch-listener', 'message' => 'Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).']],
            'warnings' => [['code' => 'no-resize', 'message' => 'resize dinleyicisi yok.'], ['code' => 'no-raf', 'message' => 'requestAnimationFrame kullanılmıyor.']],
        ],
        'bytes' => 12_595,
        'demoUrl' => '/workflows/runs/'.$run->slug.'/demos/or-acme-alpha/demos/001/build',
    ], $overrides);
}

/**
 * Replaces the runner and the run files with the given page data, so the page renders without a worker or run folder.
 *
 * @param  array<string, mixed>  $snapshot
 * @param  array<string, mixed>|null  $template
 * @param  list<array<string, mixed>>  $gallery
 */
function fakeWorkflowRunPage(array $snapshot, ?array $template, array $gallery = []): void
{
    mock(WorkflowRunner::class)->shouldReceive('snapshot')->once()->andReturn($snapshot);
    $files = mock(WorkflowRunFiles::class);
    $files->shouldReceive('template')->andReturn($template);
    $files->shouldReceive('galleryItems')->andReturn($gallery);
}

test('it shows each model tab with its steps in template order and the foreach progress', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22T15:33:05Z'));
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'models' => [
            workflowRunPageModel([
                'active' => [['step' => 'ideas', 'child' => 'generate', 'item' => '003', 'label' => null, 'attempt' => 2, 'startedAt' => '2026-10-22T18:31:00+03:00', 'tail' => '{"ideas": [{"title": "Kule']],
            ]),
            workflowRunPageModel(['slug' => 'go-beta', 'model' => 'opencode-go/beta', 'name' => 'Beta', 'status' => 'pending']),
        ],
        'events' => [
            ['time' => '2026-10-22T18:30:40+03:00', 'level' => 'warn', 'type' => 'rate_limited', 'model' => 'or-acme-alpha', 'step' => 'ideas', 'item' => '003', 'message' => '429 alındı; 15 sn bekleniyor.'],
        ],
    ]), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSeeInOrder(['Fikir üret', 'Fikirleri puanla', 'Demolar'])
        ->assertSee("4 öğeden 2'si tamamlandı · 1 çalışıyor")
        ->assertSee('Henüz öğe yok')
        ->assertSee('Döngü')
        ->assertSee('JSON')
        ->assertSee('Kod (tek seferde)')
        ->assertSee('Tarayıcı testi')
        ->assertSeeInOrder(['Var olan oyun', 'Özgün oyun', 'Öğe 3', 'Bulmaca'])
        ->assertSee('data-detail-item="003" data-detail-child="generate"', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('id="workflow-tab-or-acme-alpha" aria-controls="workflow-panel-or-acme-alpha" aria-selected="true"', false)
        ->assertSee('id="workflow-tab-go-beta" aria-controls="workflow-panel-go-beta" aria-selected="false"', false)
        ->assertSee('Etkin çağrılar')
        ->assertSee('Fikir üret › Kategori fikirleri · Öğe 3 · Deneme 2')
        ->assertSee('<span>Geçen süre</span> <span data-workflow-tail-elapsed>2 dk 5 sn</span>', false)
        ->assertSee('<pre class="workflow-tail-text" tabindex="0" data-workflow-tail-text>{&quot;ideas&quot;: [{&quot;title&quot;: &quot;Kule</pre>', false)
        ->assertSee('<p class="workflow-tail-note hidden" data-workflow-tail-note>', false)
        ->assertSee('Başlangıç 22 Eki 2026 · 18:30')
        ->assertSee('18:30:40 · UYARI · Alpha · ideas/003 · 429 alındı; 15 sn bekleniyor.');

    expect($response->getContent())
        ->toMatch('/data-workflow-model-panel="or-acme-alpha"\s*>/')
        ->toMatch('/data-workflow-model-panel="go-beta"\s+hidden\s*>/');
});

test('it explains why a running call has no text yet and shows the last finished answer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22T15:40:00Z'));
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'models' => [
            workflowRunPageModel([
                'active' => [['step' => 'demos', 'child' => 'build', 'item' => '002', 'label' => 'Zıplayan top', 'attempt' => 1, 'startedAt' => '2026-10-22T15:33:30+00:00', 'tail' => '']],
                'lastOutput' => ['step' => 'demos', 'child' => 'build', 'item' => '001', 'label' => 'Kayan kutular', 'status' => 'failed', 'finishedAt' => '2026-10-22T15:33:05+00:00', 'tail' => '<!doctype html><html><body>Kutular'],
            ]),
            workflowRunPageModel(['slug' => 'go-beta', 'model' => 'opencode-go/beta', 'name' => 'Beta', 'status' => 'pending', 'lastOutput' => null]),
        ],
    ]), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('Demolar › Demo yaz · Zıplayan top · Deneme 1')
        ->assertSee('<span data-workflow-tail-elapsed>6 dk 30 sn</span>', false)
        ->assertSee('<p class="workflow-tail-note" data-workflow-tail-note>OpenCode metni yalnız bir yanıt parçası tamamlanınca bildirir; uzun yanıtlarda metin çağrı bitince görünür.</p>', false)
        ->assertSee('<pre class="workflow-tail-text hidden" tabindex="0" data-workflow-tail-text></pre>', false)
        ->assertDontSee('Model henüz metin üretmedi.')
        ->assertSeeInOrder(['Son yanıt', 'Demolar › Demo yaz · Kayan kutular', 'Başarısız', '15:33:05', '&lt;!doctype html&gt;&lt;html&gt;&lt;body&gt;Kutular'], false)
        ->assertSee('data-last-output-key="demos|build|001|2026-10-22T15:33:05+00:00"', false)
        ->assertSee('<p class="mt-2 text-xs text-[var(--muted)] hidden" data-workflow-last-output-empty>', false)
        ->assertSee('<article class="workflow-tail mt-2 hidden" data-workflow-last-output data-last-output-key="">', false)
        ->assertSee('<p class="mt-2 text-xs text-[var(--muted)]" data-workflow-last-output-empty>Henüz biten bir model yanıtı yok.</p>', false);
});

test('it shows unknown usage as bilinmiyor and keeps a known zero', function () {
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'models' => [
            workflowRunPageModel(),
            workflowRunPageModel([
                'slug' => 'go-beta',
                'name' => 'Beta',
                'usage' => ['inputTokens' => 1234, 'outputTokens' => 0, 'costUsd' => 0, 'calls' => 1],
            ]),
        ],
    ]), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('data-workflow-total="inputTokens">bilinmiyor<', false)
        ->assertSee('data-workflow-total="outputTokens">bilinmiyor<', false)
        ->assertSee('data-workflow-total="cost">bilinmiyor<', false)
        ->assertSee('data-workflow-total="calls">3<', false)
        ->assertSee('data-workflow-total="duration">1 dk 16 sn<', false)
        ->assertSee('data-workflow-total="items">2/4<', false)
        ->assertSee('data-workflow-model-metric="tokens">bilinmiyor<', false)
        ->assertSee('data-workflow-model-metric="cost">bilinmiyor<', false)
        ->assertSee('data-workflow-step-metric="duration">bilinmiyor<', false)
        ->assertSee('data-workflow-step-metric="tokens">bilinmiyor<', false)
        ->assertSee('data-workflow-model-metric="tokens">1.234 girdi / 0 çıktı<', false)
        ->assertSee('data-workflow-model-metric="cost">$0.0000<', false)
        ->assertDontSee('data-workflow-total="inputTokens">0<', false)
        ->assertDontSee('data-workflow-total="cost">$0', false);
});

test('it says what the input and output token counts include', function () {
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    fakeWorkflowRunPage(workflowRunPageSnapshot(), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('<p class="mt-2 text-xs leading-5 text-[var(--muted)]" id="workflow-token-note">Girdi önbellekten okunan ve yazılan token\'ları, çıktı akıl yürütme token\'larını da içerir.</p>', false)
        ->assertSeeInOrder(['aria-describedby="workflow-token-note"', 'Girdi token', 'aria-describedby="workflow-token-note"', 'Çıktı token'], false);
});

test('it escapes model-provided text in every region of the page', function () {
    $markers = [
        'runMessage' => '<script>alert("run-message")</script>',
        'modelName' => '<img src=x onerror=alert("model-name")>',
        'modelMessage' => '<script>alert("model-message")</script>',
        'stepMessage' => '<script>alert("step-message")</script>',
        'itemLabel' => '<script>alert("item-label")</script>',
        'activeLabel' => '<script>alert("active-label")</script>',
        'tail' => '</pre><script>alert("tail")</script>',
        'event' => '<script>alert("event")</script>',
        'galleryTitle' => '<script>alert("gallery-title")</script>',
        'checkMessage' => '<script>alert("check-message")</script>',
        'templateName' => '<script>alert("template-name")</script>',
        'lastOutputLabel' => '<script>alert("last-output-label")</script>',
        'lastOutputTail' => '</pre><script>alert("last-output-tail")</script>',
        'galleryMessage' => '<script>alert("gallery-message")</script>',
    ];
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    $model = workflowRunPageModel([
        'name' => $markers['modelName'],
        'message' => $markers['modelMessage'],
        'active' => [['step' => 'demos', 'child' => 'build', 'item' => '001', 'label' => $markers['activeLabel'], 'attempt' => 1, 'startedAt' => null, 'tail' => $markers['tail']]],
        'lastOutput' => ['step' => 'demos', 'child' => 'build', 'item' => '002', 'label' => $markers['lastOutputLabel'], 'status' => 'completed', 'finishedAt' => null, 'tail' => $markers['lastOutputTail']],
    ]);
    $model['steps'][0]['items'][0]['label'] = $markers['itemLabel'];
    $model['steps'][1]['message'] = $markers['stepMessage'];
    $template = workflowRunPageTemplate();
    $template['name'] = $markers['templateName'];
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'message' => $markers['runMessage'],
        'models' => [$model],
        'events' => [['time' => null, 'level' => 'error', 'type' => 'step_failed', 'model' => 'or-acme-alpha', 'step' => 'score', 'item' => null, 'message' => $markers['event']]],
    ]), $template, [workflowRunPageCard($run, [
        'title' => $markers['galleryTitle'],
        'modelName' => $markers['modelName'],
        'checks' => ['critical' => [['code' => 'x', 'message' => $markers['checkMessage']]], 'warnings' => []],
    ]), workflowRunPageCard($run, ['item' => '002', 'status' => 'failed', 'message' => $markers['galleryMessage'], 'bytes' => null, 'demoUrl' => null])]);

    $response = $this->get(route('workflows.runs.show', $run));

    foreach ($markers as $marker) {
        $response->assertSee(e($marker), false)->assertDontSee($marker, false);
    }
});

test('it renders gallery cards with a sandboxed player, the demo link and the static check summary', function () {
    $run = WorkflowRun::factory()->create(['status' => 'completed']);
    $card = workflowRunPageCard($run);
    fakeWorkflowRunPage(workflowRunPageSnapshot(['status' => 'completed', 'active' => false]), workflowRunPageTemplate(), [
        $card,
        workflowRunPageCard($run, ['item' => '002', 'title' => 'Kayan kutular', 'status' => 'failed', 'checks' => null, 'bytes' => null, 'demoUrl' => null]),
    ]);

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('data-workflow-gallery', false)
        ->assertSeeInOrder(['Zıplayan top', 'Kayan kutular'])
        ->assertSee('<template data-workflow-demo-template>', false)
        ->assertSee('src="'.$card['demoUrl'].'"', false)
        ->assertSee('sandbox="allow-scripts" referrerpolicy="no-referrer"', false)
        ->assertSee('href="'.$card['demoUrl'].'" target="_blank" rel="noopener noreferrer">Yeni sekmede aç ↗</a>', false)
        ->assertSee('<li class="status-badge status-fail">1 kritik sorun</li>', false)
        ->assertSee('<li class="status-badge status-unknown">2 uyarı</li>', false)
        ->assertSee("<li class=\"status-badge status-skip\">Tarayıcı testi Aşama 2'de</li>", false)
        ->assertSee('Öğe 1 · 12,3 KB')
        ->assertSee('<li class="status-badge status-skip">Kontrol sonucu yok</li>', false)
        ->assertSee('<span class="sync-pill">1/2 demo hazır</span>', false)
        ->assertDontSee('data-preview-canvas', false);

    expect(substr_count($response->getContent(), '<iframe'))->toBe(1)
        ->and(substr_count($response->getContent(), 'data-workflow-play'))->toBe(1);
});

test('it states only the demo isolation that is enforced', function () {
    $run = WorkflowRun::factory()->create(['status' => 'completed']);
    fakeWorkflowRunPage(workflowRunPageSnapshot(['status' => 'completed', 'active' => false]), workflowRunPageTemplate(), [workflowRunPageCard($run)]);

    $this->get(route('workflows.runs.show', $run))
        ->assertSee("Demolar sandbox'lı çerçevede açılır ve uygulama oturumuna erişemez. Tarayıcı dış kaynak yüklemeyi ve bağlantı isteklerini (fetch, görsel, script, WebSocket) CSP ile engeller; WebRTC ve sayfa yönlendirmesi yalnız statik kontrolde yakalanır, yine de güvenilmeyen kod olarak düşünün.", false)
        ->assertSee('<span class="sync-pill">1 demo</span>', false)
        ->assertDontSee('ağ ve oturum erişimi olmadan');
});

test('it shows why a code item has no demo and keeps its static check result', function () {
    $run = WorkflowRun::factory()->create(['status' => 'partial']);
    $failedChecks = [
        'critical' => [['code' => 'no-touch-listener', 'message' => 'Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).']],
        'warnings' => [['code' => 'no-resize', 'message' => 'resize dinleyicisi yok.']],
    ];
    fakeWorkflowRunPage(workflowRunPageSnapshot(['status' => 'partial', 'active' => false]), workflowRunPageTemplate(), [
        workflowRunPageCard($run, [
            'title' => 'Statik kontrolden kalan',
            'status' => 'failed',
            'checks' => $failedChecks,
            'message' => 'Demo statik kontrollerden geçemedi: Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).',
            'bytes' => null,
            'demoUrl' => null,
        ]),
        workflowRunPageCard($run, ['item' => '002', 'title' => 'Süresi dolan', 'status' => 'failed', 'checks' => null, 'message' => 'Süre sınırı (1200 sn) doldu.', 'bytes' => null, 'demoUrl' => null]),
        workflowRunPageCard($run, ['item' => '003', 'title' => 'Sırada bekleyen', 'status' => 'pending', 'checks' => null, 'message' => null, 'bytes' => null, 'demoUrl' => null]),
        workflowRunPageCard($run, ['item' => '004', 'title' => 'Dosyası kaybolan', 'status' => 'completed', 'checks' => ['critical' => [], 'warnings' => []], 'message' => null, 'bytes' => 2048, 'demoUrl' => null]),
    ]);

    $response = $this->get(route('workflows.runs.show', $run));
    $cards = array_slice(explode('data-workflow-gallery-card=', (string) $response->getContent()), 1);

    expect($cards)->toHaveCount(4)
        ->and($cards[0])
        ->toContain('<li class="status-badge status-fail">1 kritik sorun</li>')
        ->toContain('<li class="status-badge status-unknown">1 uyarı</li>')
        ->toMatch('/<details class="mt-3 text-xs"\s+open\s*>/')
        ->toContain('Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).')
        ->toContain('Demo üretilemedi.')
        ->toContain('Demo statik kontrollerden geçemedi: Dokunma/fare dinleyicisi yok')
        ->toContain('>Öğe 1</p>')
        ->not->toContain('Kontrol sonucu yok')
        ->not->toContain('henüz yok')
        ->not->toContain('bilinmiyor')
        ->and($cards[1])
        ->toContain('Kontrol sonucu yok')
        ->toContain('Demo üretilemedi.')
        ->toContain('Süre sınırı (1200 sn) doldu.')
        ->not->toContain('<details')
        ->not->toContain('henüz yok')
        ->and($cards[2])
        ->toContain('Çalıştırılmadı')
        ->toContain('Demo üretilmedi (çalıştırma bitti).')
        ->not->toContain('Bekliyor')
        ->not->toContain('henüz yok')
        ->not->toContain('Demo üretilemedi.')
        ->and($cards[3])
        ->toContain('Öğe 4 · 2,0 KB')
        ->toContain('Statik kontrol temiz')
        ->toContain('Demo dosyası bulunamadı.')
        ->not->toContain('Demo üretilemedi.');

    $response->assertSee('<span class="sync-pill">0/4 demo hazır</span>', false);
});

test('it labels work that never started by whether the run is still active', function (bool $active, string $label, string $tone, string $demoText, string $otherLabel, string $otherDemoText) {
    $run = WorkflowRun::factory()->create($active ? ['status' => 'running', 'finished_at' => null] : ['status' => 'stopped']);
    $model = workflowRunPageModel(['status' => $active ? 'running' : 'stopped']);
    $model['steps'][0]['status'] = $active ? 'running' : 'stopped';
    $model['steps'][0]['items'][2] = ['key' => '003', 'label' => null, 'status' => 'stopped', 'steps' => ['generate' => 'stopped']];
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'status' => $active ? 'running' : 'stopped',
        'active' => $active,
        'models' => [$model],
    ]), workflowRunPageTemplate(), [
        workflowRunPageCard($run, ['item' => '004', 'title' => 'Sırada bekleyen', 'status' => 'pending', 'checks' => null, 'message' => null, 'bytes' => null, 'demoUrl' => null]),
    ]);

    $response = $this->get(route('workflows.runs.show', $run));
    $content = (string) $response->getContent();
    $cards = array_slice(explode('data-workflow-gallery-card=', $content), 1);

    $response->assertSee('<article class="workflow-step" data-workflow-step="score" data-workflow-step-status="pending">', false)
        ->assertSee('<span class="status-badge status-'.$tone.'" data-workflow-step-badge><span class="status-dot"></span><span data-workflow-status-label>'.$label.'</span></span>', false)
        ->assertSee('data-workflow-item="004" data-workflow-item-status="pending"', false)
        ->assertSee('title="Kategori fikirleri: '.$label.'" aria-label="Bulmaca · Kategori fikirleri: '.$label.'"', false)
        ->assertSee('title="Kategori fikirleri: Durduruldu"', false)
        ->assertDontSee($otherLabel);

    expect(substr_count($content, 'data-workflow-step-badge><span class="status-dot"></span><span data-workflow-status-label>'.$label.'<'))->toBe(2)
        ->and($cards)->toHaveCount(1)
        ->and($cards[0])
        ->toContain('<span class="status-badge status-'.$tone.' shrink-0"><span class="status-dot"></span>'.$label.'</span>')
        ->toContain($demoText)
        ->not->toContain($otherDemoText)
        ->not->toContain('Demo üretilemedi.');
})->with([
    'active run' => [true, 'Bekliyor', 'unknown', 'Demo dosyası henüz yok.', 'Çalıştırılmadı', 'Demo üretilmedi (çalıştırma bitti).'],
    'finished run' => [false, 'Çalıştırılmadı', 'skip', 'Demo üretilmedi (çalıştırma bitti).', 'Bekliyor', 'Demo dosyası henüz yok.'],
]);

test('it shows work a worker left running as interrupted once the run has ended', function (bool $active, string $label, string $tone) {
    $run = WorkflowRun::factory()->create($active ? ['status' => 'running', 'finished_at' => null] : ['status' => 'failed']);
    $model = workflowRunPageModel(['status' => $active ? 'running' : 'failed']);
    $model['steps'][0]['status'] = 'running';
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'status' => $active ? 'running' : 'failed',
        'active' => $active,
        'models' => [$model],
    ]), workflowRunPageTemplate());

    $this->get(route('workflows.runs.show', $run))
        ->assertSee('data-workflow-step-status="running"', false)
        ->assertSee('<span class="status-badge status-'.$tone.'" data-workflow-step-badge><span class="status-dot"></span><span data-workflow-status-label>'.$label.'</span></span>', false);
})->with([
    'active run' => [true, 'Çalışıyor', 'running'],
    'ended run' => [false, 'Yarıda kaldı', 'unknown'],
]);

test('it shows the gallery section only for a template with a gallery', function (?array $gallery, bool $hasRegion) {
    $run = WorkflowRun::factory()->create(['status' => 'completed']);
    fakeWorkflowRunPage(workflowRunPageSnapshot(['status' => 'completed', 'active' => false]), workflowRunPageTemplate($gallery));

    $response = $this->get(route('workflows.runs.show', $run));

    if ($hasRegion) {
        $response->assertSee('data-workflow-gallery', false)->assertSee('Henüz demo yok');
    } else {
        $response->assertDontSee('data-workflow-gallery', false)->assertDontSee('Henüz demo yok');
    }
})->with([
    'gallery without cards yet' => [['from' => 'demos', 'title' => '{{item.title}}', 'file' => 'build'], true],
    'no gallery' => [null, false],
]);

test('it offers Stop and the polling hooks while the run is active', function () {
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    fakeWorkflowRunPage(workflowRunPageSnapshot(), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('data-status-url="'.route('workflows.runs.status', $run).'"', false)
        ->assertSee('data-detail-url="'.route('workflows.runs.detail', $run).'"', false)
        ->assertSee('data-active="true"', false)
        ->assertSee('data-gallery-signature="2|0|true"', false)
        ->assertSee('<form class="mt-5" method="POST" action="'.route('workflows.runs.stop', $run).'" data-workflow-stop data-confirm=', false)
        ->assertSee('İş akışını durdur')
        ->assertSee('<span data-workflow-live-hint>Sayfa 2 sn arayla güncellenir</span>', false)
        ->assertSee('<span data-workflow-live-hint> · 2 sn yenileme</span>', false)
        ->assertSee('<span class="hidden" data-workflow-finished>Bitiş <span data-workflow-finished-time></span></span>', false)
        ->assertDontSee('name="_method"', false);
});

test('it hides Stop once the run has finished but keeps the status hooks', function () {
    $run = WorkflowRun::factory()->create(['status' => 'partial']);
    fakeWorkflowRunPage(workflowRunPageSnapshot([
        'status' => 'partial',
        'active' => false,
        'finishedAt' => '2026-10-22T18:42:09+03:00',
        'message' => 'Bazı adımlar başarısız oldu.',
        'models' => [workflowRunPageModel(['status' => 'partial'])],
    ]), workflowRunPageTemplate());

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('data-status-url="'.route('workflows.runs.status', $run).'"', false)
        ->assertSee('data-active="false"', false)
        ->assertSee('Kısmen tamamlandı')
        ->assertSee('Bazı adımlar başarısız oldu.')
        ->assertDontSee(route('workflows.runs.stop', $run), false)
        ->assertDontSee('data-workflow-stop', false)
        ->assertDontSee('data-workflow-live-hint', false)
        ->assertDontSee('2 sn arayla güncellenir')
        ->assertDontSee('2 sn yenileme')
        ->assertSee('<span class="" data-workflow-finished>Bitiş <span data-workflow-finished-time>22 Eki 2026 · 18:42</span></span>', false);
});

test('it renders a run whose run folder is missing from its database record', function () {
    $run = WorkflowRun::factory()->create(['status' => 'failed', 'message' => 'İş akışı süreci beklenmedik şekilde kapandı.']);

    $response = $this->get(route('workflows.runs.show', $run));

    $response->assertSee('Başarısız')
        ->assertSee('İş akışı süreci beklenmedik şekilde kapandı.')
        ->assertSee('Bu çalıştırmada model kaydı yok')
        ->assertSee('Henüz olay kaydı yok.')
        ->assertSee('data-workflow-total="inputTokens">bilinmiyor<', false)
        ->assertDontSee('data-workflow-gallery', false)
        ->assertDontSee('data-workflow-stop', false);
});
