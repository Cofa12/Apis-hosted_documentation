@php
    $tryUrl = $operation->resolvedUrl($baseUrl ?? null);
    $tryHeaders = $operation->headers();
    $tryBody = $operation->requestExampleJson();
@endphp

<form class="tryit" data-tryit data-method="{{ $operation->method }}">
    <h4>Try it</h4>

    <label for="{{ $operation->id() }}-url">Request URL</label>
    <input id="{{ $operation->id() }}-url" name="__url" type="text" value="{{ $tryUrl }}" spellcheck="false">

    @foreach($tryHeaders as $header)
        <label for="{{ $operation->id() }}-h-{{ \Illuminate\Support\Str::slug($header['name']) }}">{{ $header['name'] }}</label>
        <input id="{{ $operation->id() }}-h-{{ \Illuminate\Support\Str::slug($header['name']) }}"
               data-header="{{ $header['name'] }}"
               type="text"
               value="{{ $header['value'] }}"
               spellcheck="false">
    @endforeach

    @if($tryBody !== '' && $operation->method !== 'GET')
        <label for="{{ $operation->id() }}-body">Body</label>
        <textarea id="{{ $operation->id() }}-body" name="__body" spellcheck="false">{{ $tryBody }}</textarea>
    @endif

    <div class="row">
        <button class="btn" type="submit">Send request</button>
        <button class="btn ghost" type="button" data-tryit-reset>Reset</button>
    </div>

    <div class="tryit-out" data-tryit-output hidden>
        <div class="tryit-status" data-tryit-status></div>
        <div class="code"><pre><code data-tryit-body></code></pre></div>
    </div>
</form>
