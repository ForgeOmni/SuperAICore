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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @stack('scripts')
</body>
</html>
