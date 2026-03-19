/**
 * @param {{ status?: string }} run
 * @returns {string}
 */
export function formatStatus(run) {
    const status = run.status || 'unknown';
    const statusLabels = {
        completed: 'Complete',
        running: 'Running',
        queued: 'Queued',
        answer_only: 'Answer only',
        budget_exhausted: 'Budget exhausted',
        loop_stopped: 'Loop stopped',
        failed: 'Failed',
        timed_out: 'Timed out',
        throttled: 'Rate limited',
        aborted: 'Aborted',
    };

    return statusLabels[status] || status;
}

/**
 * @param {{
 *     tokenBudgetUsed?: number|null,
 *     tokenBudgetHardCap?: number|null,
 *     loopDetected?: boolean,
 *     answerOnlyTriggered?: boolean,
 * }} run
 * @returns {string}
 */
export function formatBudgetMeta(run) {
    const parts = [];
    if (run.tokenBudgetUsed != null && run.tokenBudgetHardCap != null) {
        parts.push(`${(run.tokenBudgetUsed / 1000).toFixed(1)}k / ${(run.tokenBudgetHardCap / 1000).toFixed(0)}k tokens`);
    }
    if (run.loopDetected) {
        parts.push('loop');
    }
    if (run.answerOnlyTriggered) {
        parts.push('answer-only');
    }

    return parts.join(', ');
}

/**
 * @param {{ failureReason?: string }} run
 * @param {typeof import('./escape_html.js').escapeHtml} escapeHtml
 * @returns {string}
 */
export function buildNoAnswerHtml(run, escapeHtml) {
    const status = formatStatus(run);
    const reason = run.failureReason ? escapeHtml(run.failureReason) : '';
    const meta = formatBudgetMeta(run);

    return `
            <p class="m-0 text-gray-500">No answer produced. Status: ${escapeHtml(status)}</p>
            ${reason ? `<p class="mt-1 text-sm text-gray-600">${reason}</p>` : ''}
            ${meta ? `<p class="mt-1 text-xs text-gray-600">${escapeHtml(meta)}</p>` : ''}
        `;
}
