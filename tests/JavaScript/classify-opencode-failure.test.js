import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const classifierPath = join(dirname(fileURLToPath(import.meta.url)), '../../engine/tools/classify-opencode-failure.mjs');
const { classifyEventLog, isOutputLimitError, isProviderError, isStartupFailure, isUnexpectedServerError, lastStopReason, providerErrorKind } = await import(pathToFileURL(classifierPath));

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

// What OpenCode 2.2.5 wrote when a response was cut off three times: the error comes before the third cut-off step.
const outputLimitError = JSON.parse('{"type":"error","timestamp":1793860001000,"sessionID":"ses_e55a002b7ffesB6KCPM0XFGmVT","error":{"type":"output-limit","message":"Response still truncated after two output token limit continuations"}}');
const stepFinish = (reason) => JSON.stringify({ type: 'step_finish', sessionID: 'ses_fixture', part: { reason, tokens: { output: 10 } } });

test('recognizes OpenCode 2.x\'s output-limit error and nothing else', () => {
    assert.equal(isOutputLimitError(outputLimitError), true);
    assert.equal(isOutputLimitError(errorEvent({ type: 'provider.auth', message: 'User not found.', status: 401 })), false);
    assert.equal(isOutputLimitError(errorEvent({ name: 'APIError', data: { statusCode: 500 } })), false);
    assert.equal(isOutputLimitError({ type: 'step_finish', error: { type: 'output-limit' } }), false);
    assert.equal(isOutputLimitError(null), false);
    assert.equal(providerErrorKind(outputLimitError), null);
    assert.equal(isProviderError(outputLimitError), false);
});

test('classifies a log that ends with the output-limit error as an output-limit stop, whatever came before', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-classify-limit-'));

    try {
        const cutOff = join(root, 'cut-off.jsonl');
        const providerThenCutOff = join(root, 'provider-then-cut-off.jsonl');
        const cutOffThenProvider = join(root, 'cut-off-then-provider.jsonl');
        await writeFile(cutOff, [stepFinish('length'), JSON.stringify(outputLimitError), stepFinish('length')].join('\n'));
        await writeFile(providerThenCutOff, [JSON.stringify(errorEvent({ name: 'APIError', data: { statusCode: 503 } })), JSON.stringify(outputLimitError)].join('\n'));
        await writeFile(cutOffThenProvider, [JSON.stringify(outputLimitError), JSON.stringify(errorEvent({ name: 'APIError', data: { statusCode: 503 } }))].join('\n'));

        assert.equal(await classifyEventLog(cutOff), 'output-limit');
        assert.equal(await classifyEventLog(providerThenCutOff), 'output-limit');
        assert.equal(await classifyEventLog(cutOffThenProvider), 'provider');
        assert.equal(await lastStopReason(cutOff), 'length');
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

// Error events OpenCode 2.2.5 wrote for the provider answers below, captured against a local fake provider.
const v2Error = (error) => ({ type: 'error', timestamp: 1793859780113, sessionID: 'ses_e55a003d9ffe4BmwDmlGjTatcu', error });
const v2KeyRefused = v2Error({ type: 'provider.auth', message: 'User not found.', status: 401, response: { body: '{"error":{"message":"User not found.","code":401}}' } });
const v2SubscriptionRequired = v2Error({ type: 'provider.auth', message: 'An active OpenCode Go subscription is required to use Go models.', status: 403 });
const v2PrivacyRefusal = v2Error({ type: 'provider.invalid-request', message: 'Provider returned error: this model trains on request data', status: 400 });
const v2DataPolicyRefusal = v2Error({ type: 'provider.invalid-request', message: 'No endpoints found matching your data policy (Free model training)', status: 404 });
const v2TransportFailure = v2Error({ type: 'unknown', message: 'Transport: ECONNRESET: The socket connection was closed unexpectedly. For more information, pass `verbose: true` in the second argument to fetch()' });
// The real event of the start race: several standalone processes starting together on a fresh shared database.
const startupFailure = JSON.parse('{"type":"error","timestamp":1793883155228,"sessionID":"","error":{"type":"unknown","message":"Standalone server exited before reporting readiness"}}');

test('reads the provider errors of OpenCode 2.x from their type, status and message', () => {
    assert.equal(providerErrorKind(v2KeyRefused), 'account');
    assert.equal(providerErrorKind(v2SubscriptionRequired), 'account');
    assert.equal(providerErrorKind(v2PrivacyRefusal), 'policy');
    assert.equal(providerErrorKind(v2DataPolicyRefusal), 'policy');
    assert.equal(providerErrorKind(v2Error({ type: 'provider.invalid-request', message: 'No endpoints found for fixture/model.', status: 404 })), 'unavailable');
    assert.equal(providerErrorKind(v2Error({ type: 'provider.invalid-request', message: 'Invalid tool call', status: 400 })), null);
    assert.equal(providerErrorKind(v2TransportFailure), 'transient');

    // The benchmark counts a refused key, a privacy refusal and a failed transport as the provider's, and keeps
    // treating a data-policy 404 as its own rule: the model is scored.
    assert.equal(isProviderError(v2KeyRefused), true);
    assert.equal(isProviderError(v2SubscriptionRequired), true);
    assert.equal(isProviderError(v2PrivacyRefusal), true);
    assert.equal(isProviderError(v2DataPolicyRefusal), false);
    assert.equal(isProviderError(v2TransportFailure), true);
});

test('sorts every named 2.x provider error type by what the next call should do', () => {
    for (const type of ['provider.auth', 'provider.quota', 'provider.free-limit', 'provider.go-limit', 'provider.budget-limit', 'provider.chatgpt-limit']) {
        assert.equal(providerErrorKind(v2Error({ type, message: 'Limit reached.' })), 'account', type);
        assert.equal(isProviderError(v2Error({ type, message: 'Limit reached.' })), true, type);
    }

    // provider.unknown is OpenCode's catch-all for a failure without a 4xx status (for example an error frame in a 200 stream); OpenCode retries it itself.
    for (const type of ['provider.rate-limit', 'provider.transport', 'provider.timeout', 'provider.internal', 'provider.unknown']) {
        assert.equal(providerErrorKind(v2Error({ type, message: 'Try again.' })), 'transient', type);
        assert.equal(isProviderError(v2Error({ type, message: 'Try again.' })), true, type);
    }

    for (const type of ['provider.no-route']) {
        assert.equal(providerErrorKind(v2Error({ type, message: 'Model unavailable: fixture/m' })), 'unavailable', type);
        assert.equal(isProviderError(v2Error({ type, message: 'Model unavailable: fixture/m' })), false, type);
    }

    // The model's own failures stay the model's: a filtered answer, malformed output, a refused tool, a failed
    // summary and a full context are not a reason to give the call another chance or to stop scoring it.
    for (const type of ['provider.content-filter', 'provider.invalid-output', 'provider.unsupported-operation', 'tool.invocation', 'tool.execution', 'permission.rejected', 'context-overflow', 'compaction.failed', 'unknown']) {
        assert.equal(providerErrorKind(v2Error({ type, message: 'Something the model caused.' })), null, type);
        assert.equal(isProviderError(v2Error({ type, message: 'Something the model caused.' })), false, type);
    }
});

test('recognizes the bare unexpected server error in the 2.x error shape too', () => {
    const unexpected = v2Error({ type: 'unknown', message: 'Unexpected server error. Check server logs for details.' });

    assert.equal(providerErrorKind(unexpected), 'transient');
    assert.equal(isProviderError(unexpected), true);
    assert.equal(isUnexpectedServerError(unexpected), true);
    assert.equal(isUnexpectedServerError(v2Error({ type: 'provider.rate-limit', message: 'Slow down.', status: 429 })), false);
});

test('recognizes the start race and nothing else as a startup failure', () => {
    assert.equal(isStartupFailure(startupFailure), true);
    assert.equal(isStartupFailure(errorEvent({ name: 'UnknownError', data: { message: 'Standalone server exited before reporting readiness' } })), true);
    assert.equal(isStartupFailure(v2TransportFailure), false);
    assert.equal(isStartupFailure(v2KeyRefused), false);
    assert.equal(isStartupFailure(outputLimitError), false);
    assert.equal(isStartupFailure({ type: 'step_finish', error: { message: 'Standalone server exited before reporting readiness' } }), false);
    assert.equal(isStartupFailure(null), false);

    // It is the provider-side kind of failure: not the model's, waiting and trying again helps, nothing was spent.
    assert.equal(providerErrorKind(startupFailure), 'transient');
    assert.equal(isProviderError(startupFailure), true);
    assert.equal(isUnexpectedServerError(startupFailure), false);
});

test('classifies 2.x logs by their last error event', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-classify-v2-'));

    try {
        const refused = join(root, 'refused.jsonl');
        const raced = join(root, 'raced.jsonl');
        const compaction = join(root, 'compaction.jsonl');
        const dataPolicy = join(root, 'data-policy.jsonl');
        await writeFile(refused, JSON.stringify(v2KeyRefused));
        await writeFile(raced, JSON.stringify(startupFailure));
        await writeFile(compaction, [JSON.stringify(v2TransportFailure), JSON.stringify(v2Error({ type: 'compaction.failed', message: 'Compaction summary did not follow the template.' }))].join('\n'));
        await writeFile(dataPolicy, JSON.stringify(v2DataPolicyRefusal));

        assert.equal(await classifyEventLog(refused), 'provider');
        assert.equal(await classifyEventLog(raced), 'provider');
        assert.equal(await classifyEventLog(compaction), 'model');
        assert.equal(await classifyEventLog(dataPolicy), 'model');
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

// The real answer of OpenRouter to a model id that does not exist, as OpenCode 2.2.5 wrote it (real run, 5 Kasım 2026).
const v2InvalidModelId = JSON.parse(String.raw`{"type":"error","timestamp":1793885174947,"sessionID":"ses_e541c8688ffeTV6rHXwR37Fnkn","error":{"type":"provider.invalid-request","message":"google/gemma-9-does-not-exist is not a valid model ID","status":400,"response":{"body":"{\"error\":{\"message\":\"google/gemma-9-does-not-exist is not a valid model ID\",\"code\":400}}"}}}`);

test('sorts a model id that OpenRouter does not know as unavailable, and keeps other 400s the model\'s', () => {
    assert.equal(providerErrorKind(v2InvalidModelId), 'unavailable');
    assert.equal(providerErrorKind(errorEvent({ name: 'APIError', data: { statusCode: 400, message: 'fixture/old is not a valid model ID' } })), 'unavailable');
    // The benchmark keeps scoring it as the model's own failure: only the workflow engine gives the model up.
    assert.equal(isProviderError(v2InvalidModelId), false);
    assert.equal(providerErrorKind(v2Error({ type: 'provider.invalid-request', message: 'Invalid tool call', status: 400 })), null);
    assert.equal(providerErrorKind(v2Error({ type: 'provider.invalid-request', message: 'The model produced a valid model response', status: 400 })), null);
});

test('sorts the spend limit of one model apart from the limits of the account', () => {
    // It arrives with a 429 status, which must not make it a transient error; the run goes on with other models.
    const modelLimit = v2Error({ type: 'provider.model-budget-limit', message: "You've reached the spend limit for this model. Switch models to keep going.", status: 429 });

    assert.equal(providerErrorKind(modelLimit), 'limit');
    assert.equal(isProviderError(modelLimit), true);
    assert.equal(providerErrorKind(v2Error({ type: 'provider.go-limit', message: 'OpenCode Go usage limit reached.', status: 429 })), 'account');
    assert.equal(providerErrorKind(v2Error({ type: 'provider.rate-limit', message: 'Slow down.', status: 429 })), 'transient');
});
