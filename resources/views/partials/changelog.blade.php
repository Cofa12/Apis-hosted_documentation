@php
    $historyOptions = $historyOptions ?? [];
    $revisions = ($historyOptions['show_in_ui'] ?? true) && isset($history)
        ? $history->latest((int) ($historyOptions['changelog'] ?? 5))
        : [];
@endphp

@if($revisions !== [])
    <section class="block changelog" id="changelog">
        <h3>Recent changes</h3>

        <ol class="timeline">
            @foreach($revisions as $revision)
                <li class="rev">
                    <div class="rev-head">
                        <span class="rev-date">{{ $revision->date() }}</span>
                        <span class="pill">{{ $revision->id() }}</span>
                        <span class="rev-headline">{{ $revision->headline() }}</span>
                        @if($revision->isBreaking())
                            <span class="badge dep">breaking</span>
                        @endif
                    </div>

                    @unless($revision->initial)
                        <ul class="rev-ops">
                            @foreach(array_slice($revision->operations, 0, 8) as $operation)
                                <li>
                                    <span class="chg chg-{{ $operation->type }}">{{ $operation->label() }}</span>
                                    <a href="#{{ $operation->id() }}">
                                        <span class="method {{ strtolower($operation->method) }}">{{ $operation->method }}</span>
                                        <code>{{ $operation->path }}</code>
                                    </a>
                                </li>
                            @endforeach

                            @if(count($revision->operations) > 8)
                                <li class="more">… and {{ count($revision->operations) - 8 }} more</li>
                            @endif
                        </ul>
                    @endunless
                </li>
            @endforeach
        </ol>
    </section>
@endif
