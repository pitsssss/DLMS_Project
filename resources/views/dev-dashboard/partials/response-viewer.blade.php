<div class="card card-dev mb-3 section-anchor" id="response">
    <div class="card-header">Raw API Response | آخر استجابة</div>
    <div class="card-body">
        @if(session('dev_flash'))
            <div class="alert alert-success">{{ session('dev_flash') }}</div>
        @endif

        @if(empty($lastResponse))
            <p class="text-muted mb-0">Run an action to see the latest API response here.</p>
        @else
            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <span class="badge bg-dark font-en">{{ $lastResponse['action'] ?? 'n/a' }}</span>
                <span class="badge bg-secondary font-en">{{ $lastResponse['method'] ?? '' }}</span>
                <span class="badge {{ ($lastResponse['success'] ?? false) ? 'bg-success' : 'bg-danger' }} font-en">
                    HTTP {{ $lastResponse['status'] ?? '?' }} — {{ ($lastResponse['success'] ?? false) ? 'Success' : 'Failed' }}
                </span>
            </div>
            <p class="small font-en text-break"><strong>URL:</strong> {{ $lastResponse['url'] ?? '' }}</p>

            @if(!empty($lastResponse['scenario_steps']))
                <p class="fw-semibold">Scenario steps ({{ $lastResponse['scenario'] ?? '' }}):</p>
                <ul class="small font-en">
                    @foreach($lastResponse['scenario_steps'] as $step)
                        <li>{{ $step['action'] ?? 'step' }} — HTTP {{ $step['status'] ?? '?' }} {{ ($step['success'] ?? false) ? '✓' : '✗' }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="fw-semibold mb-1">Response body</p>
            <pre class="json-viewer">@json($lastResponse['body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)</pre>

            @if(!empty($lastResponse['saved']))
                <p class="fw-semibold mt-3 mb-1">Saved session variables</p>
                <pre class="json-viewer">@json($lastResponse['saved'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)</pre>
            @endif
        @endif
    </div>
</div>
