#!/usr/bin/env python3
"""Generate SYRTAK Flutter-ready Postman Collection + Environment from audited backend routes."""

from __future__ import annotations

import json
import uuid
from pathlib import Path
from typing import Any

OUT_DIR = Path(__file__).resolve().parent


def uid() -> str:
    return str(uuid.uuid4())


# ---------------------------------------------------------------------------
# Shared scripts
# ---------------------------------------------------------------------------

SCRIPT_OK = """
pm.test('Status is successful', function () {
  pm.expect(pm.response.code).to.be.oneOf([200, 201]);
});
pm.test('Response is JSON', function () {
  pm.response.to.be.json;
});
pm.test('success === true when present', function () {
  const j = pm.response.json();
  if (Object.prototype.hasOwnProperty.call(j, 'success')) {
    pm.expect(j.success).to.eql(true);
  }
});
""".strip()

SCRIPT_SAFE_JSON = """
function safeJson() {
  try { return pm.response.json(); } catch (e) { return null; }
}
function setEnv(key, value) {
  if (value === undefined || value === null || value === '') return;
  pm.environment.set(key, String(value));
}
function firstArray(data) {
  if (Array.isArray(data)) return data;
  if (data && Array.isArray(data.items)) return data.items;
  if (data && Array.isArray(data.data)) return data.data;
  if (data && Array.isArray(data.tests)) return data.tests;
  return [];
}
function pickByCode(arr, preferredCodes) {
  if (!Array.isArray(arr) || !arr.length) return null;
  if (preferredCodes && preferredCodes.length) {
    for (const code of preferredCodes) {
      const hit = arr.find(x => x && String(x.code) === String(code));
      if (hit) return hit;
    }
  }
  return arr[0];
}
""".strip()


def tests(*extra: str) -> list[dict]:
    parts = [SCRIPT_SAFE_JSON, SCRIPT_OK, *extra]
    return [
        {
            "listen": "test",
            "script": {
                "type": "text/javascript",
                "exec": "\n".join(parts).split("\n"),
            },
        }
    ]


def desc(purpose: str, flutter: str = "", requires: str = "", stores: str = "", nxt: str = "", prereq: str = "") -> str:
    lines = [f"Purpose:\n{purpose}"]
    if flutter:
        lines.append(f"\nFlutter usage:\n{flutter}")
    if requires:
        lines.append(f"\nRequires:\n{requires}")
    if stores:
        lines.append(f"\nStores automatically:\n{stores}")
    if prereq:
        lines.append(f"\nPrerequisite:\n{prereq}")
    if nxt:
        lines.append(f"\nNext:\n{nxt}")
    return "\n".join(lines)


def headers(*pairs: tuple[str, str], include_json: bool = False) -> list[dict]:
    h = [{"key": k, "value": v, "type": "text"} for k, v in pairs]
    if include_json:
        h.append({"key": "Content-Type", "value": "application/json", "type": "text"})
    return h


H_PUBLIC = headers(("Accept", "application/json"), ("Accept-Language", "{{app_language}}"))
H_CITIZEN = headers(
    ("Accept", "application/json"),
    ("Accept-Language", "{{app_language}}"),
    ("Authorization", "Bearer {{citizen_token}}"),
)
H_CITIZEN_JSON = headers(
    ("Accept", "application/json"),
    ("Accept-Language", "{{app_language}}"),
    ("Authorization", "Bearer {{citizen_token}}"),
    include_json=True,
)
H_PUBLIC_JSON = headers(
    ("Accept", "application/json"),
    ("Accept-Language", "{{app_language}}"),
    include_json=True,
)
H_DASH = headers(("Accept", "application/json"), ("Authorization", "Bearer {{employee_token}}"))
H_DASH_JSON = headers(
    ("Accept", "application/json"),
    ("Authorization", "Bearer {{employee_token}}"),
    include_json=True,
)
H_DASH_PUBLIC_JSON = headers(("Accept", "application/json"), include_json=True)


def url(path: str, query: list[tuple[str, str]] | None = None) -> dict:
    raw = "{{base_url}}/api" + path
    segments = [s for s in ("api" + path).split("/") if s]
    u: dict[str, Any] = {
        "raw": raw if not query else raw + "?" + "&".join(f"{k}={v}" for k, v in query),
        "host": ["{{base_url}}"],
        "path": segments,
    }
    if query:
        u["query"] = [{"key": k, "value": v} for k, v in query]
    return u


def body_json(obj: Any) -> dict:
    return {"mode": "raw", "raw": json.dumps(obj, ensure_ascii=False, indent=2), "options": {"raw": {"language": "json"}}}


def body_formdata(fields: list[dict]) -> dict:
    return {"mode": "formdata", "formdata": fields}


def req(
    name: str,
    method: str,
    path: str,
    *,
    hdrs: list[dict] | None = None,
    body: dict | None = None,
    query: list[tuple[str, str]] | None = None,
    description: str = "",
    event: list[dict] | None = None,
) -> dict:
    item: dict[str, Any] = {
        "name": name,
        "request": {
            "method": method,
            "header": hdrs if hdrs is not None else H_PUBLIC,
            "url": url(path, query),
        },
        "response": [],
    }
    if description:
        item["request"]["description"] = description
    if body is not None:
        item["request"]["body"] = body
    if event:
        item["event"] = event
    return item


def folder(name: str, items: list, description: str = "") -> dict:
    f: dict[str, Any] = {"name": name, "item": items}
    if description:
        f["description"] = description
    return f


# ---------------------------------------------------------------------------
# Capture scripts
# ---------------------------------------------------------------------------

CAPTURE_CITIZEN_LOGIN = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
setEnv('citizen_token', data.token);
if (data.user) {
  setEnv('citizen_id', data.user.id);
  if (data.user.language) setEnv('app_language', data.user.language);
}
"""

CAPTURE_EMPLOYEE_LOGIN = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
setEnv('employee_token', data.token);
if (data.user) setEnv('employee_id', data.user.id);
"""

CAPTURE_ME = """
const j = safeJson();
if (!j || !j.success) return;
const user = j.data || {};
setEnv('citizen_id', user.id);
if (user.language) setEnv('app_language', user.language);
"""

CAPTURE_SERVICE_TYPES = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
const preferred = (pm.environment.get('service_type_code') || 'new_license');
const hit = pickByCode(arr, [preferred, 'new_license']);
if (hit) {
  setEnv('service_type_id', hit.id);
  setEnv('service_type_code', hit.code);
}
"""

CAPTURE_LICENSE_TYPES = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
const preferred = (pm.environment.get('license_type_code') || 'private');
const hit = pickByCode(arr, [preferred, 'private']);
if (hit) {
  setEnv('license_type_id', hit.id);
  setEnv('license_type_code', hit.code);
}
"""

CAPTURE_TEST_TYPES = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
const preferred = (pm.environment.get('test_type_code') || 'vision');
const hit = pickByCode(arr, [preferred, 'vision']);
if (hit) {
  setEnv('test_type_id', hit.id);
  setEnv('test_type_code', hit.code);
}
"""

CAPTURE_APPLICATION = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const app = data.id ? data : (Array.isArray(data.items) && data.items[0] ? data.items[0] : null);
if (app) {
  setEnv('application_id', app.id);
  if (app.application_number) setEnv('application_number', app.application_number);
  if (app.related_license && app.related_license.id) setEnv('related_license_id', app.related_license.id);
}
"""

CAPTURE_REQUIRED_DOCS = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
const missing = arr.find(d => d && d.is_required && !d.latest_document);
const hit = missing || arr[0];
if (hit) {
  setEnv('required_document_id', hit.id);
  if (hit.code) setEnv('required_document_code', hit.code);
}
"""

CAPTURE_UPLOADED_DOC = """
const j = safeJson();
if (!j || !j.success) return;
const doc = j.data || {};
setEnv('application_document_id', doc.id);
"""

CAPTURE_FEE = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
if (data.fee) {
  setEnv('fee_id', data.fee.id);
  setEnv('fee_code', data.fee.code);
}
"""

CAPTURE_PAYMENT = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const payment = data.payment || data;
setEnv('payment_id', payment.id);
if (data.checkout_url) setEnv('checkout_url', data.checkout_url);
"""

CAPTURE_AVAILABLE_TESTS = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const arr = firstArray(data.tests || data);
const bookable = arr.find(t => t && (t.can_book || t.is_available));
const hit = bookable || arr[0];
if (hit) {
  setEnv('test_type_id', hit.test_type_id || hit.id);
  setEnv('test_type_code', hit.code);
}
"""

CAPTURE_SLOTS = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
if (data.id) { setEnv('appointment_slot_id', data.id); return; }
const arr = firstArray(data);
if (arr[0]) setEnv('appointment_slot_id', arr[0].id);
"""

CAPTURE_APPOINTMENT = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const appt = data.id ? data : (Array.isArray(data) ? data[0] : (data.items && data.items[0]));
if (appt) setEnv('appointment_id', appt.id);
"""

CAPTURE_TEST_RESULTS = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
if (arr[0]) setEnv('test_result_id', arr[0].id);
"""

CAPTURE_LICENSES = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
const active = arr.find(l => l && (l.status === 'active' || l.effective_status === 'active'));
const hit = active || arr[0];
if (hit) {
  setEnv('license_id', hit.id);
  setEnv('related_license_id', hit.id);
  if (hit.license_number) setEnv('license_number', hit.license_number);
}
"""

CAPTURE_LICENSE_SHOW = """
const j = safeJson();
if (!j || !j.success) return;
const lic = j.data || {};
setEnv('license_id', lic.id);
if (lic.license_number) setEnv('license_number', lic.license_number);
"""

CAPTURE_FINES = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
if (arr[0]) setEnv('fine_id', arr[0].id);
"""

CAPTURE_NOTIFICATIONS = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
if (arr[0]) setEnv('notification_id', arr[0].id);
"""

CAPTURE_AI_MESSAGE = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
setEnv('ai_session_id', data.session_id);
if (data.pending_action && data.pending_action.id) setEnv('ai_action_id', data.pending_action.id);
const token =
  data.upload_token ||
  (data.ui_payload && data.ui_payload.upload_token) ||
  (data.document_flow && data.document_flow.upload_token) ||
  (data.ui_payload && data.ui_payload.document_flow && data.ui_payload.document_flow.upload_token);
if (token) setEnv('ai_upload_token', token);
"""

CAPTURE_AI_SESSIONS = """
const j = safeJson();
if (!j || !j.success) return;
const arr = firstArray(j.data);
if (arr[0]) setEnv('ai_session_id', arr[0].id);
"""

CAPTURE_RESET_TOKEN = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
if (data.reset_token) setEnv('reset_token', data.reset_token);
"""

CAPTURE_LANG_AR = """
const j = safeJson();
if (!j || !j.success) return;
pm.environment.set('app_language', 'ar');
"""

CAPTURE_LANG_EN = """
const j = safeJson();
if (!j || !j.success) return;
pm.environment.set('app_language', 'en');
"""

CAPTURE_PENDING_DOC_ADMIN = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const items = firstArray(data.items || data);
const hit = items[0];
if (hit) {
  setEnv('application_document_id', hit.id);
  if (hit.application_id) setEnv('application_id', hit.application_id);
  if (hit.application && hit.application.id) setEnv('application_id', hit.application.id);
}
"""

CAPTURE_PROFILE_REVIEW = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const items = firstArray(data.items || data);
const preferredId = pm.environment.get('citizen_id');
let hit = null;
if (preferredId) hit = items.find(x => String(x.id) === String(preferredId) || String(x.user_id) === String(preferredId));
if (!hit) hit = items[0];
if (hit) setEnv('profile_review_user_id', hit.id || hit.user_id);
"""

CAPTURE_DASH_DOC = """
const j = safeJson();
if (!j || !j.success) return;
const data = j.data || {};
const docs = data.documents || data.items || firstArray(data);
const pending = (Array.isArray(docs) ? docs : []).find(d => d && (d.status === 'pending_review' || !d.status));
const hit = pending || (Array.isArray(docs) ? docs[0] : null);
if (hit) setEnv('application_document_id', hit.id);
if (data.application && data.application.id) setEnv('application_id', data.application.id);
if (data.id && !data.documents) setEnv('application_id', data.id);
"""


# ---------------------------------------------------------------------------
# Folders
# ---------------------------------------------------------------------------

def build_start_here() -> dict:
    return folder(
        "00 - Start Here",
        [
            req(
                "1. Login Citizen",
                "POST",
                "/auth/login",
                hdrs=H_PUBLIC_JSON,
                body=body_json({"email": "{{citizen_email}}", "password": "{{citizen_password}}"}),
                description=desc(
                    "Logs in a seeded/approved citizen and returns Sanctum token.",
                    "App launch / Login screen.",
                    "citizen_email, citizen_password",
                    "citizen_token, citizen_id, app_language (from user)",
                    "Get My Profile",
                ),
                event=tests(CAPTURE_CITIZEN_LOGIN),
            ),
            req(
                "2. Get My Profile",
                "GET",
                "/auth/me",
                hdrs=H_CITIZEN,
                description=desc(
                    "Returns the authenticated citizen profile.",
                    "Home / Profile bootstrap.",
                    "citizen_token",
                    "citizen_id, app_language",
                ),
                event=tests(CAPTURE_ME),
            ),
            req(
                "3. Get App Settings",
                "GET",
                "/settings",
                hdrs=H_CITIZEN,
                description=desc(
                    "Returns citizen settings/preferences (language, theme, etc.).",
                    "Settings screen.",
                    "citizen_token",
                ),
                event=tests(),
            ),
            req(
                "4. Get Available Services",
                "GET",
                "/service-types",
                hdrs=H_PUBLIC,
                description=desc(
                    "Public catalog of active service types (new/renew/lost/damaged/unblock).",
                    "Start new service flow.",
                    "stores preferred code (default new_license)",
                    "service_type_id, service_type_code",
                ),
                event=tests(CAPTURE_SERVICE_TYPES),
            ),
            req(
                "5. Get License Types",
                "GET",
                "/license-types",
                hdrs=H_PUBLIC,
                description=desc(
                    "Public catalog of active license types (private/public/truck/bus).",
                    "License type picker for new_license.",
                    "",
                    "license_type_id, license_type_code",
                ),
                event=tests(CAPTURE_LICENSE_TYPES),
            ),
        ],
        "Run top → bottom after importing Collection + Environment. Uses seeded citizen@example.com by default.",
    )


def build_auth() -> dict:
    return folder(
        "01 - Authentication",
        [
            req(
                "Register Citizen",
                "POST",
                "/auth/register",
                hdrs=H_PUBLIC_JSON,
                body=body_json(
                    {
                        "name": "Flutter Tester",
                        "email": "{{register_email}}",
                        "phone": "{{register_phone}}",
                        "password": "{{citizen_password}}",
                        "password_confirmation": "{{citizen_password}}",
                    }
                ),
                description=desc(
                    "Creates a citizen account and sends email OTP.",
                    "Registration screen.",
                    "register_email (unique), password min 8",
                    "",
                    "Verify Email OTP",
                ),
                event=tests(),
            ),
            req(
                "Verify Email OTP",
                "POST",
                "/auth/verify-otp",
                hdrs=H_PUBLIC_JSON,
                body=body_json(
                    {
                        "email": "{{register_email}}",
                        "code": "{{otp_code}}",
                        "purpose": "register",
                    }
                ),
                description=desc(
                    "Verifies registration OTP (local: set OTP_FIXED_CODE e.g. 123456).",
                    "OTP screen after register.",
                    "register_email, otp_code",
                    "citizen_token, citizen_id",
                    "Complete Profile",
                ),
                event=tests(CAPTURE_CITIZEN_LOGIN),
            ),
            req(
                "Login Citizen",
                "POST",
                "/auth/login",
                hdrs=H_PUBLIC_JSON,
                body=body_json({"email": "{{citizen_email}}", "password": "{{citizen_password}}"}),
                description=desc(
                    "Login with email + password (identifier also accepted).",
                    "Login screen.",
                    "citizen_email, citizen_password",
                    "citizen_token, citizen_id",
                ),
                event=tests(CAPTURE_CITIZEN_LOGIN),
            ),
            req(
                "Login Citizen By Identifier",
                "POST",
                "/auth/login",
                hdrs=H_PUBLIC_JSON,
                body=body_json({"identifier": "{{citizen_email}}", "password": "{{citizen_password}}"}),
                description=desc(
                    "Same login using identifier field (email or phone depending on backend).",
                    "Login screen alternate payload.",
                ),
                event=tests(CAPTURE_CITIZEN_LOGIN),
            ),
            req(
                "Get Current User",
                "GET",
                "/auth/me",
                hdrs=H_CITIZEN,
                description=desc("Current authenticated user.", "Session restore.", "citizen_token", "citizen_id"),
                event=tests(CAPTURE_ME),
            ),
            req(
                "Forgot Password",
                "POST",
                "/auth/forgot-password",
                hdrs=H_PUBLIC_JSON,
                body=body_json({"email": "{{citizen_email}}"}),
                description=desc("Sends forgot-password OTP to email.", "Forgot password screen."),
                event=tests(),
            ),
            req(
                "Verify Forgot Password OTP",
                "POST",
                "/auth/verify-forgot-password-otp",
                hdrs=H_PUBLIC_JSON,
                body=body_json({"email": "{{citizen_email}}", "code": "{{otp_code}}"}),
                description=desc("Returns short-lived reset_token.", "", "otp_code", "reset_token", "Reset Password"),
                event=tests(CAPTURE_RESET_TOKEN),
            ),
            req(
                "Reset Password",
                "POST",
                "/auth/reset-password",
                hdrs=H_PUBLIC_JSON,
                body=body_json(
                    {
                        "email": "{{citizen_email}}",
                        "reset_token": "{{reset_token}}",
                        "password": "{{citizen_password}}",
                        "password_confirmation": "{{citizen_password}}",
                    }
                ),
                description=desc("Sets a new password using reset_token.", "Reset password screen."),
                event=tests(),
            ),
            req(
                "Logout Citizen",
                "POST",
                "/auth/logout",
                hdrs=H_CITIZEN,
                description=desc("Revokes current Sanctum token.", "Logout action.", "citizen_token"),
                event=tests(),
            ),
        ],
    )


def build_profile_settings() -> dict:
    return folder(
        "02 - Profile & Settings",
        [
            req(
                "Get My Profile",
                "GET",
                "/auth/me",
                hdrs=H_CITIZEN,
                description=desc("Full profile payload.", "Profile screen.", "citizen_token"),
                event=tests(CAPTURE_ME),
            ),
            req(
                "Get Profile Status",
                "GET",
                "/profile/status",
                hdrs=H_CITIZEN,
                description=desc(
                    "Profile completion/review status (incomplete/pending_review/approved/rejected).",
                    "Gate before creating applications.",
                    "citizen_token",
                ),
                event=tests(),
            ),
            req(
                "Complete Profile",
                "PUT",
                "/profile/complete",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "name": "Flutter Citizen",
                        "national_id": "{{national_id}}",
                        "birth_date": "1995-05-20",
                        "governorate": "Damascus",
                        "address": "Damascus - Syria",
                    }
                ),
                description=desc(
                    "Submits profile for employee review → profile_status=pending_review.",
                    "Complete-profile onboarding.",
                    "citizen_token; unique national_id",
                    "",
                    "Wait for employee approval before create-application APIs.",
                    "90 - Dashboard / Employee APIs → Profile Reviews → Approve Citizen Profile",
                ),
                event=tests(),
            ),
            req(
                "Update Profile",
                "PUT",
                "/profile/update",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "name": "Flutter Citizen Updated",
                        "governorate": "Damascus",
                        "address": "Updated address",
                    }
                ),
                description=desc("Partial profile update.", "Edit profile screen."),
                event=tests(),
            ),
            req(
                "Change Password (Profile)",
                "PUT",
                "/profile/change-password",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "current_password": "{{citizen_password}}",
                        "password": "{{citizen_password}}",
                        "password_confirmation": "{{citizen_password}}",
                    }
                ),
                description=desc("Change password via profile endpoint.", "Security settings."),
                event=tests(),
            ),
            req(
                "Get Settings",
                "GET",
                "/settings",
                hdrs=H_CITIZEN,
                description=desc("Citizen settings bundle.", "Settings home."),
                event=tests(),
            ),
            req(
                "Change Language to Arabic",
                "PUT",
                "/settings/preferences",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"language": "ar"}),
                description=desc(
                    "Persists users.language=ar and updates app_language env.",
                    "Language picker.",
                    "citizen_token",
                    "app_language=ar",
                ),
                event=tests(CAPTURE_LANG_AR),
            ),
            req(
                "Change Language to English",
                "PUT",
                "/settings/preferences",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"language": "en"}),
                description=desc(
                    "Persists users.language=en and updates app_language env.",
                    "Language picker.",
                    "citizen_token",
                    "app_language=en",
                ),
                event=tests(CAPTURE_LANG_EN),
            ),
            req(
                "Update Theme Preference",
                "PUT",
                "/settings/preferences",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"theme": "system"}),
                description=desc("Valid themes: light | dark | system.", "Appearance settings."),
                event=tests(),
            ),
            req(
                "Change Password (Settings)",
                "PUT",
                "/settings/change-password",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "current_password": "{{citizen_password}}",
                        "password": "{{citizen_password}}",
                        "password_confirmation": "{{citizen_password}}",
                    }
                ),
                description=desc("Change password via settings module.", "Settings → Security."),
                event=tests(),
            ),
        ],
    )


def build_app_content() -> dict:
    return folder(
        "03 - App Content",
        [
            req(
                "Get FAQs",
                "GET",
                "/content/faqs",
                hdrs=H_PUBLIC,
                description=desc("Localized FAQ list via Accept-Language.", "Help / FAQ screen."),
                event=tests(),
            ),
            req(
                "Get Privacy Policy",
                "GET",
                "/content/privacy-policy",
                hdrs=H_PUBLIC,
                description=desc("Localized privacy policy.", "Legal screen."),
                event=tests(),
            ),
            req(
                "Get Contact Info",
                "GET",
                "/content/contact-info",
                hdrs=H_PUBLIC,
                description=desc("Localized contact info.", "Contact screen."),
                event=tests(),
            ),
            req(
                "Send Contact Message",
                "POST",
                "/contact-messages",
                hdrs=H_PUBLIC_JSON,
                body=body_json(
                    {
                        "name": "Flutter User",
                        "email": "{{citizen_email}}",
                        "phone": "{{citizen_phone}}",
                        "subject": "Support question",
                        "message": "I need help with my license application.",
                    }
                ),
                description=desc("Public contact form (auth optional; auto-fills from user if logged in).", "Contact form."),
                event=tests(),
            ),
            req(
                "Get License Types",
                "GET",
                "/license-types",
                hdrs=H_PUBLIC,
                description=desc("Catalog for pickers.", "", "", "license_type_id, license_type_code"),
                event=tests(CAPTURE_LICENSE_TYPES),
            ),
            req(
                "Get Service Types",
                "GET",
                "/service-types",
                hdrs=H_PUBLIC,
                description=desc("Catalog for pickers.", "", "", "service_type_id, service_type_code"),
                event=tests(CAPTURE_SERVICE_TYPES),
            ),
            req(
                "Get Test Types",
                "GET",
                "/test-types",
                hdrs=H_PUBLIC,
                description=desc("Catalog (vision/theory/practical seeded).", "", "", "test_type_id, test_type_code"),
                event=tests(CAPTURE_TEST_TYPES),
            ),
        ],
    )


def build_applications() -> dict:
    return folder(
        "04 - Driving License Applications",
        [
            req(
                "Get My Applications",
                "GET",
                "/applications",
                hdrs=H_CITIZEN,
                query=[("per_page", "20")],
                description=desc("Paginated citizen applications (data.items).", "My applications list.", "citizen_token", "application_id"),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Create New License Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "service_type_code": "new_license",
                        "license_type_code": "{{license_type_code}}",
                    }
                ),
                description=desc(
                    "Creates a new_license application in draft.",
                    "After selecting service + license type.",
                    "citizen_token + profile_status=approved; service_type_code + license_type_code",
                    "application_id",
                    "Get Required Documents",
                    "Profile must be approved. Use service_type_id/license_type_id as alternatives.",
                ),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Create Renewal Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "service_type_code": "renew_license",
                        "related_license_id": "{{related_license_id}}",
                    }
                ),
                description=desc(
                    "Renewal application workflow (docs → payment → approved). related_license_id required.",
                    "Renew license via application flow (preferred for Flutter).",
                    "related_license_id from Get My Licenses",
                    "application_id",
                ),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Create Lost Replacement Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "service_type_code": "lost_replacement",
                        "related_license_id": "{{related_license_id}}",
                    }
                ),
                description=desc("Lost replacement application. related_license_id required.", "Lost license flow."),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Create Damaged Replacement Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "service_type_code": "damaged_replacement",
                        "related_license_id": "{{related_license_id}}",
                    }
                ),
                description=desc("Damaged replacement application. related_license_id required.", "Damaged license flow."),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Create Unblock Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "service_type_code": "license_unblock",
                        "related_license_id": "{{related_license_id}}",
                    }
                ),
                description=desc("Unblock application workflow. related_license_id required.", "Unblock request via application."),
                event=tests(CAPTURE_APPLICATION),
            ),
            req(
                "Get Application Details",
                "GET",
                "/applications/{{application_id}}",
                hdrs=H_CITIZEN,
                description=desc("Single application with status + types.", "Application details / status refresh.", "application_id"),
                event=tests(CAPTURE_APPLICATION),
            ),
        ],
    )


def build_documents() -> dict:
    return folder(
        "05 - Documents",
        [
            req(
                "Get Required Documents",
                "GET",
                "/applications/{{application_id}}/required-documents",
                hdrs=H_CITIZEN,
                description=desc(
                    "Checklist for this application (id/code + latest_document).",
                    "Upload documents screen.",
                    "application_id",
                    "required_document_id (prefers first missing required)",
                    "⚠ Upload Document — Select File",
                ),
                event=tests(CAPTURE_REQUIRED_DOCS),
            ),
            req(
                "Get Uploaded Documents",
                "GET",
                "/applications/{{application_id}}/documents",
                hdrs=H_CITIZEN,
                description=desc("Lists uploaded application documents.", "Documents progress UI."),
                event=tests(),
            ),
            req(
                "⚠ Upload Document — Select File",
                "POST",
                "/applications/{{application_id}}/documents",
                hdrs=H_CITIZEN,
                body=body_formdata(
                    [
                        {"key": "required_document_id", "value": "{{required_document_id}}", "type": "text"},
                        {
                            "key": "file",
                            "type": "file",
                            "src": [],
                            "description": "Select a local jpg/jpeg/png/pdf (max ~4–5 MB).",
                        },
                    ]
                ),
                description=desc(
                    "Multipart upload. Only manual step: choose a local file in Postman.",
                    "Document upload tile.",
                    "application_id, required_document_id, file",
                    "application_document_id",
                    "Repeat for each required doc → Submit Documents for Review",
                ),
                event=tests(CAPTURE_UPLOADED_DOC),
            ),
            req(
                "Submit Documents for Review",
                "POST",
                "/applications/{{application_id}}/submit-documents",
                hdrs=H_CITIZEN,
                description=desc(
                    "Moves application to documents_under_review when all required docs uploaded.",
                    "Submit for review CTA.",
                    "application_id + all required docs uploaded",
                    "",
                    "Refresh Application Status",
                    "Employee must approve documents before payment:\n90 - Dashboard / Employee APIs → Documents → Approve Document (Admin) or Approve Document (Dashboard)",
                ),
                event=tests(CAPTURE_APPLICATION),
            ),
        ],
    )


def build_payments() -> dict:
    return folder(
        "06 - Payments",
        [
            req(
                "Get Application Fee",
                "GET",
                "/applications/{{application_id}}/fee",
                hdrs=H_CITIZEN,
                description=desc(
                    "Fee for current application status (application_fee / renewal_fee / …).",
                    "Payment summary screen.",
                    "application_id; usually after documents approved → payment_pending",
                    "fee_id, fee_code",
                    "Create Payment",
                    "Documents must be approved first.",
                ),
                event=tests(CAPTURE_FEE),
            ),
            req(
                "Create Payment",
                "POST",
                "/applications/{{application_id}}/payments",
                hdrs=H_CITIZEN_JSON,
                body=body_json({}),
                description=desc(
                    "Creates pending payment. Stripe returns checkout_url; mock returns PaymentResource.",
                    "Pay now CTA.",
                    "application_id",
                    "payment_id (+ checkout_url if Stripe)",
                    "Confirm Mock Payment (local) OR open checkout_url (Stripe)",
                ),
                event=tests(CAPTURE_PAYMENT),
            ),
            req(
                "Confirm Mock Payment",
                "POST",
                "/applications/{{application_id}}/payments/{{payment_id}}/confirm",
                hdrs=H_CITIZEN,
                description=desc(
                    "Completes payment when PAYMENT_PROVIDER=mock. Disabled for Stripe.",
                    "Local/dev payment completion.",
                    "payment_id",
                    "",
                    "For new_license → appointment_pending; renew/lost/damaged/unblock → approved",
                ),
                event=tests(CAPTURE_PAYMENT),
            ),
            req(
                "Get Payment Status",
                "GET",
                "/applications/{{application_id}}/payments/{{payment_id}}/status",
                hdrs=H_CITIZEN,
                description=desc("Payment + application status snapshot.", "Payment status polling."),
                event=tests(),
            ),
            req(
                "Get Application Payments",
                "GET",
                "/applications/{{application_id}}/payments",
                hdrs=H_CITIZEN,
                description=desc("All payments for an application.", "Payment history."),
                event=tests(CAPTURE_PAYMENT),
            ),
        ],
    )


def build_tests_appointments() -> dict:
    return folder(
        "07 - Tests & Appointments",
        [
            req(
                "Get Available Tests",
                "GET",
                "/applications/{{application_id}}/available-tests",
                hdrs=H_CITIZEN,
                description=desc(
                    "Test progression for this application (can_book flags).",
                    "Tests hub after payment (new_license).",
                    "application_id; status appointment_pending/in_testing/waiting_retest",
                    "test_type_id, test_type_code (prefers bookable)",
                    "Get Available Slots",
                ),
                event=tests(CAPTURE_AVAILABLE_TESTS),
            ),
            req(
                "Get Available Slots",
                "GET",
                "/appointment-slots",
                hdrs=H_CITIZEN,
                query=[("test_type_id", "{{test_type_id}}")],
                description=desc(
                    "Open slots for a test_type_id (optional from_date/to_date).",
                    "Slot picker.",
                    "test_type_id",
                    "appointment_slot_id",
                    "Book Appointment",
                ),
                event=tests(CAPTURE_SLOTS),
            ),
            req(
                "Book Appointment",
                "POST",
                "/applications/{{application_id}}/appointments",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"appointment_slot_id": "{{appointment_slot_id}}"}),
                description=desc(
                    "Books a slot for the application’s current bookable test.",
                    "Confirm booking.",
                    "application_id, appointment_slot_id",
                    "appointment_id",
                    "Employee records result before next test.",
                    "90 - Dashboard / Employee APIs → Tests → Record Test Result (Passed)",
                ),
                event=tests(CAPTURE_APPOINTMENT),
            ),
            req(
                "Get My Appointments",
                "GET",
                "/applications/{{application_id}}/appointments",
                hdrs=H_CITIZEN,
                description=desc("Appointments for application.", "Appointments list."),
                event=tests(CAPTURE_APPOINTMENT),
            ),
            req(
                "Reschedule Appointment",
                "PUT",
                "/appointments/{{appointment_id}}/reschedule",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"appointment_slot_id": "{{appointment_slot_id}}"}),
                description=desc("Moves booked appointment to another slot.", "Reschedule screen."),
                event=tests(CAPTURE_APPOINTMENT),
            ),
            req(
                "Cancel Appointment",
                "DELETE",
                "/appointments/{{appointment_id}}/cancel",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"cancellation_reason": "Need a different time"}),
                description=desc("Cancels a booked appointment.", "Cancel booking."),
                event=tests(),
            ),
            req(
                "Get My Test Results",
                "GET",
                "/applications/{{application_id}}/test-results",
                hdrs=H_CITIZEN,
                description=desc("Test results for application.", "Results screen.", "", "test_result_id"),
                event=tests(CAPTURE_TEST_RESULTS),
            ),
        ],
    )


def build_licenses() -> dict:
    return folder(
        "08 - Licenses",
        [
            req(
                "Get My Licenses",
                "GET",
                "/licenses",
                hdrs=H_CITIZEN,
                description=desc(
                    "Citizen licenses with can_renew / replacement flags.",
                    "My licenses.",
                    "citizen_token",
                    "license_id, license_number, related_license_id",
                ),
                event=tests(CAPTURE_LICENSES),
            ),
            req(
                "Get License Details",
                "GET",
                "/licenses/{{license_id}}",
                hdrs=H_CITIZEN,
                description=desc("Single license details.", "License details card."),
                event=tests(CAPTURE_LICENSE_SHOW),
            ),
            req(
                "Verify License (Public)",
                "GET",
                "/licenses/verify/{{verification_token}}",
                hdrs=H_PUBLIC,
                description=desc(
                    "Public verification by token (QR).",
                    "Verify license / scanner.",
                    "verification_token (from QR / digital license; not returned on LicenseResource)",
                ),
                event=tests(),
            ),
            req(
                "Direct Renew License (Immediate)",
                "POST",
                "/licenses/{{license_id}}/renew",
                hdrs=H_CITIZEN,
                description=desc(
                    "IMMEDIATE license mutation (creates new Active license). Prefer Create Renewal Application for workflow fees/docs.",
                    "Quick renew shortcut if product uses Path B.",
                ),
                event=tests(CAPTURE_LICENSE_SHOW),
            ),
            req(
                "Direct Lost Replacement (Immediate)",
                "POST",
                "/licenses/{{license_id}}/replacement",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"type": "lost"}),
                description=desc("Immediate replacement type=lost|damaged. Prefer application workflow for Flutter.", "Quick replacement."),
                event=tests(CAPTURE_LICENSE_SHOW),
            ),
            req(
                "Request Unblock (Ack Only)",
                "POST",
                "/licenses/{{license_id}}/unblock-request",
                hdrs=H_CITIZEN,
                description=desc(
                    "Acknowledges unblock request only — does NOT unblock. Staff must unblock.",
                    "Unblock request CTA.",
                    "",
                    "",
                    "",
                    "Actual unblock: 90 - Dashboard → Licenses → Unblock License",
                ),
                event=tests(),
            ),
        ],
    )


def build_fines() -> dict:
    return folder(
        "09 - Fines",
        [
            req(
                "Get My Fines",
                "GET",
                "/fines",
                hdrs=H_CITIZEN,
                description=desc("Citizen fines list.", "Fines screen.", "", "fine_id"),
                event=tests(CAPTURE_FINES),
            ),
        ],
    )


def build_notifications() -> dict:
    return folder(
        "10 - Notifications",
        [
            req(
                "Get My Notifications",
                "GET",
                "/notifications",
                hdrs=H_CITIZEN,
                query=[("page", "1"), ("per_page", "20")],
                description=desc(
                    "Paginated notification inbox (data.items + pagination). Newest first. Max per_page=100. "
                    "Envelope message follows Accept-Language; historical title/body stay as stored text. No Firebase yet.",
                    "Open notification center.",
                    "citizen_token",
                    "notification_id",
                ),
                event=tests(CAPTURE_NOTIFICATIONS),
            ),
            req(
                "Get Unread Notifications",
                "GET",
                "/notifications",
                hdrs=H_CITIZEN,
                query=[("page", "1"), ("per_page", "20"), ("unread_only", "1")],
                description=desc(
                    "Same list endpoint with unread_only=1. Does NOT replace badge count — use unread-count.",
                    "Unread filter tab.",
                    "citizen_token",
                    "notification_id",
                ),
                event=tests(CAPTURE_NOTIFICATIONS),
            ),
            req(
                "Get Unread Notification Count",
                "GET",
                "/notifications/unread-count",
                hdrs=H_CITIZEN,
                description=desc(
                    "Lightweight badge count: data.unread_count (integer). One aggregate query; no list payload. "
                    "Accept-Language localizes envelope message only.",
                    "Bell badge / app bar.",
                    "citizen_token",
                ),
                event=tests(),
            ),
            req(
                "Mark Notification as Read",
                "PUT",
                "/notifications/{{notification_id}}/read",
                hdrs=H_CITIZEN,
                description=desc(
                    "Marks one owned notification read. Foreign id → 404. Already-read is idempotent.",
                    "Tap notification row.",
                    "citizen_token; notification_id",
                ),
                event=tests(),
            ),
            req(
                "Mark All Notifications as Read",
                "PUT",
                "/notifications/read-all",
                hdrs=H_CITIZEN,
                description=desc(
                    "Bulk marks all current unread notifications for the authenticated citizen. "
                    "Returns marked_read_count + unread_count. Idempotent (second call marked_read_count=0).",
                    "Mark all read button.",
                    "citizen_token",
                ),
                event=tests(),
            ),
        ],
    )


def build_ai_agent() -> dict:
    return folder(
        "11 - AI Agent",
        [
            req(
                "Send Agent Message",
                "POST",
                "/ai-agent/message",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"message": "أريد فتح طلب رخصة جديدة"}),
                description=desc(
                    "Starts/continues AI session. Add session_id only when continuing an existing chat.",
                    "AI chat composer.",
                    "citizen_token; AI_AGENT_ENABLED=true",
                    "ai_session_id, ai_action_id (if confirmation), ai_upload_token (if present)",
                    "Confirm/Cancel action or continue chatting",
                ),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "Continue Agent Conversation",
                "POST",
                "/ai-agent/message",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "message": "نعم",
                        "session_id": "{{ai_session_id}}",
                    }
                ),
                description=desc("Continue with existing session_id. Affirmative text may confirm pending actions.", "Chat thread."),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "List AI Sessions",
                "GET",
                "/ai-agent/sessions",
                hdrs=H_CITIZEN,
                description=desc("Past sessions.", "Chat history.", "", "ai_session_id"),
                event=tests(CAPTURE_AI_SESSIONS),
            ),
            req(
                "Get AI Session Details",
                "GET",
                "/ai-agent/sessions/{{ai_session_id}}",
                hdrs=H_CITIZEN,
                description=desc("Session with messages/actions.", "Open chat thread."),
                event=tests(),
            ),
            req(
                "Confirm Pending Action",
                "POST",
                "/ai-agent/actions/{{ai_action_id}}/confirm",
                hdrs=H_CITIZEN,
                description=desc("Confirms awaiting agent action.", "Confirm button when requires_confirmation.", "ai_action_id"),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "Cancel Pending Action",
                "POST",
                "/ai-agent/actions/{{ai_action_id}}/cancel",
                hdrs=H_CITIZEN,
                description=desc("Cancels awaiting agent action.", "Cancel confirmation."),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "Agent Interaction — Confirm Pending",
                "POST",
                "/ai-agent/sessions/{{ai_session_id}}/interactions",
                hdrs=H_CITIZEN_JSON,
                body=body_json({"action": "confirm_pending_action", "action_id": "{{ai_action_id}}"}),
                description=desc("Interaction API alternative for confirm.", "UI button handler."),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "Agent Interaction — Select Application",
                "POST",
                "/ai-agent/sessions/{{ai_session_id}}/interactions",
                hdrs=H_CITIZEN_JSON,
                body=body_json(
                    {
                        "action": "select_application",
                        "selection_token": "{{ai_selection_token}}",
                    }
                ),
                description=desc(
                    "Selection actions need selection_token from prior agent ui_payload.",
                    "Choice list tap.",
                    "ai_selection_token from previous agent response",
                ),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
            req(
                "⚠ Upload Agent Document — Select File",
                "POST",
                "/ai-agent/sessions/{{ai_session_id}}/documents",
                hdrs=H_CITIZEN,
                body=body_formdata(
                    [
                        {"key": "upload_token", "value": "{{ai_upload_token}}", "type": "text"},
                        {
                            "key": "file",
                            "type": "file",
                            "src": [],
                            "description": "Token mode (preferred). Select jpg/jpeg/png/pdf.",
                        },
                    ]
                ),
                description=desc(
                    "Conversational upload using upload_token from agent flow.",
                    "AI document upload step.",
                    "ai_session_id, ai_upload_token, file",
                ),
                event=tests(CAPTURE_AI_MESSAGE),
            ),
        ],
        "Requires AI_PROVIDER/GEMINI configured and AI_AGENT_ENABLED=true. Do not invent steps.",
    )


def build_public() -> dict:
    return folder(
        "12 - Public APIs",
        [
            req(
                "Health Check (Ping)",
                "GET",
                "/ping",
                hdrs=H_PUBLIC,
                description=desc("API health check.", "Connectivity probe."),
                event=tests(),
            ),
            req(
                "Get Service Types",
                "GET",
                "/service-types",
                hdrs=H_PUBLIC,
                event=tests(CAPTURE_SERVICE_TYPES),
            ),
            req(
                "Get License Types",
                "GET",
                "/license-types",
                hdrs=H_PUBLIC,
                event=tests(CAPTURE_LICENSE_TYPES),
            ),
            req(
                "Get Test Types",
                "GET",
                "/test-types",
                hdrs=H_PUBLIC,
                event=tests(CAPTURE_TEST_TYPES),
            ),
            req(
                "Get FAQs",
                "GET",
                "/content/faqs",
                hdrs=H_PUBLIC,
                event=tests(),
            ),
            req(
                "Get Privacy Policy",
                "GET",
                "/content/privacy-policy",
                hdrs=H_PUBLIC,
                event=tests(),
            ),
            req(
                "Get Contact Info",
                "GET",
                "/content/contact-info",
                hdrs=H_PUBLIC,
                event=tests(),
            ),
            req(
                "Verify License",
                "GET",
                "/licenses/verify/{{verification_token}}",
                hdrs=H_PUBLIC,
                description=desc("Public license verification.", "Requires verification_token."),
                event=tests(),
            ),
        ],
    )


def flow_req(name: str, method: str, path: str, **kwargs) -> dict:
    """Alias for flow requests — identical helpers."""
    return req(name, method, path, **kwargs)


def build_full_flows() -> dict:
    return folder(
        "20 - Full Citizen Flows",
        [
            folder(
                "Flow 1 — Login & Setup",
                [
                    flow_req(
                        "01 Login Citizen",
                        "POST",
                        "/auth/login",
                        hdrs=H_PUBLIC_JSON,
                        body=body_json({"email": "{{citizen_email}}", "password": "{{citizen_password}}"}),
                        description=desc("Start here.", "", "", "citizen_token"),
                        event=tests(CAPTURE_CITIZEN_LOGIN),
                    ),
                    flow_req("02 Get My Profile", "GET", "/auth/me", hdrs=H_CITIZEN, event=tests(CAPTURE_ME)),
                    flow_req("03 Get Settings", "GET", "/settings", hdrs=H_CITIZEN, event=tests()),
                    flow_req(
                        "04 Change Language to English",
                        "PUT",
                        "/settings/preferences",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({"language": "en"}),
                        event=tests(CAPTURE_LANG_EN),
                    ),
                    flow_req("05 Get FAQs", "GET", "/content/faqs", hdrs=H_PUBLIC, event=tests()),
                    flow_req(
                        "06 Change Language to Arabic",
                        "PUT",
                        "/settings/preferences",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({"language": "ar"}),
                        event=tests(CAPTURE_LANG_AR),
                    ),
                ],
            ),
            folder(
                "Flow 2 — New License Application",
                [
                    flow_req(
                        "01 Get Service Types",
                        "GET",
                        "/service-types",
                        hdrs=H_PUBLIC,
                        event=tests(CAPTURE_SERVICE_TYPES),
                    ),
                    flow_req(
                        "02 Get License Types",
                        "GET",
                        "/license-types",
                        hdrs=H_PUBLIC,
                        event=tests(CAPTURE_LICENSE_TYPES),
                    ),
                    flow_req(
                        "03 Create Application",
                        "POST",
                        "/applications",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json(
                            {
                                "service_type_code": "new_license",
                                "license_type_code": "{{license_type_code}}",
                            }
                        ),
                        description=desc(
                            "Requires profile_status=approved.",
                            "",
                            "",
                            "application_id",
                            "",
                            "If 403: Complete Profile then employee Approve Citizen Profile",
                        ),
                        event=tests(CAPTURE_APPLICATION),
                    ),
                    flow_req(
                        "04 Get Application Details",
                        "GET",
                        "/applications/{{application_id}}",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_APPLICATION),
                    ),
                    flow_req(
                        "05 Get Required Documents",
                        "GET",
                        "/applications/{{application_id}}/required-documents",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_REQUIRED_DOCS),
                    ),
                    flow_req(
                        "06 ⚠ Upload Documents — Select File",
                        "POST",
                        "/applications/{{application_id}}/documents",
                        hdrs=H_CITIZEN,
                        body=body_formdata(
                            [
                                {"key": "required_document_id", "value": "{{required_document_id}}", "type": "text"},
                                {"key": "file", "type": "file", "src": []},
                            ]
                        ),
                        description="Repeat: Get Required Documents → Upload until all required docs have latest_document.",
                        event=tests(CAPTURE_UPLOADED_DOC),
                    ),
                    flow_req(
                        "07 Submit Documents for Review",
                        "POST",
                        "/applications/{{application_id}}/submit-documents",
                        hdrs=H_CITIZEN,
                        description=desc(
                            "Citizen submits documents.",
                            "",
                            "",
                            "",
                            "Refresh after employee approval",
                            "STOP → Run employee Approve Document requests under folder 90, then continue Flow 3",
                        ),
                        event=tests(CAPTURE_APPLICATION),
                    ),
                    flow_req(
                        "08 Refresh Application Status",
                        "GET",
                        "/applications/{{application_id}}",
                        hdrs=H_CITIZEN,
                        description="Expect payment_pending after all docs approved.",
                        event=tests(CAPTURE_APPLICATION),
                    ),
                ],
                "Citizen path. Between 07 and payment: employee must approve each document.",
            ),
            folder(
                "Flow 3 — Payment",
                [
                    flow_req(
                        "01 Get Application Fee",
                        "GET",
                        "/applications/{{application_id}}/fee",
                        hdrs=H_CITIZEN,
                        description="Prerequisite: application_status=payment_pending",
                        event=tests(CAPTURE_FEE),
                    ),
                    flow_req(
                        "02 Create Payment",
                        "POST",
                        "/applications/{{application_id}}/payments",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({}),
                        event=tests(CAPTURE_PAYMENT),
                    ),
                    flow_req(
                        "03 Confirm Mock Payment",
                        "POST",
                        "/applications/{{application_id}}/payments/{{payment_id}}/confirm",
                        hdrs=H_CITIZEN,
                        description="Local mock only. Stripe: complete checkout_url instead.",
                        event=tests(CAPTURE_PAYMENT),
                    ),
                    flow_req(
                        "04 Refresh Application Status",
                        "GET",
                        "/applications/{{application_id}}",
                        hdrs=H_CITIZEN,
                        description="new_license → appointment_pending; other services → approved",
                        event=tests(CAPTURE_APPLICATION),
                    ),
                ],
            ),
            folder(
                "Flow 4 — Tests",
                [
                    flow_req(
                        "01 Get Available Tests",
                        "GET",
                        "/applications/{{application_id}}/available-tests",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_AVAILABLE_TESTS),
                    ),
                    flow_req(
                        "02 Get Available Slots",
                        "GET",
                        "/appointment-slots",
                        hdrs=H_CITIZEN,
                        query=[("test_type_id", "{{test_type_id}}")],
                        event=tests(CAPTURE_SLOTS),
                    ),
                    flow_req(
                        "03 Book Appointment",
                        "POST",
                        "/applications/{{application_id}}/appointments",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({"appointment_slot_id": "{{appointment_slot_id}}"}),
                        description=desc(
                            "",
                            "",
                            "",
                            "appointment_id",
                            "",
                            "STOP → 90 Dashboard → Tests → Record Test Result (Passed), then continue to next test",
                        ),
                        event=tests(CAPTURE_APPOINTMENT),
                    ),
                    flow_req(
                        "04 Get My Appointments",
                        "GET",
                        "/applications/{{application_id}}/appointments",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_APPOINTMENT),
                    ),
                    flow_req(
                        "05 Get Test Results",
                        "GET",
                        "/applications/{{application_id}}/test-results",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_TEST_RESULTS),
                    ),
                    flow_req(
                        "06 Continue to Next Test",
                        "GET",
                        "/applications/{{application_id}}/available-tests",
                        hdrs=H_CITIZEN,
                        description="After examiner records pass, book next bookable test (theory/practical).",
                        event=tests(CAPTURE_AVAILABLE_TESTS),
                    ),
                ],
                "new_license only. After all tests passed + approved, employee issues license.",
            ),
            folder(
                "Flow 5 — License",
                [
                    flow_req(
                        "01 Get My Licenses",
                        "GET",
                        "/licenses",
                        hdrs=H_CITIZEN,
                        description=desc(
                            "",
                            "",
                            "",
                            "",
                            "",
                            "If empty: employee must Issue License (folder 90) when application approved",
                        ),
                        event=tests(CAPTURE_LICENSES),
                    ),
                    flow_req(
                        "02 Get License Details",
                        "GET",
                        "/licenses/{{license_id}}",
                        hdrs=H_CITIZEN,
                        event=tests(CAPTURE_LICENSE_SHOW),
                    ),
                    flow_req(
                        "03 Verify License (Public)",
                        "GET",
                        "/licenses/verify/{{verification_token}}",
                        hdrs=H_PUBLIC,
                        description="Set verification_token manually from QR/digital license.",
                        event=tests(),
                    ),
                    flow_req(
                        "04 Create Renewal Application",
                        "POST",
                        "/applications",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json(
                            {
                                "service_type_code": "renew_license",
                                "related_license_id": "{{related_license_id}}",
                            }
                        ),
                        description="Application-based renewal (docs+fee). Preferred Flutter path.",
                        event=tests(CAPTURE_APPLICATION),
                    ),
                ],
            ),
            folder(
                "Flow 6 — Notifications",
                [
                    flow_req(
                        "01 Get Notifications",
                        "GET",
                        "/notifications",
                        hdrs=H_CITIZEN,
                        query=[("page", "1"), ("per_page", "20")],
                        event=tests(CAPTURE_NOTIFICATIONS),
                    ),
                    flow_req(
                        "02 Get Unread Notification Count",
                        "GET",
                        "/notifications/unread-count",
                        hdrs=H_CITIZEN,
                        event=tests(),
                    ),
                    flow_req(
                        "03 Mark Notification as Read",
                        "PUT",
                        "/notifications/{{notification_id}}/read",
                        hdrs=H_CITIZEN,
                        event=tests(),
                    ),
                    flow_req(
                        "04 Mark All Notifications as Read",
                        "PUT",
                        "/notifications/read-all",
                        hdrs=H_CITIZEN,
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Flow 7 — AI Agent",
                [
                    flow_req(
                        "01 Login Citizen",
                        "POST",
                        "/auth/login",
                        hdrs=H_PUBLIC_JSON,
                        body=body_json({"email": "{{citizen_email}}", "password": "{{citizen_password}}"}),
                        event=tests(CAPTURE_CITIZEN_LOGIN),
                    ),
                    flow_req(
                        "02 Send Agent Message",
                        "POST",
                        "/ai-agent/message",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({"message": "ما هي الخطوات لطلب رخصة جديدة؟"}),
                        event=tests(CAPTURE_AI_MESSAGE),
                    ),
                    flow_req(
                        "03 Continue Conversation",
                        "POST",
                        "/ai-agent/message",
                        hdrs=H_CITIZEN_JSON,
                        body=body_json({"message": "أريد فتح طلب", "session_id": "{{ai_session_id}}"}),
                        event=tests(CAPTURE_AI_MESSAGE),
                    ),
                    flow_req(
                        "04 Confirm Pending Action (if any)",
                        "POST",
                        "/ai-agent/actions/{{ai_action_id}}/confirm",
                        hdrs=H_CITIZEN,
                        description="Skip if no pending_action was returned.",
                        event=tests(CAPTURE_AI_MESSAGE),
                    ),
                    flow_req(
                        "05 Get Session Details",
                        "GET",
                        "/ai-agent/sessions/{{ai_session_id}}",
                        hdrs=H_CITIZEN,
                        event=tests(),
                    ),
                ],
            ),
        ],
        "Run folders top → bottom. Yellow STOP notes mean an employee action is required between citizen steps.",
    )


def build_dashboard() -> dict:
    return folder(
        "90 - Dashboard / Employee APIs",
        [
            folder(
                "Auth — Role Logins",
                [
                    req(
                        "Login Document Reviewer",
                        "POST",
                        "/dashboard/auth/login",
                        hdrs=H_DASH_PUBLIC_JSON,
                        body=body_json(
                            {
                                "email": "{{reviewer_email}}",
                                "password": "{{employee_password}}",
                            }
                        ),
                        description=desc("profile_document_reviewer — profiles + documents.", "", "", "employee_token"),
                        event=tests(CAPTURE_EMPLOYEE_LOGIN),
                    ),
                    req(
                        "Login Examiner",
                        "POST",
                        "/dashboard/auth/login",
                        hdrs=H_DASH_PUBLIC_JSON,
                        body=body_json({"email": "{{examiner_email}}", "password": "{{employee_password}}"}),
                        description=desc("test_employee — record test results.", "", "", "employee_token"),
                        event=tests(CAPTURE_EMPLOYEE_LOGIN),
                    ),
                    req(
                        "Login Payment Employee",
                        "POST",
                        "/dashboard/auth/login",
                        hdrs=H_DASH_PUBLIC_JSON,
                        body=body_json({"email": "{{payment_employee_email}}", "password": "{{employee_password}}"}),
                        event=tests(CAPTURE_EMPLOYEE_LOGIN),
                    ),
                    req(
                        "Login License Employee",
                        "POST",
                        "/dashboard/auth/login",
                        hdrs=H_DASH_PUBLIC_JSON,
                        body=body_json(
                            {"email": "{{license_employee_email}}", "password": "{{employee_password}}"}
                        ),
                        event=tests(CAPTURE_EMPLOYEE_LOGIN),
                    ),
                    req(
                        "Login Admin / Super Admin",
                        "POST",
                        "/dashboard/auth/login",
                        hdrs=H_DASH_PUBLIC_JSON,
                        body=body_json({"email": "{{admin_email}}", "password": "{{admin_password}}"}),
                        description="superadmin@syrtak.gov.sy / password123 by default",
                        event=tests(CAPTURE_EMPLOYEE_LOGIN),
                    ),
                    req("Get Employee Me", "GET", "/dashboard/auth/me", hdrs=H_DASH, event=tests()),
                    req("Employee Session Heartbeat", "POST", "/dashboard/session/heartbeat", hdrs=H_DASH, event=tests()),
                    req("Logout Employee", "POST", "/dashboard/auth/logout", hdrs=H_DASH, event=tests()),
                ],
                "Each login overwrites employee_token. Use the role needed for the next action.",
            ),
            folder(
                "Profile Reviews",
                [
                    req(
                        "List Pending Profile Reviews",
                        "GET",
                        "/admin/profile-reviews",
                        hdrs=H_DASH,
                        description="permission: review_profiles",
                        event=tests(CAPTURE_PROFILE_REVIEW),
                    ),
                    req(
                        "Approve Citizen Profile",
                        "POST",
                        "/admin/profile-reviews/{{profile_review_user_id}}/approve",
                        hdrs=H_DASH,
                        description=desc(
                            "Approves citizen so profile.approved middleware passes.",
                            "Needed before Create Application.",
                            "profile_review_user_id (defaults toward citizen_id)",
                        ),
                        event=tests(),
                    ),
                    req(
                        "Reject Citizen Profile",
                        "POST",
                        "/admin/profile-reviews/{{profile_review_user_id}}/reject",
                        hdrs=H_DASH_JSON,
                        body=body_json({"rejection_reason": "Incomplete national ID information."}),
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Documents",
                [
                    req(
                        "List Pending Documents (Admin)",
                        "GET",
                        "/admin/documents/pending-review",
                        hdrs=H_DASH,
                        query=[("per_page", "20")],
                        description="permission: review_documents",
                        event=tests(CAPTURE_PENDING_DOC_ADMIN),
                    ),
                    req(
                        "Approve Document (Admin)",
                        "POST",
                        "/admin/documents/{{application_document_id}}/approve",
                        hdrs=H_DASH,
                        description="Approve one uploaded document. Repeat until application → payment_pending.",
                        event=tests(),
                    ),
                    req(
                        "Reject Document (Admin)",
                        "POST",
                        "/admin/documents/{{application_document_id}}/reject",
                        hdrs=H_DASH_JSON,
                        body=body_json({"rejection_reason": "Document is unclear. Please upload a clearer scan."}),
                        event=tests(),
                    ),
                    req(
                        "List Document Reviews (Dashboard)",
                        "GET",
                        "/dashboard/document-reviews",
                        hdrs=H_DASH,
                        event=tests(),
                    ),
                    req(
                        "Show Document Review Application",
                        "GET",
                        "/dashboard/document-reviews/{{application_id}}",
                        hdrs=H_DASH,
                        event=tests(CAPTURE_DASH_DOC),
                    ),
                    req(
                        "Approve Document (Dashboard)",
                        "POST",
                        "/dashboard/document-reviews/documents/{{application_document_id}}/approve",
                        hdrs=H_DASH,
                        event=tests(),
                    ),
                    req(
                        "Reject Document (Dashboard)",
                        "POST",
                        "/dashboard/document-reviews/documents/{{application_document_id}}/reject",
                        hdrs=H_DASH_JSON,
                        body=body_json(
                            {
                                "rejection_reason_code": "unclear_document",
                                "rejection_details": None,
                            }
                        ),
                        description="Codes: unclear_document|wrong_document|expired_document|incomplete_document|other (+ details if other)",
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Tests",
                [
                    req(
                        "Record Test Result (Passed)",
                        "POST",
                        "/admin/test-appointments/{{appointment_id}}/record-result",
                        hdrs=H_DASH_JSON,
                        body=body_json({"result": "passed", "notes": "Citizen met requirements."}),
                        description=desc(
                            "permission: record_test_result. result ∈ passed|failed|no_show",
                            "After citizen books appointment.",
                            "appointment_id",
                        ),
                        event=tests(),
                    ),
                    req(
                        "Record Test Result (Failed)",
                        "POST",
                        "/admin/test-appointments/{{appointment_id}}/record-result",
                        hdrs=H_DASH_JSON,
                        body=body_json({"result": "failed", "notes": "Needs more practice."}),
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Licenses",
                [
                    req(
                        "Issue License",
                        "POST",
                        "/admin/applications/{{application_id}}/issue-license",
                        hdrs=H_DASH,
                        description=desc(
                            "permission: issue_license. Application must be approved.",
                            "After all tests / payment workflows complete.",
                            "application_id",
                        ),
                        event=tests(),
                    ),
                    req(
                        "Block License (Admin)",
                        "POST",
                        "/admin/licenses/{{license_id}}/block",
                        hdrs=H_DASH_JSON,
                        body=body_json({"reason": "Under investigation"}),
                        event=tests(),
                    ),
                    req(
                        "Unblock License (Admin)",
                        "POST",
                        "/admin/licenses/{{license_id}}/unblock",
                        hdrs=H_DASH,
                        event=tests(),
                    ),
                    req("List Issued Licenses", "GET", "/dashboard/licenses", hdrs=H_DASH, event=tests()),
                    req(
                        "Block License (Dashboard)",
                        "POST",
                        "/dashboard/licenses/{{license_id}}/block",
                        hdrs=H_DASH_JSON,
                        body=body_json({"reason": "Under investigation"}),
                        event=tests(),
                    ),
                    req(
                        "Unblock License (Dashboard)",
                        "POST",
                        "/dashboard/licenses/{{license_id}}/unblock",
                        hdrs=H_DASH,
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Fines",
                [
                    req("List Fines (Admin)", "GET", "/admin/fines", hdrs=H_DASH, event=tests(CAPTURE_FINES)),
                    req(
                        "Create Fine",
                        "POST",
                        "/admin/fines",
                        hdrs=H_DASH_JSON,
                        body=body_json(
                            {
                                "citizen_id": "{{citizen_id}}",
                                "amount": 10000,
                                "reason": "Traffic violation",
                                "license_id": "{{license_id}}",
                            }
                        ),
                        event=tests(CAPTURE_FINES),
                    ),
                    req(
                        "Update Fine Status",
                        "PUT",
                        "/admin/fines/{{fine_id}}",
                        hdrs=H_DASH_JSON,
                        body=body_json({"status": "paid"}),
                        description="status ∈ unpaid|paid|cancelled",
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Payments (Dashboard)",
                [
                    req("List Payments", "GET", "/dashboard/payments", hdrs=H_DASH, event=tests()),
                    req("Payment Stats", "GET", "/dashboard/payments/stats", hdrs=H_DASH, event=tests()),
                    req(
                        "Verify Payment",
                        "POST",
                        "/dashboard/payments/{{payment_id}}/verify",
                        hdrs=H_DASH,
                        description="permission: manage_payments",
                        event=tests(),
                    ),
                ],
            ),
            folder(
                "Applications & Overview",
                [
                    req("Dashboard Overview", "GET", "/dashboard/overview", hdrs=H_DASH, event=tests()),
                    req("List Applications", "GET", "/dashboard/applications", hdrs=H_DASH, event=tests()),
                    req(
                        "Get Application By Number",
                        "GET",
                        "/dashboard/applications/{{application_number}}",
                        hdrs=H_DASH,
                        event=tests(),
                    ),
                    req("Reports Overview (Admin)", "GET", "/admin/reports/overview", hdrs=H_DASH, event=tests()),
                ],
            ),
            folder(
                "Appointment Slots (Manage)",
                [
                    req("List Appointment Slots", "GET", "/dashboard/appointment-slots", hdrs=H_DASH, event=tests()),
                    req("Slot Options", "GET", "/dashboard/appointment-slots/options", hdrs=H_DASH, event=tests()),
                    req(
                        "Create Appointment Slot",
                        "POST",
                        "/dashboard/appointment-slots",
                        hdrs=H_DASH_JSON,
                        body=body_json(
                            {
                                "test_type_id": "{{test_type_id}}",
                                "date": "2026-09-01",
                                "start_time": "09:00",
                                "end_time": "09:30",
                                "capacity": 5,
                                "location": "Damascus Test Center",
                            }
                        ),
                        description="Use a future date. Fields match StoreDashboardAppointmentSlotRequest.",
                        event=tests(CAPTURE_SLOTS),
                    ),
                ],
            ),
        ],
        "Separated from Flutter citizen APIs. Login with the role that has the required permission.",
    )


def build_reference() -> dict:
    return folder(
        "99 - Technical / Reference",
        [
            req(
                "Unauthorized Example",
                "GET",
                "/auth/me",
                hdrs=headers(("Accept", "application/json"), ("Accept-Language", "{{app_language}}")),
                description="Expect 401 without Authorization header.",
                event=[
                    {
                        "listen": "test",
                        "script": {
                            "type": "text/javascript",
                            "exec": [
                                "pm.test('Unauthorized', function () { pm.expect(pm.response.code).to.eql(401); });"
                            ],
                        },
                    }
                ],
            ),
            req(
                "Validation Error Example — Create Application",
                "POST",
                "/applications",
                hdrs=H_CITIZEN_JSON,
                body=body_json({}),
                description="Missing service_type → validation error (422).",
                event=[
                    {
                        "listen": "test",
                        "script": {
                            "type": "text/javascript",
                            "exec": [
                                "pm.test('Validation status', function () { pm.expect(pm.response.code).to.be.oneOf([401, 403, 422]); });"
                            ],
                        },
                    }
                ],
            ),
            req(
                "Unsupported Language Header",
                "GET",
                "/content/faqs",
                hdrs=headers(("Accept", "application/json"), ("Accept-Language", "fr")),
                description="Unsupported locale falls back to default (ar).",
                event=tests(),
            ),
            req(
                "Stripe Webhook (Technical)",
                "POST",
                "/webhooks/stripe",
                hdrs=headers(("Accept", "application/json"), ("Content-Type", "application/json")),
                body=body_json({"type": "checkout.session.completed", "data": {"object": {}}}),
                description="Not for Flutter. Requires valid Stripe signature in real use.",
                event=[],
            ),
            folder(
                "Service Codes Reference",
                [
                    req(
                        "Note — Valid Codes",
                        "GET",
                        "/ping",
                        hdrs=H_PUBLIC,
                        description=(
                            "service_type_code: new_license | renew_license | lost_replacement | damaged_replacement | license_unblock\\n"
                            "license_type_code: private | public | truck | bus\\n"
                            "test_type_code: vision | theory | practical\\n"
                            "languages: ar | en\\n"
                            "application statuses include: draft, documents_under_review, payment_pending, appointment_pending, in_testing, approved, license_issued, ..."
                        ),
                        event=tests(),
                    )
                ],
            ),
        ],
        "Edge cases / negatives. Flutter team can ignore during normal integration.",
    )


def build_collection() -> dict:
    return {
        "info": {
            "_postman_id": str(uuid.uuid4()),
            "name": "SYRTAK Flutter API",
            "description": (
                "Production-quality Postman kit for the Flutter team integrating with SYRTAK / DLMS.\n\n"
                "How to use:\n"
                "1. Import this Collection + SYRTAK_Local environment\n"
                "2. Select environment SYRTAK Local\n"
                "3. Run 00 - Start Here top → bottom\n"
                "4. Follow 20 - Full Citizen Flows for end-to-end paths\n"
                "5. Toggle app_language = ar|en for localization\n\n"
                "Citizen APIs use Accept-Language + Bearer {{citizen_token}}.\n"
                "Dashboard APIs are under 90 and use {{employee_token}} (no locale header).\n"
                "Tokens/IDs are auto-captured by test scripts."
            ),
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "variable": [
            {"key": "base_url", "value": "http://127.0.0.1:8000"},
            {"key": "app_language", "value": "ar"},
        ],
        "item": [
            build_start_here(),
            build_auth(),
            build_profile_settings(),
            build_app_content(),
            build_applications(),
            build_documents(),
            build_payments(),
            build_tests_appointments(),
            build_licenses(),
            build_fines(),
            build_notifications(),
            build_ai_agent(),
            build_public(),
            build_full_flows(),
            build_dashboard(),
            build_reference(),
        ],
    }


def build_environment() -> dict:
    values = [
        ("base_url", "http://127.0.0.1:8000", True),
        ("app_language", "ar", True),
        ("otp_code", "123456", True),
        ("citizen_email", "citizen@example.com", True),
        ("citizen_password", "password", True),
        ("citizen_phone", "0977777777", True),
        ("citizen_token", "", True),
        ("citizen_id", "", True),
        ("register_email", "flutter.citizen.new@example.com", True),
        ("register_phone", "0977123456", True),
        ("national_id", "11001100999", True),
        ("reset_token", "", True),
        ("employee_email", "employee@example.com", True),
        ("employee_password", "password123", True),
        ("employee_token", "", True),
        ("employee_id", "", True),
        ("reviewer_email", "profile_document_reviewer@syrtak.gov.sy", True),
        ("examiner_email", "test.employee@syrtak.gov.sy", True),
        ("payment_employee_email", "payment.employee@syrtak.gov.sy", True),
        ("license_employee_email", "license.employee@syrtak.gov.sy", True),
        ("admin_email", "superadmin@syrtak.gov.sy", True),
        ("admin_password", "password123", True),
        ("service_type_id", "", True),
        ("service_type_code", "new_license", True),
        ("license_type_id", "", True),
        ("license_type_code", "private", True),
        ("application_id", "", True),
        ("application_number", "", True),
        ("required_document_id", "", True),
        ("required_document_code", "", True),
        ("application_document_id", "", True),
        ("fee_id", "", True),
        ("fee_code", "", True),
        ("payment_id", "", True),
        ("checkout_url", "", True),
        ("test_type_id", "", True),
        ("test_type_code", "vision", True),
        ("appointment_slot_id", "", True),
        ("appointment_id", "", True),
        ("test_result_id", "", True),
        ("license_id", "", True),
        ("related_license_id", "", True),
        ("license_number", "", True),
        ("verification_token", "", True),
        ("fine_id", "", True),
        ("notification_id", "", True),
        ("ai_session_id", "", True),
        ("ai_action_id", "", True),
        ("ai_upload_token", "", True),
        ("ai_selection_token", "", True),
        ("profile_review_user_id", "", True),
    ]
    return {
        "id": str(uuid.uuid4()),
        "name": "SYRTAK Local",
        "values": [
            {"key": k, "value": v, "type": "default", "enabled": enabled} for k, v, enabled in values
        ],
        "_postman_variable_scope": "environment",
    }


def count_requests(items: list) -> int:
    n = 0
    for it in items:
        if "request" in it:
            n += 1
        if "item" in it:
            n += count_requests(it["item"])
    return n


def collect_urls(items: list, out: list) -> None:
    for it in items:
        if "request" in it:
            raw = it["request"].get("url", {})
            if isinstance(raw, dict):
                out.append(raw.get("raw", ""))
            else:
                out.append(str(raw))
        if "item" in it:
            collect_urls(it["item"], out)


def main() -> None:
    collection = build_collection()
    environment = build_environment()

    coll_path = OUT_DIR / "SYRTAK_Flutter_API.postman_collection.json"
    env_path = OUT_DIR / "SYRTAK_Local.postman_environment.json"

    coll_path.write_text(json.dumps(collection, ensure_ascii=False, indent=2), encoding="utf-8")
    env_path.write_text(json.dumps(environment, ensure_ascii=False, indent=2), encoding="utf-8")

    total = count_requests(collection["item"])
    urls: list[str] = []
    collect_urls(collection["item"], urls)
    bad = [u for u in urls if u and "{{base_url}}" not in u]
    bearer_hardcoded = []
    text = coll_path.read_text(encoding="utf-8")
    if "Bearer ey" in text or "Bearer 1|" in text:
        bearer_hardcoded.append("possible hardcoded token")

    env_keys = {v["key"] for v in environment["values"]}
    import re

    refs = set(re.findall(r"\{\{([a-zA-Z0-9_]+)\}\}", text))
    missing = sorted(refs - env_keys - {"base_url", "app_language"})  # collection vars ok

    print(json.dumps({
        "collection": str(coll_path),
        "environment": str(env_path),
        "total_requests": total,
        "urls_missing_base_url": bad,
        "hardcoded_bearer": bearer_hardcoded,
        "env_refs_missing": missing,
        "schema": collection["info"]["schema"],
    }, indent=2))


if __name__ == "__main__":
    main()
