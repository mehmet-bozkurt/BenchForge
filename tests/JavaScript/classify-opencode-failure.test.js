import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const classifierPath = join(dirname(fileURLToPath(import.meta.url)), '../../engine/tools/classify-opencode-failure.mjs');
const { classifyEventLog, isProviderError, isUnexpectedServerError, lastStopReason, providerErrorKind } = await import(pathToFileURL(classifierPath));

const errorEvent = (error) => ({ type: 'error', sessionID: 'ses_fixture', error });

// The real OpenCode Go refusal from runs/plush-octopus-v2/_logs/go-gemini-4-argon-contributor-5cbf1a.opencode.events.jsonl.
const goPrivacyRefusal = JSON.parse(String.raw`{"type":"error","timestamp":1792087229006,"sessionID":"ses_ebf4703bcffe9MlbN2w6FPp2BB","error":{"name":"APIError","data":{"message":"Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it.","statusCode":400,"isRetryable":false,"responseHeaders":{"cf-ray":"a4b0b93beea9b26e-PIT","connection":"keep-alive","content-length":"212","content-type":"application/json","date":"Thu, 15 Oct 2026 18:00:28 GMT","server":"cloudflare","x-opencode-log-id":"6c416b05-df2c-4ca7-bdea-e61a3bbc3f3c"},"responseBody":"{\"error\":{\"code\":400,\"status\":\"UNKNOWN\",\"message\":\"Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it.\"}}","metadata":{"url":"https://opencode.ai/inference/go/v1/models/gemini-4-argon-contributor:streamGenerateContent?alt=sse"}}}}`);

test('treats server, capacity and unexpected server errors as provider failures', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 500, message: 'Upstream request failed: Endpoint is unavailable.' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 429, message: 'Rate limited' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { isRetryable: true } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'UnknownError', data: { message: 'Unexpected server error. Check server logs for details.' } })), true);
});

test('keeps model and request errors as model failures', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'Invalid tool call' } })), false);
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

test('treats a refused key, subscription or missing credential as an account failure', () => {
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 401, isRetryable: false, message: 'This API key has expired. Create a new API key in OpenCode Console and replace it in your client or secret store.' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'APIError', data: { statusCode: 403, isRetryable: false, message: 'Upstream request failed: An active OpenCode Go subscription is required to use Go models.' } })), true);
    assert.equal(isProviderError(errorEvent({ name: 'ProviderAuthError', data: { message: 'Missing key' } })), true);
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

test('sorts provider errors that should be retried after a wait as transient', () => {
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 429, isRetryable: true, message: 'Rate limited' } })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 503, message: 'Upstream request failed: Endpoint is unavailable.' } })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { isRetryable: true, message: 'Overloaded' } })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ name: 'UnknownError', data: { message: 'Unexpected server error. Check server logs for details.' } })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ message: 'Rate limited', code: 429 })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ message: 'Rate limited', status: '502' })), 'transient');
    assert.equal(providerErrorKind(errorEvent({ code: 'rate_limit_exceeded' })), 'transient');
    assert.equal(providerErrorKind({ type: 'error', part: { error: { code: 429 } } }), 'transient');
    assert.equal(providerErrorKind(errorEvent({ name: 'UnknownError', code: 'ECONNRESET', message: 'socket hang up' })), 'transient');
});

test('sorts a refused key or subscription as an account error', () => {
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 401, isRetryable: false, message: 'This API key has expired.' } })), 'account');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 403, isRetryable: true, message: 'An active OpenCode Go subscription is required to use Go models.' } })), 'account');
    assert.equal(providerErrorKind(errorEvent({ name: 'ProviderAuthError', data: { message: 'Missing key' } })), 'account');
    assert.equal(providerErrorKind(errorEvent({ message: 'Unauthorized', code: 401 })), 'account');
    assert.equal(providerErrorKind({ type: 'error', part: { error: { code: '403' } } }), 'account');
});

test('sorts an unknown or withdrawn model as unavailable', () => {
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 404, message: 'No endpoints found for fixture/model.' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 410, message: 'Gone' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'ModelNotFoundError', data: { message: 'Unknown model' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'The model fixture/old has been deprecated.' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'UnknownError', data: { message: 'Model fixture/missing does not exist' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ message: 'Not found', code: 404 })), 'unavailable');
});

test('leaves model, request and non-error events without a provider error kind', () => {
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 400, isRetryable: false, message: 'Invalid tool call' } })), null);
    assert.equal(providerErrorKind(errorEvent({ name: 'UnknownError', data: { message: 'Tool failed' } })), null);
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { isRetryable: false } })), null);
    assert.equal(providerErrorKind(errorEvent({ message: 'Broken', code: 'unexpected' })), null);
    assert.equal(providerErrorKind(errorEvent(null)), null);
    assert.equal(providerErrorKind({ type: 'step_finish', error: { code: 429 } }), null);
    assert.equal(providerErrorKind(null), null);
});

test('sorts a model refused under the account privacy or data policy as a policy error', () => {
    assert.equal(providerErrorKind(goPrivacyRefusal), 'policy');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'This Go model trains on request data. Allow it in your Privacy settings.' } })), 'policy');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 404, isRetryable: false, message: 'No endpoints found matching your data policy (Free model publication). Configure: https://openrouter.ai/settings/privacy' } })), 'policy');
    assert.equal(providerErrorKind(errorEvent({ message: 'No endpoints found matching your data policy.', code: 404 })), 'policy');

    // A plain 404 is still an unknown model, a 403 still an account error, and the benchmark keeps its own rule.
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 404, message: 'No endpoints found for fixture/model.' } })), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 403, message: 'Check your privacy settings.' } })), 'account');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'Invalid data policy field' } })), 'policy');
    assert.equal(isProviderError(goPrivacyRefusal), true);
});

test('recognizes the bare unexpected server error apart from capacity errors', () => {
    const unexpected = errorEvent({ name: 'UnknownError', data: { message: 'Unexpected server error. Check server logs for details.' } });

    assert.equal(isUnexpectedServerError(unexpected), true);
    assert.equal(isUnexpectedServerError(errorEvent({ name: 'UnknownError', message: 'Unexpected server error' })), true);
    assert.equal(isUnexpectedServerError(errorEvent({ name: 'UnknownError', data: { statusCode: 503, message: 'Unexpected server error' } })), false);
    assert.equal(isUnexpectedServerError(errorEvent({ name: 'APIError', data: { statusCode: 429, isRetryable: true, message: 'Rate limited' } })), false);
    assert.equal(isUnexpectedServerError(errorEvent({ name: 'UnknownError', data: { message: 'Tool failed' } })), false);
    assert.equal(isUnexpectedServerError(goPrivacyRefusal), false);
    assert.equal(isUnexpectedServerError({ type: 'step_finish' }), false);
    assert.equal(isUnexpectedServerError(null), false);
});
