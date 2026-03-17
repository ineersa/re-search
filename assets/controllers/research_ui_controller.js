import { Controller } from '@hotwired/stimulus';

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
    ];

    connect() {
        this.timer = null;
        this.stepIndex = 0;
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
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    submit(event) {
        event.preventDefault();

        const query = this.inputTarget.value.trim();
        if (!query) {
            return;
        }

        this.cancelRun();
        this.toolCalls = [];
        this.stepIndex = 0;
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
        this.resultsTarget.hidden = false;
        this.heroTarget.style.display = 'none';
        this.showTraceTab();

        this.runNextStep(query);
    }

    switchTab(event) {
        const tab = event.currentTarget.dataset.tab;
        if (tab === 'trace') {
            this.showTraceTab();

            return;
        }

        this.showAnswerTab();
    }

    runNextStep(query) {
        const script = [
            {
                type: 'tool',
                label: 'websearch.search',
                message: 'Looking up official Symfony + Tailwind integration docs.',
                link: 'https://symfony.com/bundles/TailwindBundle',
                path: 'seed query -> official docs',
            },
            {
                type: 'reasoning',
                label: 'reasoning',
                message: 'Comparing AssetMapper-first workflow versus Encore-specific setup.',
            },
            {
                type: 'tool',
                label: 'websearch.open',
                message: 'Reading bundle commands and build order details.',
                link: 'https://symfony.com/doc/current/frontend/asset_mapper.html',
                path: 'docs -> commands -> deploy notes',
            },
            {
                type: 'tool',
                label: 'websearch.open',
                message: 'Cross-checking Tailwind standalone CLI options.',
                link: 'https://tailwindcss.com/blog/standalone-cli',
                path: 'alt flow -> cli flags',
            },
            {
                type: 'reasoning',
                label: 'reasoning',
                message: 'Preparing concise recommendation and rollout command list.',
            },
        ];

        if (this.stepIndex >= script.length) {
            this.completeRun(query);

            return;
        }

        const step = script[this.stepIndex];
        this.stepIndex += 1;

        const row = document.createElement('article');
        row.className = `border border-[#8da8d638] bg-[#0f1521e6] p-3`;
        if (step.type === 'tool') {
            row.classList.add('border-gray-500');
        }
        const safeLabel = this.escapeHtml(step.label);
        const safeMessage = this.escapeHtml(step.message);
        row.innerHTML = `
            <p class="m-0 text-xs uppercase tracking-wider text-[#95a1b9]">${safeLabel}</p>
            <p class="mt-1 leading-relaxed">${safeMessage}</p>
        `;
        this.streamTarget.appendChild(row);
        this.streamTarget.scrollTop = this.streamTarget.scrollHeight;

        if (step.type === 'tool') {
            this.toolCalls.push(step);
            this.renderTrace();
        }

        this.timer = setTimeout(() => this.runNextStep(query), 820);
    }

    completeRun(query) {
        this.cancelRun();
        this.element.classList.remove('is-searching');
        this.element.classList.add('is-complete');
        this.statusTarget.classList.remove('text-gray-400');
        this.statusTarget.classList.add('text-white');
        this.statusTarget.textContent = 'Research complete';
        this.streamTarget.innerHTML = '';
        this.answerBodyTarget.innerHTML = `
            <h2 class="m-0 text-lg">Symfony + Tailwind (AssetMapper)</h2>
            <p class="mt-1 leading-relaxed">Use <code>symfonycasts/tailwind-bundle</code> as the default path, then run <code>tailwind:build</code> before <code>asset-map:compile</code> in production builds.</p>
            <p class="mt-1 leading-relaxed">Keep daily development on <code>tailwind:build --watch</code> and retain trace details in the Tools tab for auditability.</p>
        `;

        this.showAnswerTab();

        this.historyItems.unshift({ query, time: 'just now' });
        this.historyItems = this.historyItems.slice(0, 10);
        this.renderHistory();
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
                <article class="border border-[#8da8d638] bg-[#0f1521e6] p-3">
                    <p class="m-0 text-xs uppercase tracking-wider text-[#95a1b9]">#${index + 1} ${safeLabel}</p>
                    <p class="mt-1 leading-relaxed">${safeMessage}</p>
                    <p class="mt-1 leading-relaxed">Path: ${safePath}</p>
                    <a class="mt-2 inline-block text-sm text-blue-400 no-underline hover:underline" href="${safeLink}" target="_blank" rel="noreferrer">${safeLink}</a>
                </article>
            `;
                },
            )
            .join('');

        this.traceBodyTarget.innerHTML = items;
    }

    renderHistory() {
        const items = this.historyItems
            .map(
                (item) => {
                    const safeQuery = this.escapeHtml(item.query);
                    const safeTime = this.escapeHtml(item.time);

                    return `
                <article class="cursor-pointer border border-transparent bg-[#0f1420bf] p-3 transition-all hover:translate-x-[2px] hover:border-[#8da8d638] history-row" data-action="click->research-ui#loadHistoryItem" data-query="${safeQuery}">
                    <p class="m-0 text-sm leading-tight">${safeQuery}</p>
                    <p class="mt-1 text-xs text-[#95a1b9]">${safeTime}</p>
                </article>
            `;
                },
            )
            .join('');

        this.historyTarget.innerHTML = items;
    }

    loadHistoryItem(event) {
        const query = event.currentTarget.dataset.query;
        this.inputTarget.value = query;
        this.queryLineTarget.textContent = query;
        this.heroTarget.style.display = 'none';
        
        this.toolCalls = [
            {
                label: 'database.query',
                message: 'Loading previous research trace from memory.',
                path: 'cache -> history',
                link: '#'
            },
            {
                label: 'agent.reasoning',
                message: 'Verifying past responses are still relevant.',
                path: 'eval -> check',
                link: '#'
            },
            {
                label: 'result.render',
                message: 'Formatting historical data for current view.',
                path: 'ui -> render',
                link: '#'
            }
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
            <p class="mt-1 leading-relaxed">This is a historical view of your query: <strong>"${this.escapeHtml(query)}"</strong></p>
            <p class="mt-1 leading-relaxed">The agent has successfully restored the final answer and the corresponding tool execution trace. You can view the original process in the Trace tab.</p>
        `;

        this.resultsTarget.hidden = false;
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
                btn.classList.remove('text-[#95a1b9]', 'border-transparent');
            } else {
                btn.classList.add('text-[#95a1b9]', 'border-transparent');
                btn.classList.remove('text-white', 'border-white');
            }
        });
    }

    cancelRun() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
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
