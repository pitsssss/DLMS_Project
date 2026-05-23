<section id="documents" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">الوثائق | Documents</h5>
    <div class="card card-dev">
        <div class="card-body">
            <div class="action-grid mb-3">
                @foreach([
                    ['required_documents', 'Required Documents'],
                    ['list_documents', 'List Documents'],
                    ['submit_documents', 'Submit Documents'],
                    ['pending_document_reviews', 'Pending Reviews'],
                    ['approve_pending_document', 'Approve Document'],
                    ['approve_all_documents', 'Approve All (App)'],
                ] as [$action, $label])
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak">{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            <form method="POST" action="{{ $devRoutes['action'] }}" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                @csrf
                <input type="hidden" name="action" value="upload_document">
                <div class="col-md-3">
                    <label class="form-label small font-en">required_document_id</label>
                    <input type="number" name="required_document_id" class="form-control form-control-sm" placeholder="from session">
                </div>
                <div class="col-md-5">
                    <label class="form-label small font-en">File</label>
                    <input type="file" name="document_file" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-syrtak w-100">Upload</button>
                </div>
            </form>
            <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                @csrf
                <input type="hidden" name="action" value="upload_sample_document">
                <button type="submit" class="btn btn-sm btn-outline-secondary font-en">Upload Sample PDF</button>
            </form>

            <form method="POST" action="{{ $devRoutes['action'] }}" class="row g-2">
                @csrf
                <input type="hidden" name="action" value="reject_pending_document">
                <div class="col-md-8">
                    <input type="text" name="rejection_reason" class="form-control form-control-sm" placeholder="Rejection reason">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm btn-danger w-100">Reject Pending Document</button>
                </div>
            </form>
        </div>
    </div>
</section>
