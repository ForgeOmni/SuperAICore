<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', __('super-ai-core::messages.app_title'))</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --tf-primary: #0d6efd;
            --tf-primary-rgb: 13, 110, 253;
            --tf-primary-light: #eff6ff;
            --tf-border: #dee2e6;
            --tf-bg: #f8f9fa;
            --tf-text: #0f172a;
            --tf-text-muted: #64748b;
            --tf-success: #10b981;
            --tf-warning: #f59e0b;
            --tf-danger: #ef4444;
            --tf-radius-sm: .375rem;
            --tf-transition: all .15s ease;
        }
    </style>
    @stack('styles')
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            @php
                $hostBack = config('super-ai-core.host_back_url');
                $hostName = config('super-ai-core.host_name');
                $hostIcon = config('super-ai-core.host_icon', 'bi-arrow-left');
            @endphp
            @if($hostBack)
                <a class="btn btn-sm btn-outline-light me-3" href="{{ $hostBack }}" title="{{ __('super-ai-core::messages.back_to_host', ['name' => $hostName ?: 'host']) }}">
                    <i class="bi {{ $hostIcon }}"></i>{{ $hostName ? ' ' . $hostName : '' }}
                </a>
            @endif
            <span class="navbar-brand"><i class="bi bi-cpu me-1"></i>{{ __('super-ai-core::messages.app_title') }}</span>
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.providers.*') ? 'active' : '' }}" href="{{ route('super-ai-core.providers.index') }}">{{ __('super-ai-core::messages.providers') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.services.*') && !request()->routeIs('super-ai-core.services.routing') ? 'active' : '' }}" href="{{ route('super-ai-core.services.index') }}">{{ __('super-ai-core::messages.services') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.services.routing') ? 'active' : '' }}" href="{{ route('super-ai-core.services.routing') }}">{{ __('super-ai-core::messages.routing') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.integrations.*') ? 'active' : '' }}" href="{{ route('super-ai-core.integrations.index') }}">{{ __('super-ai-core::messages.integrations') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.usage.*') ? 'active' : '' }}" href="{{ route('super-ai-core.usage.index') }}">{{ __('super-ai-core::messages.usage') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.costs.*') ? 'active' : '' }}" href="{{ route('super-ai-core.costs.index') }}">{{ __('super-ai-core::messages.costs') }}</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('super-ai-core.processes.*') ? 'active' : '' }}" href="{{ route('super-ai-core.processes.index') }}">{{ __('super-ai-core::messages.processes') }}</a></li>
            </ul>
            @php
                $locales = config('super-ai-core.locales', []);
                $current = app()->getLocale();
            @endphp
            @if(!empty($locales))
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-translate me-1"></i>{{ $locales[$current] ?? $current }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach($locales as $code => $label)
                                <li>
                                    <a class="dropdown-item {{ $code === $current ? 'active' : '' }}" href="{{ route('super-ai-core.locale.switch', $code) }}">
                                        {{ $label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                </ul>
            @endif
        </div>
    </nav>

    <div class="container-fluid py-4">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif
        @yield('content')
    </div>

    @if(config('super-ai-core.ui.side_panel_enabled', true))
        {{-- 0.9.2 — Right-hand side panel (jcode-style) for auxiliary content
             that shouldn't steal real estate from the main view: file diffs,
             mermaid diagrams, JSON inspectors, scratch HTML. Driven by
             `window.SuperAICorePanel` (vanilla JS over Bootstrap offcanvas)
             and an inline marker grammar so streamed agent output can pop
             content over without page reloads. --}}
        <div class="offcanvas offcanvas-end" tabindex="-1" id="superAiCoreSidePanel"
             aria-labelledby="superAiCoreSidePanelLabel" style="width: 540px;">
            <div class="offcanvas-header border-bottom">
                <h6 class="offcanvas-title m-0" id="superAiCoreSidePanelLabel">
                    <i class="bi bi-window-sidebar me-1"></i><span data-side-panel-title>Side panel</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body p-3" data-side-panel-body>
                <div class="text-muted small">No content yet.</div>
            </div>
            <div class="offcanvas-footer border-top px-3 py-2 small text-muted" data-side-panel-footer style="display:none;"></div>
        </div>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @if(config('super-ai-core.ui.side_panel_enabled', true))
        <script>
            // 0.9.2 — Side-panel host. jcode borrows the idea of routing
            // auxiliary agent output (diffs, diagrams, JSON dumps) into a
            // pull-out drawer instead of inlining it. The marker grammar
            //   <!-- side-panel: {"title": "...", "type": "...", "content": "..."} -->
            // can be embedded anywhere streamed output lands; calling
            // SuperAICorePanel.bind(node) replaces each marker with an
            // "Open in side panel" button.
            window.SuperAICorePanel = {
                _bsRef: null,
                _instance() {
                    if (this._bsRef) return this._bsRef;
                    var el = document.getElementById('superAiCoreSidePanel');
                    if (!el || !window.bootstrap) return null;
                    this._bsRef = window.bootstrap.Offcanvas.getOrCreateInstance(el);
                    return this._bsRef;
                },
                show(payload) {
                    var el = document.getElementById('superAiCoreSidePanel');
                    if (!el) return;
                    var titleNode  = el.querySelector('[data-side-panel-title]');
                    var bodyNode   = el.querySelector('[data-side-panel-body]');
                    var footerNode = el.querySelector('[data-side-panel-footer]');
                    if (titleNode) titleNode.textContent = payload.title || 'Side panel';
                    if (bodyNode)  bodyNode.innerHTML = this._renderBody(payload);
                    if (footerNode) {
                        if (payload.footer) {
                            footerNode.innerHTML = payload.footer;
                            footerNode.style.display = '';
                        } else {
                            footerNode.style.display = 'none';
                        }
                    }
                    // Re-run mermaid against the freshly-injected body so a
                    // payload of type:'mermaid' actually paints.
                    if (window.SuperAICoreMermaid) window.SuperAICoreMermaid.upgrade(bodyNode);
                    var inst = this._instance();
                    if (inst) inst.show();
                },
                hide() {
                    var inst = this._instance();
                    if (inst) inst.hide();
                },
                _renderBody(payload) {
                    var t = (payload.type || 'html').toLowerCase();
                    var c = payload.content == null ? '' : String(payload.content);
                    if (t === 'mermaid') {
                        return '<pre class="mermaid">' + this._escape(c) + '</pre>';
                    }
                    if (t === 'json') {
                        var pretty;
                        try { pretty = JSON.stringify(JSON.parse(c), null, 2); }
                        catch (e) { pretty = c; }
                        return '<pre class="small bg-light p-2 rounded" style="max-height:80vh;overflow:auto;"><code>'
                            + this._escape(pretty) + '</code></pre>';
                    }
                    if (t === 'iframe') {
                        return '<iframe src="' + this._attr(c) + '" style="width:100%;height:80vh;border:0;"></iframe>';
                    }
                    if (t === 'text') {
                        return '<pre class="small" style="white-space:pre-wrap;">' + this._escape(c) + '</pre>';
                    }
                    // Default: trust as html. Callers must sanitise upstream.
                    return c;
                },
                /**
                 * Walk `root` for `<!-- side-panel: {…json…} -->` markers and
                 * replace each with a button that opens the panel. Idempotent.
                 * Designed for log-tail panes that stream new content on
                 * intervals — call after each fetch.
                 */
                bind(root) {
                    root = root || document.body;
                    var iter = document.createNodeIterator(root, NodeFilter.SHOW_COMMENT);
                    var node, pending = [];
                    while ((node = iter.nextNode())) {
                        var m = /^\s*side-panel\s*:\s*([\s\S]+?)\s*$/.exec(node.nodeValue || '');
                        if (m) pending.push({ node: node, raw: m[1] });
                    }
                    pending.forEach(function (entry) {
                        var payload;
                        try { payload = JSON.parse(entry.raw); } catch (e) { return; }
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn-sm btn-outline-secondary';
                        btn.innerHTML = '<i class="bi bi-window-sidebar me-1"></i>'
                            + (payload.title || 'Open side panel');
                        btn.addEventListener('click', function () { window.SuperAICorePanel.show(payload); });
                        entry.node.parentNode.replaceChild(btn, entry.node);
                    });
                },
                _escape(s) {
                    return String(s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                },
                _attr(s) { return this._escape(s); },
            };
            // Auto-bind on page load — host views just need to drop the
            // marker comment anywhere inside <body>.
            document.addEventListener('DOMContentLoaded', function () {
                window.SuperAICorePanel.bind(document.body);
                // Also wire any [data-side-panel] elements that carry their
                // payload as a JSON data-attribute (preferred for
                // server-rendered triggers — no comment-node round-trip).
                document.querySelectorAll('[data-side-panel-trigger]').forEach(function (el) {
                    el.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        try {
                            var payload = JSON.parse(el.getAttribute('data-side-panel-trigger'));
                            window.SuperAICorePanel.show(payload);
                        } catch (e) { /* malformed payload, ignore */ }
                    });
                });
            });
        </script>
    @endif
    @if(config('super-ai-core.ui.mermaid_enabled', true))
        {{-- 0.9.0 — Mermaid renderer for inline diagrams (jcode-style).
             Loads on every page so any view can drop a `pre.mermaid` /
             `code.language-mermaid` block and have it render. Hosts that
             can't reach the CDN flip super-ai-core.ui.mermaid_enabled off
             and the script tag disappears entirely. --}}
        <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
        <script>
            window.SuperAICoreMermaid = {
                booted: false,
                run() {
                    if (!window.mermaid) return;
                    if (!this.booted) {
                        window.mermaid.initialize({
                            startOnLoad: false,
                            securityLevel: 'strict',
                            theme: 'default',
                        });
                        this.booted = true;
                    }
                    // Run against any node carrying the `mermaid` class —
                    // <pre class="mermaid">, <div class="mermaid">, etc.
                    // Idempotent: mermaid.run() skips already-rendered nodes
                    // because it sets data-processed="true" on the parent.
                    try {
                        window.mermaid.run({ querySelector: '.mermaid:not([data-processed])' });
                    } catch (e) { /* malformed diagrams swallow per-node */ }
                },
                /**
                 * Walk the given root for ```mermaid fenced code blocks
                 * (rendered as `<pre><code class="language-mermaid">`) and
                 * upgrade them to mermaid-renderable divs in place. Designed
                 * for log-tail panels that stream raw terminal text.
                 */
                upgrade(root) {
                    root = root || document.body;
                    root.querySelectorAll('pre > code.language-mermaid:not([data-mermaid-upgraded])').forEach(code => {
                        const div = document.createElement('div');
                        div.className = 'mermaid';
                        div.textContent = code.textContent;
                        code.setAttribute('data-mermaid-upgraded', 'true');
                        code.parentNode.replaceWith(div);
                    });
                    this.run();
                }
            };
            document.addEventListener('DOMContentLoaded', () => window.SuperAICoreMermaid.run());
        </script>
    @endif
    @stack('scripts')
</body>
</html>
