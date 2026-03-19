import { marked } from 'marked';
import DOMPurify from 'dompurify';
import hljs from 'highlight.js';
import { escapeHtml } from './escape_html.js';
import { buildReferenceEvidence } from './reference_evidence.js';

/**
 * 1-based index in the trace list (matches "#N" on trace cards), or null.
 *
 * @param {import('./trace_view.js').TraceCall[]} toolCalls
 * @param {number|null} sourceSequence
 * @returns {number|null}
 */
function traceDisplayNumber(toolCalls, sourceSequence) {
    if (!Number.isInteger(sourceSequence)) {
        return null;
    }
    const idx = toolCalls.findIndex((c) => c.sequence === sourceSequence);
    if (idx === -1) {
        return null;
    }

    return idx + 1;
}

/**
 * @param {{
 *     accumulatedMarkdown: string,
 *     renderMode: string,
 *     answerBody: HTMLElement,
 * }} ctx
 */
export function renderAnswerBody(ctx) {
    const { accumulatedMarkdown, renderMode, answerBody } = ctx;

    if (renderMode === 'raw') {
        answerBody.innerHTML = `<pre class="answer-markdown-raw m-0 p-0 overflow-x-auto whitespace-pre-wrap break-words text-gray-300">${escapeHtml(accumulatedMarkdown)}</pre>`;

        return;
    }

    const rawHtml = marked.parse(accumulatedMarkdown, { gfm: true, breaks: false, silent: true }) ?? '';
    const safeHtml = DOMPurify.sanitize(String(rawHtml));
    answerBody.innerHTML = `<div class="answer-markdown-rendered markdown-body">${safeHtml}</div>`;
    answerBody.querySelectorAll('pre code').forEach((el) => {
        hljs.highlightElement(el);
    });
}

/**
 * @param {{
 *     hasAnswerReferencesTarget: boolean,
 *     answerReferences: HTMLElement,
 *     accumulatedMarkdown: string,
 *     toolCalls: import('./trace_view.js').TraceCall[],
 * }} ctx
 */
export function renderAnswerReferences(ctx) {
    const { hasAnswerReferencesTarget, answerReferences, accumulatedMarkdown, toolCalls } = ctx;

    if (!hasAnswerReferencesTarget) {
        return;
    }

    const references = buildReferenceEvidence(accumulatedMarkdown, toolCalls);
    if (references.length === 0) {
        answerReferences.innerHTML = '';

        return;
    }

    const rows = references.map((reference) => {
        const safeUrl = escapeHtml(reference.url);
        const markerList = reference.markers.map((m) => escapeHtml(m)).join(', ');

        const displayNum = traceDisplayNumber(toolCalls, reference.sourceSequence);
        const jumpLabel = null !== displayNum
            ? `Jump to #${displayNum}`
            : `Jump to step #${reference.sourceSequence}`;

        const jump = Number.isInteger(reference.sourceSequence)
            ? `<button type="button" class="ml-2 border border-[#444] bg-transparent px-2 py-0.5 text-[11px] text-gray-400 hover:text-white hover:border-gray-500 transition-colors cursor-pointer" data-action="click->research-ui#jumpToTraceStep" data-step-sequence="${reference.sourceSequence}">${escapeHtml(jumpLabel)}</button>`
            : '';

        return `
                <article class="rounded border border-[#2b2b2b] bg-[#131313] p-3">
                    <p class="m-0 text-sm text-gray-300">
                        <span class="font-semibold text-white">Used by refs: ${markerList}</span>
                        <a href="${safeUrl}" target="_blank" rel="noopener" class="ml-2 break-all text-blue-400 hover:text-blue-300">${safeUrl}</a>
                    </p>
                    <p class="mt-1 text-xs text-gray-500">${jump || 'No matching step in trace for this source.'}</p>
                </article>
            `;
    }).join('');

    answerReferences.innerHTML = `
            <section class="rounded-lg border border-[#333] bg-[#111] p-3">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">References evidence</h3>
                <div class="space-y-3">${rows}</div>
            </section>
        `;
}
