@php
    $codeSamples = $samples->for($operation, $baseUrl ?? '');
    $group = $operation->id() . '-lang';
@endphp

@if($codeSamples !== [])
    <section class="block">
        <h3>Request</h3>

        <div class="tabs" role="tablist" aria-label="Code samples">
            @foreach($codeSamples as $index => $sample)
                <button class="tab" type="button" role="tab"
                        data-tab-group="{{ $group }}"
                        data-tab="{{ $sample['id'] }}"
                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}">{{ $sample['label'] }}</button>
            @endforeach
        </div>

        @foreach($codeSamples as $index => $sample)
            <div class="tab-panel" role="tabpanel"
                 data-panel-group="{{ $group }}"
                 data-panel="{{ $sample['id'] }}"
                 @if($index !== 0) hidden @endif>
                <div class="code">
                    <button class="copy" type="button" data-copy="{{ $operation->id() }}-code-{{ $sample['id'] }}">Copy</button>
                    <pre><code id="{{ $operation->id() }}-code-{{ $sample['id'] }}">{{ $sample['code'] }}</code></pre>
                </div>
            </div>
        @endforeach
    </section>
@endif
