/**
 * @param {Event} event
 * @param {{
 *     traceTarget: HTMLElement,
 *     traceBodyTarget: HTMLElement,
 *     showTraceTab: () => void,
 * }} ctx
 */
export function jumpToTraceStep(event, ctx) {
    event.preventDefault();
    const sequenceRaw = event.currentTarget?.dataset?.stepSequence;
    const sequence = Number.parseInt(sequenceRaw, 10);
    if (!Number.isInteger(sequence)) {
        return;
    }

    const { traceTarget, traceBodyTarget, showTraceTab } = ctx;

    showTraceTab();

    requestAnimationFrame(() => {
        const selector = `.trace-card[data-step-sequence="${sequence}"]`;
        const card = traceBodyTarget.querySelector(selector);
        if (!card) {
            return;
        }

        card.querySelectorAll('pre.hidden').forEach((node) => {
            node.classList.remove('hidden');
        });
        card.querySelectorAll('.trace-toggle-params').forEach((btn) => {
            btn.textContent = 'Hide params';
        });
        card.querySelectorAll('.trace-toggle-result').forEach((btn) => {
            btn.textContent = 'Close result';
        });

        card.classList.add('trace-highlight');
        const scrollTop = Math.max(0, card.offsetTop - 24);
        traceTarget.scrollTo({ top: scrollTop, behavior: 'smooth' });
        setTimeout(() => card.classList.remove('trace-highlight'), 1800);
    });
}
