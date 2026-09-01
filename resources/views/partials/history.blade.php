@php
    $historyOptions = $historyOptions ?? [];
    $entries = ($historyOptions['show_in_ui'] ?? true) && isset($history)
        ? $history->forOperation($operation->method, $operation->path, (int) ($historyOptions['per_endpoint'] ?? 5))
        : [];
@endphp

@if($entries !== [])
    <section class="block">
        <h3>History</h3>

        <ol class="timeline endpoint-history">
            @foreach($entries as $entry)
                @php
                    $revision = $entry['revision'];
                    $change = $entry['operation'];
                @endphp
                <li class="rev">
                    <div class="rev-head">
                        <span class="chg chg-{{ $change->type }}">{{ $change->label() }}</span>
                        <span class="rev-date">{{ $revision->date() }}</span>
                        <span class="pill">{{ $revision->id() }}</span>
                        @if($revision->version !== '')
                            <span class="pill">v{{ $revision->version }}</span>
                        @endif
                        @if($change->isBreaking())
                            <span class="badge dep">breaking</span>
                        @endif
                    </div>

                    <ul class="rev-changes">
                        @foreach($change->changes as $item)
                            <li class="chg-item chg-{{ $item->type }}">
                                {!! \Cofa\ApiDocs\Support\Markdown::inline($item->summary) !!}
                                @if($item->category === 'description' && is_string($item->to) && $item->to !== '')
                                    <details>
                                        <summary>see the new text</summary>
                                        <p>{{ $item->to }}</p>
                                    </details>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ol>
    </section>
@endif
