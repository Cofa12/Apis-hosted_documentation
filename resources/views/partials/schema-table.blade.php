@php
    $rows = $schema->rows();
@endphp

@if($rows === [])
    <p class="empty">No fields documented for this payload.</p>
@else
    <div class="params">
        @foreach($rows as $row)
            @php
                /** @var \Cofa\ApiDocs\OpenApi\SchemaObject $field */
                $field = $row['schema'];
                $example = $field->example();
                $pills = $field->constraints();
                $enum = $field->enum();
            @endphp
            <div class="param{{ $row['depth'] > 0 ? ' depth-' . min($row['depth'], 6) : '' }}">
                <div class="param-top">
                    <span class="param-name">{{ $row['name'] }}</span>
                    <span class="param-type">{{ $field->type() }}</span>
                    @if($row['required'])
                        <span class="req">required</span>
                    @else
                        <span class="opt">optional</span>
                    @endif
                </div>

                @if($field->description() !== '')
                    <div class="param-desc">{!! \Cofa\ApiDocs\Support\Markdown::inline($field->description()) !!}</div>
                @endif

                @if($enum !== [] || $pills !== [] || (is_scalar($example) && ! $field->isObject()))
                    <div class="pills">
                        @foreach($enum as $value)
                            <span class="pill enum">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                        @endforeach
                        @foreach($pills as $pill)
                            <span class="pill">{{ $pill }}</span>
                        @endforeach
                        @if(is_scalar($example) && ! $field->isObject() && $enum === [])
                            <span class="pill">example: {{ is_bool($example) ? ($example ? 'true' : 'false') : $example }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
