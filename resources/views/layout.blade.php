<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"@if(($ui['theme'] ?? 'auto') !== 'auto') data-theme="{{ $ui['theme'] }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($spec->description()), 160) }}">
    <title>{{ $spec->title() }} · API reference</title>
    @include('api-docs::assets.styles')
</head>
<body>
<header class="topbar">
    <div class="brand">
        @if(! empty($ui['logo']))
            <img src="{{ $ui['logo'] }}" alt="">
        @endif
        <span>{{ $spec->title() }}</span>
        <span class="pill">v{{ $spec->version() }}</span>
    </div>

    <div class="topbar-spacer"></div>

    <div class="search">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="9" cy="9" r="6"></circle><path d="m14 14 4 4"></path>
        </svg>
        <input id="api-docs-search" type="search" placeholder="Search endpoints" aria-label="Search endpoints" autocomplete="off">
        <kbd>/</kbd>
    </div>

    @isset($specUrl)
        <a class="icon-btn" href="{{ $specUrl }}" title="Download the OpenAPI document" download>
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M10 3v10m0 0 4-4m-4 4-4-4M4 16h12"></path>
            </svg>
            OpenAPI
        </a>
    @endisset

    <button class="icon-btn" data-theme-toggle type="button" title="Toggle colour scheme" aria-label="Toggle colour scheme">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M16 11.5A6.5 6.5 0 0 1 8.5 4a6.5 6.5 0 1 0 7.5 7.5Z"></path>
        </svg>
    </button>
</header>

<div class="shell">
    @include('api-docs::partials.sidebar')
    <main class="content">
        @yield('content')
    </main>
</div>

@include('api-docs::assets.scripts')
</body>
</html>
