import { Controller } from '@hotwired/stimulus';
import { renderAnswerBody as renderAnswerBodyView, renderAnswerReferences as renderAnswerReferencesView } from './research_ui/answer_view.js';
import { escapeHtml } from './research_ui/escape_html.js';
import { jumpToTraceStep as scrollToTraceStep } from './research_ui/jump_trace.js';
import { buildNoAnswerHtml } from './research_ui/run_format.js';
import { buildTraceItem, renderRunStatus as renderRunStatusView, renderTrace as renderTraceView } from './research_ui/trace_view.js';

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
        'runStatus',
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
        stopUrl: { type: String, default: '' },
        historyFrameUrl: { type: String, default: '' },
        mercureAuthUrl: { type: String, default: '' },
    };

    sidebarOpen = false;
    accumulatedMarkdown = '';
    renderMode = 'rendered';
    answerStreamingStarted = false;

    connect() {
        this.eventSource = null;
        this.activeTab = 'answer';
        this.toolCalls = [];
        this.runProgress = this.createInitialRunProgress();
        this.stopRequested = false;
        this.terminalHandled = false;
        this.finalAnswerReceived = false;
        this.finalizeTimerId = null;
        this.handlePageHide = () => this.closeEventSource();
        window.addEventListener('pagehide', this.handlePageHide);
        window.addEventListener('beforeunload', this.handlePageHide);

        this.showAnswerTab();
    }

    disconnect() {
        if (this.handlePageHide) {
            window.removeEventListener('pagehide', this.handlePageHide);
            window.removeEventListener('beforeunload', this.handlePageHide);
            this.handlePageHide = null;
        }

        this.closeEventSource();
    }

    submit(event) {
        event.preventDefault();

        const query = this.inputTarget.value.trim();
        if ('' === query) {
            return;
        }

        this.cancelRun();
        this.stopRequested = false;
        this.terminalHandled = false;
        this.finalAnswerReceived = false;
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
                    this.runProgress = {
                        ...this.runProgress,
                        status: 'throttled',
                        phase: 'failed',
                        phaseMessage: 'Rate limited - retry tomorrow!',
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

            if (this.stopRequested) {
                await this.requestRunStop(runId);
            }
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

        if (this.stopRequested) {
            if (type === 'complete') {
                this.completeRun(payload);

                return;
            }

            if (type === 'phase') {
                this.updatePhase(payload);
            }

            return;
        }

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

        this.applyActivityToProgress(stepType, summary, meta);

        if (stepType === 'assistant_stream') {
            this.appendAssistantStreamChunk(summary, meta);
            this.renderTrace();

            return;
        }

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

    appendAssistantStreamChunk(summary, meta = {}) {
        const textChunk = typeof summary === 'string' ? summary : '';
        if (textChunk.length === 0) {
            return;
        }

        const turnNumber = Number.isInteger(meta.turnNumber) ? meta.turnNumber : (Number.isInteger(meta.turn) ? meta.turn : null);
        const last = this.toolCalls.length > 0 ? this.toolCalls[this.toolCalls.length - 1] : null;
        const sameTurn = Number.isInteger(turnNumber)
            ? (Number.isInteger(last?.turnNumber) && last.turnNumber === turnNumber)
            : !Number.isInteger(last?.turnNumber);

        if (last && last.type === 'assistant_stream' && sameTurn) {
            last.message += textChunk;

            return;
        }

        this.toolCalls.push(buildTraceItem('assistant_stream', 'assistant stream', textChunk, {}, null, null, null, turnNumber));
    }

    updatePhase(payload) {
        const phase = typeof payload.phase === 'string' ? payload.phase : null;
        const status = typeof payload.status === 'string' ? payload.status : this.runProgress.status;
        const message = typeof payload.message === 'string' ? payload.message : null;
        const isEnteringWaitingLlm = phase === 'waiting_llm' && this.runProgress.phase !== 'waiting_llm';
        const terminalByPhase = phase === 'completed' || phase === 'failed' || phase === 'aborted';

        this.runProgress = {
            ...this.runProgress,
            phase,
            status,
            phaseMessage: message,
            hasRunStarted: this.runProgress.hasRunStarted || (typeof phase === 'string' && phase !== 'queued'),
            llmTurns: isEnteringWaitingLlm ? this.runProgress.llmTurns + 1 : this.runProgress.llmTurns,
        };

        if (!this.terminalHandled && this.stopRequested && terminalByPhase) {
            void this.completeRun({
                meta: {
                    status: typeof status === 'string' ? status : (phase === 'aborted' ? 'aborted' : 'failed'),
                    reason: message || '',
                },
            });

            return;
        }

        this.renderTrace();
    }

    appendAnswer(payload) {
        const { markdown, isFinal } = payload;
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
            this.finalAnswerReceived = true;

            if (this.finalizeTimerId !== null) {
                window.clearTimeout(this.finalizeTimerId);
            }
            this.finalizeTimerId = window.setTimeout(() => {
                if (!this.terminalHandled && this.finalAnswerReceived) {
                    void this.completeRun({
                        meta: {
                            status: 'completed',
                        },
                    });
                }
            }, 1800);

            if (!this.terminalHandled) {
                this.runProgress = {
                    ...this.runProgress,
                    status: 'completed',
                    phase: 'completed',
                    phaseMessage: 'Done',
                };
                this.renderTrace();
            }
        }

        this.renderAnswerReferences();
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

    async completeRun(payload = {}) {
        if (this.terminalHandled) {
            return;
        }
        this.terminalHandled = true;

        if (this.finalizeTimerId !== null) {
            window.clearTimeout(this.finalizeTimerId);
            this.finalizeTimerId = null;
        }

        const meta = payload.meta || {};
        const status = meta.status || 'completed';
        const reason = meta.reason || '';

        this.closeEventSource();

        this.updateCancelButtonVisibility(false);
        this.stopRequested = false;
        this.currentRunId = null;
        this.currentMercureTopic = null;
        this.finalAnswerReceived = status === 'completed';

        this.element.classList.remove('is-searching');
        this.element.classList.add('is-complete');

        const phaseMessage = this.statusLabelForCompletion(status, reason);

        this.runProgress = {
            ...this.runProgress,
            status,
            phase: status === 'aborted' ? 'aborted' : (status === 'completed' ? 'completed' : 'failed'),
            phaseMessage,
        };
        this.renderTrace();

        this.streamTarget.innerHTML = '';

        if (this.accumulatedMarkdown.trim().length > 0) {
            this.renderAnswerBody();
            this.updateRenderModeToggleVisibility(true);
        } else if (this.answerBodyTarget.innerHTML.trim().length === 0) {
            this.answerBodyTarget.innerHTML = buildNoAnswerHtml({
                status,
                failureReason: reason,
            }, escapeHtml);
            this.updateRenderModeToggleVisibility(false);
        }

        this.showAnswerTab();
        this.renderAnswerReferences();

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
        if (this.finalizeTimerId !== null) {
            window.clearTimeout(this.finalizeTimerId);
            this.finalizeTimerId = null;
        }

        this.closeEventSource();
        this.updateCancelButtonVisibility(false);
        this.currentMercureTopic = null;
        this.stopRequested = false;
        this.finalAnswerReceived = false;

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

        this.currentRunId = null;
    }

    async stopRun(event) {
        event.preventDefault();

        this.stopRequested = true;

        const runId = this.currentRunId;

        this.updateCancelButtonVisibility(true);
        if (this.hasCancelBtnTarget) {
            this.cancelBtnTarget.disabled = true;
            this.cancelBtnTarget.textContent = 'Stopping...';
        }
        this.runProgress = {
            ...this.runProgress,
            phaseMessage: 'Stopping...',
        };
        this.renderTrace();

        if (!runId) {
            return;
        }

        try {
            await this.requestRunStop(runId);
        } catch (error) {
            void error;
        }
    }

    closeEventSource() {
        if (this.eventSource) {
            this.eventSource.onmessage = null;
            this.eventSource.onerror = null;
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    async requestRunStop(runId) {
        const template = this.stopUrlValue || `/research/runs/${runId}/stop`;
        const stopUrl = template.replace('__ID__', runId);
        await fetch(stopUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        });
    }

    async fetchRunSnapshot(runId) {
        const template = this.runUrlValue || `/research/runs/${runId}`;
        const runUrl = template.replace('__ID__', runId);
        const response = await fetch(runUrl, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to fetch run snapshot');
        }

        return response.json();
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

        if (!visible) {
            this.cancelBtnTarget.disabled = false;
            this.cancelBtnTarget.textContent = 'Stop';
        }
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
        if (this.hasRunStatusTarget) {
            renderRunStatusView({ progress: this.runProgress, runStatus: this.runStatusTarget });
        }
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
        this.cancelRun();

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
            phaseMessage: 'Planning first step',
        };
    }

    applyActivityToProgress(stepType, summary, meta = {}) {
        if (stepType === 'run_started') {
            this.runProgress = {
                ...this.runProgress,
                hasRunStarted: true,
                phaseMessage: 'Planning first step',
            };

            return;
        }

        if (stepType === 'tool_succeeded') {
            const toolName = typeof meta.tool === 'string' ? meta.tool.trim() : '';
            this.runProgress = {
                ...this.runProgress,
                toolCallsCompleted: this.runProgress.toolCallsCompleted + 1,
                phaseMessage: toolName ? `Planning next step after ${toolName}` : 'Planning next step',
            };

            return;
        }

        if (stepType === 'tool_failed') {
            this.runProgress = {
                ...this.runProgress,
                toolCallsCompleted: this.runProgress.toolCallsCompleted + 1,
                phaseMessage: 'Recovering from tool error',
            };

            return;
        }

        if (stepType === 'assistant_reasoning' || stepType === 'llm_retry' || stepType === 'answer_invalid_format' || stepType === 'assistant_empty') {
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: 'Planning next step',
            };

            return;
        }

        if (stepType === 'assistant_stream') {
            this.runProgress = {
                ...this.runProgress,
                phaseMessage: 'Writing answer',
            };

            return;
        }

        if (stepType === 'loop_detected') {
            this.runProgress = {
                ...this.runProgress,
                phase: 'failed',
                status: 'loop_stopped',
                phaseMessage: summary || 'Stopped: loop detected',
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

    isTerminalStatus(status) {
        return status === 'completed'
            || status === 'budget_exhausted'
            || status === 'loop_stopped'
            || status === 'timed_out'
            || status === 'throttled'
            || status === 'aborted'
            || status === 'failed';
    }
}
