import assert from 'node:assert/strict';
import test from 'node:test';

const originalDocument = globalThis.document;
let initializePage;

globalThis.document = {
    addEventListener: (eventName, handler) => {
        assert.equal(eventName, 'DOMContentLoaded');
        initializePage = handler;
    },
};

const { initializeDestructiveForms } = await import('../../resources/js/app.js');

globalThis.document = originalDocument;

function deletionForm(message = 'Only delete this workspace?', confirmation = { value: '' }) {
    let submitHandler;
    const feedback = [];
    const button = { disabled: false, textContent: 'Benchmarkı tamamen sil' };
    const form = {
        dataset: { confirmMessage: message },
        querySelector: (selector) => selector === 'input[name="confirmation"]' ? confirmation : button,
        querySelectorAll: () => [button],
        addEventListener: (eventName, handler) => {
            assert.equal(eventName, 'submit');
            submitHandler = handler;
        },
        append: (element) => feedback.push(element),
    };

    return {
        form,
        button,
        confirmation,
        feedback,
        submit: () => {
            let prevented = false;
            submitHandler({
                submitter: button,
                preventDefault: () => { prevented = true; },
            });

            return prevented;
        },
    };
}

function withPage(forms, approve, callback, { attemptRefresh = false } = {}) {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const state = { promptCount: 0, promptMessage: null, valuesAtPrompt: [], timeout: null, cancelledTimeout: null, reloadCount: 0 };

    globalThis.document = {
        querySelectorAll: (selector) => selector === '[data-destructive-form]' ? forms.map(({ form }) => form) : [],
        querySelector: (selector) => {
            if (selector === '[data-attempt-refresh]') return attemptRefresh ? {} : null;
            if (selector === '[data-destructive-form][data-submitting]') {
                return forms.find(({ form }) => form.dataset.submitting === 'true')?.form || null;
            }

            return null;
        },
        createElement: () => ({ setAttribute(name, value) { this[name] = value; } }),
        addEventListener() {},
    };
    globalThis.window = {
        confirm: (message) => {
            state.promptCount += 1;
            state.promptMessage = message;
            state.valuesAtPrompt = forms.map(({ confirmation }) => confirmation?.value);

            return approve;
        },
        setTimeout: (handler) => {
            state.timeout = handler;

            return 17;
        },
        clearTimeout: (timeoutId) => { state.cancelledTimeout = timeoutId; },
        location: { reload: () => { state.reloadCount += 1; } },
    };

    try {
        callback(state);
    } finally {
        globalThis.document = previousDocument;
        globalThis.window = previousWindow;
    }
}

test('approved deletion submits once with confirmation and visible progress', () => {
    const selected = deletionForm();
    const other = deletionForm('Delete results?');

    withPage([selected, other], true, (state) => {
        initializeDestructiveForms();

        assert.equal(selected.submit(), false);
        assert.equal(selected.confirmation.value, '1');
        assert.equal(state.promptMessage, 'Only delete this workspace?');
        assert.deepEqual(state.valuesAtPrompt, ['', '']);
        assert.equal(selected.button.textContent, 'Siliniyor…');
        assert.equal(selected.button.disabled, true);
        assert.equal(other.button.disabled, true);
        assert.equal(selected.feedback.length, 1);
        assert.equal(selected.feedback[0].textContent, 'Dosyalar siliniyor; klasör büyüklüğüne göre sürebilir.');
        assert.equal(selected.feedback[0].role, 'status');
        assert.equal(selected.feedback[0]['aria-live'], 'polite');

        assert.equal(selected.submit(), true);
        assert.equal(state.promptCount, 1);
        assert.equal(selected.feedback.length, 1);
    });
});

test('cancelling deletion leaves buttons and progress unchanged', () => {
    const selected = deletionForm();
    const other = deletionForm('Delete results?');
    selected.confirmation.value = '1';

    withPage([selected, other], false, () => {
        initializeDestructiveForms();

        assert.equal(selected.submit(), true);
        assert.equal(selected.confirmation.value, '');
        assert.equal(selected.button.disabled, false);
        assert.equal(other.button.disabled, false);
        assert.equal(selected.button.textContent, 'Benchmarkı tamamen sil');
        assert.equal(selected.feedback.length, 0);
    });
});

test('deletion without a confirmation field never prompts or submits', () => {
    const selected = deletionForm(undefined, null);

    withPage([selected], true, (state) => {
        initializeDestructiveForms();

        assert.equal(selected.submit(), true);
        assert.equal(state.promptCount, 0);
        assert.equal(selected.feedback.length, 0);
    });
});

test('approved deletion cancels attempt refresh and prevents a queued reload', () => {
    const selected = deletionForm();

    withPage([selected], true, (state) => {
        initializePage();
        assert.equal(typeof state.timeout, 'function');

        assert.equal(selected.submit(), false);
        assert.equal(state.cancelledTimeout, 17);

        state.timeout();
        assert.equal(state.reloadCount, 0);
    }, { attemptRefresh: true });
});
