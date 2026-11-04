import assert from 'node:assert/strict';
import test from 'node:test';

import {
    formatWorkflowBytes,
    formatWorkflowCost,
    formatWorkflowDuration,
    formatWorkflowNumber,
    formatWorkflowTokens,
    initializeWorkflowRun,
    isWorkflowRunActive,
    safeWorkflowPath,
    turkishCountSuffix,
    WORKFLOW_POLL_INTERVAL_MS,
    WORKFLOW_TAIL_PENDING_NOTE,
    workflowActiveCallTitle,
    workflowActiveCallView,
    workflowClockTime,
    workflowDateTime,
    workflowDisplayStatus,
    workflowElapsedSeconds,
    workflowEventLine,
    workflowEventLines,
    workflowFailedItemsText,
    workflowGallerySignature,
    workflowItemLabel,
    workflowItemTotalsText,
    workflowLastOutputView,
    workflowMark,
    workflowOutputText,
    workflowProgressText,
    workflowStatusLabel,
    workflowStatusTone,
    workflowStepTypeLabel,
    workflowTailKey,
} from '../../resources/js/workflows.js';

const unknownValues = [null, undefined, '', '  ', 'abc', Number.NaN, Number.POSITIVE_INFINITY, true, {}, []];

test('labels and tones every run, model, step and item status', () => {
    const expected = {
        pending: ['Bekliyor', 'unknown'],
        starting: ['Başlatılıyor', 'running'],
        running: ['Çalışıyor', 'running'],
        stopping: ['Durduruluyor', 'running'],
        completed: ['Tamamlandı', 'pass'],
        partial: ['Kısmen tamamlandı', 'unknown'],
        failed: ['Başarısız', 'fail'],
        stopped: ['Durduruldu', 'unknown'],
        skipped: ['Atlandı', 'skip'],
        not_run: ['Çalıştırılmadı', 'skip'],
    };

    Object.entries(expected).forEach(([status, [label, tone]]) => {
        assert.equal(workflowStatusLabel(status), label);
        assert.equal(workflowStatusTone(status), tone);
    });
});

test('falls back for unknown statuses without reading inherited keys', () => {
    ['mystery', 'constructor', 'toString', '__proto__', null, undefined, 3].forEach((status) => {
        assert.equal(workflowStatusLabel(status), 'Bilinmiyor');
        assert.equal(workflowStatusTone(status), 'unknown');
        assert.deepEqual(workflowMark(status), { tone: 'skip', symbol: '·' });
    });
});

test('names every step type and keeps an unknown type readable', () => {
    assert.equal(workflowStepTypeLabel('llm_text'), 'Metin');
    assert.equal(workflowStepTypeLabel('llm_json'), 'JSON');
    assert.equal(workflowStepTypeLabel('foreach'), 'Döngü');
    assert.equal(workflowStepTypeLabel('code_text'), 'Kod (tek seferde)');
    assert.equal(workflowStepTypeLabel('code_agent'), 'Kod (ajan)');
    assert.equal(workflowStepTypeLabel('browser_check'), 'Tarayıcı testi');
    assert.equal(workflowStepTypeLabel('future_step'), 'future_step');
    assert.equal(workflowStepTypeLabel('constructor'), 'constructor');
    assert.equal(workflowStepTypeLabel(null), 'Adım');
});

test('marks foreach child statuses with a stage tone and a symbol', () => {
    assert.deepEqual(workflowMark('completed'), { tone: 'pass', symbol: '✓' });
    assert.deepEqual(workflowMark('failed'), { tone: 'fail', symbol: '✕' });
    assert.deepEqual(workflowMark('running'), { tone: 'running', symbol: '•' });
    assert.deepEqual(workflowMark('stopped'), { tone: 'unknown', symbol: '■' });
    assert.deepEqual(workflowMark('skipped'), { tone: 'skip', symbol: '–' });
});

test('shows work that never started as not run only once the run is no longer active', () => {
    assert.equal(workflowDisplayStatus('pending', true), 'pending');
    assert.equal(workflowDisplayStatus('pending', false), 'not_run');
    ['completed', 'partial', 'failed', 'stopped', 'skipped', null, undefined].forEach((status) => {
        assert.equal(workflowDisplayStatus(status, false), status);
        assert.equal(workflowDisplayStatus(status, true), status);
    });
    assert.equal(workflowDisplayStatus('running', true), 'running');
    assert.equal(workflowDisplayStatus('running', false), 'interrupted');
    assert.equal(workflowStatusLabel(workflowDisplayStatus('running', false)), 'Yarıda kaldı');
    assert.deepEqual(workflowMark('interrupted'), { tone: 'unknown', symbol: '!' });
    assert.equal(workflowStatusLabel(workflowDisplayStatus('pending', true)), 'Bekliyor');
    assert.equal(workflowStatusLabel(workflowDisplayStatus('pending', false)), 'Çalıştırılmadı');
    assert.deepEqual(workflowMark(workflowDisplayStatus('pending', false)), { tone: 'skip', symbol: '·' });
});

test('treats only starting, running and stopping as active', () => {
    assert.deepEqual(
        ['starting', 'running', 'stopping', 'completed', 'partial', 'failed', 'stopped', 'pending'].map(isWorkflowRunActive),
        [true, true, true, false, false, false, false, false],
    );
});

test('shows every unknown number, duration, cost, token pair and size as bilinmiyor', () => {
    unknownValues.forEach((value) => {
        assert.equal(formatWorkflowNumber(value), 'bilinmiyor');
        assert.equal(formatWorkflowDuration(value), 'bilinmiyor');
        assert.equal(formatWorkflowCost(value), 'bilinmiyor');
        assert.equal(formatWorkflowTokens(value, value), 'bilinmiyor');
        assert.equal(formatWorkflowBytes(value), 'bilinmiyor');
    });
    assert.equal(formatWorkflowCost(-1), 'bilinmiyor');
    assert.equal(formatWorkflowBytes(-1), 'bilinmiyor');
});

test('keeps a known zero instead of hiding it as unknown', () => {
    assert.equal(formatWorkflowNumber(0), '0');
    assert.equal(formatWorkflowDuration(0), '0,0 sn');
    assert.equal(formatWorkflowCost(0), '$0.0000');
    assert.equal(formatWorkflowTokens(0, 0), '0 girdi / 0 çıktı');
    assert.equal(formatWorkflowBytes(0), '0 B');
});

test('formats numbers, durations, costs, tokens and sizes like the server', () => {
    assert.equal(formatWorkflowNumber(1234567), '1.234.567');
    assert.equal(formatWorkflowNumber('42'), '42');
    assert.equal(formatWorkflowDuration(12.46), '12,5 sn');
    assert.equal(formatWorkflowDuration(59.96), '60,0 sn');
    assert.equal(formatWorkflowDuration(192), '3 dk 12 sn');
    assert.equal(formatWorkflowDuration(180), '3 dk');
    assert.equal(formatWorkflowDuration(3900), '1 sa 5 dk');
    assert.equal(formatWorkflowDuration(7200), '2 sa');
    assert.equal(formatWorkflowCost(0.12345), '$0.12345');
    assert.equal(formatWorkflowCost(1.5), '$1.5000');
    assert.equal(formatWorkflowCost(1234.1234567), '$1,234.123457');
    assert.equal(formatWorkflowTokens(1234, null), '1.234 girdi / bilinmiyor çıktı');
    assert.equal(formatWorkflowTokens(null, 5678), 'bilinmiyor girdi / 5.678 çıktı');
    assert.equal(formatWorkflowBytes(512), '512 B');
    assert.equal(formatWorkflowBytes(12_595), '12,3 KB');
    assert.equal(formatWorkflowBytes(1_258_291), '1,2 MB');
});

test('adds the Turkish suffix that matches the last spoken word of a number', () => {
    const expected = {
        0: "'ı", 1: "'i", 2: "'si", 3: "'ü", 4: "'ü", 5: "'i", 6: "'sı", 7: "'si", 8: "'i", 9: "'u",
        10: "'u", 20: "'si", 30: "'u", 40: "'ı", 50: "'si", 60: "'ı", 70: "'i", 80: "'i", 90: "'ı",
        11: "'i", 26: "'sı", 100: "'ü", 300: "'ü", 1000: "'i", 2000000: "'u",
    };

    Object.entries(expected).forEach(([number, suffix]) => assert.equal(turkishCountSuffix(Number(number)), suffix, number));
});

test('describes foreach progress with its extra counts', () => {
    assert.equal(workflowProgressText({ total: 4, completed: 2, failed: 0, running: 0, skipped: 0, stopped: 0 }), "4 öğeden 2'si tamamlandı");
    assert.equal(workflowProgressText({ total: 4, completed: 1, failed: 1, running: 1, skipped: 0, stopped: 1 }), "4 öğeden 1'i tamamlandı · 1 başarısız · 1 çalışıyor · 1 durduruldu");
    assert.equal(workflowProgressText({ total: 20, completed: 0, skipped: 3 }), "20 öğeden 0'ı tamamlandı · 3 atlandı");
    assert.equal(workflowProgressText({ total: 0, completed: 0 }), 'Henüz öğe yok');
    assert.equal(workflowProgressText(null), 'Henüz öğe yok');
});

test('summarises the item totals of the run', () => {
    assert.equal(workflowItemTotalsText({ itemsTotal: 1200, itemsCompleted: 2, itemsFailed: 1 }), '2/1.200');
    assert.equal(workflowItemTotalsText(null), '0/0');
    assert.equal(workflowFailedItemsText({ itemsFailed: 3 }), '3 öğe başarısız');
    assert.equal(workflowFailedItemsText({ itemsFailed: 0 }), 'Başarısız öğe yok');
});

test('labels items by their label or their one-based number', () => {
    assert.equal(workflowItemLabel({ key: '002', label: '  Zıplayan top  ' }), 'Zıplayan top');
    assert.equal(workflowItemLabel({ key: '012', label: null }), 'Öğe 12');
    assert.equal(workflowItemLabel({ key: 'broken' }), 'Öğe');
});

test('writes one event log line with time, level, model name, place and message', () => {
    const names = { 'or-acme-alpha': 'Alpha' };

    assert.equal(workflowClockTime('2026-10-22T18:30:12+03:00'), '18:30:12');
    assert.equal(workflowClockTime('yesterday'), '--:--:--');
    assert.equal(
        workflowEventLine({ time: '2026-10-22T18:30:12+03:00', level: 'warn', type: 'rate_limited', model: 'or-acme-alpha', step: 'demos', item: '002', message: '429; 15 sn bekleniyor.' }, names),
        '18:30:12 · UYARI · Alpha · demos/002 · 429; 15 sn bekleniyor.',
    );
    assert.equal(
        workflowEventLine({ time: '2026-10-22T18:31:00+03:00', level: 'info', model: 'or-unknown', step: null, item: null, message: 'Başladı.' }, names),
        '18:31:00 · or-unknown · Başladı.',
    );
    assert.equal(workflowEventLine({ time: null, level: 'error', model: null, message: '' }), '--:--:-- · HATA');
    assert.equal(workflowEventLines([]), 'Henüz olay kaydı yok.');
    assert.equal(workflowEventLines([{ time: '2026-10-22T10:00:00Z', message: 'A' }, null, { time: '2026-10-22T10:00:01Z', message: 'B' }]), '10:00:00 · A\n10:00:01 · B');
});

test('titles an active call with its step path, item and attempt', () => {
    const steps = [{ id: 'demos', title: 'Demolar', children: [{ id: 'build', title: 'Demo yaz' }] }];

    assert.equal(workflowActiveCallTitle({ step: 'demos', child: 'build', item: '002', label: 'Zıplayan top', attempt: 2 }, steps), 'Demolar › Demo yaz · Zıplayan top · Deneme 2');
    assert.equal(workflowActiveCallTitle({ step: 'demos', child: 'build', item: '003', label: null, attempt: null }, steps), 'Demolar › Demo yaz · Öğe 3');
    assert.equal(workflowActiveCallTitle({ step: 'score', child: null, item: null, attempt: 1 }, steps), 'score · Deneme 1');
    assert.equal(workflowTailKey({ step: 'demos', child: 'build', item: '002', attempt: 3 }), 'demos|build|002');
    assert.equal(workflowTailKey({ step: 'score', child: null, item: null }), 'score||');
});

test('reads clock and date texts straight from snapshot times without converting them to the browser timezone', () => {
    // The server already gives these in the app timezone; a UTC time must stay 17:37, whatever zone the browser is in.
    assert.equal(workflowClockTime('2026-10-22T17:37:37+00:00'), '17:37:37');
    assert.equal(workflowClockTime('2026-10-22T17:37:37.250Z'), '17:37:37');
    assert.equal(workflowClockTime(null), '--:--:--');
    assert.equal(workflowDateTime('2026-10-22T17:37:37+00:00'), '22 Eki 2026 · 17:37');
    assert.equal(workflowDateTime('2026-01-05T09:07:00+03:00'), '5 Oca 2026 · 09:07');
    assert.equal(workflowDateTime('2026-08-31T23:59:59Z'), '31 Ağu 2026 · 23:59');
    ['2026-13-01T10:00:00Z', '2026-00-01T10:00:00Z', 'yesterday', '', null, 42].forEach((value) => {
        assert.equal(workflowDateTime(value), null, String(value));
    });
});

test('measures how long a call has been running from its start instant', () => {
    const now = Date.parse('2026-10-22T15:33:05Z');

    assert.equal(workflowElapsedSeconds('2026-10-22T18:31:00+03:00', now), 125);
    assert.equal(workflowElapsedSeconds('2026-10-22T15:33:04.250Z', now), 0.8);
    assert.equal(workflowElapsedSeconds('2026-10-22T15:40:00+00:00', now), 0);
    ['2026-10-22T15:33:00', '2026-10-22 15:33:00+00:00', 'yesterday', '', null, 42].forEach((value) => {
        assert.equal(workflowElapsedSeconds(value, now), null, String(value));
    });
    assert.equal(workflowElapsedSeconds('2026-10-22T15:33:00Z', Number.NaN), null);
});

test('shows a running call with its elapsed time and says why its text is not there yet', () => {
    const steps = [{ id: 'demos', title: 'Demolar', children: [{ id: 'build', title: 'Demo yaz' }] }];
    const now = Date.parse('2026-10-22T15:33:05Z');
    const call = { step: 'demos', child: 'build', item: '002', label: 'Zıplayan top', attempt: 1, startedAt: '2026-10-22T18:31:00+03:00', tail: '' };

    assert.equal(WORKFLOW_TAIL_PENDING_NOTE, 'OpenCode metni yalnız bir yanıt parçası tamamlanınca bildirir; uzun yanıtlarda metin çağrı bitince görünür.');
    assert.deepEqual(workflowActiveCallView(call, steps, now), {
        key: 'demos|build|002',
        title: 'Demolar › Demo yaz · Zıplayan top · Deneme 1',
        elapsed: '2 dk 5 sn',
        text: '',
        note: WORKFLOW_TAIL_PENDING_NOTE,
    });
    assert.deepEqual(workflowActiveCallView({ ...call, tail: '<html>', startedAt: null }, steps, now), {
        key: 'demos|build|002',
        title: 'Demolar › Demo yaz · Zıplayan top · Deneme 1',
        elapsed: 'bilinmiyor',
        text: '<html>',
        note: null,
    });
    assert.equal(workflowActiveCallView({ step: 'score', tail: 42 }, [], now).note, WORKFLOW_TAIL_PENDING_NOTE);
});

test('describes the last finished answer of a model', () => {
    const steps = [{ id: 'demos', title: 'Demolar', children: [{ id: 'build', title: 'Demo yaz' }] }];
    const output = { step: 'demos', child: 'build', item: '002', label: 'Zıplayan top', status: 'failed', finishedAt: '2026-10-22T15:33:05+00:00', tail: '</pre><script>x</script>' };

    assert.deepEqual(workflowLastOutputView(output, steps), {
        key: 'demos|build|002|2026-10-22T15:33:05+00:00',
        title: 'Demolar › Demo yaz · Zıplayan top',
        status: 'failed',
        time: '15:33:05',
        text: '</pre><script>x</script>',
    });
    assert.deepEqual(workflowLastOutputView({ step: 'score', child: null, item: null, label: null, status: 'completed', finishedAt: null, tail: '', attempt: 3 }, []), {
        key: 'score|||',
        title: 'score',
        status: 'completed',
        time: '--:--:--',
        text: 'Yanıt metni boş.',
    });
    [null, undefined, 'text', {}, { step: 3 }].forEach((value) => assert.equal(workflowLastOutputView(value, steps), null));
});

test('shows step output as text or pretty JSON', () => {
    assert.equal(workflowOutputText('Merhaba'), 'Merhaba');
    assert.equal(workflowOutputText({ ideas: [{ title: 'A' }] }), '{\n  "ideas": [\n    {\n      "title": "A"\n    }\n  ]\n}');
    assert.equal(workflowOutputText(0), '0');
    assert.equal(workflowOutputText(null), null);
    assert.equal(workflowOutputText(undefined), null);
});

test('accepts only same-origin paths for demo links', () => {
    assert.equal(safeWorkflowPath('/workflows/runs/a/demos/or-x/demos/001/build'), '/workflows/runs/a/demos/or-x/demos/001/build');
    ['//evil.example/demo', 'https://evil.example', 'javascript:alert(1)', '/\\evil', null, 42].forEach((value) => {
        assert.equal(safeWorkflowPath(value), null);
    });
});

test('changes the gallery signature when items finish or the run stops', () => {
    assert.equal(workflowGallerySignature({ itemsCompleted: 2, itemsFailed: 1 }, true), '2|1|true');
    assert.equal(workflowGallerySignature(null, false), '0|0|false');
});

function withPage({ root = null, fetchResult = null, throwOnQuery = false } = {}, callback) {
    const previous = { document: globalThis.document, window: globalThis.window, fetch: globalThis.fetch };
    const timers = [];
    const requests = [];

    globalThis.document = {
        querySelector: (selector) => {
            if (throwOnQuery) throw new Error('Broken document');

            return selector === '[data-workflow-run]' ? root : null;
        },
        createElement: (tag) => fakeNode(tag),
    };
    globalThis.window = {
        setTimeout: (handler, delay) => {
            timers.push({ handler, delay });

            return timers.length;
        },
        location: { href: 'http://localhost/workflows/runs/demo', origin: 'http://localhost' },
    };
    globalThis.fetch = async (url, options) => {
        requests.push({ url, options });
        if (fetchResult instanceof Error) throw fetchResult;

        return { ok: true, status: 200, json: async () => fetchResult };
    };

    return Promise.resolve()
        .then(() => callback({ timers, requests }))
        .finally(() => {
            globalThis.document = previous.document;
            globalThis.window = previous.window;
            globalThis.fetch = previous.fetch;
        });
}

function fakeRunRoot(active) {
    return {
        dataset: { active: active ? 'true' : 'false', statusUrl: '/workflows/runs/demo/status', gallerySignature: '0|0|true' },
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener() {},
    };
}

async function flush() {
    await new Promise((resolve) => setImmediate(resolve));
}

test('does nothing and never throws on pages without a run root', async () => {
    await withPage({}, ({ timers }) => {
        assert.doesNotThrow(() => initializeWorkflowRun());
        assert.equal(timers.length, 0);
    });
    await withPage({ throwOnQuery: true }, () => {
        assert.doesNotThrow(() => initializeWorkflowRun());
    });
});

test('polls every two seconds while active and stops when the run is no longer active', async () => {
    const root = fakeRunRoot(true);

    await withPage({ root, fetchResult: { status: 'completed', active: false, totals: {}, models: [], events: [] } }, async ({ timers, requests }) => {
        initializeWorkflowRun();
        assert.equal(timers.length, 1);
        assert.equal(timers[0].delay, WORKFLOW_POLL_INTERVAL_MS);
        assert.equal(WORKFLOW_POLL_INTERVAL_MS, 2000);

        timers[0].handler();
        await flush();

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, '/workflows/runs/demo/status');
        assert.equal(requests[0].options.headers.Accept, 'application/json');
        assert.equal(root.dataset.active, 'false');
        assert.equal(root.dataset.currentStatus, 'completed');
        assert.equal(timers.length, 1);
    });
});

function fakeElement() {
    const classes = new Set();

    return {
        textContent: '',
        classes,
        classList: {
            contains: (name) => classes.has(name),
            toggle: (name, force) => (force ? classes.add(name) : classes.delete(name)),
        },
    };
}

test('hides the refresh hints and shows the finish time once the run is no longer active', async () => {
    const hints = [fakeElement(), fakeElement()];
    const finishedParts = [fakeElement(), fakeElement()];
    const finishedTime = fakeElement();
    finishedParts.forEach((part) => part.classList.toggle('hidden', true));
    const root = {
        ...fakeRunRoot(true),
        querySelector: (selector) => (selector === '[data-workflow-finished-time]' ? finishedTime : null),
        querySelectorAll: (selector) => ({ '[data-workflow-live-hint]': hints, '[data-workflow-finished]': finishedParts })[selector] ?? [],
    };
    const finished = { status: 'stopped', active: false, finishedAt: '2026-10-22T17:40:05+00:00', totals: {}, models: [], events: [] };

    await withPage({ root, fetchResult: finished }, async ({ timers }) => {
        initializeWorkflowRun();
        assert.ok(hints.every((hint) => !hint.classList.contains('hidden')));

        timers[0].handler();
        await flush();

        assert.ok(hints.every((hint) => hint.classList.contains('hidden')));
        assert.ok(finishedParts.every((part) => !part.classList.contains('hidden')));
        assert.equal(finishedTime.textContent, '22 Eki 2026 · 17:40');
        assert.equal(timers.length, 1);
    });
});

test('keeps the refresh hints while the run stays active', async () => {
    const hints = [fakeElement()];
    const root = { ...fakeRunRoot(true), querySelectorAll: (selector) => (selector === '[data-workflow-live-hint]' ? hints : []) };

    await withPage({ root, fetchResult: { status: 'running', active: true, finishedAt: null, totals: {}, models: [], events: [] } }, async ({ timers }) => {
        initializeWorkflowRun();
        timers[0].handler();
        await flush();

        assert.equal(hints[0].classList.contains('hidden'), false);
        assert.equal(timers.length, 2);
    });
});

test('keeps polling after a failed status request', async () => {
    const root = fakeRunRoot(true);

    await withPage({ root, fetchResult: new Error('offline') }, async ({ timers }) => {
        initializeWorkflowRun();
        timers[0].handler();
        await flush();

        assert.equal(root.dataset.active, 'true');
        assert.equal(timers.length, 2);
        assert.equal(timers[1].delay, 2000);
    });
});

test('does not poll a finished run', async () => {
    await withPage({ root: fakeRunRoot(false) }, ({ timers }) => {
        initializeWorkflowRun();
        assert.equal(timers.length, 0);
    });
});

/** A small stand-in for a DOM element: classes, data-* values, attributes, children and fixed query answers. */
function fakeNode(tag = 'div', { dataset = {}, className = '', queries = {}, queryAlls = {} } = {}) {
    const classes = new Set(className.split(/\s+/).filter(Boolean));
    const node = {
        tagName: tag.toUpperCase(),
        textContent: '',
        dataset: { ...dataset },
        attributes: {},
        children: [],
        classes,
        classList: {
            contains: (name) => classes.has(name),
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            toggle: (name, force) => {
                if (force ?? !classes.has(name)) classes.add(name);
                else classes.delete(name);
            },
        },
        get className() {
            return [...classes].join(' ');
        },
        set className(value) {
            classes.clear();
            value.split(/\s+/).filter(Boolean).forEach((name) => classes.add(name));
        },
        setAttribute(name, value) {
            node.attributes[name] = String(value);
        },
        getAttribute(name) {
            return node.attributes[name] ?? null;
        },
        append(...nodes) {
            node.children.push(...nodes);
        },
        replaceChildren(...nodes) {
            node.children = nodes;
        },
        contains: () => false,
        addEventListener() {},
        querySelector: (selector) => queries[selector] ?? null,
        querySelectorAll: (selector) => queryAlls[selector] ?? [],
    };

    return node;
}

/** One model panel with a top-level step box and a foreach step box, wired like _models.blade.php and _step.blade.php. */
function fakeRunPage() {
    const scoreLabel = fakeNode('span');
    const scoreBadge = fakeNode('span', { className: 'status-badge status-unknown', queries: { '[data-workflow-status-label]': scoreLabel } });
    const scoreBox = fakeNode('article', { dataset: { workflowStep: 'score', workflowStepStatus: 'pending' }, queries: { '[data-workflow-step-badge]': scoreBadge } });
    const demosLabel = fakeNode('span');
    const demosBadge = fakeNode('span', { className: 'status-badge status-unknown', queries: { '[data-workflow-status-label]': demosLabel } });
    const demosItems = fakeNode('ol', { className: 'workflow-items' });
    const demosBox = fakeNode('article', {
        dataset: { workflowStep: 'demos', workflowStepStatus: 'stopped' },
        queries: { '[data-workflow-step-badge]': demosBadge, '[data-workflow-step-items]': demosItems },
    });
    const panel = fakeNode('section', { dataset: { workflowModelPanel: 'or-acme-alpha' }, queryAlls: { '[data-workflow-step]': [scoreBox, demosBox] } });
    const root = fakeNode('div', {
        dataset: { active: 'true', statusUrl: '/workflows/runs/demo/status', gallerySignature: '0|0|true' },
        queryAlls: { '[data-workflow-model-panel]': [panel] },
    });

    return { root, scoreBox, scoreBadge, scoreLabel, demosBadge, demosLabel, demosItems };
}

function snapshotWithWaitingWork(active) {
    return {
        status: active ? 'stopping' : 'stopped',
        active,
        totals: {},
        events: [],
        models: [{
            slug: 'or-acme-alpha',
            status: active ? 'running' : 'stopped',
            steps: [
                { id: 'score', type: 'llm_json', title: 'Fikirleri puanla', status: 'pending' },
                {
                    id: 'demos',
                    type: 'foreach',
                    title: 'Demolar',
                    status: 'stopped',
                    children: [{ id: 'build', type: 'code_text', title: 'Demo yaz' }],
                    items: [
                        { key: '001', label: 'Zıplayan top', status: 'stopped', steps: { build: 'stopped' } },
                        { key: '003', label: 'Sırada bekleyen', status: 'pending', steps: {} },
                    ],
                },
            ],
        }],
    };
}

function markTitles(items) {
    return items.children.map((row) => row.children[1].children.map((mark) => mark.title));
}

test('relabels waiting steps and item marks as not run when polling sees the run end', async () => {
    const page = fakeRunPage();
    const snapshot = snapshotWithWaitingWork(true);

    await withPage({ root: page.root, fetchResult: snapshot }, async ({ timers }) => {
        initializeWorkflowRun();
        timers[0].handler();
        await flush();

        assert.equal(page.scoreLabel.textContent, 'Bekliyor');
        assert.ok(page.scoreBadge.classList.contains('status-unknown'));
        assert.deepEqual(markTitles(page.demosItems), [['Demo yaz: Durduruldu'], ['Demo yaz: Bekliyor']]);
        assert.equal(page.demosItems.children[1].children[1].children[0].getAttribute('aria-label'), 'Sırada bekleyen · Demo yaz: Bekliyor');
        assert.equal(timers.length, 2);

        Object.assign(snapshot, snapshotWithWaitingWork(false));
        timers[1].handler();
        await flush();

        assert.equal(page.scoreLabel.textContent, 'Çalıştırılmadı');
        assert.ok(page.scoreBadge.classList.contains('status-skip'));
        assert.ok(!page.scoreBadge.classList.contains('status-unknown'));
        assert.equal(page.scoreBox.dataset.workflowStepStatus, 'pending');
        assert.equal(page.demosLabel.textContent, 'Durduruldu');
        assert.deepEqual(markTitles(page.demosItems), [['Demo yaz: Durduruldu'], ['Demo yaz: Çalıştırılmadı']]);
        assert.equal(page.demosItems.children[1].children[1].children[0].getAttribute('aria-label'), 'Sırada bekleyen · Demo yaz: Çalıştırılmadı');
        assert.ok(page.demosItems.children[1].children[1].children[0].classList.contains('stage-mark-skip'));
        assert.equal(page.demosItems.children[1].dataset.workflowItemStatus, 'pending');
        assert.equal(page.root.dataset.active, 'false');
        assert.equal(timers.length, 2);
    });
});
