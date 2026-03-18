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
        mercureAuthUrl: { type: String, default: '' },
    };

    sidebarOpen = false;
    historyPage = 0;
    historyItemsPerPage = 5;
    accumulatedMarkdown = '';
    renderMode = 'rendered';

    connect() {
        this.timer = null;
        this.eventSource = null;
        this.activeTab = 'answer';
        this.toolCalls = [];
        this.historyItems = [
            { query: 'How to setup Symfony UX Turbo with Mercure?', time: '2m ago' },
            { query: 'Tailwind with AssetMapper deployment steps', time: '9m ago' },
            { query: 'Best strategy for LiveComponent form validation', time: '24m ago' },
            { query: 'SQLite migration rollback in Symfony', time: '36m ago' },
            { query: 'Difference between Turbo Frames and Streams', time: '52m ago' },
            { query: 'How to debug Stimulus disconnect leaks', time: '1h ago' },
            { query: 'Secure API key storage in Docker Symfony apps', time: '2h ago' },
            { query: 'Implement skeleton loaders in Twig components', time: '3h ago' },
            { query: 'AssetMapper vs Vite tradeoffs in 2026', time: '5h ago' },
            { query: 'Profiler timeline for slow Doctrine queries', time: '7h ago' },
        ];

        this.renderHistory();
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
                this.setError(data.error || 'Failed to start research');
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
        const link = meta.url || meta.link || null;
        const path = meta.path || '';

        const row = document.createElement('article');
        row.className = 'border border-[#333] bg-[#1a1a1a] p-3';
        if (stepType?.startsWith('tool_') || toolName !== stepType) {
            row.classList.add('border-gray-500');
        }
        const safeLabel = this.escapeHtml(toolName);
        const safeMessage = this.escapeHtml(summary || '');
        row.innerHTML = `
            <p class="m-0 text-xs uppercase tracking-wider text-gray-500">${safeLabel}</p>
            <p class="mt-1 leading-relaxed text-gray-300">${safeMessage}</p>
        `;
        if (link) {
            const safeLink = this.escapeHtml(link);
            row.innerHTML += `<a class="mt-2 inline-block text-sm text-blue-400 no-underline hover:text-blue-300 hover:underline transition-colors" href="${safeLink}" target="_blank" rel="noreferrer">${safeLink}</a>`;
        }
        this.streamTarget.appendChild(row);
        this.streamTarget.scrollTop = this.streamTarget.scrollHeight;

        this.toolCalls.push({
            label: toolName,
            message: summary || '',
            path: path || 'n/a',
            link: link || '#',
        });
        this.renderTrace();
    }

    appendAnswer(payload) {
        const { markdown, isFinal } = payload;
        if (!markdown) {
            return;
        }

        this.accumulatedMarkdown += markdown;
        this.renderAnswerBody();
        this.answerBodyTarget.scrollTop = this.answerBodyTarget.scrollHeight;

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
        } else if (status === 'failed') {
            this.statusTarget.textContent = reason || 'Research failed';
        } else {
            this.statusTarget.textContent = 'Research complete';
        }

        this.streamTarget.innerHTML = '';
        this.showAnswerTab();

        const query = this.queryLineTarget?.textContent || '';
        this.historyItems.unshift({ query, time: 'just now' });
        this.historyItems = this.historyItems.slice(0, 10);
        this.historyPage = 0;
        this.renderHistory();
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

    renderTrace() {
        if (this.toolCalls.length === 0) {
            this.traceBodyTarget.innerHTML = '<p class="trace-empty">No tool calls yet.</p>';

            return;
        }

        const items = this.toolCalls
            .map(
                (call, index) => {
                    const safeLabel = this.escapeHtml(call.label);
                    const safeMessage = this.escapeHtml(call.message);
                    const safePath = this.escapeHtml(call.path ?? 'n/a');
                    const safeLink = this.escapeHtml(call.link ?? '#');

                    return `
                <article class="border border-[#333] bg-[#1a1a1a] p-3">
                    <p class="m-0 text-xs uppercase tracking-wider text-gray-500">#${index + 1} ${safeLabel}</p>
                    <p class="mt-1 leading-relaxed text-gray-300">${safeMessage}</p>
                    <p class="mt-1 leading-relaxed text-gray-400">Path: ${safePath}</p>
                    <a class="mt-2 inline-block text-sm text-blue-400 no-underline hover:text-blue-300 hover:underline transition-colors" href="${safeLink}" target="_blank" rel="noreferrer">${safeLink}</a>
                </article>
            `;
                },
            )
            .join('');

        this.traceBodyTarget.innerHTML = items;
    }

    renderHistory() {
        const totalPages = Math.ceil(this.historyItems.length / this.historyItemsPerPage);
        const start = this.historyPage * this.historyItemsPerPage;
        const end = start + this.historyItemsPerPage;
        const itemsToShow = this.historyItems.slice(start, end);

        const items = itemsToShow
            .map(
                (item) => {
                    const safeQuery = this.escapeHtml(item.query);
                    const safeTime = this.escapeHtml(item.time);

                    return `
                <article class="cursor-pointer border border-transparent bg-[#141414] p-3 transition-all hover:translate-x-[2px] hover:border-[#444] history-row" data-action="click->research-ui#loadHistoryItem" data-query="${safeQuery}">
                    <p class="m-0 text-sm leading-tight text-gray-200">${safeQuery}</p>
                    <p class="mt-1 text-xs text-gray-500">${safeTime}</p>
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

    loadHistoryItem(event) {
        const query = event.currentTarget.dataset.query;
        this.inputTarget.value = query;
        this.accumulatedMarkdown = '';
        this.renderMode = 'rendered';
        this.queryLineTarget.textContent = query;
        this.heroTarget.style.display = 'none';

        this.toolCalls = [
            {
                label: 'database.query',
                message: 'Loading previous research trace from memory.',
                path: 'cache -> history',
                link: '#',
            },
            {
                label: 'agent.reasoning',
                message: 'Verifying past responses are still relevant.',
                path: 'eval -> check',
                link: '#',
            },
            {
                label: 'result.render',
                message: 'Formatting historical data for current view.',
                path: 'ui -> render',
                link: '#',
            },
        ];
        this.renderTrace();

        this.element.classList.remove('is-searching');
        this.element.classList.add('is-complete');
        this.statusTarget.classList.remove('text-gray-400');
        this.statusTarget.classList.add('text-white');
        this.statusTarget.textContent = 'Loaded from history';
        this.streamTarget.innerHTML = '';
        this.answerBodyTarget.innerHTML = `
            <h2 class="m-0 text-lg">Retrieved from cache</h2>
            <p class="mt-1 leading-relaxed">This is a historical view of your query: <strong>${this.escapeHtml(query)}</strong></p>
            <p class="mt-1 leading-relaxed">The agent has successfully restored the final answer and the corresponding tool execution trace. You can view the original process in the Trace tab.</p>
        `;

        this.resultsTarget.hidden = false;
        this.updateRenderModeToggleVisibility(false);
        this.showAnswerTab();
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

        const rawHtml = marked.parse(this.accumulatedMarkdown, { gfm: true, breaks: true, silent: true }) ?? '';
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
