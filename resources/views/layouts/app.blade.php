<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'AI Core')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand"><i class="bi bi-cpu me-1"></i>AI Core</span>
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.providers.index') }}">{{ __('super-ai-core::messages.providers') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.services.index') }}">{{ __('super-ai-core::messages.services') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.services.routing') }}">{{ __('super-ai-core::messages.routing') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.integrations.index') }}">{{ __('super-ai-core::messages.integrations') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.usage.index') }}">{{ __('super-ai-core::messages.usage') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.costs.index') }}">{{ __('super-ai-core::messages.costs') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('super-super-ai-core.processes.index') }}">{{ __('super-ai-core::messages.processes') }}</a></li>
            </ul>
        </div>
    </nav>

    <div class="container-fluid py-4">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif
        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
