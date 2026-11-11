/**
 * Punto de entrada del visor/editor.
 */

import { Api, ApiError } from './api.js';
import { Inspector } from './editor.js';
import { Graph } from './graph.js';
import { Store } from './state.js';
import {
    clear,
    copyText,
    debounce,
    download,
    el,
    formatCount,
    isEditableTarget,
    qs,
    slugify,
} from './utils.js';

class DberApp {
    constructor(root) {
        this.root = root;
        const configuredPoll = Number(root.dataset.pollInterval ?? 1500);
        this.pollInterval = configuredPoll <= 0 ? 0 : Math.max(700, configuredPoll);
        this.schemaPath = root.dataset.schemaPath ?? 'diagram-er/base.json';
        this.storageKey = `dber:layout:${this.schemaPath}`;

        this.store = new Store();
        this.api = new Api(root);
        this.saving = false;
        this.polling = false;
        this.pollFailed = false;
        this.loadError = null;
        this.transientIssues = [];
        this.pendingPayload = null;
        this.pollAbort = null;
        this.lastIssuesSignature = null;

        this.elements = {
            projectName: qs('#dber-project-name'),
            stats: qs('#dber-stats'),
            path: qs('#dber-path'),
            dirty: qs('#dber-dirty'),
            save: qs('#dber-save'),
            undo: qs('#dber-undo'),
            redo: qs('#dber-redo'),
            import: qs('#dber-import'),
            live: qs('#dber-live'),
            liveLabel: qs('#dber-live-label'),
            theme: qs('#dber-theme'),
            themeIcon: qs('#dber-theme-icon'),
            addTable: qs('#dber-add-table'),
            search: qs('#dber-search'),
            tableList: qs('#dber-table-list'),
            showPivots: qs('#dber-show-pivots'),
            autoLayout: qs('#dber-auto-layout'),
            fit: qs('#dber-fit'),
            zoomIn: qs('#dber-zoom-in'),
            zoomOut: qs('#dber-zoom-out'),
            zoomLabel: qs('#dber-zoom-label'),
            empty: qs('#dber-empty'),
            emptyTitle: qs('#dber-empty-title'),
            emptyText: qs('#dber-empty-text'),
            emptyAction: qs('#dber-empty-action'),
            emptyPath: qs('#dber-empty-path'),
            issues: qs('#dber-issues'),
            loading: qs('#dber-loading'),
            exportToggle: qs('#dber-export-toggle'),
            exportMenu: qs('#dber-export-menu'),
            downloadJson: qs('#dber-download-json'),
            copyContext: qs('#dber-copy-context'),
            contextLink: qs('a[href][download][href$="context.md"]'),
            mermaidLink: qs('a[href][download][href$="diagram.mmd"]'),
            modalRoot: qs('#dber-modal-root'),
            toastRoot: qs('#dber-toast-root'),
            inspector: qs('#dber-inspector'),
            canvas: qs('#dber-canvas'),
            viewport: qs('#dber-viewport'),
            edges: qs('#dber-edges'),
            nodes: qs('#dber-nodes'),
        };

        this.showPivots = window.localStorage.getItem('dber:show-pivots') === '1';
        this.store.showPivots = this.showPivots;
        this.elements.showPivots.checked = this.showPivots;

        this.theme = window.localStorage.getItem('dber:theme')
            ?? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        this.applyTheme(this.theme, false);

        this.graph = new Graph({
            svg: this.elements.canvas,
            viewport: this.elements.viewport,
            edges: this.elements.edges,
            nodes: this.elements.nodes,
            storageKey: this.storageKey,
            onSelect: (key) => this.openTable(key),
            onEdit: (key) => this.openTable(key),
            onZoom: (percent) => {
                this.elements.zoomLabel.textContent = `${percent}%`;
            },
        });

        this.inspector = new Inspector({
            root: this.elements.inspector,
            modalRoot: this.elements.modalRoot,
            store: this.store,
            notify: (message, type) => this.notify(message, type),
            onSave: () => this.saveSchema(),
        });

        this.syncGraph = this.syncGraph.bind(this);
        this.scheduleGraphSync = debounce(() => this.syncGraph(), 70);
        this.scheduleIssues = debounce(() => this.refreshIssues(), 260);

        this.bindStore();
        this.bindUi();
        this.bindKeyboard();
        this.bindLifecycle();

        this.updateLive();
        this.loadInitial();
    }

    bindStore() {
        this.store.subscribe((type, detail) => {
            switch (type) {
                case 'load':
                    this.loadError = null;
                    this.transientIssues = [];
                    this.pendingPayload = null;
                    this.lastIssuesSignature = null;
                    this.syncAll();
                    this.updateLive();
                    break;

                case 'edit':
                    this.transientIssues = [];
                    this.scheduleGraphSync();
                    this.refreshChrome();
                    this.scheduleIssues();
                    break;

                case 'structure':
                    if (detail?.renamed) {
                        this.graph.renamePosition(detail.renamed.from, detail.renamed.to);
                    }

                    this.transientIssues = [];
                    this.syncAll();
                    break;

                case 'history':
                    this.transientIssues = [];
                    this.syncAll();
                    break;

                case 'selection':
                    this.graph.setSelected(this.store.selected);
                    this.renderSidebar();
                    this.inspector.render();
                    this.refreshChrome();

                    if (this.store.selected !== null) {
                        this.graph.revealTable(this.store.selected);
                    }

                    break;

                case 'filter':
                    this.transientIssues = [];
                    this.syncGraph();
                    this.renderSidebar();
                    this.refreshChrome();
                    break;

                case 'clean':
                    this.refreshChrome();
                    this.updateLive();
                    break;

                case 'saved':
                    this.refreshChrome();
                    this.updateLive();
                    break;

                case 'import':
                    this.transientIssues = [];
                    this.loadError = null;
                    this.syncAll();
                    break;

                case 'conflict':
                    this.updateLive();
                    this.refreshIssues(true);
                    break;

                default:
                    break;
            }
        });
    }

    bindUi() {
        this.elements.save.addEventListener('click', () => this.saveSchema());
        this.elements.undo.addEventListener('click', () => this.store.undo());
        this.elements.redo.addEventListener('click', () => this.store.redo());
        this.elements.theme.addEventListener('click', () => {
            this.applyTheme(this.theme === 'light' ? 'dark' : 'light');
        });

        this.elements.import.addEventListener('click', () => this.openImportModal());

        this.elements.addTable.addEventListener('click', () => this.addTable());

        const search = debounce((value) => this.store.setFilter(value), 90);
        this.elements.search.addEventListener('input', () => search(this.elements.search.value));

        this.elements.showPivots.addEventListener('change', () => {
            const checked = this.elements.showPivots.checked;

            try {
                window.localStorage.setItem('dber:show-pivots', checked ? '1' : '0');
            } catch {
                // Ignorar almacenamiento no disponible.
            }

            this.store.setShowPivots(checked);
            window.setTimeout(() => this.graph.resetLayout(), 0);
        });

        this.elements.autoLayout.addEventListener('click', () => {
            this.graph.resetLayout();
            this.notify('Layout arranged.', 'info');
        });

        this.elements.fit.addEventListener('click', () => this.graph.fit());
        this.elements.zoomIn.addEventListener('click', () => this.graph.zoomIn());
        this.elements.zoomOut.addEventListener('click', () => this.graph.zoomOut());

        this.elements.exportToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const hidden = this.elements.exportMenu.hidden;
            this.elements.exportMenu.hidden = !hidden;
            this.elements.exportToggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.dber-menu')) {
                this.closeExportMenu();
            }
        });

        this.elements.downloadJson.addEventListener('click', () => {
            this.closeExportMenu();
            const name = this.store.document.name || 'schema';
            download(
                `${slugify(name) || 'schema'}.json`,
                `${JSON.stringify(this.store.document, null, 2)}\n`,
                'application/json',
            );
            this.notify('Schema JSON downloaded.', 'success');
        });

        this.elements.copyContext.addEventListener('click', () => {
            this.closeExportMenu();
            this.copyContext();
        });

        for (const [link, suffix] of [
            [this.elements.contextLink, 'context.md'],
            [this.elements.mermaidLink, 'diagram.mmd'],
        ]) {
            link?.addEventListener('click', (event) => {
                event.preventDefault();
                this.closeExportMenu();

                if (this.store.dirty) {
                    this.notify('Save the schema before downloading a generated export.', 'info');
                    return;
                }

                this.downloadExport(link.href, suffix);
            });
        }

        this.elements.issues.addEventListener('click', (event) => {
            const action = event.target.closest('[data-issue-action]')?.dataset.issueAction;

            if (action === 'reload') {
                this.reloadFromDisk();
            }

            if (action === 'overwrite') {
                this.saveSchema(true);
            }

            if (action === 'keep') {
                this.store.conflict = false;
                this.updateLive();
                this.refreshIssues(true);
            }

            if (action === 'retry') {
                this.loadInitial();
            }
        });
    }

    bindKeyboard() {
        document.addEventListener('keydown', (event) => {
            const meta = event.metaKey || event.ctrlKey;

            if (meta && event.key.toLowerCase() === 's') {
                event.preventDefault();
                this.saveSchema();
                return;
            }

            if (meta && !isEditableTarget(event.target) && event.key.toLowerCase() === 'z') {
                event.preventDefault();

                if (event.shiftKey) {
                    this.store.redo();
                } else {
                    this.store.undo();
                }

                return;
            }

            if (event.key === 'Escape' && !this.elements.modalRoot.firstChild) {
                if (this.store.selected !== null) {
                    this.store.setSelected(null);
                }
            }

            if (event.key === '/' && !isEditableTarget(event.target)) {
                event.preventDefault();
                this.elements.search.focus();
            }
        });
    }

    bindLifecycle() {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.poll();
            }
        });

        window.addEventListener('focus', () => this.poll());

        window.addEventListener('beforeunload', (event) => {
            if (this.store.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        if (this.pollInterval > 0) {
            window.setInterval(() => this.poll(), this.pollInterval);
        }
    }

    async loadInitial() {
        this.setLoading(true);
        this.updateLive();

        try {
            const payload = await this.api.load();
            this.loadError = null;
            this.transientIssues = [];
            this.store.setLoaded(payload, 'initial');
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            this.handleLoadError(error);
        } finally {
            this.setLoading(false);
            this.updateLive();
        }
    }

    handleLoadError(error) {
        const errors = error instanceof ApiError ? error.errors : [];

        this.loadError = {
            message: error?.message ?? 'The schema could not be loaded.',
            errors,
        };
        this.pollFailed = false;
        this.lastIssuesSignature = null;
        this.renderEmpty();
        this.refreshIssues(true);
        this.updateLive();
        this.notify(error?.message ?? 'The schema could not be loaded.', 'error');
    }

    async poll() {
        if (this.pollInterval <= 0 || this.saving || this.polling || document.visibilityState !== 'visible') {
            return;
        }

        this.polling = true;
        this.pollAbort = new AbortController();

        try {
            const payload = await this.api.load({ signal: this.pollAbort.signal });
            this.pollFailed = false;

            if (this.loadError !== null) {
                this.loadError = null;
                this.lastIssuesSignature = null;
                this.refreshIssues(true);
            }

            if (this.store.hash === payload.hash) {
                if (this.store.conflict) {
                    this.store.conflict = false;
                    this.refreshIssues(true);
                }

                this.updateLive();
                return;
            }

            if (this.store.dirty) {
                this.pendingPayload = payload;
                this.store.markConflict();
                this.updateLive();
                return;
            }

            this.transientIssues = [];
            this.store.setLoaded(payload, 'poll');
            this.notify('Schema reloaded from disk.', 'info', 2200);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            if (error instanceof ApiError && error.status === 422) {
                if (this.store.dirty) {
                    this.pendingPayload = null;
                    this.store.markConflict();
                } else {
                    this.loadError = {
                        message: error.message,
                        errors: error.errors,
                    };
                    this.lastIssuesSignature = null;
                    this.renderEmpty();
                    this.refreshIssues(true);
                }
            } else {
                this.pollFailed = true;
            }

            this.updateLive();
        } finally {
            this.polling = false;
            this.pollAbort = null;
        }
    }

    async saveSchema(force = false) {
        if (!this.store.editable || this.saving) {
            return;
        }

        if (this.store.conflict && !force) {
            this.refreshIssues(true);
            this.notify('The file changed on disk. Reload it or overwrite it.', 'error');
            return;
        }

        if (!force) {
            const issues = this.store.clientIssues();
            const errors = issues.filter((issue) => issue.severity === 'error');

            if (errors.length > 0) {
                this.transientIssues = issues;
                this.refreshIssues(true);
                this.notify('Fix the validation errors before saving.', 'error');
                return;
            }
        }

        this.saving = true;
        this.pollAbort?.abort();
        this.refreshChrome();
        this.updateLive();

        try {
            const payload = await this.api.save(this.store.document, this.store.hash, force);
            this.loadError = null;
            this.transientIssues = [];
            this.pendingPayload = null;
            this.store.applySaved(payload);
            this.notify('Schema saved.', 'success');
        } catch (error) {
            if (error instanceof ApiError && error.status === 409) {
                this.pendingPayload = null;
                this.store.markConflict();
                this.refreshIssues(true);
                this.notify('The file changed on disk. Reload it or overwrite it.', 'error');
            } else if (error instanceof ApiError && error.status === 422) {
                this.transientIssues = error.errors.length > 0
                    ? error.errors
                    : [
                        {
                            severity: 'error',
                            path: 'document',
                            message: error.message,
                        },
                    ];
                this.refreshIssues(true);
                this.notify(error.message, 'error');
            } else {
                this.notify(error?.message ?? 'The schema could not be saved.', 'error');
            }
        } finally {
            this.saving = false;
            this.refreshChrome();
            this.updateLive();
        }
    }

    async reloadFromDisk() {
        if (this.saving) {
            return;
        }

        if (this.store.dirty) {
            const confirmed = await this.inspector.confirm({
                title: 'Reload from disk',
                message: 'Your unsaved changes will be discarded. Reload the schema file?',
                confirmLabel: 'Discard and reload',
            });

            if (!confirmed) {
                return;
            }
        }

        try {
            const payload = this.pendingPayload ?? (await this.api.load());
            this.pendingPayload = null;
            this.loadError = null;
            this.transientIssues = [];
            this.store.setLoaded(payload, 'poll');
            this.notify('Schema reloaded from disk.', 'success');
        } catch (error) {
            this.handleLoadError(error);
        }
    }

    addTable() {
        if (!this.store.editable) {
            return;
        }

        const name = this.store.addTable();
        this.graph.revealTable(name);

        window.setTimeout(() => {
            const input = this.elements.inspector.querySelector('[data-table-name]');
            input?.focus();
            input?.select();
        }, 0);
    }

    openTable(key) {
        if (key === null) {
            this.store.setSelected(null);
            return;
        }

        this.store.setSelected(key);
        this.graph.setSelected(key);
        this.graph.revealTable(key);
        this.inspector.render();
    }

    openImportModal() {
        if (!this.store.editable) {
            return;
        }

        const keepExtra = el('input', {
            type: 'checkbox',
        });

        const body = el('div', {}, [
            el('p', {
                text: 'Build the diagram from your existing migrations. Columns removed by a migration are removed from the schema.',
            }),
            el('label', { className: 'dber-check' }, [
                keepExtra,
                el('span', { text: 'Keep columns not found in migrations' }),
            ]),
        ]);

        const error = el('p', {
            className: 'dber-note',
            style: 'display:none; color:var(--dber-red); border-color:rgba(225,29,72,.3); margin-top:10px;',
        });
        body.appendChild(error);

        let close = () => {};

        const run = el('button', {
            type: 'button',
            className: 'dber-btn dber-btn-primary',
            text: 'Import',
        });

        run.addEventListener('click', async () => {
            run.disabled = true;
            error.style.display = 'none';

            try {
                const data = await this.api.importMigrations({ prune: !keepExtra.checked });
                close();
                this.store.applyImported(data.document);
                this.notify(this.importSummary(data.summary), 'success');
            } catch (exception) {
                run.disabled = false;
                error.style.display = 'block';
                error.textContent = exception?.message ?? 'The import failed.';
            }
        });

        const modal = this.inspector.showModal({
            title: 'Import migrations',
            body,
            footer: [
                el('button', {
                    type: 'button',
                    className: 'dber-btn',
                    text: 'Cancel',
                    onclick: () => close(),
                }),
                run,
            ],
        });

        close = modal.close;
    }

    importSummary(summary = {}) {
        const parts = [
            `${summary.tables ?? 0} tables`,
            `${summary.fields ?? 0} fields`,
        ];

        if ((summary.warnings ?? 0) > 0) {
            parts.push(`${summary.warnings} warnings`);
        }

        return `Imported ${parts.join(', ')}.`;
    }

    applyTheme(theme, persist = true) {
        this.theme = theme === 'light' ? 'light' : 'dark';
        document.documentElement.dataset.theme = this.theme;

        if (persist) {
            try {
                window.localStorage.setItem('dber:theme', this.theme);
            } catch {
                // Ignorar almacenamiento no disponible.
            }
        }

        if (this.elements.themeIcon) {
            this.elements.themeIcon.textContent = this.theme === 'light' ? '☾' : '☀';
        }

        if (this.elements.theme) {
            this.elements.theme.title = this.theme === 'light'
                ? 'Switch to dark theme'
                : 'Switch to light theme';
            this.elements.theme.setAttribute('aria-pressed', this.theme === 'light' ? 'true' : 'false');
        }

        document.querySelector('meta[name="color-scheme"]')
            ?.setAttribute('content', this.theme === 'light' ? 'light' : 'dark');
    }

    handleEmptyAction() {
        if (this.loadError !== null) {
            this.loadInitial();
            return;
        }

        if (!this.store.editable) {
            return;
        }

        const name = this.store.addTable();
        this.graph.revealTable(name);
        this.saveSchema();
    }

    async copyContext() {
        if (this.store.dirty) {
            this.notify('Save the schema before copying the AI context.', 'info');
            return;
        }

        try {
            const text = await this.api.contextText();
            await copyText(text);
            this.notify('AI context copied to the clipboard.', 'success');
        } catch (error) {
            this.notify(error?.message ?? 'The AI context could not be copied.', 'error');
        }
    }

    async downloadExport(url, suffix) {
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/markdown, text/plain',
                },
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                this.notify(payload?.message ?? 'The export could not be generated.', 'error');
                return;
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const name = this.store.document.name || 'schema';

            link.href = objectUrl;
            link.download = `${slugify(name) || 'schema'}-${suffix}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            this.notify('Export downloaded.', 'success');
        } catch {
            this.notify('The export could not be generated.', 'error');
        }
    }

    syncAll() {
        this.syncGraph();
        this.renderSidebar();
        this.inspector.render();
        this.refreshChrome();
        this.renderEmpty();
        this.refreshIssues(true);
        this.updateLive();
    }

    syncGraph() {
        this.scheduleGraphSync?.cancel?.();

        const derived = this.store.derived();

        this.graph.setData({
            tables: derived.tables,
            relations: derived.relations,
            pivotTables: derived.pivotTables,
            selected: this.store.selected,
            filter: this.store.filter,
            showPivots: this.store.showPivots,
        });
    }

    renderSidebar() {
        const derived = this.store.derived();
        const query = this.store.filter.trim().toLowerCase();
        const list = this.elements.tableList;

        clear(list);

        const tables = derived.tables.filter((table) => query === '' || tableMatches(table, query));

        if (tables.length === 0) {
            list.appendChild(el('div', {
                className: 'dber-list-empty',
                text: query === ''
                    ? 'No tables yet. Create one to start the diagram.'
                    : 'No tables match the search.',
            }));
            return;
        }

        for (const table of tables) {
            const foreignKeys = table.fields.filter((field) => field.references !== null).length;
            const item = el('button', {
                type: 'button',
                className: `dber-table-item${table.key === this.store.selected ? ' is-active' : ''}${table.pivot ? ' is-pivot' : ''}`,
                title: table.description || table.key,
                dataset: {
                    table: table.key,
                },
            }, [
                el('span', {
                    className: 'dber-table-icon',
                    text: table.pivot ? '◇' : '#',
                }),
                el('span', { className: 'dber-table-item-main' }, [
                    el('span', { className: 'dber-table-name', text: table.key }),
                    el('span', {
                        className: 'dber-table-meta',
                        text: `${table.fields.length} fields${foreignKeys > 0 ? ` · ${foreignKeys} FK` : ''}${table.pivot ? ' · pivot' : ''}`,
                    }),
                ]),
                el('span', {
                    className: 'dber-table-count',
                    text: formatCount(table.fields.length),
                }),
            ]);

            item.addEventListener('click', () => this.store.setSelected(table.key));
            list.appendChild(item);
        }
    }

    refreshChrome() {
        const derived = this.store.derived();
        const project = this.store.document.name || 'Untitled schema';

        this.elements.projectName.textContent = project;
        this.elements.stats.textContent = `${derived.stats.tables} tables · ${derived.stats.fields} fields · ${derived.stats.relations} relations`;
        this.elements.path.textContent = this.store.path || this.schemaPath;
        this.elements.dirty.hidden = !this.store.dirty;
        this.elements.save.disabled = !this.store.editable || !this.store.dirty || this.saving;
        this.elements.save.textContent = this.saving ? 'Saving…' : 'Save';
        this.elements.undo.disabled = !this.store.editable || this.store.history.length === 0;
        this.elements.redo.disabled = !this.store.editable || this.store.future.length === 0;
        this.elements.addTable.disabled = !this.store.editable;
        this.elements.import.disabled = !this.store.editable;
        this.elements.showPivots.checked = this.store.showPivots;
        this.elements.emptyPath.textContent = this.store.path || this.schemaPath;

        document.title = `Dber · ${project}`;
    }

    renderEmpty() {
        const hasTables = this.store.derived().tables.length > 0;

        if (this.loadError !== null && !hasTables) {
            this.elements.empty.hidden = false;
            this.elements.emptyTitle.textContent = 'The schema could not be loaded';
            this.elements.emptyText.textContent = this.loadError.message;
            this.elements.emptyAction.hidden = false;
            this.elements.emptyAction.textContent = 'Retry';
            this.elements.emptyAction.onclick = () => this.loadInitial();
            return;
        }

        if (hasTables) {
            this.elements.empty.hidden = true;
            return;
        }

        const missing = !this.store.exists;

        this.elements.empty.hidden = false;
        this.elements.emptyTitle.textContent = missing
            ? 'The schema file does not exist yet'
            : 'The schema is empty';
        this.elements.emptyText.textContent = missing
            ? 'Create the first table. The file will be written to the path below and versioned with the project.'
            : 'Add the first table to start drawing relations.';
        this.elements.emptyAction.hidden = !this.store.editable;
        this.elements.emptyAction.textContent = missing ? 'Create schema' : 'Create first table';
        this.elements.emptyAction.onclick = () => this.handleEmptyAction();
    }

    refreshIssues(force = false) {
        const mode = this.store.conflict
            ? 'conflict'
            : this.loadError !== null
                ? 'error'
                : 'issues';
        const fallbackIssues = this.store.dirty
            ? this.store.clientIssues()
            : this.store.serverIssues;
        const issues = this.transientIssues.length > 0 ? this.transientIssues : fallbackIssues;
        const signature = JSON.stringify({
            mode,
            dirty: this.store.dirty,
            issues,
            error: this.loadError?.message ?? null,
            conflict: this.store.conflict,
        });

        if (!force && signature === this.lastIssuesSignature) {
            return;
        }

        this.lastIssuesSignature = signature;

        if (mode === 'issues' && issues.length === 0) {
            this.elements.issues.hidden = true;
            clear(this.elements.issues);
            return;
        }

        const errors = issues.filter((issue) => issue.severity === 'error');
        const warnings = issues.filter((issue) => issue.severity !== 'error');
        const warningOnly = mode === 'issues' && errors.length === 0;

        this.elements.issues.hidden = false;
        this.elements.issues.classList.toggle('is-warning', warningOnly);
        clear(this.elements.issues);

        const main = el('div', { className: 'dber-issues-main' });
        const head = el('div', { className: 'dber-issues-head' });

        if (mode === 'conflict') {
            head.appendChild(el('span', { text: 'File changed on disk' }));
            main.appendChild(head);
            main.appendChild(el('div', {
                className: 'dber-issue',
                text: 'The schema file changed while you were editing. Reload it to keep the external changes or overwrite it with your version.'
                    + (this.loadError !== null ? ` The file is currently invalid: ${this.loadError.message}` : ''),
            }));
        } else if (mode === 'error') {
            head.appendChild(el('span', { text: 'Schema file is invalid' }));
            main.appendChild(head);
            main.appendChild(el('div', {
                className: 'dber-issue',
                text: this.loadError?.message ?? 'The schema could not be loaded.',
            }));
        } else {
            const parts = [];

            if (errors.length > 0) {
                parts.push(`${errors.length} error${errors.length === 1 ? '' : 's'}`);
            }

            if (warnings.length > 0) {
                parts.push(`${warnings.length} warning${warnings.length === 1 ? '' : 's'}`);
            }

            head.appendChild(el('span', { text: parts.join(' · ') }));
            main.appendChild(head);
        }

        if (issues.length > 0) {
            const list = el('ul', { className: 'dber-issues-list' });

            for (const issue of issues.slice(0, 40)) {
                list.appendChild(el('li', { className: 'dber-issue' }, [
                    el('span', {
                        className: 'dber-issue-path',
                        text: issue.path || '$',
                    }),
                    el('span', { text: issue.message }),
                ]));
            }

            main.appendChild(list);
        }

        const actions = el('div', { className: 'dber-issues-actions' });

        if (mode === 'conflict') {
            actions.appendChild(this.issueButton('Reload from disk', 'reload'));
            actions.appendChild(this.issueButton('Overwrite file', 'overwrite', 'dber-btn-danger'));
            actions.appendChild(this.issueButton('Keep editing', 'keep'));
        } else if (mode === 'error') {
            actions.appendChild(this.issueButton('Retry', 'retry'));
        }

        this.elements.issues.appendChild(main);

        if (actions.childElementCount > 0) {
            this.elements.issues.appendChild(actions);
        }
    }

    issueButton(label, action, className = '') {
        return el('button', {
            type: 'button',
            className: `dber-btn dber-btn-small ${className}`.trim(),
            text: label,
            dataset: {
                issueAction: action,
            },
        });
    }

    updateLive() {
        let state = 'live';
        let label = 'Live';

        if (this.loadError !== null || this.pollFailed) {
            state = 'error';
            label = 'Error';
        }

        if (this.store.conflict) {
            state = 'paused';
            label = 'Changed';
        }

        if (this.saving) {
            state = 'live';
            label = 'Saving';
        }

        if (this.pollInterval <= 0) {
            state = 'paused';
            label = 'Static';
        }

        this.elements.live.classList.toggle('is-error', state === 'error');
        this.elements.live.classList.toggle('is-paused', state === 'paused');
        this.elements.liveLabel.textContent = label;
        this.elements.live.title = state === 'error'
            ? 'The file could not be read. Retrying.'
            : state === 'paused'
                ? 'The file changed on disk.'
                : 'Watching the schema file for changes.';
    }

    setLoading(visible) {
        this.elements.loading.hidden = !visible;
    }

    closeExportMenu() {
        this.elements.exportMenu.hidden = true;
        this.elements.exportToggle.setAttribute('aria-expanded', 'false');
    }

    notify(message, type = 'info', duration = 3600) {
        const toast = el('div', { className: `dber-toast is-${type}` }, [
            el('span', { className: 'dber-toast-dot' }),
            el('span', { text: message }),
        ]);

        toast.addEventListener('click', () => toast.remove());
        this.elements.toastRoot.appendChild(toast);

        while (this.elements.toastRoot.childElementCount > 4) {
            this.elements.toastRoot.firstElementChild?.remove();
        }

        window.setTimeout(() => toast.remove(), duration);
    }
}

function tableMatches(table, query) {
    if (table.key.toLowerCase().includes(query) || table.model.toLowerCase().includes(query)) {
        return true;
    }

    return table.fields.some(
        (field) => field.name.toLowerCase().includes(query)
            || field.type.toLowerCase().includes(query)
            || (field.references?.table ?? '').toLowerCase().includes(query),
    );
}

const root = document.getElementById('dber-app');

if (root) {
    new DberApp(root);
}
