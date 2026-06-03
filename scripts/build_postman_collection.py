#!/usr/bin/env python3
"""Generate DLMS_API_Postman_Collection.json (Postman v2.1)."""
import json
from pathlib import Path

BASE = "{{base_url}}"

def url(path, query=None):
    raw = f"{BASE}{path}"
    if not query:
        return raw
    parts = [f"{k}={v}" for k, v in query.items()]
    return raw + "?" + "&".join(parts)

def headers_json(auth=None):
    h = [
        {"key": "Accept", "value": "application/json", "type": "text"},
        {"key": "Content-Type", "value": "application/json", "type": "text"},
    ]
    if auth:
        h.append({"key": "Authorization", "value": f"Bearer {{{{{auth}}}}}", "type": "text"})
    return h

def headers_auth(auth, content_type=True):
    h = [{"key": "Accept", "value": "application/json", "type": "text"}]
    if content_type:
        h.insert(0, {"key": "Content-Type", "value": "application/json", "type": "text"})
    h.append({"key": "Authorization", "value": f"Bearer {{{{{auth}}}}}", "type": "text"})
    return h

def test_script(lines):
    return [{"listen": "test", "script": {"type": "text/javascript", "exec": lines}}]

def req(name, method, path, *, auth=None, body=None, tests=None, query=None, formdata=None, description=None):
    if formdata:
        header = [{"key": "Accept", "value": "application/json", "type": "text"}]
        if auth:
            header.append({"key": "Authorization", "value": f"Bearer {{{{{auth}}}}}", "type": "text"})
    elif auth:
        header = headers_auth(auth)
    elif body is not None and method in ("POST", "PUT", "PATCH"):
        header = headers_json()
    else:
        header = [{"key": "Accept", "value": "application/json", "type": "text"}]

    item = {
        "name": name,
        "request": {
            "method": method,
            "header": header,
            "url": url(path, query),
        },
    }
    if body is not None:
        item["request"]["body"] = {"mode": "raw", "raw": body}
    if formdata:
        item["request"]["body"] = {"mode": "formdata", "formdata": formdata}
    if tests:
        item["event"] = test_script(tests)
    if description:
        item["request"]["description"] = description
    return item

SAVE_TOKEN_CITIZEN = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.token) pm.collectionVariables.set('citizen_token', j.data.token);",
    "  if (j.data && j.data.user && j.data.user.id) pm.collectionVariables.set('citizen_user_id', String(j.data.user.id));",
    "} catch (e) {}",
]

SAVE_TOKEN_ADMIN = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.token) pm.collectionVariables.set('admin_token', j.data.token);",
    "} catch (e) {}",
]

SAVE_TOKEN_EMPLOYEE = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.token) pm.collectionVariables.set('employee_token', j.data.token);",
    "} catch (e) {}",
]

SAVE_ME_USER = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.id) {",
    "    pm.collectionVariables.set('citizen_user_id', String(j.data.id));",
    "    pm.collectionVariables.set('profile_review_user_id', String(j.data.id));",
    "  }",
    "} catch (e) {}",
]

SAVE_PROFILE_STATUS = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.id) pm.collectionVariables.set('citizen_user_id', String(j.data.id));",
    "  if (j.data && j.data.profile_status) pm.test('profile_status', function () { pm.expect(j.data.profile_status).to.be.a('string'); });",
    "} catch (e) {}",
]

SAVE_PROFILE_REVIEW_USER = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.items && j.data.items.length) {",
    "    pm.collectionVariables.set('profile_review_user_id', String(j.data.items[0].id));",
    "    pm.collectionVariables.set('citizen_user_id', String(j.data.items[0].id));",
    "  }",
    "} catch (e) {}",
]

SAVE_APPLICATION = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.id) pm.collectionVariables.set('application_id', String(j.data.id));",
    "} catch (e) {}",
]

SAVE_AI_SESSION = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.session_id) pm.collectionVariables.set('ai_agent_session_id', String(j.data.session_id));",
    "  if (j.data && j.data.pending_action && j.data.pending_action.id) {",
    "    pm.collectionVariables.set('ai_agent_action_id', String(j.data.pending_action.id));",
    "  }",
    "} catch (e) {}",
]

SAVE_AI_APP_FROM_CONFIRM = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.result && j.data.result.application_id) {",
    "    pm.collectionVariables.set('application_id', String(j.data.result.application_id));",
    "  }",
    "} catch (e) {}",
]

collection = {
    "info": {
        "name": "DLMS / SYRTAK API — Full Workflow (Profile Approval + AI Agent)",
        "description": "End-to-end flow: Register → Verify OTP → Login → Complete Profile (pending_review) → Admin Approve Profile → Applications → Documents → Payments → Appointments → License. AI Agent chat and action confirm/cancel included.",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "variable": [
        {"key": "base_url", "value": "http://127.0.0.1:8000/api"},
        {"key": "citizen_email", "value": "testcitizen@example.com"},
        {"key": "citizen_phone", "value": "0977000001"},
        {"key": "citizen_password", "value": "password123"},
        {"key": "otp_code", "value": "123456"},
        {"key": "citizen_token", "value": ""},
        {"key": "citizen_user_id", "value": ""},
        {"key": "profile_review_user_id", "value": ""},
        {"key": "admin_email", "value": "admin@example.com"},
        {"key": "employee_email", "value": "employee@example.com"},
        {"key": "seed_password", "value": "password"},
        {"key": "admin_token", "value": ""},
        {"key": "employee_token", "value": ""},
        {"key": "reset_token", "value": ""},
        {"key": "new_password", "value": "newpassword123"},
        {"key": "license_type_id", "value": "1"},
        {"key": "service_type_id", "value": "1"},
        {"key": "application_id", "value": ""},
        {"key": "required_document_id", "value": ""},
        {"key": "document_id", "value": ""},
        {"key": "pending_document_id", "value": ""},
        {"key": "payment_id", "value": ""},
        {"key": "checkout_url", "value": ""},
        {"key": "stripe_session_id", "value": ""},
        {"key": "appointment_id", "value": ""},
        {"key": "appointment_slot_id", "value": ""},
        {"key": "license_id", "value": ""},
        {"key": "fine_id", "value": ""},
        {"key": "notification_id", "value": ""},
        {"key": "ai_agent_session_id", "value": ""},
        {"key": "ai_agent_action_id", "value": ""},
    ],
    "item": [],
}

items = collection["item"]

# 0 Health
items.append(
    req("Ping", "GET", "/ping", description="Health check; data.phase indicates API phase.")
)

# 1 Auth & Profile
auth_profile = {"name": "1. Auth & Profile", "item": []}
auth_profile["item"].extend([
    req(
        "1.1 Register Citizen",
        "POST",
        "/auth/register",
        body='{\n  "name": "Test Citizen",\n  "email": "{{citizen_email}}",\n  "phone": "{{citizen_phone}}",\n  "password": "{{citizen_password}}",\n  "password_confirmation": "{{citizen_password}}"\n}',
        description="Step 1 of main workflow. OTP sent to email (use otp_code in testing).",
    ),
    req(
        "1.2 Verify Email OTP",
        "POST",
        "/auth/verify-otp",
        body='{\n  "email": "{{citizen_email}}",\n  "code": "{{otp_code}}",\n  "purpose": "register"\n}',
        tests=SAVE_TOKEN_CITIZEN,
        description="Step 2. Activates account and returns token + user.",
    ),
    req(
        "1.3 Login Citizen",
        "POST",
        "/auth/login",
        body='{\n  "email": "{{citizen_email}}",\n  "password": "{{citizen_password}}"\n}',
        tests=SAVE_TOKEN_CITIZEN,
        description="Step 3. Use after register or for returning citizen.",
    ),
    req("1.4 Get Me", "GET", "/auth/me", auth="citizen_token", tests=SAVE_ME_USER),
    req(
        "1.5 Complete Profile (submit for review)",
        "PUT",
        "/profile/complete",
        auth="citizen_token",
        body='{\n  "name": "Test Citizen",\n  "national_id": "11001100110",\n  "birth_date": "1998-01-15",\n  "governorate": "Damascus",\n  "address": "Damascus - Syria"\n}',
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.id) pm.collectionVariables.set('citizen_user_id', String(j.data.id));",
            "  if (j.data && j.data.profile_status === 'pending_review') {",
            "    pm.test('Submitted for review', function () { pm.response.to.have.status(200); });",
            "  }",
            "} catch (e) {}",
        ],
        description="Step 4. Sets profile_completed=true and profile_status=pending_review. Services remain blocked until admin approval.",
    ),
    req(
        "1.6 Get Profile Status",
        "GET",
        "/profile/status",
        auth="citizen_token",
        description="Flutter routing: incomplete | pending_review | rejected | approved.",
    ),
    req(
        "1.7 Update Profile (resubmit after reject)",
        "PUT",
        "/profile/update",
        auth="citizen_token",
        body='{\n  "address": "Damascus - updated address"\n}',
        description="Rejected citizens resubmit → pending_review. Approved citizens changing sensitive fields also re-enter review.",
    ),
    req("1.8 Logout", "POST", "/auth/logout", auth="citizen_token"),
    req(
        "1.9 Login Admin",
        "POST",
        "/auth/login",
        body='{\n  "email": "{{admin_email}}",\n  "password": "{{seed_password}}"\n}',
        tests=SAVE_TOKEN_ADMIN,
    ),
    req(
        "1.10 Login Employee",
        "POST",
        "/auth/login",
        body='{\n  "email": "{{employee_email}}",\n  "password": "{{seed_password}}"\n}',
        tests=SAVE_TOKEN_EMPLOYEE,
    ),
])
items.append(auth_profile)

# 2 Profile Approval
profile_folder = {"name": "2. Profile Approval (Employee/Admin)", "item": []}
profile_folder["item"].extend([
    req(
        "2.1 List Pending Profile Reviews",
        "GET",
        "/admin/profile-reviews",
        auth="employee_token",
        query={"status": "pending_review", "per_page": "20"},
        tests=SAVE_PROFILE_REVIEW_USER,
        description="Requires permission review_profiles. Default filter: pending_review.",
    ),
    req(
        "2.2 Show Profile Review",
        "GET",
        "/admin/profile-reviews/{{profile_review_user_id}}",
        auth="employee_token",
    ),
    req(
        "2.3 Approve Profile",
        "POST",
        "/admin/profile-reviews/{{profile_review_user_id}}/approve",
        auth="employee_token",
        description="Step 5 of main workflow. Citizen can use services after approval.",
    ),
    req(
        "2.4 Reject Profile",
        "POST",
        "/admin/profile-reviews/{{profile_review_user_id}}/reject",
        auth="employee_token",
        body='{\n  "rejection_reason": "الوثائق أو البيانات غير مكتملة. يرجى التعديل وإعادة الإرسال."\n}',
    ),
    req(
        "2.5 Create Application BEFORE approval (expect 403)",
        "POST",
        "/applications",
        auth="citizen_token",
        body='{\n  "license_type_id": {{license_type_id}},\n  "service_type_id": {{service_type_id}}\n}',
        description="Run after Complete Profile but before Approve Profile. Expect profile pending_review message.",
    ),
])
items.append(profile_folder)

# 3 Reference
ref = {"name": "3. Reference Data (Public)", "item": []}
ref["item"].extend([
    req(
        "3.1 List License Types",
        "GET",
        "/license-types",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (Array.isArray(j.data) && j.data.length && j.data[0].id) {",
            "    pm.collectionVariables.set('license_type_id', String(j.data[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req(
        "3.2 List Service Types",
        "GET",
        "/service-types",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (Array.isArray(j.data)) {",
            "    var row = j.data.find(function (d) { return d.code === 'new_license'; });",
            "    if (row && row.id) pm.collectionVariables.set('service_type_id', String(row.id));",
            "    else if (j.data.length && j.data[0].id) pm.collectionVariables.set('service_type_id', String(j.data[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
])
items.append(ref)

# 4 Applications
apps = {"name": "4. Applications (requires approved profile)", "item": []}
apps["item"].extend([
    req(
        "4.1 Create Application Draft",
        "POST",
        "/applications",
        auth="citizen_token",
        body='{\n  "license_type_id": {{license_type_id}},\n  "service_type_id": {{service_type_id}}\n}',
        tests=SAVE_APPLICATION,
        description="Step 6. Requires profile_status=approved.",
    ),
    req("4.2 List My Applications", "GET", "/applications", auth="citizen_token", query={"per_page": "15"}),
    req("4.3 Get Application By Id", "GET", "/applications/{{application_id}}", auth="citizen_token"),
    req(
        "4.4 Create Duplicate Application (expect 422)",
        "POST",
        "/applications",
        auth="citizen_token",
        body='{\n  "license_type_id": {{license_type_id}},\n  "service_type_id": {{service_type_id}}\n}',
        description="Same license_type + service_type with active application should fail.",
    ),
])
items.append(apps)

# 5 Documents
docs = {"name": "5. Documents", "item": []}
docs["item"].extend([
    req(
        "5.1 Required Documents Checklist",
        "GET",
        "/applications/{{application_id}}/required-documents",
        auth="citizen_token",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (Array.isArray(j.data) && j.data.length && j.data[0].id) {",
            "    pm.collectionVariables.set('required_document_id', String(j.data[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req(
        "5.2 Upload Document",
        "POST",
        "/applications/{{application_id}}/documents",
        auth="citizen_token",
        formdata=[
            {"key": "required_document_id", "value": "{{required_document_id}}", "type": "text"},
            {"key": "file", "type": "file", "src": "", "description": "PDF or image per document rules."},
        ],
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.id) pm.collectionVariables.set('document_id', String(j.data.id));",
            "} catch (e) {}",
        ],
    ),
    req("5.3 List Application Documents", "GET", "/applications/{{application_id}}/documents", auth="citizen_token"),
    req("5.4 Submit Documents For Review", "POST", "/applications/{{application_id}}/submit-documents", auth="citizen_token"),
    req(
        "5.5 List Pending Documents (employee)",
        "GET",
        "/admin/documents/pending-review",
        auth="employee_token",
        query={"per_page": "20"},
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.items && j.data.items.length && j.data.items[0].id) {",
            "    pm.collectionVariables.set('pending_document_id', String(j.data.items[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req("5.6 Approve Document", "POST", "/admin/documents/{{pending_document_id}}/approve", auth="employee_token"),
    req(
        "5.7 Reject Document",
        "POST",
        "/admin/documents/{{pending_document_id}}/reject",
        auth="employee_token",
        body='{\n  "rejection_reason": "Document is unclear."\n}',
    ),
])
items.append(docs)

# 6 Payments
pay = {"name": "6. Payments", "item": []}
pay["item"].extend([
    req("6.1 Get Application Fee", "GET", "/applications/{{application_id}}/fee", auth="citizen_token"),
    req(
        "6.2 Create Payment",
        "POST",
        "/applications/{{application_id}}/payments",
        auth="citizen_token",
        body="{}",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (!j.data) return;",
            "  if (j.data.payment && j.data.payment.id) {",
            "    pm.collectionVariables.set('payment_id', String(j.data.payment.id));",
            "  } else if (j.data.id) {",
            "    pm.collectionVariables.set('payment_id', String(j.data.id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req("6.3 Payment Status", "GET", "/applications/{{application_id}}/payments/{{payment_id}}/status", auth="citizen_token"),
    req("6.4 Confirm Payment (mock)", "POST", "/applications/{{application_id}}/payments/{{payment_id}}/confirm", auth="citizen_token"),
    req("6.5 List Payments", "GET", "/applications/{{application_id}}/payments", auth="citizen_token"),
])
items.append(pay)

# 7 Appointments
apt = {"name": "7. Appointments & Tests", "item": []}
apt["item"].extend([
    req("7.1 Available Tests", "GET", "/applications/{{application_id}}/available-tests", auth="citizen_token"),
    req(
        "7.2 List Appointment Slots",
        "GET",
        "/appointment-slots",
        auth="citizen_token",
        query={"test_type_id": "1"},
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.length && j.data[0].id) {",
            "    pm.collectionVariables.set('appointment_slot_id', String(j.data[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req(
        "7.3 Book Appointment",
        "POST",
        "/applications/{{application_id}}/appointments",
        auth="citizen_token",
        body='{\n  "appointment_slot_id": {{appointment_slot_id}}\n}',
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.id) pm.collectionVariables.set('appointment_id', String(j.data.id));",
            "} catch (e) {}",
        ],
    ),
    req("7.4 List Appointments", "GET", "/applications/{{application_id}}/appointments", auth="citizen_token"),
    req("7.5 List Test Results", "GET", "/applications/{{application_id}}/test-results", auth="citizen_token"),
    req(
        "7.6 Record Test Result (employee)",
        "POST",
        "/admin/test-appointments/{{appointment_id}}/record-result",
        auth="employee_token",
        body='{\n  "result": "passed",\n  "notes": "Citizen met requirements."\n}',
    ),
    req(
        "7.7 Reschedule Appointment",
        "PUT",
        "/appointments/{{appointment_id}}/reschedule",
        auth="citizen_token",
        body='{\n  "appointment_slot_id": {{appointment_slot_id}}\n}',
    ),
    req(
        "7.8 Cancel Appointment",
        "DELETE",
        "/appointments/{{appointment_id}}/cancel",
        auth="citizen_token",
        body='{\n  "cancellation_reason": "Schedule conflict"\n}',
    ),
])
items.append(apt)

# 8 Licenses
lic = {"name": "8. Licenses & Fines", "item": []}
lic["item"].extend([
    req(
        "8.1 Issue License (employee)",
        "POST",
        "/admin/applications/{{application_id}}/issue-license",
        auth="employee_token",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.id) pm.collectionVariables.set('license_id', String(j.data.id));",
            "} catch (e) {}",
        ],
    ),
    req("8.2 List My Licenses", "GET", "/licenses", auth="citizen_token"),
    req("8.3 Show License", "GET", "/licenses/{{license_id}}", auth="citizen_token"),
    req("8.4 Renew License", "POST", "/licenses/{{license_id}}/renew", auth="citizen_token", body="{}"),
    req("8.5 Replacement License", "POST", "/licenses/{{license_id}}/replacement", auth="citizen_token", body="{}"),
    req("8.6 Unblock Request", "POST", "/licenses/{{license_id}}/unblock-request", auth="citizen_token", body="{}"),
    req("8.7 Block License (employee)", "POST", "/admin/licenses/{{license_id}}/block", auth="employee_token", body='{"reason": "Under investigation"}'),
    req("8.8 Unblock License (employee)", "POST", "/admin/licenses/{{license_id}}/unblock", auth="employee_token"),
    req("8.9 List Fines (admin)", "GET", "/admin/fines", auth="admin_token", query={"per_page": "20"}),
    req(
        "8.10 Create Fine (admin)",
        "POST",
        "/admin/fines",
        auth="admin_token",
        body='{\n  "citizen_id": {{citizen_user_id}},\n  "amount": 50000,\n  "reason": "Traffic violation",\n  "type": "traffic"\n}',
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.id) pm.collectionVariables.set('fine_id', String(j.data.id));",
            "} catch (e) {}",
        ],
    ),
    req("8.11 Update Fine (admin)", "PUT", "/admin/fines/{{fine_id}}", auth="admin_token", body='{"status": "paid"}'),
    req("8.12 List My Fines (citizen)", "GET", "/fines", auth="citizen_token"),
])
items.append(lic)

# 9 Notifications & Reports
rep = {"name": "9. Notifications, Reports & Audit", "item": []}
rep["item"].extend([
    req(
        "9.1 List Notifications",
        "GET",
        "/notifications",
        auth="citizen_token",
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.items && j.data.items.length && j.data.items[0].id) {",
            "    pm.collectionVariables.set('notification_id', String(j.data.items[0].id));",
            "  }",
            "} catch (e) {}",
        ],
    ),
    req("9.2 Mark Notification Read", "PUT", "/notifications/{{notification_id}}/read", auth="citizen_token"),
    req("9.3 Reports Overview", "GET", "/admin/reports/overview", auth="admin_token"),
    req("9.4 Audit Logs", "GET", "/admin/audit-logs", auth="admin_token", query={"per_page": "20"}),
    req("9.5 Application Status History", "GET", "/admin/application-status-histories/{{application_id}}", auth="admin_token"),
])
items.append(rep)

# 10 Password reset (optional)
pwd = {"name": "10. Password Reset (optional)", "item": []}
pwd["item"].extend([
    req("10.1 Forgot Password", "POST", "/auth/forgot-password", body='{"email": "{{citizen_email}}"}'),
    req(
        "10.2 Verify Forgot OTP",
        "POST",
        "/auth/verify-forgot-password-otp",
        body='{"email": "{{citizen_email}}", "code": "{{otp_code}}"}',
        tests=[
            "try {",
            "  var j = pm.response.json();",
            "  if (j.data && j.data.reset_token) pm.collectionVariables.set('reset_token', j.data.reset_token);",
            "} catch (e) {}",
        ],
    ),
    req(
        "10.3 Reset Password",
        "POST",
        "/auth/reset-password",
        body='{\n  "email": "{{citizen_email}}",\n  "reset_token": "{{reset_token}}",\n  "password": "{{new_password}}",\n  "password_confirmation": "{{new_password}}"\n}',
    ),
])
items.append(pwd)

# 11 AI Agent Chat
ai_chat = {"name": "11. AI Agent — Chat", "item": []}
ai_chat["item"].extend([
    req(
        "11.1 Send Message (new license intent)",
        "POST",
        "/ai-agent/message",
        auth="citizen_token",
        body='{\n  "message": "بدي رخصة جديدة"\n}',
        tests=SAVE_AI_SESSION,
    ),
    req(
        "11.2 Continue Session (license type slot)",
        "POST",
        "/ai-agent/message",
        auth="citizen_token",
        body='{\n  "message": "رخصة خاصة",\n  "session_id": {{ai_agent_session_id}}\n}',
        tests=SAVE_AI_SESSION,
        description="After profile approved: may propose create_application. Before approval: blocked with Arabic message.",
    ),
    req("11.3 List Sessions", "GET", "/ai-agent/sessions", auth="citizen_token"),
    req("11.4 Show Session", "GET", "/ai-agent/sessions/{{ai_agent_session_id}}", auth="citizen_token"),
])
items.append(ai_chat)

# 12 AI Agent Actions
ai_act = {"name": "12. AI Agent — Actions", "item": []}
ai_act["item"].extend([
    req(
        "12.1 Confirm Action (create_application)",
        "POST",
        "/ai-agent/actions/{{ai_agent_action_id}}/confirm",
        auth="citizen_token",
        tests=SAVE_AI_APP_FROM_CONFIRM,
        description="Requires approved profile for create_application. Saves application_id from result.",
    ),
    req("12.2 Cancel Action", "POST", "/ai-agent/actions/{{ai_agent_action_id}}/cancel", auth="citizen_token"),
])
items.append(ai_act)

# 13 AI Scenarios
ai_sc = {"name": "13. AI Agent — Scenarios", "item": []}
ai_sc["item"].extend([
    req(
        "13.1 Blocked create_application (pending profile)",
        "POST",
        "/ai-agent/message",
        auth="citizen_token",
        body='{\n  "message": "بدي رخصة جديدة"\n}',
        description="Run when profile_status=pending_review. Expect no pending_action for create_application; reply mentions review.",
    ),
    req(
        "13.2 Duplicate active application (after approval)",
        "POST",
        "/ai-agent/message",
        auth="citizen_token",
        body='{\n  "message": "رخصة خاصة",\n  "session_id": {{ai_agent_session_id}}\n}',
        description="With existing draft/active app for same type: should propose get_application_status instead of create_application.",
    ),
    req(
        "13.3 Confirm blocked when profile not approved",
        "POST",
        "/ai-agent/actions/{{ai_agent_action_id}}/confirm",
        auth="citizen_token",
        description="If action was created before rejection/pending: confirm fails with Arabic profile error.",
    ),
])
items.append(ai_sc)

out = Path(__file__).resolve().parent.parent / "DLMS_API_Postman_Collection.json"
out.write_text(json.dumps(collection, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
print(f"Wrote {out}")
print(f"Folders: {len(collection['item'])}")
print(f"Variables: {len(collection['variable'])}")
