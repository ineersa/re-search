import { escapeHtml } from './escape_html.js';

/**
 * @typedef {object} TraceCall
 * @property {'run_started'|'reasoning'|'assistant_stream'|'trace_pruned'|'tool'} type
 * @property {string} label
 * @property {string} message
 * @property {Record<string, unknown>} arguments
 * @property {string|null} query
 * @property {string|null} url
 * @property {string|null} filter
 * @property {string|null} result
 * @property {number|null} sequence
 * @property {number|null} turnNumber
 */

/**
 * @typedef {object} TraceProgress
 * @property {'queued'|'running'|'waiting_llm'|'waiting_tools'|'completed'|'failed'|'aborted'|null} phase
 * @property {string|null} status
 * @property {boolean} hasRunStarted
 * @property {number} llmTurns
 * @property {number} toolCallsCompleted
 * @property {string|null} phaseMessage
 */

/**
 * @param {string} stepType
 * @param {string} toolName
 * @param {string} summary
 * @param {Record<string, unknown>} args
 * @param {string|null} link
 * @param {string|null} [result]
 * @param {number|null} [sequence]
 * @param {number|null} [turnNumber]
 * @returns {TraceCall}
 */
export function buildTraceItem(stepType, toolName, summary, args, link, result = null, sequence = null, turnNumber = null) {
    const isRunStarted = stepType === 'run_started';
    const isReasoning = stepType === 'assistant_reasoning';
    const isAssistantStream = stepType === 'assistant_stream';
    const isPruned = stepType === 'trace_pruned';
    const url = /** @type {string|null} */ (args.url || link || null);
    const query = /** @type {string|null} */ (args.query ?? null);
    const filter = /** @type {string|null} */ (args.query ?? args.selector ?? null);

    return {
        type: isRunStarted ? 'run_started' : (isReasoning ? 'reasoning' : (isAssistantStream ? 'assistant_stream' : (isPruned ? 'trace_pruned' : 'tool'))),
        label: isReasoning ? 'reasoning' : (isAssistantStream ? 'assistant stream' : (isPruned ? 'trace pruned' : toolName)),
        message: summary || '',
        arguments: args,
        query,
        url,
        filter,
        result: result ?? null,
        sequence,
        turnNumber,
    };
}

/**
 * @param {{ toolCalls: TraceCall[], traceBody: HTMLElement, progress: TraceProgress }} ctx
 */
export function renderTrace(ctx) {
    const { toolCalls, traceBody, progress } = ctx;
    const progressPanel = renderTraceProgress(progress);

    if (toolCalls.length === 0) {
        const emptyMessage = isTerminalPhase(progress.phase) && progress.hasRunStarted
            ? 'Trace was pruned. Full trace is available only for the most recent 10 runs.'
            : 'No trace events yet.';
        traceBody.innerHTML = `${progressPanel}<p class="trace-empty">${escapeHtml(emptyMessage)}</p>`;

        return;
    }

    const items = toolCalls
        .map(
            (call, index) => {
                if (call.type === 'run_started') {
                    return renderTraceRunStarted(index, call);
                }

                if (call.type === 'reasoning') {
                    return renderTraceReasoning(index, call);
                }

                if (call.type === 'assistant_stream') {
                    return renderTraceAssistantStream(index, call);
                }

                if (call.type === 'trace_pruned') {
                    return renderTracePruned(index, call);
                }

                return renderTraceToolCall(index, call);
            },
        )
        .join('');

    traceBody.innerHTML = `${progressPanel}${items}`;
    attachTraceClickHandlers(traceBody);
}

/**
 * @param {TraceProgress} progress
 * @returns {string}
 */
function renderTraceProgress(progress) {
    const phase = progress.phase || 'queued';
    const title = phaseTitle(phase, progress.phaseMessage);
    const statusIcon = isTerminalPhase(phase)
        ? '<span class="text-emerald-400">✓</span>'
        : '<span class="inline-block h-2 w-2 animate-pulse rounded-full bg-sky-400"></span>';
    const toolCallsCounter = `<p class="mt-2 mb-0 text-[11px] text-gray-500">Tool calls finished: ${progress.toolCallsCompleted}</p>`;

    return `
            <section class="border border-[#2a2a2a] bg-[#121212] p-3 trace-progress">
                <p class="m-0 text-xs uppercase tracking-wider text-gray-500">Run status</p>
                <p class="mt-1 mb-0 flex items-center gap-2 text-sm text-gray-200">${statusIcon}<span>${escapeHtml(title)}</span></p>
                ${toolCallsCounter}
            </section>
        `;
}

/**
 * @param {TraceProgress['phase']} phase
 * @param {string|null} phaseMessage
 * @returns {string}
 */
function phaseTitle(phase, phaseMessage) {
    if (phaseMessage && phaseMessage.trim().length > 0) {
        return phaseMessage;
    }

    const labels = {
        queued: 'Forming initial query',
        running: 'Starting run',
        waiting_llm: 'Waiting for LLM response',
        waiting_tools: 'Waiting for tool call results',
        completed: 'Research complete',
        failed: 'Research failed',
        aborted: 'Research aborted',
    };

    return labels[phase || 'queued'] || 'Running';
}

/**
 * @param {TraceProgress['phase']} phase
 * @returns {boolean}
 */
function isTerminalPhase(phase) {
    return phase === 'completed' || phase === 'failed' || phase === 'aborted';
}

/**
 * @param {HTMLElement} traceBody
 */
export function attachTraceClickHandlers(traceBody) {
    traceBody.querySelectorAll('.trace-card').forEach((card) => {
        const paramsEl = card.querySelector('[data-trace-params]');
        const paramsBtn = card.querySelector('.trace-toggle-params');
        if (paramsEl && paramsBtn) {
            paramsBtn.addEventListener('click', () => {
                const isHidden = paramsEl.classList.contains('hidden');
                paramsEl.classList.toggle('hidden');
                paramsBtn.textContent = isHidden ? 'Hide params' : 'Show params';
            });
        }
        const resultEl = card.querySelector('[data-trace-result]');
        const resultBtn = card.querySelector('.trace-toggle-result');
        if (resultEl && resultBtn) {
            resultBtn.addEventListener('click', () => {
                const isHidden = resultEl.classList.contains('hidden');
                resultEl.classList.toggle('hidden');
                resultBtn.textContent = isHidden ? 'Close result' : 'Open result';
            });
        }
    });
}

/**
 * @param {number} index
 * @param {TraceCall} call
 */
function renderTraceRunStarted(index, call) {
    const safeLabel = escapeHtml(call.label);
    const safeMessage = escapeHtml(call.message);
    const sequenceAttr = Number.isInteger(call.sequence) ? ` data-step-sequence="${call.sequence}"` : '';

    return `
            <article class="border border-[#333] bg-[#1a1a1a] p-3 trace-card" data-trace-index="${index}"${sequenceAttr}>
                <p class="m-0 text-xs uppercase tracking-wider text-gray-500">#${index + 1} ${safeLabel}</p>
                <p class="mt-1 leading-relaxed text-gray-300">${safeMessage}</p>
            </article>
        `;
}

/**
 * @param {number} index
 * @param {TraceCall} call
 */
function renderTraceToolCall(index, call) {
    const safeLabel = escapeHtml(call.label);
    let primaryText = '';
    if (call.label === 'websearch_search' && call.query) {
        primaryText = escapeHtml(call.query);
    } else if (call.label === 'websearch_open' && call.url) {
        primaryText = escapeHtml(call.url);
    } else if (call.label === 'websearch_find' && (call.url || call.filter)) {
        primaryText = [call.url, call.filter].filter(Boolean).map((s) => escapeHtml(String(s))).join(' · ');
    } else {
        primaryText = escapeHtml(call.message);
    }

    const hasParams = Object.keys(call.arguments || {}).length > 0;
    const paramsJson = hasParams ? escapeHtml(JSON.stringify(call.arguments, null, 2)) : '';
    const hasResult = typeof call.result === 'string' && call.result.length > 0;
    const resultText = hasResult ? escapeHtml(call.result) : '';
    const sequenceAttr = Number.isInteger(call.sequence) ? ` data-step-sequence="${call.sequence}"` : '';

    return `
            <article class="border border-[#333] bg-[#1a1a1a] p-3 trace-card hover:border-gray-500 transition-colors" data-trace-index="${index}"${sequenceAttr}>
                <p class="m-0 text-xs uppercase tracking-wider text-gray-500">#${index + 1} ${safeLabel}</p>
                <p class="mt-1 leading-relaxed text-gray-300 break-all">${primaryText}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    ${hasParams ? `<button type="button" class="trace-toggle-params text-xs text-gray-500 hover:text-gray-300 border border-[#444] px-2 py-1 rounded bg-transparent cursor-pointer">Show params</button>` : ''}
                    ${hasResult ? `<button type="button" class="trace-toggle-result text-xs text-gray-500 hover:text-gray-300 border border-[#444] px-2 py-1 rounded bg-transparent cursor-pointer">Open result</button>` : ''}
                </div>
                ${hasParams ? `<pre class="trace-params mt-2 p-2 bg-[#0a0a0a] text-xs text-gray-400 rounded hidden overflow-x-auto max-h-48" data-trace-params>${paramsJson}</pre>` : ''}
                ${hasResult ? `<pre class="trace-result mt-2 p-2 bg-[#0a0a0a] text-xs text-gray-400 rounded hidden overflow-auto max-h-96 whitespace-pre-wrap break-words" data-trace-result>${resultText}</pre>` : ''}
            </article>
        `;
}

/**
 * @param {number} index
 * @param {TraceCall} call
 */
function renderTraceReasoning(index, call) {
    const safeMessage = escapeHtml(call.message);
    const sequenceAttr = Number.isInteger(call.sequence) ? ` data-step-sequence="${call.sequence}"` : '';

    return `
            <article class="border border-[#2f2f2f] bg-[#151515] p-3 trace-card" data-trace-index="${index}"${sequenceAttr}>
                <p class="m-0 text-xs uppercase tracking-wider text-gray-500">#${index + 1} reasoning</p>
                <p class="mt-1 leading-relaxed text-gray-300 whitespace-pre-wrap break-words">${safeMessage}</p>
            </article>
        `;
}

/**
 * @param {number} index
 * @param {TraceCall} call
 */
function renderTraceAssistantStream(index, call) {
    const safeMessage = escapeHtml(call.message);
    const sequenceAttr = Number.isInteger(call.sequence) ? ` data-step-sequence="${call.sequence}"` : '';

    return `
            <article class="border border-sky-700/40 bg-sky-950/20 p-3 trace-card" data-trace-index="${index}"${sequenceAttr}>
                <p class="m-0 text-xs uppercase tracking-wider text-sky-400">#${index + 1} assistant stream</p>
                <p class="mt-1 leading-relaxed text-sky-100 whitespace-pre-wrap break-words">${safeMessage}</p>
            </article>
        `;
}

/**
 * @param {number} index
 * @param {TraceCall} call
 */
function renderTracePruned(index, call) {
    const safeMessage = escapeHtml(call.message || 'Trace was pruned. Full trace is available only for the most recent 10 runs.');
    const sequenceAttr = Number.isInteger(call.sequence) ? ` data-step-sequence="${call.sequence}"` : '';

    return `
            <article class="border border-amber-700/50 bg-amber-950/20 p-3 trace-card" data-trace-index="${index}"${sequenceAttr}>
                <p class="m-0 text-xs uppercase tracking-wider text-amber-400">#${index + 1} trace pruned</p>
                <p class="mt-1 leading-relaxed text-amber-100">${safeMessage}</p>
            </article>
        `;
}
