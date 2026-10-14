import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const classifierPath = join(dirname(fileURLToPath(import.meta.url)), '../../engine/tools/classify-opencode-failure.mjs');
const { classifyEventLog, isProviderError, lastStopReason } = await import(pathToFileURL(classifierPath));

const errorEvent = (error) => ({ type: 'error', sessionID: 'ses_fixture', error });

test('treats server, capacity and unexpected server errors as provider failures', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 500, message: 'Upstream request failed: Endpoint is unavailable.' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 429, message: 'Rate limited' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { isRetryable: true } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'UnknownError', data: { message: 'Unexpected server error. Check server logs for details.' } })), true);
});

test('keeps model, request and authentication errors as model failures', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'Invalid tool call' } })), false);
    assert.equal(isProviderError(errorEvent({ name: 'ProviderAuthError', data: { message: 'Missing key' } })), false);
    assert.equal(isProviderError(errorEvent({ name: 'UnknownError', data: { message: 'Tool failed' } })), false);
    assert.equal(isProviderError({ type: 'step_finish' }), false);
});

test('classifies an event log by its last error and treats missing logs as model failures', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-classify-'));

    try {
        const providerLog = join(root, 'provider.jsonl');
        const modelLog = join(root, 'model.jsonl');
        await writeFile(providerLog, [
            JSON.stringify({ type: 'step_start' }),
            'not json',
            JSON.stringify(errorEvent({ name: 'UnknownError', data: { message: 'Unexpected server error.' } })),
        ].join('\n'));
        await writeFile(modelLog, [
            JSON.stringify(errorEvent({ name: 'APIError', data: { statusCode: 503 } })),
            JSON.stringify(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'Bad request' } })),
        ].join('\n'));

        assert.equal(await classifyEventLog(providerLog), 'provider');
        assert.equal(await classifyEventLog(modelLog), 'model');
        assert.equal(await classifyEventLog(join(root, 'missing.jsonl')), 'model');
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('treats an account privacy refusal as a provider-side failure', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 400, isRetryable: false, message: "Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it." } })), true);
});

test('reports the reason of the last finished step', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-stop-'));

    try {
        const log = join(root, 'events.jsonl');
        await writeFile(log, [
            JSON.stringify({ type: 'step_finish', part: { reason: 'tool-calls' } }),
            'not json',
            JSON.stringify({ type: 'step_finish', part: { reason: 'length' } }),
        ].join('\n'));
        assert.equal(await lastStopReason(log), 'length');
        assert.equal(await lastStopReason(join(root, 'missing.jsonl')), 'unknown');
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});
