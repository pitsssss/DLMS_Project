<section id="auth" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">المصادقة | Auth</h5>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card card-dev h-100">
                <div class="card-header">Citizen</div>
                <div class="card-body">
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="register_citizen">
                        <label class="form-label small font-en">Email</label>
                        <input type="email" name="citizen_email" class="form-control form-control-sm mb-2" value="{{ $defaults['citizen_email'] }}" placeholder="auto if empty">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100">Register Test Citizen</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="verify_citizen_otp">
                        <label class="form-label small font-en">OTP Code</label>
                        <input type="text" name="otp_code" class="form-control form-control-sm mb-2" value="{{ config('otp.fixed_code') ?: '123456' }}" maxlength="6">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak w-100">Verify Citizen OTP</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="login_citizen">
                        <label class="form-label small font-en">Email / Password</label>
                        <input type="email" name="citizen_email" class="form-control form-control-sm mb-1" value="{{ $defaults['citizen_email'] }}">
                        <input type="password" name="citizen_password" class="form-control form-control-sm mb-2" value="{{ $defaults['citizen_password'] }}">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100">Login Citizen</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="complete_citizen_profile">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak w-100">Complete / Submit Profile for Review</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="citizen_profile_status">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100 font-en">GET /profile/status</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}">
                        @csrf
                        <input type="hidden" name="action" value="citizen_me">
                        <button type="submit" class="btn btn-sm btn-secondary w-100 font-en">GET /auth/me</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dev h-100">
                <div class="card-header">Employee</div>
                <div class="card-body">
                    <form method="POST" action="{{ $devRoutes['action'] }}">
                        @csrf
                        <input type="hidden" name="action" value="login_employee">
                        <label class="form-label small font-en">Email or phone (identifier)</label>
                        <input type="text" name="employee_login" class="form-control form-control-sm mb-1" value="{{ $defaults['employee_login'] }}">
                        <input type="password" name="employee_password" class="form-control form-control-sm mb-2" value="{{ $defaults['employee_password'] }}">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100 mb-3">Login Employee</button>
                    </form>
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="action" value="employee_me">
                        <button type="submit" class="btn btn-sm btn-secondary w-100 font-en">GET /auth/me</button>
                    </form>

                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-2">
                        @csrf
                        <input type="hidden" name="action" value="list_pending_profile_reviews">
                        <button type="submit" class="btn btn-sm btn-outline-syrtak w-100 font-en">GET /admin/profile-reviews</button>
                    </form>
                    <form method="POST" action="{{ $devRoutes['action'] }}" class="mb-2">
                        @csrf
                        <input type="hidden" name="action" value="approve_citizen_profile">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100">Approve Current Citizen Profile</button>
                    </form>
                    <form method="POST" action="{{ $devRoutes['action'] }}">
                        @csrf
                        <input type="hidden" name="action" value="reject_citizen_profile">
                        <input type="text" name="profile_rejection_reason" class="form-control form-control-sm mb-2" placeholder="سبب الرفض">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Reject Current Citizen Profile</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0 font-en">Seeded: 0988888888 or employee@example.com / password</p>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dev h-100">
                <div class="card-header">Admin</div>
                <div class="card-body">
                    <form method="POST" action="{{ $devRoutes['action'] }}">
                        @csrf
                        <input type="hidden" name="action" value="login_admin">
                        <label class="form-label small font-en">Email or phone (identifier)</label>
                        <input type="text" name="admin_login" class="form-control form-control-sm mb-1" value="{{ $defaults['admin_login'] }}">
                        <input type="password" name="admin_password" class="form-control form-control-sm mb-2" value="{{ $defaults['admin_password'] }}">
                        <button type="submit" class="btn btn-sm btn-syrtak w-100 mb-3">Login Admin</button>
                    </form>
                    <form method="POST" action="{{ $devRoutes['action'] }}">
                        @csrf
                        <input type="hidden" name="action" value="admin_me">
                        <button type="submit" class="btn btn-sm btn-secondary w-100 font-en">GET /auth/me</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0 font-en">Seeded: 0999999999 or admin@example.com / password</p>
                </div>
            </div>
        </div>
    </div>
</section>
