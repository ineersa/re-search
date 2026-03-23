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

    if (toolCalls.length === 0) {
        const emptyMessage = isTerminalPhase(progress.phase) && progress.hasRunStarted
            ? 'Trace was pruned. Full trace is available only for the most recent 10 runs.'
            : 'No trace events yet.';
        traceBody.innerHTML = `<p class="trace-empty">${escapeHtml(emptyMessage)}</p>`;

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

    traceBody.innerHTML = items;
    attachTraceClickHandlers(traceBody);
}

/**
 * @param {{ progress: TraceProgress, runStatus: HTMLElement }} ctx
 */
export function renderRunStatus(ctx) {
    const { progress, runStatus } = ctx;
    const phase = progress.phase || 'queued';
    const title = phaseTitle(phase, progress.phaseMessage);
    const titleClass = isTerminalPhase(phase) ? 'run-status-title-terminal' : 'run-status-title-active';
    const marker = renderPhaseMarker(phase);

    runStatus.innerHTML = `
            <span class="run-status-count">Tool calls: ${progress.toolCallsCompleted}</span>
            <span class="run-status-separator">•</span>
            <span class="run-status-activity ${titleClass}" style="position: relative; top: -1px;">${marker}<span>${escapeHtml(title)}</span></span>
        `;
}

/**
 * @param {TraceProgress['phase']} phase
 * @param {string|null} phaseMessage
 * @returns {string}
 */
function phaseTitle(phase, phaseMessage) {
    const normalizedPhaseMessage = normalizePhaseMessage(phaseMessage);
    if (normalizedPhaseMessage) {
        return normalizedPhaseMessage;
    }

    const labels = {
        queued: 'Planning first step',
        running: 'Planning next step',
        waiting_llm: 'Waiting for model response',
        waiting_tools: 'Waiting for tool results',
        completed: 'Done',
        failed: 'Stopped with an error',
        aborted: 'Stopped',
    };

    return labels[phase || 'queued'] || 'Planning next step';
}

/**
 * @param {string|null} phaseMessage
 * @returns {string|null}
 */
function normalizePhaseMessage(phaseMessage) {
    if (!phaseMessage || phaseMessage.trim().length === 0) {
        return null;
    }

    const message = phaseMessage.trim();
    const lowerMessage = message.toLowerCase();

    if (lowerMessage.startsWith('executed ')) {
        return 'Planning next step';
    }

    if (lowerMessage.startsWith('tool error:')) {
        return 'Recovering from tool error';
    }

    if (lowerMessage === 'forming initial query') {
        return 'Planning first step';
    }

    if (lowerMessage === 'waiting for llm response') {
        return 'Waiting for model response';
    }

    if (lowerMessage === 'waiting for tool call results') {
        return 'Waiting for tool results';
    }

    if (lowerMessage === 'streaming model output') {
        return 'Writing answer';
    }

    if (lowerMessage === 'research complete') {
        return 'Done';
    }

    if (lowerMessage === 'research aborted') {
        return 'Stopped';
    }

    if (lowerMessage === 'research failed') {
        return 'Stopped with an error';
    }

    return message;
}

/**
 * @param {TraceProgress['phase']} phase
 * @returns {boolean}
 */
function isTerminalPhase(phase) {
    return phase === 'completed' || phase === 'failed' || phase === 'aborted';
}

/**
 * @param {TraceProgress['phase']} phase
 * @returns {string}
 */
function renderPhaseMarker(phase) {
    if (phase === 'completed') {
        return '<span aria-hidden="true" style="display: inline-block; color: #22c55e; font-size: 0.8rem; line-height: 1;">✓</span>';
    }

    if (phase === 'failed') {
        return '<span class="run-status-icon run-status-icon-failed" aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; width: 0.95rem; height: 0.95rem; border-radius: 999px; background: rgba(244, 63, 94, 0.2); color: #fda4af; font-size: 0.65rem; line-height: 1;">!</span>';
    }

    if (phase === 'aborted') {
        return '<span class="run-status-icon run-status-icon-aborted" aria-hidden="true" style="display: inline-block; width: 0.65rem; height: 0.65rem; background: #ef4444; border-radius: 1px;"></span>';
    }

    if (phase === 'waiting_llm' || phase === 'waiting_tools') {
        return '<svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" style="display: block; width: 0.78rem; height: 0.78rem; flex-shrink: 0;"><circle cx="6" cy="6" r="4.5" fill="none" stroke="#38bdf8" stroke-width="1.8" stroke-linecap="round" stroke-dasharray="20 12"><animateTransform attributeName="transform" type="rotate" from="0 6 6" to="360 6 6" dur="0.9s" repeatCount="indefinite" /></circle></svg>';
    }

    return '<svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" style="display: block; width: 0.78rem; height: 0.78rem; flex-shrink: 0;"><circle cx="6" cy="6" r="4" fill="#7dd3fc"><animate attributeName="opacity" values="1;0.35;1" dur="1.1s" repeatCount="indefinite" /></circle></svg>';
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
