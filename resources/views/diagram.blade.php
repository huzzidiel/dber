<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >
    <meta
        name="color-scheme"
        content="dark"
    >
    <title>Dber · ER diagram</title>
    <link
        rel="icon"
        type="image/svg+xml"
        href="{{ $faviconUrl }}"
    >
    <link
        rel="stylesheet"
        href="{{ $cssUrl }}"
    >
</head>
<body>
<div
    id="dber-app"
    data-data-url="{{ $dataUrl }}"
    data-save-url="{{ $saveUrl }}"
    data-context-url="{{ $contextUrl }}"
    data-mermaid-url="{{ $mermaidUrl }}"
    data-editable="{{ $editable ? '1' : '0' }}"
    data-poll-interval="{{ $pollInterval }}"
    data-schema-path="{{ $schemaPath }}"
    data-format="{{ $format }}"
>
    <header class="dber-topbar">
        <div class="dber-brand">
            <span
                class="dber-logo"
                aria-hidden="true"
            >
                <svg
                    viewBox="0 0 32 32"
                    width="22"
                    height="22"
                >
                    <ellipse
                        cx="16"
                        cy="10"
                        rx="9"
                        ry="3.6"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    />
                    <path
                        d="M7 10v11c0 2 4 3.6 9 3.6s9-1.6 9-3.6V10"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    />
                    <path
                        d="M7 15.2c0 2 4 3.6 9 3.6s9-1.6 9-3.6"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        opacity=".65"
                    />
                </svg>
            </span>
            <div class="dber-brand-text">
                <strong>Dber</strong>
                <span
                    id="dber-path"
                    class="dber-path"
                >{{ $schemaPath }}</span>
            </div>
        </div>

        <div class="dber-project">
            <span
                id="dber-project-name"
                class="dber-project-name"
            >Loading schema…</span>
            <span
                id="dber-stats"
                class="dber-stats"
            ></span>
        </div>

        <div class="dber-actions">
            <span
                id="dber-live"
                class="dber-live"
                title="Watching the schema file for changes"
            >
                <span class="dber-live-dot"></span>
                <span id="dber-live-label">Live</span>
            </span>

            <span
                id="dber-dirty"
                class="dber-dirty"
                hidden
            >Unsaved</span>

            <button
                id="dber-undo"
                type="button"
                class="dber-btn dber-btn-icon"
                title="Undo (⌘Z)"
                disabled
            >
                <span aria-hidden="true">↶</span>
                <span class="dber-sr-only">Undo</span>
            </button>

            <button
                id="dber-redo"
                type="button"
                class="dber-btn dber-btn-icon"
                title="Redo (⇧⌘Z)"
                disabled
            >
                <span aria-hidden="true">↷</span>
                <span class="dber-sr-only">Redo</span>
            </button>

            <button
                id="dber-save"
                type="button"
                class="dber-btn dber-btn-primary"
                title="Save schema (⌘S)"
                disabled
            >Save</button>

            <div class="dber-menu">
                <button
                    id="dber-export-toggle"
                    type="button"
                    class="dber-btn"
                    aria-haspopup="true"
                    aria-expanded="false"
                >
                    Export
                    <span aria-hidden="true">▾</span>
                </button>
                <div
                    id="dber-export-menu"
                    class="dber-menu-panel"
                    hidden
                >
                    <a
                        href="{{ $contextUrl }}"
                        download
                    >AI context (.md)</a>
                    <a
                        href="{{ $mermaidUrl }}"
                        download
                    >Mermaid (.mmd)</a>
                    <button
                        id="dber-download-json"
                        type="button"
                    >Schema (.json)</button>
                    <button
                        id="dber-copy-context"
                        type="button"
                    >Copy AI context</button>
                </div>
            </div>
        </div>
    </header>

    <div
        id="dber-issues"
        class="dber-issues"
        hidden
    ></div>

    <main class="dber-main">
        <aside class="dber-sidebar">
            <div class="dber-search-wrap">
                <span
                    class="dber-search-icon"
                    aria-hidden="true"
                >⌕</span>
                <input
                    id="dber-search"
                    type="search"
                    placeholder="Search tables or fields…"
                    autocomplete="off"
                    spellcheck="false"
                >
            </div>

            <div class="dber-sidebar-head">
                <span>Tables</span>
                <button
                    id="dber-add-table"
                    type="button"
                    class="dber-btn dber-btn-small"
                >+ New</button>
            </div>

            <div
                id="dber-table-list"
                class="dber-table-list"
            ></div>

            <div class="dber-sidebar-foot">
                <label class="dber-check">
                    <input
                        id="dber-show-pivots"
                        type="checkbox"
                    >
                    <span>Show pivot tables on canvas</span>
                </label>

                <div class="dber-sidebar-actions">
                    <button
                        id="dber-auto-layout"
                        type="button"
                        class="dber-btn dber-btn-small"
                    >Auto arrange</button>
                    <button
                        id="dber-fit"
                        type="button"
                        class="dber-btn dber-btn-small"
                    >Fit view</button>
                </div>

                <div class="dber-zoom">
                    <button
                        id="dber-zoom-out"
                        type="button"
                        class="dber-btn dber-btn-icon"
                        title="Zoom out"
                    >−</button>
                    <span id="dber-zoom-label">100%</span>
                    <button
                        id="dber-zoom-in"
                        type="button"
                        class="dber-btn dber-btn-icon"
                        title="Zoom in"
                    >+</button>
                </div>
            </div>
        </aside>

        <section class="dber-canvas-wrap">
            <div
                id="dber-empty"
                class="dber-empty"
                hidden
            >
                <div class="dber-empty-card">
                    <h2 id="dber-empty-title">The schema file is empty</h2>
                    <p id="dber-empty-text">
                        Create the first table to start the diagram. The file is versioned with the project.
                    </p>
                    <button
                        id="dber-empty-action"
                        type="button"
                        class="dber-btn dber-btn-primary"
                    >Create first table</button>
                    <code id="dber-empty-path"></code>
                </div>
            </div>

            <svg
                id="dber-canvas"
                xmlns="http://www.w3.org/2000/svg"
                role="img"
                aria-label="Entity relationship diagram"
                tabindex="0"
            >
                <defs id="dber-defs"></defs>
                <g id="dber-viewport">
                    <g id="dber-edges"></g>
                    <g id="dber-nodes"></g>
                </g>
            </svg>

            <div class="dber-canvas-hint">
                Drag tables to move · Scroll to zoom · Double-click a table to edit
            </div>
        </section>

        <aside
            id="dber-inspector"
            class="dber-inspector"
            hidden
            aria-live="polite"
        ></aside>
    </main>

    <div id="dber-modal-root"></div>
    <div
        id="dber-toast-root"
        class="dber-toasts"
        aria-live="polite"
    ></div>

    <div
        id="dber-loading"
        class="dber-loading"
    >
        <span
            class="dber-spinner"
            aria-hidden="true"
        ></span>
        Loading schema…
    </div>

    <noscript>
        <div class="dber-noscript">Dber needs JavaScript enabled to render the diagram.</div>
    </noscript>
</div>

<script
    type="module"
    src="{{ $jsUrl }}"
></script>
</body>
</html>
