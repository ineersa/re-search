import { Controller } from '@hotwired/stimulus';
import { renderAnswerBody as renderAnswerBodyView, renderAnswerReferences as renderAnswerReferencesView } from './research_ui/answer_view.js';
import { escapeHtml } from './research_ui/escape_html.js';
import { jumpToTraceStep as scrollToTraceStep } from './research_ui/jump_trace.js';
import { buildNoAnswerHtml } from './research_ui/run_format.js';
import { buildTraceItem, renderTrace as renderTraceView } from './research_ui/trace_view.js';

/**
 * Research UI controller: submits real runs, consumes Mercure events,
 * appends tool activity to trace UI, streams markdown to answer container,
 * and supports cancel/reconnect behavior.
 *
 * Markdown is rendered safely (marked + DOMPurify) with raw/rendered toggle
 * and highlight.js for fenced code blocks.
 *
 * Implementation is split under `./research_ui/` (trace, answer, evidence parsing).
 * History is a Turbo Frame (`research-history`) rendered on the server.
 */
export default class extends Controller {
    static targets = [
        'hero',
        'form',
        'input',
        'queryLine',
        'stream',
        'answer',
        'answerBody',
        'answerReferences',
        'trace',
        'traceBody',
        'historyFrame',
        'tabs',
        'results',
        'sidebar',
        'sidebarOverlay',
        'cancelBtn',
        'renderModeToggle',
    ];

    static values = {
        mercureHubUrl: { type: String, default: '' },
        submitUrl: { type: String, default: '/research/runs' },
        runUrl: { type: String, default: '' },
        historyFrameUrl: { type: String, default: '' },
        mercureAuthUrl: { type: String, default: '' },
    };

    sidebarOpen = false;
    accumulatedMarkdown = '';
    renderMode = 'rendered';
    answerStreamingStarted = false;

    connect() {
        this.timer = null;
        this.eventSource = null;
        this.activeTab = 'answer';
        this.toolCalls = [];
        this.runProgress = this.createInitialRunProgress();

        this.showAnswerTab();
    }

    disconnect() {
        this.cancelRun();
    }

    submit(event) {
        event.preventDefault();

        const query = this.inputTarget.value.trim();
        if ('' === query) {
            return;
        }

        this.cancelRun();
        this.toolCalls = [];
        this.runProgress = this.createInitialRunProgress();
        this.accumulatedMarkdown = '';
        this.renderMode = 'rendered';
        this.answerStreamingStarted = false;
        this.activeTab = 'answer';
        this.queryLineTarget.textContent = query;

        this.element.classList.add('is-searching');
        this.element.classList.remove('is-complete');
        this.streamTarget.innerHTML = '';
        this.answerBodyTarget.innerHTML = '';
        this.answerReferencesTarget.innerHTML = '';
        this.traceBodyTarget.innerHTML = '';
        this.renderTrace();
        this.answerTarget.scrollTop = 0;
        this.traceTarget.scrollTop = 0;
        this.updateRenderModeToggleVisibility(false);
        this.resultsTarget.hidden = false;
        this.heroTarget.style.display = 'none';
        this.showTraceTab();
        this.updateCancelButtonVisibility(true);

        this.submitRun(query);
    }

    switchTab(event) {
        const tab = event.currentTarget.dataset.tab;
        if (tab === 'trace') {
            this.showTraceTab();

            return;
        }

        this.showAnswerTab();
    }

    async submitRun(query) {
        const formData = new FormData();
        formData.append('query', query);

        try {
            const submitUrl = this.submitUrlValue || '/research/runs';
            const response = await fetch(submitUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });

            const data = await response.json();

            if (!response.ok) {
                if (response.status === 429) {
                    this.element.classList.remove('is-searching');
                    this.element.classList.add('is-complete');
                    const retrySec = data.retryAfter ?? 600;
                    this.runProgress = {
                        ...this.runProgress,
                        status: 'throttled',
                        phase: 'failed',
                        phaseMessage: `Rate limited — retry in ${Math.ceil(retrySec / 60)} min`,
                    };
                    this.renderTrace();
                } else {
                    this.setError(data.error || 'Failed to start research');
                }

                return;
            }

            const { runId, mercureTopic } = data;
            this.currentRunId = runId;
            this.currentMercureTopic = mercureTopic;

            await this.authorizeMercure(runId);
            this.subscribeToMercure(mercureTopic);
        } catch (err) {
            this.setError(err.message || 'Network error');
        }
    }

    async authorizeMercure(runId) {
        const template = this.mercureAuthUrlValue || `/research/runs/${runId}/mercure-auth`;
        const authUrl = template.replace('__ID__', runId);
        await fetch(authUrl, { credentials: 'same-origin' });
    }

    subscribeToMercure(topic) {
        this.closeEventSource();

        const hubBase = this.mercureHubUrlValue || new URL('/.well-known/mercure', window.location.origin).href;
        const hubUrl = new URL(hubBase);
        hubUrl.searchParams.set('topic', topic);
        const subscribeUrl = hubUrl.toString();

        this.eventSource = new EventSource(subscribeUrl);

        this.eventSource.onmessage = (e) => this.handleMercureMessage(e);
        this.eventSource.onerror = (e) => this.handleMercureError(e);
    }

    handleMercureMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch {
            return;
        }

        const { type } = payload;

        switch (type) {
            case 'activity':
                this.appendActivity(payload);
                break;
            case 'answer':
                this.appendAnswer(payload);
                break;
            case 'budget':
                this.updateBudget(payload);
                break;
            case 'complete':
                this.completeRun(payload);
                break;
            case 'phase':
                this.updatePhase(payload);
                break;
            default:
                break;
        }
    }

    appendActivity(payload) {
        const { stepType, summary, meta = {} } = payload;

        this.applyActivityToProgress(stepType, summary);

        const toolName = meta.tool || stepType;
        const args = meta.arguments || {};
        const link = meta.url || meta.link || null;

        const result = meta.result ?? null;
        const sequence = Number.isInteger(meta.sequence) ? meta.sequence : null;
        const turnNumber = Number.isInteger(meta.turnNumber) ? meta.turnNumber : (Number.isInteger(meta.turn) ? meta.turn : null);
        const traceItem = buildTraceItem(stepType, toolName, summary, args, link, result, sequence, turnNumber);
        this.toolCalls.push(traceItem);
        this.renderTrace();
        this.renderAnswerReferences();
    }

    updatePhase(payload) {
        const phase = typeof payload.phase === 'string' ? payload.phase : null;
        const status = typeof payload.status === 'string' ? payload.status : this.runProgress.status;
        const message = typeof payload.message === 'string' ? payload.message : null;
        const isEnteringWaitingLlm = phase === 'waiting_llm' && this.runProgress.phase !== 'waiting_llm';

        this.runProgress = {
            ...this.runProgress,
            phase,
            status,
            phaseMessage: message,
            hasRunStarted: this.runProgress.hasRunStarted || (typeof phase === 'string' && phase !== 'queued'),
            llmTurns: isEnteringWaitingLlm ? this.runProgress.llmTurns + 1 : this.runProgress.llmTurns,
        };

        this.renderTrace();
    }

    appendAnswer(payload) {
        const { markdown, isFinal } = payload;
        if (isFinal && markdown && this.accumulatedMarkdown.trim().length === 0 && !this.answerStreamingStarted) {
            this.playbackFinalAnswer(markdown);

            return;
        }

        if (markdown) {
            const hadContentBefore = this.accumulatedMarkdown.trim().length > 0;
            this.accumulatedMarkdown += markdown;
            this.renderAnswerBody();

            if (!this.answerStreamingStarted && !hadContentBefore && this.accumulatedMarkdown.trim().length > 0) {
                this.answerStreamingStarted = true;
                this.showAnswerTab();
            }
        }

        if (isFinal) {
            this.updateRenderModeToggleVisibility(true);
            this.showAnswerTab();
        }

        this.renderAnswerReferences();
    }

    playbackFinalAnswer(markdown) {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        const chunkSize = 72;
        const delayMs = 22;
        let index = 0;

        const tick = () => {
            const next = markdown.slice(index, index + chunkSize);
            if (next.length > 0) {
                const hadContentBefore = this.accumulatedMarkdown.trim().length > 0;
                this.accumulatedMarkdown += next;
                this.renderAnswerBody();

                if (!this.answerStreamingStarted && !hadContentBefore && this.accumulatedMarkdown.trim().length > 0) {
                    this.answerStreamingStarted = true;
                    this.showAnswerTab();
                }
            }

            index += chunkSize;
            if (index < markdown.length) {
                this.timer = setTimeout(tick, delayMs);

                return;
            }

            this.timer = null;
            this.updateRenderModeToggleVisibility(true);
            this.showAnswerTab();
            this.renderAnswerReferences();
        };

        tick();
    }

    updateBudget(payload) {
        const { meta = {} } = payload;
        const remaining = meta.remaining;
        if (typeof remaining === 'number' && remaining < 10000) {
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: `Researching… ~${Math.round(remaining / 1000)}k tokens left`,
            };
            this.renderTrace();
        }
    }

    completeRun(payload = {}) {
        this.closeEventSource();
        this.updateCancelButtonVisibility(false);

        this.element.classList.remove('is-searching');
        this.element.classList.add('is-complete');

        const meta = payload.meta || {};
        const status = meta.status || 'completed';
        const reason = meta.reason || '';

        const phaseMessage = this.statusLabelForCompletion(status, reason);

        this.runProgress = {
            ...this.runProgress,
            status,
            phase: status === 'aborted' ? 'aborted' : (status === 'completed' ? 'completed' : 'failed'),
            phaseMessage,
        };
        this.renderTrace();

        this.streamTarget.innerHTML = '';
        this.showAnswerTab();

        this.reloadHistoryFrame();
    }

    handleMercureError() {
        if (this.eventSource?.readyState === EventSource.CLOSED) {
            return;
        }
        if (this.eventSource?.readyState === EventSource.CONNECTING) {
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: 'Reconnecting…',
            };
            this.renderTrace();
        }
    }

    cancelRun() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        this.closeEventSource();
        this.updateCancelButtonVisibility(false);

        if (this.element?.classList?.contains('is-searching')) {
            this.element.classList.remove('is-searching');
            this.element.classList.add('is-complete');
            this.runProgress = {
                ...this.runProgress,
                status: 'aborted',
                phase: 'aborted',
                phaseMessage: 'Stopped',
            };
            this.renderTrace();
        }
    }

    closeEventSource() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    reconnect() {
        if (!this.currentRunId || !this.currentMercureTopic) {
            return;
        }
        this.authorizeMercure(this.currentRunId).then(() => {
            this.subscribeToMercure(this.currentMercureTopic);
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: 'Reconnecting…',
            };
            this.renderTrace();
        });
    }

    updateCancelButtonVisibility(visible) {
        if (!this.hasCancelBtnTarget) {
            return;
        }
        this.cancelBtnTarget.hidden = !visible;
    }

    setError(message) {
        this.cancelRun();
        this.runProgress = {
            ...this.runProgress,
            status: 'failed',
            phase: 'failed',
            phaseMessage: message,
        };
        this.renderTrace();
    }

    renderTrace() {
        renderTraceView({ toolCalls: this.toolCalls, traceBody: this.traceBodyTarget, progress: this.runProgress });
    }

    reloadHistoryFrame() {
        if (!this.hasHistoryFrameTarget) {
            return;
        }
        const url = this.historyFrameUrlValue;
        if (url) {
            this.historyFrameTarget.src = url;

            return;
        }
        if (typeof this.historyFrameTarget.reload === 'function') {
            this.historyFrameTarget.reload();
        }
    }

    stopPropagation(event) {
        event.stopPropagation();
    }

    async loadHistoryItem(event) {
        const runId = event.currentTarget.dataset.runId;
        if (!runId) {
            return;
        }

        const template = this.runUrlValue || `/research/runs/${runId}`;
        const runUrl = template.replace('__ID__', runId);

        try {
            const response = await fetch(runUrl, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                this.setError('Failed to load run');

                return;
            }

            const data = await response.json();
            const { run, steps } = data;

            this.inputTarget.value = run.query;
            this.accumulatedMarkdown = run.finalAnswerMarkdown || '';
            this.renderMode = 'rendered';
            this.queryLineTarget.textContent = run.query;
            this.heroTarget.style.display = 'none';

            this.toolCalls = steps
                .filter((step) => step.type === 'run_started' || step.type === 'assistant_reasoning' || step.type === 'tool_succeeded' || step.type === 'trace_pruned' || step.type === 'llm_retry' || step.type === 'answer_invalid_format')
                .map((step) => {
                    const meta = step.payloadJson ? (() => {
                        try {
                            return JSON.parse(step.payloadJson);
                        } catch {
                            return {};
                        }
                    })() : {};
                    let args = meta.arguments || {};
                    if (step.toolArgumentsJson) {
                        try {
                            args = JSON.parse(step.toolArgumentsJson);
                        } catch {
                            // keep meta.arguments
                        }
                    }
                    const url = args.url || args.link || null;
                    const result = meta.result ?? meta.result_preview ?? null;
                    const sequence = Number.isInteger(step.sequence) ? step.sequence : null;
                    const turnNumber = Number.isInteger(step.turnNumber) ? step.turnNumber : null;

                    return buildTraceItem(step.type, step.toolName || step.type, step.summary || '', args, url, result, sequence, turnNumber);
                });
            this.runProgress = this.buildProgressFromHistory(run, steps);
            this.renderTrace();

            this.element.classList.remove('is-searching');
            this.element.classList.add('is-complete');

            this.streamTarget.innerHTML = '';
            if (run.finalAnswerMarkdown) {
                this.renderAnswerBody();
                this.updateRenderModeToggleVisibility(true);
            } else {
                this.answerBodyTarget.innerHTML = buildNoAnswerHtml(run, escapeHtml);
                this.updateRenderModeToggleVisibility(false);
            }
            this.renderAnswerReferences();
            this.answerTarget.scrollTop = 0;
            this.traceTarget.scrollTop = 0;
            this.resultsTarget.hidden = false;
            this.showAnswerTab();
        } catch (err) {
            this.setError(err.message || 'Failed to load run');
        }
    }

    showAnswerTab() {
        this.activeTab = 'answer';
        this.tabsTarget.dataset.activeTab = 'answer';
        this.answerTarget.hidden = false;
        this.traceTarget.hidden = true;
        this.updateTabStyles();
    }

    showTraceTab() {
        this.activeTab = 'trace';
        this.tabsTarget.dataset.activeTab = 'trace';
        this.answerTarget.hidden = true;
        this.traceTarget.hidden = false;
        this.updateTabStyles();
    }

    updateTabStyles() {
        const buttons = this.tabsTarget.querySelectorAll('.tab-btn');
        buttons.forEach((btn) => {
            if (btn.dataset.tab === this.activeTab) {
                btn.classList.add('text-white', 'border-white');
                btn.classList.remove('text-gray-500', 'border-transparent');
            } else {
                btn.classList.add('text-gray-500', 'border-transparent');
                btn.classList.remove('text-white', 'border-white');
            }
        });
    }

    toggleSidebar() {
        this.sidebarOpen = !this.sidebarOpen;

        if (this.sidebarOpen) {
            this.sidebarTarget.classList.remove('-translate-x-full');
            this.sidebarTarget.classList.add('translate-x-0');
            this.sidebarOverlayTarget.classList.remove('hidden');
        } else {
            this.sidebarTarget.classList.add('-translate-x-full');
            this.sidebarTarget.classList.remove('translate-x-0');
            this.sidebarOverlayTarget.classList.add('hidden');
        }
    }

    renderAnswerBody() {
        renderAnswerBodyView({
            accumulatedMarkdown: this.accumulatedMarkdown,
            renderMode: this.renderMode,
            answerBody: this.answerBodyTarget,
        });
    }

    toggleRenderMode() {
        this.renderMode = this.renderMode === 'rendered' ? 'raw' : 'rendered';
        this.renderAnswerBody();
        this.renderAnswerReferences();
        this.updateRenderModeToggleLabel();
    }

    renderAnswerReferences() {
        renderAnswerReferencesView({
            hasAnswerReferencesTarget: this.hasAnswerReferencesTarget,
            answerReferences: this.answerReferencesTarget,
            accumulatedMarkdown: this.accumulatedMarkdown,
            toolCalls: this.toolCalls,
        });
    }

    jumpToTraceStep(event) {
        scrollToTraceStep(event, {
            traceTarget: this.traceTarget,
            traceBodyTarget: this.traceBodyTarget,
            showTraceTab: () => this.showTraceTab(),
        });
    }

    updateRenderModeToggleLabel() {
        if (!this.hasRenderModeToggleTarget) {
            return;
        }
        this.renderModeToggleTarget.textContent = this.renderMode === 'rendered' ? 'Raw' : 'Rendered';
    }

    updateRenderModeToggleVisibility(visible) {
        if (!this.hasRenderModeToggleTarget) {
            return;
        }
        this.renderModeToggleTarget.hidden = !visible;
        if (visible) {
            this.updateRenderModeToggleLabel();
        }
    }

    createInitialRunProgress() {
        return {
            phase: 'queued',
            status: 'running',
            hasRunStarted: false,
            llmTurns: 0,
            toolCallsCompleted: 0,
            phaseMessage: 'Forming initial query',
        };
    }

    applyActivityToProgress(stepType, summary) {
        if (stepType === 'run_started') {
            this.runProgress = {
                ...this.runProgress,
                hasRunStarted: true,
                phaseMessage: summary || this.runProgress.phaseMessage,
            };

            return;
        }

        if (stepType === 'tool_succeeded' || stepType === 'tool_failed') {
            this.runProgress = {
                ...this.runProgress,
                toolCallsCompleted: this.runProgress.toolCallsCompleted + 1,
                phaseMessage: summary || this.runProgress.phaseMessage,
            };

            return;
        }

        if (stepType === 'assistant_reasoning' || stepType === 'llm_retry' || stepType === 'answer_invalid_format' || stepType === 'assistant_empty') {
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: summary || this.runProgress.phaseMessage,
            };

            return;
        }

        if (stepType === 'loop_detected') {
            this.runProgress = {
                ...this.runProgress,
                phase: 'failed',
                status: 'loop_stopped',
                phaseMessage: summary || this.runProgress.phaseMessage,
            };
        }
    }

    buildProgressFromHistory(run, steps) {
        const progress = this.createInitialRunProgress();
        progress.phase = typeof run.phase === 'string' ? run.phase : 'queued';
        progress.status = typeof run.status === 'string' ? run.status : 'running';
        progress.phaseMessage = null;
        const llmTurns = new Set();

        for (const step of steps) {
            if (step.type === 'run_started') {
                progress.hasRunStarted = true;
            }

            if (Number.isInteger(step.turnNumber) && step.turnNumber >= 0) {
                llmTurns.add(step.turnNumber);
            }

            if (step.type === 'tool_succeeded' || step.type === 'tool_failed') {
                progress.toolCallsCompleted += 1;
            }
        }

        progress.llmTurns = llmTurns.size;

        return progress;
    }

    statusLabelForCompletion(status, reason) {
        if (status === 'completed') {
            return 'Research complete';
        }
        if (status === 'budget_exhausted') {
            return 'Budget exhausted';
        }
        if (status === 'loop_stopped') {
            return 'Stopped (loop detected)';
        }
        if (status === 'timed_out') {
            return 'Research timed out';
        }
        if (status === 'throttled') {
            return 'Rate limited — try again later';
        }
        if (status === 'aborted') {
            return 'Research aborted';
        }
        if (status === 'failed') {
            return reason || 'Research failed';
        }

        return 'Research complete';
    }
}
