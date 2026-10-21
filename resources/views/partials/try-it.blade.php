@php
    $tryUrl = $operation->resolvedUrl($baseUrl ?? null);
    $tryHeaders = $operation->headers();
    $tryBody = $operation->requestExampleJson();
    $tryId = $operation->id();
    $tryMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
@endphp

<form class="tryit" data-tryit data-content-type="{{ $operation->requestMediaType() ?? 'application/json' }}">
    <h4>Try it</h4>

    <label for="{{ $tryId }}-url">Request URL</label>
    <div class="tryit-line">
        <select name="__method" aria-label="HTTP method" data-tryit-method>
            @foreach($tryMethods as $method)
                <option value="{{ $method }}" @selected($method === $operation->method)>{{ $method }}</option>
            @endforeach
        </select>
        <input id="{{ $tryId }}-url" name="__url" type="text" value="{{ $tryUrl }}" spellcheck="false">
    </div>

    <div class="tryit-head">
        <label>Headers</label>
        <button class="btn ghost sm" type="button" data-tryit-add-header>+ Add header</button>
    </div>
    <div class="tryit-headers" data-tryit-headers>
        @foreach($tryHeaders as $header)
            <div class="tryit-header" data-tryit-header>
                <input type="checkbox" data-header-on checked aria-label="Send this header">
                <input type="text" data-header-name value="{{ $header['name'] }}" placeholder="Name" spellcheck="false" aria-label="Header name">
                <input type="text" data-header-value value="{{ $header['value'] }}" placeholder="Value" spellcheck="false" aria-label="Header value">
                <button class="btn ghost sm" type="button" data-tryit-remove-header aria-label="Remove header">&times;</button>
            </div>
        @endforeach
    </div>
    <template data-tryit-header-template>
        <div class="tryit-header" data-tryit-header>
            <input type="checkbox" data-header-on checked aria-label="Send this header">
            <input type="text" data-header-name value="" placeholder="Name" spellcheck="false" aria-label="Header name">
            <input type="text" data-header-value value="" placeholder="Value" spellcheck="false" aria-label="Header value">
            <button class="btn ghost sm" type="button" data-tryit-remove-header aria-label="Remove header">&times;</button>
        </div>
    </template>

    <div data-tryit-body-field>
        <label for="{{ $tryId }}-body">Body</label>
        <textarea id="{{ $tryId }}-body" name="__body" spellcheck="false" placeholder="Request body (sent for methods other than GET and HEAD)">{{ $tryBody }}</textarea>
    </div>

    <div class="row">
        <button class="btn" type="submit">Send request</button>
        <button class="btn ghost" type="button" data-tryit-reset>Reset</button>
    </div>

    <div class="tryit-out" data-tryit-output hidden>
        <div class="tryit-status" data-tryit-status></div>
        <div class="code"><pre><code data-tryit-body></code></pre></div>
    </div>
</form>
