<nav class="sidebar" aria-label="Endpoints">
    <h2>Reference</h2>
    @foreach($spec->groupedOperations() as $group => $operations)
        <div class="nav-group{{ ($ui['collapse_groups'] ?? false) ? ' collapsed' : '' }}">
            <button type="button" aria-expanded="{{ ($ui['collapse_groups'] ?? false) ? 'false' : 'true' }}">
                <svg class="chev" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 8 4 4 4-4"></path>
                </svg>
                <span>{{ $group }}</span>
                <span class="count">{{ count($operations) }}</span>
            </button>
            <ul class="nav-items">
                @foreach($operations as $operation)
                    <li>
                        <a href="#{{ $operation->id() }}" title="{{ $operation->summary() }}">
                            <span class="method {{ strtolower($operation->method) }}">{{ $operation->method }}</span>
                            <span class="label">{{ $operation->summary() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
