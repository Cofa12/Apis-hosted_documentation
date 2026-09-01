@extends('api-docs::layout')

@section('content')
    <section class="intro">
        <h1>{{ $spec->title() }}</h1>

        @if($spec->description() !== '')
            <p>{{ $spec->description() }}</p>
        @endif

        <div class="meta-grid">
            <div class="meta-card">
                <div class="k">Base URL</div>
                <div class="v">{{ $spec->baseUrl() !== '' ? $spec->baseUrl() : '—' }}</div>
            </div>
            <div class="meta-card">
                <div class="k">Version</div>
                <div class="v">{{ $spec->version() }}</div>
            </div>
            <div class="meta-card">
                <div class="k">Endpoints</div>
                <div class="v">{{ $spec->operationCount() }}</div>
            </div>
            <div class="meta-card">
                <div class="k">Specification</div>
                <div class="v">OpenAPI {{ $spec->openapiVersion() }}</div>
            </div>
        </div>

        @if($spec->securitySchemes() !== [])
            <section class="block" style="margin-top:26px">
                <h3>Authentication</h3>
                <div class="params">
                    @foreach($spec->securitySchemes() as $name => $scheme)
                        <div class="param">
                            <div class="param-top">
                                <span class="param-name">{{ $name }}</span>
                                <span class="param-type">{{ $scheme['type'] ?? 'http' }}@isset($scheme['scheme']) · {{ $scheme['scheme'] }}@endisset</span>
                            </div>
                            @if(! empty($scheme['description']))
                                <div class="param-desc">{{ $scheme['description'] }}</div>
                            @endif
                            @if(($scheme['type'] ?? null) === 'apiKey')
                                <div class="pills"><span class="pill">in: {{ $scheme['in'] ?? 'header' }}</span><span class="pill">{{ $scheme['name'] ?? '' }}</span></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </section>

    @forelse($spec->groupedOperations() as $group => $operations)
        <section class="group" id="group-{{ \Illuminate\Support\Str::slug($group) ?: 'general' }}">
            <h2>{{ $group }}</h2>
            @if($spec->tagDescription($group) !== '')
                <p class="group-desc">{{ $spec->tagDescription($group) }}</p>
            @endif

            @foreach($operations as $operation)
                @include('api-docs::partials.operation', ['operation' => $operation])
            @endforeach
        </section>
    @empty
        <p class="empty">No endpoints matched the configured route filters.</p>
    @endforelse

    <div class="no-results" id="api-docs-no-results" hidden>No endpoint matches your search.</div>
@endsection
