<section id="ai-agent" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">وكيل الذكاء الاصطناعي | AI Agent</h5>
    <div class="card card-dev border-success">
        <div class="card-header" style="background: linear-gradient(90deg, #054239, #428177); color: #fff;">
            AI Service Agent — Phase 9
        </div>
        <div class="card-body">
            <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                @csrf
                <input type="hidden" name="action" value="ai_agent_message">
                <label class="form-label">رسالة | Message</label>
                <textarea name="message" class="form-control mb-2" rows="2" placeholder="اكتب رسالتك...">{{ session('ai_agent_message') }}</textarea>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @foreach([
                        'بدي رخصة جديدة',
                        'رخصة خاصة',
                        'شو حالة طلبي؟',
                        'اعرضلي مخالفاتي',
                        'اعرضلي رخصي',
                        'وافقلي على وثائقي',
                        'شو رأيك بالبيتكوين؟',
                    ] as $preset)
                        <button type="submit" name="message" value="{{ $preset }}" class="btn btn-sm btn-outline-syrtak">{{ $preset }}</button>
                    @endforeach
                </div>
                <button type="submit" class="btn btn-syrtak">Send AI Message</button>
            </form>

            <div class="action-grid mb-3">
                @foreach([
                    ['ai_agent_confirm', 'Confirm Action'],
                    ['ai_agent_cancel', 'Cancel Action'],
                    ['ai_agent_sessions', 'List Sessions'],
                    ['ai_agent_show_session', 'Show Session'],
                ] as [$action, $label])
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <button type="submit" class="btn btn-sm {{ str_contains($action, 'confirm') ? 'btn-success' : 'btn-outline-syrtak' }}">{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            <p class="small text-muted mb-0 font-en">
                Session: {{ session('ai_agent_session_id') ?? '—' }} |
                Pending action: {{ session('ai_agent_action_id') ?? '—' }}
            </p>
        </div>
    </div>
</section>
