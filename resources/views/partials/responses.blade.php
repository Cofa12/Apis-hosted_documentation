@php
    $responses = $operation->responses();
    $group = $operation->id() . '-resp';
@endphp

@if($responses !== [])
    <section class="block">
        <h3>Responses</h3>

        <div class="tabs" role="tablist" aria-label="Responses">
            @foreach($responses as $index => $response)
                <button class="tab" type="button" role="tab"
                        data-tab-group="{{ $group }}"
                        data-tab="{{ $response->status }}"
                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                    <span class="dot {{ $response->isSuccessful() ? 'ok' : 'err' }}"></span>{{ $response->status }}
                </button>
            @endforeach
        </div>

        @foreach($responses as $index => $response)
            <div class="tab-panel" role="tabpanel"
                 data-panel-group="{{ $group }}"
                 data-panel="{{ $response->status }}"
                 @if($index !== 0) hidden @endif>

                <p class="resp-desc">
                    <strong>{{ $response->status }}</strong>
                    {{ $response->description() !== '' ? $response->description() : $response->statusText() }}
                </p>

                @if($response->headers() !== [])
                    <div class="params" style="margin-bottom:10px">
                        @foreach($response->headers() as $header)
                            <div class="param">
                                <div class="param-top">
                                    <span class="param-name">{{ $header['name'] }}</span>
                                    @if($header['example'] !== '')
                                        <span class="param-type">{{ $header['example'] }}</span>
                                    @endif
                                </div>
                                @if($header['description'] !== '')
                                    <div class="param-desc">{{ $header['description'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @php $body = $response->body(); @endphp

                @if($body !== '')
                    <div class="code">
                        <button class="copy" type="button" data-copy="{{ $operation->id() }}-body-{{ $response->status }}">Copy</button>
                        <pre><code id="{{ $operation->id() }}-body-{{ $response->status }}">{!! \Cofa\ApiDocs\Support\JsonHighlighter::highlight($body) !!}</code></pre>
                    </div>
                @else
                    <p class="empty">This response has no body.</p>
                @endif

                @php $schema = $response->schema(); @endphp

                @if($schema !== null && $schema->rows() !== [])
                    <details style="margin-top:10px">
                        <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Response schema</summary>
                        <div style="margin-top:8px">
                            @include('api-docs::partials.schema-table', ['schema' => $schema])
                        </div>
                    </details>
                @endif
            </div>
        @endforeach
    </section>
@endif
