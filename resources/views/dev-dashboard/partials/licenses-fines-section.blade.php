<section id="licenses" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">الرخص والمخالفات | Licenses & Fines</h5>
    <div class="card card-dev">
        <div class="card-body">
            <div class="action-grid mb-3">
                @foreach([
                    ['list_licenses', 'List Licenses'],
                    ['show_license', 'Show License'],
                    ['renew_license', 'Renew'],
                    ['issue_license', 'Issue License (Admin)'],
                    ['list_fines', 'Citizen Fines'],
                    ['admin_list_fines', 'Admin Fines'],
                    ['block_license', 'Block License'],
                    ['unblock_license', 'Unblock License'],
                ] as [$action, $label])
                    <form method="POST" action="{{ route('dev-dashboard.action') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak">{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            <div class="row g-2">
                <div class="col-md-4">
                    <form method="POST" action="{{ route('dev-dashboard.action') }}">
                        @csrf
                        <input type="hidden" name="action" value="replacement_license">
                        <select name="replacement_type" class="form-select form-select-sm mb-1">
                            <option value="lost">lost</option>
                            <option value="damaged">damaged</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Replacement</button>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" action="{{ route('dev-dashboard.action') }}">
                        @csrf
                        <input type="hidden" name="action" value="unblock_license_request">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Unblock Request</button>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" action="{{ route('dev-dashboard.action') }}">
                        @csrf
                        <input type="hidden" name="action" value="create_fine">
                        <input type="number" name="fine_amount" class="form-control form-control-sm mb-1" value="100" placeholder="amount">
                        <input type="text" name="fine_reason" class="form-control form-control-sm mb-1" value="Dev dashboard fine">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100">Create Fine</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
