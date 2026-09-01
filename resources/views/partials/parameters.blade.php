@if(! empty($parameters))
    <section class="block">
        <h3>{{ $title }}</h3>
        <div class="params">
            @foreach($parameters as $parameter)
                @php
                    /** @var \Cofa\ApiDocs\OpenApi\SchemaObject $schema */
                    $schema = $parameter['schema_object'];
                    $example = $parameter['example'] ?? $schema->example();
                @endphp
                <div class="param">
                    <div class="param-top">
                        <span class="param-name">{{ $parameter['name'] ?? '' }}</span>
                        <span class="param-type">{{ $schema->type() }}</span>
                        @if($parameter['required'] ?? false)
                            <span class="req">required</span>
                        @else
                            <span class="opt">optional</span>
                        @endif
                    </div>

                    @if(! empty($parameter['description']))
                        <div class="param-desc">{!! \Cofa\ApiDocs\Support\Markdown::inline((string) $parameter['description']) !!}</div>
                    @elseif($schema->description() !== '')
                        <div class="param-desc">{!! \Cofa\ApiDocs\Support\Markdown::inline($schema->description()) !!}</div>
                    @endif

                    @php
                        $pills = $schema->constraints();
                        $enum = $schema->enum();
                    @endphp

                    @if($pills !== [] || $enum !== [] || is_scalar($example))
                        <div class="pills">
                            @foreach($enum as $value)
                                <span class="pill enum">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                            @endforeach
                            @foreach($pills as $pill)
                                <span class="pill">{{ $pill }}</span>
                            @endforeach
                            @if(is_scalar($example))
                                <span class="pill">example: {{ $example }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
