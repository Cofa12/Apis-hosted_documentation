@php
    $id = $operation->id();
    $path = e($operation->path);
    $path = preg_replace('/\{([^}]+)\}/', '<span class="var">{$1}</span>', $path);
    $needle = strtolower(implode(' ', array_filter([
        $operation->method,
        $operation->path,
        $operation->summary(),
        $operation->description(),
        $operation->operationId(),
        $operation->routeName(),
        $operation->controller(),
        implode(' ', $operation->tags()),
    ])));
    $requestSchema = $operation->requestSchema();
    $lastChanged = isset($history) && (($historyOptions ?? [])['show_in_ui'] ?? true)
        ? $history->lastChangedAt($operation->method, $operation->path)
        : null;
    $showHandler = ($ui['show_controllers'] ?? true) || ($ui['show_middleware'] ?? true);
@endphp

<article class="op" id="{{ $id }}" data-search="{{ $needle }}">
    <header class="op-head" role="button" tabindex="0" aria-expanded="true" aria-controls="{{ $id }}-body">
        <svg class="chev" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="m6 8 4 4 4-4"></path>
        </svg>
        <span class="method {{ strtolower($operation->method) }}">{{ $operation->method }}</span>
        <span class="path">{!! $path !!}</span>

        @if($operation->isAuthenticated())
            <span class="badge lock" title="Requires authentication">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="4" y="9" width="12" height="8" rx="2"></rect><path d="M7 9V6.5a3 3 0 0 1 6 0V9"></path>
                </svg>
                Auth
            </span>
        @endif

        @if($operation->isDeprecated())
            <span class="badge dep">Deprecated</span>
        @endif

        @if($lastChanged !== null)
            <span class="badge updated" title="Last documented change">Updated {{ substr($lastChanged, 0, 10) }}</span>
        @endif

        <span class="summary">{{ $operation->summary() }}</span>
    </header>

    <div class="op-body" id="{{ $id }}-body">
        <div class="op-main">
            @if($operation->description() !== '')
                <div class="op-desc">{!! \Cofa\ApiDocs\Support\Markdown::blocks($operation->description()) !!}</div>
            @endif

            @if($operation->headers() !== [])
                <section class="block">
                    <h3>Headers</h3>
                    <div class="params">
                        @foreach($operation->headers() as $header)
                            <div class="param">
                                <div class="param-top">
                                    <span class="param-name">{{ $header['name'] }}</span>
                                    @if($header['required'])
                                        <span class="req">required</span>
                                    @else
                                        <span class="opt">optional</span>
                                    @endif
                                </div>
                                @if($header['description'] !== '')
                                    <div class="param-desc">{{ $header['description'] }}</div>
                                @endif
                                @if($header['value'] !== '')
                                    <div class="pills"><span class="pill">{{ $header['value'] }}</span></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @include('api-docs::partials.parameters', [
                'title' => 'URL parameters',
                'parameters' => $operation->pathParameters(),
            ])

            @include('api-docs::partials.parameters', [
                'title' => 'Query parameters',
                'parameters' => $operation->queryParameters(),
            ])

            @if($requestSchema !== null)
                <section class="block">
                    <h3>
                        Body
                        <span class="param-type" style="text-transform:none;letter-spacing:0">
                            {{ $operation->requestMediaType() }}{{ $operation->requestBodyRequired() ? ' · required' : '' }}
                        </span>
                    </h3>
                    @include('api-docs::partials.schema-table', ['schema' => $requestSchema])
                </section>
            @endif

            @include('api-docs::partials.responses', ['operation' => $operation])

            @include('api-docs::partials.history', ['operation' => $operation])

            @if($showHandler && ($operation->controller() !== null || $operation->middleware() !== []))
                <div class="handler">
                    @if(($ui['show_controllers'] ?? true) && $operation->controller() !== null)
                        Handled by <code>{{ $operation->controller() }}</code>
                        @if($operation->routeName() !== null)
                            · route <code>{{ $operation->routeName() }}</code>
                        @endif
                    @endif
                    @if(($ui['show_middleware'] ?? true) && $operation->middleware() !== [])
                        <div class="mw">
                            @foreach($operation->middleware() as $middleware)
                                <span class="pill">{{ $middleware }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <aside class="op-side">
            @include('api-docs::partials.code-samples', ['operation' => $operation])

            @if($ui['try_it'] ?? true)
                @include('api-docs::partials.try-it', ['operation' => $operation])
            @endif
        </aside>
    </div>
</article>
