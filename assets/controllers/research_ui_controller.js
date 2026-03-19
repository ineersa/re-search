import { Controller } from '@hotwired/stimulus';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import hljs from 'highlight.js';

/**
 * Research UI controller: submits real runs, consumes Mercure events,
 * appends tool activity to trace UI, streams markdown to answer container,
 * and supports cancel/reconnect behavior.
 *
 * Markdown is rendered safely (marked + DOMPurify) with raw/rendered toggle
 * and highlight.js for fenced code blocks.
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
        'trace',
        'traceBody',
        'status',
        'history',
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
        historyUrl: { type: String, default: '/research/runs' },
        runUrl: { type: String, default: '' },
        mercureAuthUrl: { type: String, default: '' },
        inspectUrl: { type: String, default: '' },
    };

    sidebarOpen = false;
    historyPage = 0;
    historyItemsPerPage = 5;
    accumulatedMarkdown = '';
    renderMode = 'rendered';
    answerStreamingStarted = false;

    connect() {
        this.timer = null;
        this.eventSource = null;
        this.activeTab = 'answer';
        this.toolCalls = [];
        this.historyItems = [];

        this.fetchHistory();
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
        this.accumulatedMarkdown = '';
        this.renderMode = 'rendered';
        this.answerStreamingStarted = false;
        this.activeTab = 'answer';
        this.queryLineTarget.textContent = query;

        this.element.classList.add('is-searching');
        this.element.classList.remove('is-complete');
        this.statusTarget.classList.remove('text-white');
        this.statusTarget.classList.add('text-gray-400');
        this.statusTarget.textContent = 'Agent is researching...';
        this.streamTarget.innerHTML = '';
        this.answerBodyTarget.innerHTML = '';
        this.traceBodyTarget.innerHTML = '';
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
                    this.statusTarget.classList.remove('text-gray-400');
                    this.statusTarget.classList.add('text-amber-400');
                    this.element.classList.remove('is-searching');
                    this.element.classList.add('is-complete');
                    const retrySec = data.retryAfter ?? 600;
                    this.statusTarget.textContent = `Rate limited — retry in ${Math.ceil(retrySec / 60)} min`;
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
            default:
                break;
        }
    }

    appendActivity(payload) {
        const { stepType, summary, meta = {} } = payload;

        const toolName = meta.tool || stepType;
        const args = meta.arguments || {};
        const link = meta.url || meta.link || null;

        const result = meta.result ?? null;
        const traceItem = this.buildTraceItem(stepType, toolName, summary, args, link, result);
        this.toolCalls.push(traceItem);
        this.renderTrace();
    }

    appendAnswer(payload) {
        const { markdown, isFinal } = payload;
        if (markdown) {
            const hadContentBefore = this.accumulatedMarkdown.trim().length > 0;
            this.accumulatedMarkdown += markdown;
            this.renderAnswerBody();
            this.answerBodyTarget.scrollTop = this.answerBodyTarget.scrollHeight;

            if (!this.answerStreamingStarted && !hadContentBefore && this.accumulatedMarkdown.trim().length > 0) {
                this.answerStreamingStarted = true;
                this.showAnswerTab();
            }
        }

        if (isFinal) {
            this.updateRenderModeToggleVisibility(true);
            this.showAnswerTab();
        }
    }

    updateBudget(payload) {
        const { meta = {} } = payload;
        const remaining = meta.remaining;
        if (typeof remaining === 'number' && remaining < 10000) {
            this.statusTarget.textContent = `Researching… ~${Math.round(remaining / 1000)}k tokens left`;
        }
    }

    completeRun(payload = {}) {
        this.closeEventSource();
        this.updateCancelButtonVisibility(false);

        this.element.classList.remove('is-searching');
        this.element.classList.add('is-complete');
        this.statusTarget.classList.remove('text-gray-400');
        this.statusTarget.classList.add('text-white');

        const meta = payload.meta || {};
        const status = meta.status || 'completed';
        const reason = meta.reason || '';

        if (status === 'completed') {
            this.statusTarget.textContent = 'Research complete';
        } else if (status === 'budget_exhausted') {
            this.statusTarget.textContent = 'Budget exhausted';
        } else if (status === 'loop_stopped') {
            this.statusTarget.textContent = 'Stopped (loop detected)';
        } else if (status === 'timed_out') {
            this.statusTarget.textContent = 'Research timed out';
        } else if (status === 'throttled') {
            this.statusTarget.textContent = 'Rate limited — try again later';
        } else if (status === 'aborted') {
            this.statusTarget.textContent = 'Research aborted';
        } else if (status === 'failed') {
            this.statusTarget.textContent = reason || 'Research failed';
        } else {
            this.statusTarget.textContent = 'Research complete';
        }

        this.streamTarget.innerHTML = '';
        this.showAnswerTab();

        this.fetchHistory();
    }

    handleMercureError() {
        if (this.eventSource?.readyState === EventSource.CLOSED) {
            return;
        }
        if (this.eventSource?.readyState === EventSource.CONNECTING) {
            this.statusTarget.textContent = 'Reconnecting…';
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
            this.statusTarget.textContent = 'Stopped';
            this.statusTarget.classList.remove('text-gray-400');
            this.statusTarget.classList.add('text-white');
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
            this.statusTarget.textContent = 'Reconnecting…';
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
        this.statusTarget.textContent = this.escapeHtml(message);
        this.statusTarget.classList.remove('text-gray-400');
        this.statusTarget.classList.add('text-red-400');
    }

    buildTraceItem(stepType, toolName, summary, args, link, result = null) {
        const isRunStarted = stepType === 'run_started';
        const url = args.url || link || null;
        const query = args.query ?? null;
        const filter = args.query ?? args.selector ?? null;

        return {
            type: isRunStarted ? 'run_started' : 'tool',
            label: toolName,
            message: summary || '',
            arguments: args,
            query,
            url,
            filter,
            result: result ?? null,
        };
    }

    renderTrace() {
        if (this.toolCalls.length === 0) {
            this.traceBodyTarget.innerHTML = '<p class="trace-empty">No tool calls yet.</p>';

            return;
        }

        const items = this.toolCalls
            .map(
                (call, index) => {
                    if (call.type === 'run_started') {
                        return this.renderTraceRunStarted(index, call);
                    }

                    return this.renderTraceToolCall(index, call);
                },
            )
            .join('');

        this.traceBodyTarget.innerHTML = items;
        this.attachTraceClickHandlers();
    }

    renderTraceRunStarted(index, call) {
        const safeLabel = this.escapeHtml(call.label);
        const safeMessage = this.escapeHtml(call.message);

        return `
            <article class="border border-[#333] bg-[#1a1a1a] p-3 trace-card" data-trace-index="${index}">
                <p class="m-0 text-xs uppercase tracking-wider text-gray-500">#${index + 1} ${safeLabel}</p>
                <p class="mt-1 leading-relaxed text-gray-300">${safeMessage}</p>
            </article>
        `;
    }

    renderTraceToolCall(index, call) {
        const safeLabel = this.escapeHtml(call.label);
        let primaryText = '';
        if (call.label === 'websearch_search' && call.query) {
            primaryText = this.escapeHtml(call.query);
        } else if (call.label === 'websearch_open' && call.url) {
            primaryText = this.escapeHtml(call.url);
        } else if (call.label === 'websearch_find' && (call.url || call.filter)) {
            primaryText = [call.url, call.filter].filter(Boolean).map((s) => this.escapeHtml(s)).join(' · ');
        } else {
            primaryText = this.escapeHtml(call.message);
        }

        const hasParams = Object.keys(call.arguments || {}).length > 0;
        const paramsJson = hasParams ? this.escapeHtml(JSON.stringify(call.arguments, null, 2)) : '';
        const hasResult = typeof call.result === 'string' && call.result.length > 0;
        const resultText = hasResult ? this.escapeHtml(call.result) : '';

        return `
            <article class="border border-[#333] bg-[#1a1a1a] p-3 trace-card hover:border-gray-500 transition-colors" data-trace-index="${index}">
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

    attachTraceClickHandlers() {
        this.traceBodyTarget.querySelectorAll('.trace-card').forEach((card) => {
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

    async fetchHistory() {
        const historyUrl = this.historyUrlValue || '/research/runs';
        try {
            const response = await fetch(historyUrl, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            this.historyItems = data.runs || [];
        } catch {
            this.historyItems = [];
        }
        this.historyPage = 0;
        this.renderHistory();
    }

    renderHistory() {
        if (this.historyItems.length === 0) {
            this.historyTarget.innerHTML = '<p class="m-0 text-sm text-gray-600">No runs yet.</p>';

            return;
        }

        const totalPages = Math.ceil(this.historyItems.length / this.historyItemsPerPage);
        const start = this.historyPage * this.historyItemsPerPage;
        const end = start + this.historyItemsPerPage;
        const itemsToShow = this.historyItems.slice(start, end);

        const items = itemsToShow
            .map(
                (item) => {
                    const safeQuery = this.escapeHtml(item.query);
                    const safeTime = this.formatTimeAgo(item.completedAt || item.createdAt);
                    const safeStatus = this.escapeHtml(this.formatStatus(item));
                    const safeMeta = this.escapeHtml(this.formatBudgetMeta(item));
                    const inspectHref = this.hasInspectUrlValue && this.inspectUrlValue
                        ? this.inspectUrlValue.replace('__ID__', this.escapeHtml(item.id))
                        : null;
                    const inspectLink = inspectHref
                        ? `<a href="${inspectHref}" target="_blank" rel="noopener" class="shrink-0 text-xs text-gray-500 hover:text-gray-300 transition-colors" data-action="click->research-ui#stopPropagation">Inspect</a>`
                        : '';

                    return `
                <article class="flex items-start justify-between gap-2 cursor-pointer border border-transparent bg-[#141414] p-3 transition-all hover:translate-x-[2px] hover:border-[#444] history-row" data-action="click->research-ui#loadHistoryItem" data-run-id="${this.escapeHtml(item.id)}">
                    <div class="min-w-0 flex-1">
                        <p class="m-0 text-sm leading-tight text-gray-200">${safeQuery}</p>
                        <p class="mt-1 text-xs text-gray-500">${safeTime}</p>
                        <p class="mt-0.5 text-xs text-gray-600">${safeStatus}${safeMeta ? ` · ${safeMeta}` : ''}</p>
                    </div>
                    ${inspectLink}
                </article>
            `;
                },
            )
            .join('');

        let pagination = '';
        if (this.historyItems.length > this.historyItemsPerPage) {
            const prevDisabled = this.historyPage === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:text-white';
            const nextDisabled = this.historyPage >= totalPages - 1 ? 'opacity-30 cursor-not-allowed' : 'hover:text-white';
            const prevPage = this.historyPage > 0 ? this.historyPage - 1 : 0;
            const nextPage = this.historyPage < totalPages - 1 ? this.historyPage + 1 : totalPages - 1;

            pagination = `
                <div class="flex items-center justify-center gap-4 mt-4 pt-4 border-t border-[#222]">
                    <button class="text-gray-500 transition ${prevDisabled}" data-action="click->research-ui#changePage" data-page="${prevPage}" ${this.historyPage === 0 ? 'disabled' : ''}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>
                    <span class="text-xs text-gray-500">${this.historyPage + 1} / ${totalPages}</span>
                    <button class="text-gray-500 transition ${nextDisabled}" data-action="click->research-ui#changePage" data-page="${nextPage}" ${this.historyPage >= totalPages - 1 ? 'disabled' : ''}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>
            `;
        }

        this.historyTarget.innerHTML = items + pagination;
    }

    changePage(event) {
        const newPage = parseInt(event.currentTarget.dataset.page, 10);
        if (newPage !== this.historyPage) {
            this.historyPage = newPage;
            this.renderHistory();
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
                .filter((step) => step.type === 'run_started' || step.type === 'tool_succeeded')
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

                    return this.buildTraceItem(step.type, step.toolName || step.type, step.summary || '', args, url, result);
                });
            this.renderTrace();

            this.element.classList.remove('is-searching');
            this.element.classList.add('is-complete');
            this.statusTarget.classList.remove('text-gray-400');
            this.statusTarget.classList.add('text-white');
            this.statusTarget.textContent = this.formatStatus(run);

            this.streamTarget.innerHTML = '';
            if (run.finalAnswerMarkdown) {
                this.renderAnswerBody();
                this.updateRenderModeToggleVisibility(true);
            } else {
                this.answerBodyTarget.innerHTML = this.buildNoAnswerHtml(run);
                this.updateRenderModeToggleVisibility(false);
            }
            this.resultsTarget.hidden = false;
            this.showAnswerTab();
        } catch (err) {
            this.setError(err.message || 'Failed to load run');
        }
    }

    formatTimeAgo(isoString) {
        if (!isoString) {
            return '';
        }
        const date = new Date(isoString);
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHr = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHr / 24);

        if (diffSec < 60) {
            return 'just now';
        }
        if (diffMin < 60) {
            return `${diffMin}m ago`;
        }
        if (diffHr < 24) {
            return `${diffHr}h ago`;
        }
        if (diffDay < 7) {
            return `${diffDay}d ago`;
        }
        return date.toLocaleDateString();
    }

    formatStatus(run) {
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

    formatBudgetMeta(run) {
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

    buildNoAnswerHtml(run) {
        const status = this.formatStatus(run);
        const reason = run.failureReason ? this.escapeHtml(run.failureReason) : '';
        const meta = this.formatBudgetMeta(run);

        return `
            <p class="m-0 text-gray-500">No answer produced. Status: ${this.escapeHtml(status)}</p>
            ${reason ? `<p class="mt-1 text-sm text-gray-600">${reason}</p>` : ''}
            ${meta ? `<p class="mt-1 text-xs text-gray-600">${this.escapeHtml(meta)}</p>` : ''}
        `;
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
        if (this.renderMode === 'raw') {
            this.answerBodyTarget.innerHTML = `<pre class="answer-markdown-raw m-0 p-0 overflow-x-auto whitespace-pre-wrap break-words text-gray-300">${this.escapeHtml(this.accumulatedMarkdown)}</pre>`;

            return;
        }

        const rawHtml = marked.parse(this.accumulatedMarkdown, { gfm: true, breaks: false, silent: true }) ?? '';
        const safeHtml = DOMPurify.sanitize(String(rawHtml));
        this.answerBodyTarget.innerHTML = `<div class="answer-markdown-rendered markdown-body">${safeHtml}</div>`;
        this.answerBodyTarget.querySelectorAll('pre code').forEach((el) => {
            hljs.highlightElement(el);
        });
    }

    toggleRenderMode() {
        this.renderMode = this.renderMode === 'rendered' ? 'raw' : 'rendered';
        this.renderAnswerBody();
        this.updateRenderModeToggleLabel();
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

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }
}
