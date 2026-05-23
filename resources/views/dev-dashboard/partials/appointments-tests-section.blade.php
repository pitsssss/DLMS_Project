<section id="appointments" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">المواعيد والاختبارات | Appointments & Tests</h5>
    <div class="card card-dev">
        <div class="card-body">
            <div class="action-grid mb-3">
                @foreach([
                    ['available_tests', 'Available Tests'],
                    ['book_appointment', 'Book Appointment'],
                    ['list_appointments', 'List Appointments'],
                    ['list_test_results', 'Test Results'],
                    ['cancel_appointment', 'Cancel Appointment'],
                    ['record_test_result', 'Record Result'],
                    ['quick_pass_test', 'Quick Pass'],
                ] as [$action, $label])
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak">{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-6 d-flex flex-wrap gap-1">
                    @foreach([1 => 'Vision (1)', 2 => 'Theory (2)', 3 => 'Practical (3)'] as $typeId => $label)
                        <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="action" value="appointment_slots">
                            <input type="hidden" name="test_type_id" value="{{ $typeId }}">
                            <button type="submit" class="btn btn-sm btn-secondary">{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
                <div class="col-md-6">
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="row g-1">
                        @csrf
                        <input type="hidden" name="action" value="reschedule_appointment">
                        <div class="col-8">
                            <input type="number" name="appointment_slot_id" class="form-control form-control-sm" placeholder="appointment_slot_id">
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-sm btn-outline-syrtak w-100">Reschedule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
