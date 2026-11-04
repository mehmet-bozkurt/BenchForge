/*
 * Live run page of the İş Akışları module (`[data-workflow-run]`).
 *
 * The page polls the run snapshot every two seconds while the run is active and updates the changed regions in place:
 * badges, step boxes, foreach items, totals, the running calls, each model's last answer and the event log. When the
 * run stops being active, polling stops, the "updates every 2 s" hints are hidden and work that never started is shown
 * as not run (see workflowDisplayStatus). Step details are loaded on demand
 * into a side panel, and gallery demos play in a sandboxed frame. Model-provided text is only ever written with
 * textContent; the only markup this module inserts is the server-rendered gallery region and its inert <template>s.
 *
 * Times in the snapshot are ISO strings that the server already gives in the app timezone. Clock and date texts are cut
 * straight out of those strings, never converted with Date, so they match the server-rendered page.
 */

export const WORKFLOW_POLL_INTERVAL_MS = 2000;

export const WORKFLOW_UNKNOWN = 'bilinmiyor';

/** Shown for a running call without text: `opencode run --format json` reports text only when a reply part is done. */
export const WORKFLOW_TAIL_PENDING_NOTE = 'OpenCode metni yalnız bir yanıt parçası tamamlanınca bildirir; uzun yanıtlarda metin çağrı bitince görünür.';

const TURKISH_MONTHS = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

/* An ISO time with an explicit offset, so it names one instant whatever the browser's timezone is. */
const ISO_INSTANT_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/;

export const WORKFLOW_ACTIVE_STATUSES = Object.freeze(['starting', 'running', 'stopping']);

export const WORKFLOW_STATUS_LABELS = Object.freeze({
    pending: 'Bekliyor',
    starting: 'Başlatılıyor',
    running: 'Çalışıyor',
    stopping: 'Durduruluyor',
    completed: 'Tamamlandı',
    partial: 'Kısmen tamamlandı',
    failed: 'Başarısız',
    stopped: 'Durduruldu',
    skipped: 'Atlandı',
    not_run: 'Çalıştırılmadı',
    interrupted: 'Yarıda kaldı',
});

export const WORKFLOW_STATUS_TONES = Object.freeze({
    pending: 'unknown',
    starting: 'running',
    running: 'running',
    stopping: 'running',
    completed: 'pass',
    partial: 'unknown',
    failed: 'fail',
    stopped: 'unknown',
    skipped: 'skip',
    not_run: 'skip',
    interrupted: 'unknown',
});

export const WORKFLOW_STEP_TYPE_LABELS = Object.freeze({
    llm_text: 'Metin',
    llm_json: 'JSON',
    foreach: 'Döngü',
    code_text: 'Kod (tek seferde)',
    code_agent: 'Kod (ajan)',
    browser_check: 'Tarayıcı testi',
});

const STATUS_MARKS = Object.freeze({
    completed: Object.freeze({ tone: 'pass', symbol: '✓' }),
    failed: Object.freeze({ tone: 'fail', symbol: '✕' }),
    running: Object.freeze({ tone: 'running', symbol: '•' }),
    stopped: Object.freeze({ tone: 'unknown', symbol: '■' }),
    partial: Object.freeze({ tone: 'unknown', symbol: '◐' }),
    skipped: Object.freeze({ tone: 'skip', symbol: '–' }),
    pending: Object.freeze({ tone: 'skip', symbol: '·' }),
    not_run: Object.freeze({ tone: 'skip', symbol: '·' }),
    interrupted: Object.freeze({ tone: 'unknown', symbol: '!' }),
});

const ATTEMPT_KIND_LABELS = Object.freeze({
    initial: 'İlk deneme',
    retry: 'Yeniden deneme',
    repair: 'Onarım',
    transient: 'Geçici hata sonrası',
});

const CODE_STEP_TYPES = ['code_text', 'code_agent'];

const STATUS_TONE_CLASSES = ['status-pass', 'status-fail', 'status-running', 'status-unknown', 'status-skip'];

/* Turkish third-person suffix after a number ("2'si", "3'ü", "10'u"), chosen by the last spoken word of the number. */
const UNIT_SUFFIXES = ['', "'i", "'si", "'ü", "'ü", "'i", "'sı", "'si", "'i", "'u"];

const TEN_SUFFIXES = ['', "'u", "'si", "'u", "'ı", "'si", "'ı", "'i", "'i", "'ı"];

const EXTRA_COUNTS = [['failed', 'başarısız'], ['running', 'çalışıyor'], ['stopped', 'durduruldu'], ['skipped', 'atlandı']];

const SCROLL_STICK_PX = 24;

function own(map, key) {
    return typeof key === 'string' && Object.hasOwn(map, key) ? map[key] : undefined;
}

function knownNumber(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && value.trim() !== '') {
        const number = Number(value);

        return Number.isFinite(number) ? number : null;
    }

    return null;
}

function count(value) {
    const number = knownNumber(value);

    return number === null ? 0 : Math.max(0, Math.trunc(number));
}

function integerText(value) {
    return Math.round(value).toLocaleString('tr-TR');
}

export function workflowStatusLabel(status) {
    return own(WORKFLOW_STATUS_LABELS, status) || 'Bilinmiyor';
}

export function workflowStatusTone(status) {
    return own(WORKFLOW_STATUS_TONES, status) || 'unknown';
}

export function workflowStepTypeLabel(type) {
    return own(WORKFLOW_STEP_TYPE_LABELS, type) || (typeof type === 'string' && type !== '' ? type : 'Adım');
}

/** The tone (for `.stage-mark-*`) and symbol of one foreach child status. */
export function workflowMark(status) {
    return own(STATUS_MARKS, status) || STATUS_MARKS.pending;
}

export function isWorkflowRunActive(status) {
    return WORKFLOW_ACTIVE_STATUSES.includes(status);
}

/**
 * The status a step, item mark, gallery card or step detail shows. Work that never started stays `pending` on disk (a
 * later phase can resume it), but once the run is no longer active nothing will start it, so it reads as not run
 * ("Çalıştırılmadı") instead of waiting. Work a worker left running when it exited unexpectedly reads as interrupted
 * ("Yarıda kaldı"). The `$displayStatus` helper of show.blade.php does the same.
 */
export function workflowDisplayStatus(status, active) {
    if (active) return status;
    if (status === 'pending') return 'not_run';
    if (status === 'running' || status === 'starting') return 'interrupted';
    return status;
}

/** A count or token number, "bilinmiyor" when the value is unknown (never 0). */
export function formatWorkflowNumber(value) {
    const number = knownNumber(value);

    return number === null ? WORKFLOW_UNKNOWN : integerText(number);
}

/** "12,5 sn" below a minute, then "3 dk 12 sn" and "1 sa 5 dk"; "bilinmiyor" when unknown. */
export function formatWorkflowDuration(value) {
    const number = knownNumber(value);

    if (number === null) return WORKFLOW_UNKNOWN;

    const seconds = Math.max(0, number);

    if (seconds < 60) return `${seconds.toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} sn`;

    const totalSeconds = Math.round(seconds);
    const minutes = Math.floor(totalSeconds / 60);
    const remainingSeconds = totalSeconds % 60;

    if (minutes < 60) return remainingSeconds > 0 ? `${minutes} dk ${remainingSeconds} sn` : `${minutes} dk`;

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    return remainingMinutes > 0 ? `${hours} sa ${remainingMinutes} dk` : `${hours} sa`;
}

/** US dollars with 4 to 6 decimals ("$0.0000"); "bilinmiyor" when unknown. */
export function formatWorkflowCost(value) {
    const cost = knownNumber(value);

    if (cost === null || cost < 0) return WORKFLOW_UNKNOWN;

    return `$${cost.toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 6 })}`;
}

/** "1.234 girdi / 5.678 çıktı"; "bilinmiyor" when neither side is known. */
export function formatWorkflowTokens(inputTokens, outputTokens) {
    if (knownNumber(inputTokens) === null && knownNumber(outputTokens) === null) return WORKFLOW_UNKNOWN;

    return `${formatWorkflowNumber(inputTokens)} girdi / ${formatWorkflowNumber(outputTokens)} çıktı`;
}

/** "512 B", "12,3 KB", "1,2 MB"; "bilinmiyor" when unknown. */
export function formatWorkflowBytes(value) {
    const bytes = knownNumber(value);

    if (bytes === null || bytes < 0) return WORKFLOW_UNKNOWN;
    if (bytes < 1024) return `${integerText(bytes)} B`;

    const options = { minimumFractionDigits: 1, maximumFractionDigits: 1 };

    if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString('tr-TR', options)} KB`;

    return `${(bytes / (1024 * 1024)).toLocaleString('tr-TR', options)} MB`;
}

export function turkishCountSuffix(value) {
    const number = count(value);

    if (number === 0) return "'ı";
    if (number % 10 !== 0) return UNIT_SUFFIXES[number % 10];
    if (number % 100 !== 0) return TEN_SUFFIXES[Math.floor((number % 100) / 10)];
    if (number % 1000 !== 0) return "'ü";
    if (number % 1_000_000 !== 0) return "'i";

    return "'u";
}

/** "4 öğeden 2'si tamamlandı · 1 başarısız" for the counts of a foreach step. */
export function workflowProgressText(counts) {
    const values = counts && typeof counts === 'object' ? counts : {};
    const total = count(values.total);

    if (total === 0) return 'Henüz öğe yok';

    const completed = count(values.completed);
    const parts = [`${integerText(total)} öğeden ${integerText(completed)}${turkishCountSuffix(completed)} tamamlandı`];

    EXTRA_COUNTS.forEach(([key, label]) => {
        const amount = count(values[key]);

        if (amount > 0) parts.push(`${integerText(amount)} ${label}`);
    });

    return parts.join(' · ');
}

/** "2/8" completed of all items in the run totals. */
export function workflowItemTotalsText(totals) {
    const values = totals && typeof totals === 'object' ? totals : {};

    return `${integerText(count(values.itemsCompleted))}/${integerText(count(values.itemsTotal))}`;
}

export function workflowFailedItemsText(totals) {
    const failed = count(totals && typeof totals === 'object' ? totals.itemsFailed : 0);

    return failed > 0 ? `${integerText(failed)} öğe başarısız` : 'Başarısız öğe yok';
}

export function workflowItemLabel(item) {
    const label = typeof item?.label === 'string' ? item.label.trim() : '';

    if (label !== '') return label;

    const number = Number.parseInt(item?.key, 10);

    return Number.isFinite(number) ? `Öğe ${number}` : 'Öğe';
}

/** "18:30:12" cut from a snapshot ISO time (already in the app timezone); "--:--:--" when there is none. */
export function workflowClockTime(time) {
    const match = typeof time === 'string' ? /T(\d{2}:\d{2}:\d{2})/.exec(time) : null;

    return match ? match[1] : '--:--:--';
}

/** "22 Eki 2026 · 18:30" cut from a snapshot ISO time, like the server's header; null when there is none. */
export function workflowDateTime(time) {
    const match = typeof time === 'string' ? /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(time) : null;
    const month = match ? TURKISH_MONTHS[Number(match[2]) - 1] : undefined;

    return match && month ? `${Number(match[3])} ${month} ${match[1]} · ${match[4]}:${match[5]}` : null;
}

/**
 * Seconds from an ISO time with an offset until `nowMs`, rounded to tenths; null when the time is unknown. The offset
 * makes the string one absolute instant, so parsing it does not depend on the browser's timezone.
 */
export function workflowElapsedSeconds(startedAt, nowMs = Date.now()) {
    if (typeof startedAt !== 'string' || !ISO_INSTANT_PATTERN.test(startedAt) || !Number.isFinite(nowMs)) return null;

    const started = Date.parse(startedAt);

    return Number.isFinite(started) ? Math.round(Math.max(0, nowMs - started) / 100) / 10 : null;
}

/** One line of the event log: time, level, model name, step/item and the message, joined with " · ". */
export function workflowEventLine(event, modelNames = {}) {
    if (!event || typeof event !== 'object') return '';

    const level = { warn: 'UYARI', error: 'HATA' }[event.level] ?? null;
    const model = typeof event.model === 'string' && event.model !== '' ? (own(modelNames, event.model) || event.model) : null;
    const where = [event.step, event.item].filter((part) => typeof part === 'string' && part !== '').join('/');

    return [workflowClockTime(event.time), level, model, where, typeof event.message === 'string' ? event.message : '']
        .filter((part) => typeof part === 'string' && part !== '')
        .join(' · ');
}

export function workflowEventLines(events, modelNames = {}) {
    const lines = (Array.isArray(events) ? events : []).map((event) => workflowEventLine(event, modelNames)).filter(Boolean);

    return lines.length > 0 ? lines.join('\n') : 'Henüz olay kaydı yok.';
}

/** The key that keeps one live-text box per step, child and item across polls and attempts. */
export function workflowTailKey(call) {
    return [call?.step, call?.child, call?.item].map((part) => (typeof part === 'string' ? part : '')).join('|');
}

/** "Demolar › Demo yaz · Zıplayan top · Deneme 2" for an active call, with titles from the model's steps. */
export function workflowActiveCallTitle(call, steps = []) {
    const step = (Array.isArray(steps) ? steps : []).find((candidate) => candidate?.id === call?.step);
    const path = [step?.title || call?.step || 'Adım'];

    if (call?.child) {
        const child = (Array.isArray(step?.children) ? step.children : []).find((candidate) => candidate?.id === call.child);
        path.push(child?.title || call.child);
    }

    const parts = [path.join(' › ')];
    const label = typeof call?.label === 'string' ? call.label.trim() : '';

    if (label !== '') parts.push(label);
    else if (call?.item) parts.push(workflowItemLabel({ key: call.item }));

    const attempt = count(call?.attempt);
    if (attempt > 0) parts.push(`Deneme ${attempt}`);

    return parts.join(' · ');
}

/** What one running call shows: its title, the time since it started, and its text or the note why there is none yet. */
export function workflowActiveCallView(call, steps = [], nowMs = Date.now()) {
    const text = typeof call?.tail === 'string' ? call.tail : '';

    return {
        key: workflowTailKey(call),
        title: workflowActiveCallTitle(call, steps),
        elapsed: formatWorkflowDuration(workflowElapsedSeconds(call?.startedAt, nowMs)),
        text,
        note: text === '' ? WORKFLOW_TAIL_PENDING_NOTE : null,
    };
}

/** What the "Son yanıt" box shows for `models[].lastOutput`; null when the model has not finished a call with text. */
export function workflowLastOutputView(output, steps = []) {
    if (!output || typeof output !== 'object' || typeof output.step !== 'string') return null;

    const parts = [output.step, output.child, output.item, output.finishedAt].map((part) => (typeof part === 'string' ? part : ''));

    return {
        key: parts.join('|'),
        title: workflowActiveCallTitle({ step: output.step, child: output.child, item: output.item, label: output.label }, steps),
        status: typeof output.status === 'string' ? output.status : null,
        time: workflowClockTime(output.finishedAt),
        text: typeof output.tail === 'string' && output.tail !== '' ? output.tail : 'Yanıt metni boş.',
    };
}

/** The output of a step as text: strings as they are, other values as pretty JSON; null when there is none. */
export function workflowOutputText(output) {
    if (output === null || output === undefined) return null;
    if (typeof output === 'string') return output;

    try {
        return JSON.stringify(output, null, 2);
    } catch {
        return null;
    }
}

/** A same-origin path from the server (`/workflows/runs/…`); anything else is refused. */
export function safeWorkflowPath(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') ? value : null;
}

export function workflowGallerySignature(totals, active) {
    const values = totals && typeof totals === 'object' ? totals : {};

    return `${count(values.itemsCompleted)}|${count(values.itemsFailed)}|${Boolean(active)}`;
}

/* ---------------------------------------------------------------- DOM ---------------------------------------------------------------- */

function setText(element, text) {
    if (element && element.textContent !== text) element.textContent = text;
}

function setMessage(element, message) {
    if (!element) return;

    const text = typeof message === 'string' ? message : '';
    setText(element, text);
    element.classList.toggle('hidden', text === '');
}

function updateStatusBadge(badge, status) {
    if (!badge) return;

    const toneClass = `status-${workflowStatusTone(status)}`;

    if (!badge.classList.contains(toneClass)) {
        badge.classList.remove(...STATUS_TONE_CLASSES);
        badge.classList.add(toneClass);
    }
    setText(badge.querySelector('[data-workflow-status-label]'), workflowStatusLabel(status));
}

function isScrolledToEnd(element) {
    return element.scrollHeight - element.scrollTop - element.clientHeight <= SCROLL_STICK_PX;
}

function scrollToEnd(element) {
    if (element) element.scrollTop = element.scrollHeight;
}

/** Replaces the text of a live <pre> and keeps it pinned to the end when the reader was already there. */
function updateLiveText(element, text) {
    if (!element || element.textContent === text) return;

    const pinned = isScrolledToEnd(element);
    element.textContent = text;
    if (pinned) scrollToEnd(element);
}

function setDetailTarget(element, modelSlug, stepId, itemKey = null, childId = null) {
    element.dataset.workflowDetail = '';
    element.dataset.detailModel = modelSlug;
    element.dataset.detailStep = stepId;
    if (itemKey && childId) {
        element.dataset.detailItem = itemKey;
        element.dataset.detailChild = childId;
    }
}

function element(tag, className = '', text = null) {
    const node = document.createElement(tag);

    if (className) node.className = className;
    if (text !== null) node.textContent = text;

    return node;
}

function statusBadge(status) {
    const badge = element('span', `status-badge status-${workflowStatusTone(status)}`);
    badge.append(element('span', 'status-dot'), element('span', '', workflowStatusLabel(status)));
    badge.lastChild.dataset.workflowStatusLabel = '';

    return badge;
}

function buildItemRow(modelSlug, step, children, item, active) {
    const row = element('li', 'workflow-item');
    const firstChild = children[0];
    const label = element(firstChild ? 'button' : 'span', 'workflow-item-label', workflowItemLabel(item));
    const marks = element('span', 'workflow-item-marks');

    row.dataset.workflowItem = item.key;
    row.dataset.workflowItemStatus = typeof item.status === 'string' ? item.status : 'pending';

    if (firstChild) {
        label.type = 'button';
        label.setAttribute('aria-haspopup', 'dialog');
        setDetailTarget(label, modelSlug, step.id, item.key, firstChild.id);
    }

    children.forEach((child) => {
        const status = workflowDisplayStatus(own(item.steps && typeof item.steps === 'object' ? item.steps : {}, child.id) || 'pending', active);
        const mark = workflowMark(status);
        const button = element('button', `stage-mark stage-mark-${mark.tone} workflow-item-mark`, mark.symbol);
        const description = `${child.title || child.id}: ${workflowStatusLabel(status)}`;

        button.type = 'button';
        button.title = description;
        button.setAttribute('aria-label', `${workflowItemLabel(item)} · ${description}`);
        button.setAttribute('aria-haspopup', 'dialog');
        setDetailTarget(button, modelSlug, step.id, item.key, child.id);
        marks.append(button);
    });

    row.append(label, marks);

    return row;
}

function focusedDetailTarget(container) {
    const active = document.activeElement;

    if (!active || !container.contains(active) || active.dataset?.detailItem === undefined) return null;

    return { item: active.dataset.detailItem, child: active.dataset.detailChild, mark: active.classList.contains('workflow-item-mark') };
}

function restoreDetailFocus(container, target) {
    if (!target) return;

    const match = [...container.querySelectorAll('[data-workflow-detail]')].find((candidate) => candidate.dataset.detailItem === target.item
        && candidate.dataset.detailChild === target.child
        && candidate.classList.contains('workflow-item-mark') === target.mark);
    match?.focus();
}

function updateItems(view, list, modelSlug, step, active) {
    if (!list) return;

    const items = (Array.isArray(step.items) ? step.items : []).filter((item) => item && typeof item.key === 'string');
    const children = (Array.isArray(step.children) ? step.children : []).filter((child) => child && typeof child.id === 'string');
    const signature = JSON.stringify([items, children, active]);

    if (view.itemSignatures.get(list) === signature) return;

    const focused = focusedDetailTarget(list);
    list.replaceChildren(...items.map((item) => buildItemRow(modelSlug, step, children, item, active)));
    list.classList.toggle('hidden', items.length === 0);
    view.itemSignatures.set(list, signature);
    restoreDetailFocus(list, focused);
}

function updateStepBox(view, box, modelSlug, step, active) {
    const status = typeof step.status === 'string' ? step.status : 'pending';
    const usage = step.usage && typeof step.usage === 'object' ? step.usage : {};

    if (box.dataset.workflowStepStatus !== status) box.dataset.workflowStepStatus = status;
    updateStatusBadge(box.querySelector('[data-workflow-step-badge]'), workflowDisplayStatus(status, active));
    setText(box.querySelector('[data-workflow-step-metric="duration"]'), formatWorkflowDuration(step.durationSeconds));
    setText(box.querySelector('[data-workflow-step-metric="tokens"]'), formatWorkflowTokens(usage.inputTokens, usage.outputTokens));
    setText(box.querySelector('[data-workflow-step-metric="attempts"]'), formatWorkflowNumber(step.attempts));
    setMessage(box.querySelector('[data-workflow-step-message]'), step.message);

    if (step.type === 'foreach') {
        setText(box.querySelector('[data-workflow-step-progress]'), workflowProgressText(step.counts));
        updateItems(view, box.querySelector('[data-workflow-step-items]'), modelSlug, step, active);
    }
}

/** Same structure as one running call in _models.blade.php. */
function buildTail(key) {
    const box = element('article', 'workflow-tail');
    const head = element('div', 'workflow-tail-head');
    const heading = element('p', 'workflow-tail-title');
    const meta = element('span', 'workflow-tail-meta');
    const elapsed = element('span');
    const note = element('p', 'workflow-tail-note', WORKFLOW_TAIL_PENDING_NOTE);
    const text = element('pre', 'workflow-tail-text');

    box.dataset.workflowTail = key;
    heading.dataset.workflowTailTitle = '';
    elapsed.dataset.workflowTailElapsed = '';
    note.dataset.workflowTailNote = '';
    text.dataset.workflowTailText = '';
    text.tabIndex = 0;
    meta.append(element('span', '', 'Geçen süre'), elapsed);
    head.append(heading, meta);
    box.append(head, note, text);

    return box;
}

function updateTails(panel, calls, steps) {
    const container = panel.querySelector('[data-workflow-tails]');

    if (!container) return;

    const existing = new Map([...container.children].map((child) => [child.dataset.workflowTail, child]));
    const wanted = new Map();
    const nowMs = Date.now();

    calls.forEach((call) => {
        if (!call || typeof call.step !== 'string') return;

        const key = workflowTailKey(call);
        if (!wanted.has(key)) wanted.set(key, call);
    });

    existing.forEach((child, key) => {
        if (!wanted.has(key)) child.remove();
    });

    [...wanted].forEach(([key, call], index) => {
        const callView = workflowActiveCallView(call, steps, nowMs);
        const box = existing.get(key) || buildTail(key);
        const isNew = !box.isConnected;

        setText(box.querySelector('[data-workflow-tail-title]'), callView.title);
        setText(box.querySelector('[data-workflow-tail-elapsed]'), callView.elapsed);
        box.querySelector('[data-workflow-tail-note]')?.classList.toggle('hidden', callView.note === null);
        if (container.children[index] !== box) container.insertBefore(box, container.children[index] || null);

        const text = box.querySelector('[data-workflow-tail-text]');
        text?.classList.toggle('hidden', callView.text === '');
        updateLiveText(text, callView.text);
        if (isNew) scrollToEnd(text);
    });

    panel.querySelector('[data-workflow-tails-empty]')?.classList.toggle('hidden', wanted.size > 0);
}

/** The "Son yanıt" box: the end of the model's last finished answer, replaced in place when a newer one arrives. */
function updateLastOutput(panel, output, steps) {
    const box = panel.querySelector('[data-workflow-last-output]');

    if (!box) return;

    const lastOutput = workflowLastOutputView(output, steps);

    box.classList.toggle('hidden', lastOutput === null);
    panel.querySelector('[data-workflow-last-output-empty]')?.classList.toggle('hidden', lastOutput !== null);

    if (!lastOutput) return;

    const text = box.querySelector('[data-workflow-last-output-text]');
    const isNewAnswer = box.dataset.lastOutputKey !== lastOutput.key;

    setText(box.querySelector('[data-workflow-last-output-title]'), lastOutput.title);
    updateStatusBadge(box.querySelector('[data-workflow-last-output-status]'), lastOutput.status);
    setText(box.querySelector('[data-workflow-last-output-time]'), lastOutput.time);
    updateLiveText(text, lastOutput.text);

    if (isNewAnswer) {
        box.dataset.lastOutputKey = lastOutput.key;
        scrollToEnd(text);
    }
}

function updateModelPanel(view, panel, model, active) {
    const usage = model.usage && typeof model.usage === 'object' ? model.usage : {};
    const items = model.items && typeof model.items === 'object' ? model.items : {};
    const steps = Array.isArray(model.steps) ? model.steps : [];
    const boxes = new Map([...panel.querySelectorAll('[data-workflow-step]')].map((box) => [box.dataset.workflowStep, box]));
    const metric = (name) => panel.querySelector(`[data-workflow-model-metric="${name}"]`);

    updateStatusBadge(panel.querySelector('[data-workflow-model-status]'), model.status);
    setMessage(panel.querySelector('[data-workflow-model-message]'), model.message);
    setText(metric('duration'), formatWorkflowDuration(model.durationSeconds));
    setText(metric('tokens'), formatWorkflowTokens(usage.inputTokens, usage.outputTokens));
    setText(metric('cost'), formatWorkflowCost(usage.costUsd));
    setText(metric('calls'), formatWorkflowNumber(usage.calls));
    setText(metric('items'), workflowItemTotalsText({ itemsCompleted: items.completed, itemsTotal: items.total }));

    steps.forEach((step) => {
        const box = step && typeof step.id === 'string' ? boxes.get(step.id) : null;
        if (box) updateStepBox(view, box, model.slug, step, active);
    });

    updateTails(panel, Array.isArray(model.active) ? model.active : [], steps);
    updateLastOutput(panel, model.lastOutput, steps);
}

function applySnapshot(view, state) {
    if (!state || typeof state !== 'object') return;

    const { root } = view;
    const active = Boolean(state.active);
    const totals = state.totals && typeof state.totals === 'object' ? state.totals : {};
    const models = (Array.isArray(state.models) ? state.models : []).filter((model) => model && typeof model.slug === 'string');
    const total = (name) => root.querySelector(`[data-workflow-total="${name}"]`);
    const panels = new Map([...root.querySelectorAll('[data-workflow-model-panel]')].map((panel) => [panel.dataset.workflowModelPanel, panel]));
    const tabs = new Map([...root.querySelectorAll('[data-workflow-model-tab]')].map((tab) => [tab.dataset.workflowModelTab, tab]));
    const modelNames = Object.fromEntries(models.map((model) => [model.slug, typeof model.name === 'string' && model.name !== '' ? model.name : model.slug]));

    updateStatusBadge(root.querySelector('[data-workflow-status]'), state.status);
    setMessage(root.querySelector('[data-workflow-message]'), state.message);

    setText(total('duration'), formatWorkflowDuration(state.durationSeconds));
    setText(total('inputTokens'), formatWorkflowNumber(totals.inputTokens));
    setText(total('outputTokens'), formatWorkflowNumber(totals.outputTokens));
    setText(total('cost'), formatWorkflowCost(totals.costUsd));
    setText(total('calls'), formatWorkflowNumber(totals.calls));
    setText(total('items'), workflowItemTotalsText(totals));
    setText(total('itemsFailed'), workflowFailedItemsText(totals));

    models.forEach((model) => {
        updateStatusBadge(tabs.get(model.slug)?.querySelector('[data-workflow-tab-status]'), model.status);

        const panel = panels.get(model.slug);
        if (panel) updateModelPanel(view, panel, model, active);
    });

    updateLiveText(root.querySelector('[data-workflow-events]'), workflowEventLines(state.events, modelNames));

    view.active = active;
    root.dataset.active = String(view.active);
    if (typeof state.status === 'string') root.dataset.currentStatus = state.status;

    // Polling ends with an inactive snapshot, so the "updates every 2 s" hints go with it.
    root.querySelectorAll('[data-workflow-live-hint]').forEach((hint) => hint.classList.toggle('hidden', !view.active));

    const finishedAt = workflowDateTime(state.finishedAt);
    root.querySelectorAll('[data-workflow-finished]').forEach((part) => part.classList.toggle('hidden', finishedAt === null));
    if (finishedAt !== null) setText(root.querySelector('[data-workflow-finished-time]'), finishedAt);

    const stopForm = root.querySelector('[data-workflow-stop]');
    if (stopForm) {
        stopForm.classList.toggle('hidden', !view.active);

        const button = stopForm.querySelector('button[type="submit"]');
        if (button && state.status === 'stopping') {
            button.disabled = true;
            setText(button, 'Durduruluyor…');
        }
    }

    const signature = workflowGallerySignature(totals, view.active);
    if (signature !== view.gallerySignature) {
        view.gallerySignature = signature;
        void refreshGallery(view);
    }
}

async function refreshGallery(view) {
    const region = view.root.querySelector('[data-workflow-gallery]');

    if (!region || typeof DOMParser === 'undefined') return;
    if (view.galleryLoading) {
        view.galleryPending = true;

        return;
    }

    view.galleryLoading = true;
    const notice = view.root.querySelector('[data-workflow-refresh-notice]');

    try {
        const response = await fetch(window.location.href, { cache: 'no-store', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error('Run page unavailable');

        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-workflow-gallery]');
        if (!fresh) throw new Error('Gallery region missing');

        const focusedCard = document.activeElement && region.contains(document.activeElement)
            ? document.activeElement.closest('[data-workflow-gallery-card]')?.dataset.workflowGalleryCard
            : null;

        region.replaceChildren(...[...fresh.childNodes].map((node) => document.importNode(node, true)));
        notice?.classList.add('hidden');

        if (focusedCard) {
            const card = [...region.querySelectorAll('[data-workflow-gallery-card]')].find((candidate) => candidate.dataset.workflowGalleryCard === focusedCard);
            card?.querySelector('button, a')?.focus();
        }
    } catch {
        notice?.classList.remove('hidden');
    } finally {
        view.galleryLoading = false;
        if (view.galleryPending) {
            view.galleryPending = false;
            void refreshGallery(view);
        }
    }
}

function schedulePoll(view) {
    window.setTimeout(() => void pollStatus(view), WORKFLOW_POLL_INTERVAL_MS);
}

async function pollStatus(view) {
    if (!view.active) return;

    try {
        const response = await fetch(view.root.dataset.statusUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Status unavailable');

        applySnapshot(view, await response.json());
    } catch {
        // A temporary local connection issue leaves the current view intact.
    } finally {
        if (view.active) schedulePoll(view);
    }
}

function setUpTabs(root) {
    const tabs = [...root.querySelectorAll('[data-workflow-model-tab]')];

    if (tabs.length === 0) return;

    const select = (tab, moveFocus) => {
        tabs.forEach((candidate) => {
            const selected = candidate === tab;
            const panel = document.getElementById(candidate.getAttribute('aria-controls') || '');

            candidate.setAttribute('aria-selected', String(selected));
            candidate.tabIndex = selected ? 0 : -1;
            if (panel) panel.hidden = !selected;
            if (panel && selected) panel.querySelectorAll('[data-workflow-tail-text], [data-workflow-last-output-text]').forEach(scrollToEnd);
        });
        if (moveFocus) tab.focus();
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab, false));
        tab.addEventListener('keydown', (event) => {
            const targets = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 };
            const target = own(targets, event.key);

            if (target === undefined) return;

            event.preventDefault();
            select(tabs[(target + tabs.length) % tabs.length], true);
        });
    });
}

function definitionRow(list, label, value) {
    const row = element('div', 'workflow-detail-fact');
    row.append(element('dt', '', label), element('dd', '', value));
    list.append(row);
}

function detailSection(heading) {
    const section = element('section', 'workflow-detail-section');
    section.append(element('h3', 'workflow-detail-heading', heading));

    return section;
}

function textBlock(text) {
    const block = element('pre', 'workflow-detail-text', text);
    block.tabIndex = 0;

    return block;
}

function checkList(heading, entries, tone) {
    const wrapper = element('div', 'workflow-detail-checks');
    const list = element('ul', '');

    wrapper.append(element('p', `workflow-detail-check-title workflow-check-${tone}`, `${heading} · ${integerText(entries.length)}`));
    entries.forEach((entry) => list.append(element('li', '', typeof entry?.message === 'string' ? entry.message : String(entry ?? ''))));
    wrapper.append(list);

    return wrapper;
}

function renderOutput(body, detail) {
    const section = detailSection('Çıktı');
    const type = detail.step?.type;
    const output = detail.output;

    if (CODE_STEP_TYPES.includes(type) && output && typeof output === 'object') {
        const checks = output.checks && typeof output.checks === 'object' ? output.checks : null;
        const facts = element('dl', 'workflow-detail-facts');

        definitionRow(facts, 'Dosya', typeof output.file === 'string' ? output.file : WORKFLOW_UNKNOWN);
        definitionRow(facts, 'Boyut', formatWorkflowBytes(output.bytes));
        section.append(facts);

        if (!checks) {
            section.append(element('p', 'workflow-detail-note', 'Statik kontrol sonucu yok.'));
        } else {
            const critical = Array.isArray(checks.critical) ? checks.critical : [];
            const warnings = Array.isArray(checks.warnings) ? checks.warnings : [];

            if (critical.length === 0 && warnings.length === 0) section.append(element('p', 'workflow-detail-note', 'Statik kontroller sorun bulmadı.'));
            if (critical.length > 0) section.append(checkList('Kritik sorunlar', critical, 'fail'));
            if (warnings.length > 0) section.append(checkList('Uyarılar', warnings, 'unknown'));
        }
    } else {
        const text = workflowOutputText(output);
        section.append(text === null ? element('p', 'workflow-detail-note', 'Henüz çıktı yok.') : textBlock(text));
    }

    if (detail.outputTruncated) section.append(element('p', 'workflow-detail-note', 'Çıktı 200 KB sınırında kesildi.'));

    body.append(section);
}

function renderAttempts(body, attempts) {
    const section = detailSection(`Denemeler · ${integerText(attempts.length)}`);

    if (attempts.length === 0) {
        section.append(element('p', 'workflow-detail-note', 'Bu adım için deneme kaydı yok.'));
        body.append(section);

        return;
    }

    attempts.forEach((attempt, index) => {
        const fold = element('details', 'workflow-attempt');
        const usage = attempt.usage && typeof attempt.usage === 'object' ? attempt.usage : {};
        const summary = [
            `Deneme ${count(attempt.n) || index + 1}`,
            own(ATTEMPT_KIND_LABELS, attempt.kind),
            attempt.status ? workflowStatusLabel(attempt.status) : null,
            formatWorkflowDuration(attempt.durationSeconds),
        ].filter(Boolean).join(' · ');
        const facts = element('dl', 'workflow-detail-facts');

        fold.open = index === attempts.length - 1;
        fold.append(element('summary', '', summary));
        if (typeof attempt.error === 'string' && attempt.error !== '') fold.append(element('p', 'workflow-detail-error', attempt.error));
        definitionRow(facts, 'Token', formatWorkflowTokens(usage.inputTokens, usage.outputTokens));
        definitionRow(facts, 'Maliyet', formatWorkflowCost(usage.costUsd));
        fold.append(facts);

        [['Gönderilen prompt', attempt.prompt, attempt.promptTruncated], ['Model yanıtı', attempt.response, attempt.responseTruncated]].forEach(([label, text, truncated]) => {
            fold.append(element('p', 'workflow-detail-label', truncated ? `${label} (200 KB sınırında kesildi)` : label));
            fold.append(typeof text === 'string' ? textBlock(text) : element('p', 'workflow-detail-note', 'Dosya yok.'));
        });

        section.append(fold);
    });

    body.append(section);
}

function renderDetail(dialog, detail, active) {
    const body = dialog.querySelector('[data-workflow-detail-body]');
    const step = detail.step && typeof detail.step === 'object' ? detail.step : {};
    const usage = detail.usage && typeof detail.usage === 'object' ? detail.usage : {};
    const item = detail.item && typeof detail.item === 'object' ? detail.item : null;
    const facts = element('dl', 'workflow-detail-facts');
    const chips = element('div', 'workflow-detail-chips');
    const demoUrl = safeWorkflowPath(detail.demoUrl);

    setText(dialog.querySelector('[data-workflow-detail-eyebrow]'), detail.model?.name || detail.model?.slug || 'Adım ayrıntısı');
    setText(dialog.querySelector('[data-workflow-detail-title]'), [step.title || step.id || 'Adım', item ? workflowItemLabel(item) : null].filter(Boolean).join(' · '));

    if (!body) return;

    chips.append(element('span', 'workflow-type-chip', workflowStepTypeLabel(step.type)), statusBadge(workflowDisplayStatus(detail.status, active)));
    if (demoUrl) {
        const link = element('a', 'btn-soft btn-xs', 'Demoyu aç ↗');
        link.href = demoUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        chips.append(link);
    }

    definitionRow(facts, 'Süre', formatWorkflowDuration(detail.durationSeconds));
    definitionRow(facts, 'Token', formatWorkflowTokens(usage.inputTokens, usage.outputTokens));
    definitionRow(facts, 'Maliyet', formatWorkflowCost(usage.costUsd));
    definitionRow(facts, 'Çağrı', formatWorkflowNumber(usage.calls));

    const parts = [chips];
    if (typeof detail.message === 'string' && detail.message !== '') parts.push(element('p', 'workflow-detail-message', detail.message));
    parts.push(facts);
    body.replaceChildren(...parts);

    if (step.type === 'foreach') {
        body.append(element('p', 'workflow-detail-note', 'Bu bir döngü adımı. Öğe ayrıntıları için adım kutusundaki listeden bir öğe seçin.'));

        return;
    }

    renderOutput(body, detail);
    renderAttempts(body, Array.isArray(detail.attempts) ? detail.attempts.filter((attempt) => attempt && typeof attempt === 'object') : []);
}

function renderDetailNotice(dialog, text) {
    dialog.querySelector('[data-workflow-detail-body]')?.replaceChildren(element('p', 'workflow-detail-note', text));
}

async function loadDetail(view, dialog, reference) {
    const request = ++view.detailRequest;
    const url = new URL(view.root.dataset.detailUrl, window.location.origin);

    url.searchParams.set('model', reference.model);
    url.searchParams.set('step', reference.step);
    if (reference.item && reference.child) {
        url.searchParams.set('item', reference.item);
        url.searchParams.set('child', reference.child);
    }

    renderDetailNotice(dialog, 'Ayrıntılar yükleniyor…');

    try {
        const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });

        if (request !== view.detailRequest) return;
        if (response.status === 404) {
            renderDetailNotice(dialog, view.active ? 'Bu adım için henüz kayıt yok.' : 'Bu adım için kayıt yok.');

            return;
        }
        if (!response.ok) throw new Error('Detail unavailable');

        const detail = await response.json();
        if (request === view.detailRequest && detail && typeof detail === 'object') renderDetail(dialog, detail, view.active);
    } catch {
        if (request === view.detailRequest) renderDetailNotice(dialog, 'Ayrıntılar yüklenemedi. Biraz sonra Yenile ile yeniden deneyin.');
    }
}

function openDetail(view, target) {
    const dialog = view.root.querySelector('[data-workflow-detail-panel]');
    const reference = {
        model: target.dataset.detailModel,
        step: target.dataset.detailStep,
        item: target.dataset.detailItem || null,
        child: target.dataset.detailChild || null,
    };

    if (!dialog || typeof dialog.showModal !== 'function' || !reference.model || !reference.step) return;

    view.detailReference = reference;
    setText(dialog.querySelector('[data-workflow-detail-eyebrow]'), 'Adım ayrıntısı');
    setText(dialog.querySelector('[data-workflow-detail-title]'), target.dataset.detailTitle || 'Adım');
    if (!dialog.open) dialog.showModal();
    void loadDetail(view, dialog, reference);
}

function openDemo(view, button) {
    const dialog = view.root.querySelector('[data-workflow-demo-dialog]');
    const template = button.closest('[data-workflow-gallery-card]')?.querySelector('template[data-workflow-demo-template]');

    if (!dialog || !template || typeof dialog.showModal !== 'function') return;

    const content = template.content.cloneNode(true);
    const heading = content.querySelector('[data-workflow-demo-part="heading"]');
    const frame = content.querySelector('[data-workflow-demo-part="frame"]');

    if (!frame || frame.getAttribute('sandbox') !== 'allow-scripts') return;

    dialog.querySelector('[data-workflow-demo-heading]')?.replaceChildren(...(heading ? [heading] : []));
    dialog.querySelector('[data-workflow-demo-stage]')?.replaceChildren(frame);
    dialog.showModal();
}

function setUpDialogs(view) {
    const { root } = view;
    const detailDialog = root.querySelector('[data-workflow-detail-panel]');
    const demoDialog = root.querySelector('[data-workflow-demo-dialog]');

    root.addEventListener('click', (event) => {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') return;

        const play = target.closest('[data-workflow-play]');
        if (play && root.contains(play)) {
            openDemo(view, play);

            return;
        }

        const detail = target.closest('[data-workflow-detail]');
        if (detail && root.contains(detail) && !detail.closest('dialog')) openDetail(view, detail);
    });

    [detailDialog, demoDialog].forEach((dialog) => {
        // A click on the dialog element itself (outside its content box) is a click on the backdrop.
        dialog?.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    detailDialog?.querySelector('[data-workflow-detail-reload]')?.addEventListener('click', () => {
        if (view.detailReference) void loadDetail(view, detailDialog, view.detailReference);
    });

    // Closing the player unloads the demo, so its game loop and sound stop.
    demoDialog?.addEventListener('close', () => {
        demoDialog.querySelector('[data-workflow-demo-stage]')?.replaceChildren();
        demoDialog.querySelector('[data-workflow-demo-heading]')?.replaceChildren();
    });
}

function setUpStopForm(root) {
    const stopForm = root.querySelector('[data-workflow-stop]');

    stopForm?.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;

        const button = stopForm.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Durduruluyor…';
        }
    });
}

/**
 * Wires the live run page. Safe on every page: it returns when `[data-workflow-run]` is missing and never throws,
 * so the initializers that run after it keep working.
 */
export function initializeWorkflowRun() {
    try {
        const root = typeof document === 'undefined' ? null : document.querySelector('[data-workflow-run]');

        if (!root) return;

        const view = {
            root,
            active: root.dataset.active === 'true',
            gallerySignature: root.dataset.gallerySignature ?? null,
            galleryLoading: false,
            galleryPending: false,
            detailRequest: 0,
            detailReference: null,
            itemSignatures: new WeakMap(),
        };

        setUpTabs(root);
        setUpDialogs(view);
        setUpStopForm(root);
        root.querySelectorAll('[data-workflow-autoscroll], [data-workflow-tail-text], [data-workflow-last-output-text]').forEach(scrollToEnd);

        if (view.active) schedulePoll(view);
    } catch {
        // The server-rendered page stays readable without live updates.
    }
}
