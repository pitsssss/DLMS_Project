#!/usr/bin/env python3
"""Generate DLMS_Dashboard_Admin_Employee_Postman_Collection.json (Postman v2.1)."""
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

def test_script(lines):
    return [{"listen": "test", "script": {"type": "text/javascript", "exec": lines}}]

def req(name, method, path, *, auth=None, body=None, tests=None, query=None):
    header = headers_json(auth) if auth or (body and method in ("POST", "PUT", "PATCH")) else [
        {"key": "Accept", "value": "application/json", "type": "text"}
    ]
    if body is not None and method in ("POST", "PUT", "PATCH") and not any(h["key"] == "Content-Type" for h in header):
        header.insert(0, {"key": "Content-Type", "value": "application/json", "type": "text"})

    item = {
        "name": name,
        "request": {"method": method, "header": header, "url": url(path, query)},
    }
    if body is not None:
        item["request"]["body"] = {"mode": "raw", "raw": body}
    if tests:
        item["event"] = test_script(tests)
    return item

SAVE_SUPER = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.token) pm.collectionVariables.set('super_admin_token', j.data.token);",
    "} catch (e) {}",
]
SAVE_EMPLOYEE = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.token) pm.collectionVariables.set('employee_token', j.data.token);",
    "} catch (e) {}",
]
SAVE_EMPLOYEE_ID = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.id) pm.collectionVariables.set('employee_id', String(j.data.id));",
    "  if (j.data && j.data.user && j.data.user.id) pm.collectionVariables.set('employee_id', String(j.data.user.id));",
    "} catch (e) {}",
]
SAVE_ROLE_ID = [
    "try {",
    "  var j = pm.response.json();",
    "  if (j.data && j.data.items && j.data.items[0]) {",
    "    pm.collectionVariables.set('role_id', String(j.data.items[0].id));",
    "    pm.collectionVariables.set('role_name', j.data.items[0].name);",
    "  }",
    "} catch (e) {}",
]

LOGIN_BODY = lambda email_var: json.dumps(
    {"email": f"{{{{{email_var}}}}}", "password": "{{super_admin_password}}"},
    ensure_ascii=False,
)

EMPLOYEE_LOGIN = lambda email_var: json.dumps(
    {"email": f"{{{{{email_var}}}}}", "password": "{{employee_password}}"},
    ensure_ascii=False,
)

collection = {
    "info": {
        "_postman_id": "dlms-dashboard-admin-employee",
        "name": "DLMS Dashboard Admin & Employee API",
        "description": "SYRTAK / DLMS official dashboard authentication, RBAC, and employee management.",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "variable": [
        {"key": "base_url", "value": "http://127.0.0.1:8000/api"},
        {"key": "super_admin_email", "value": "superadmin@syrtak.gov.sy"},
        {"key": "super_admin_password", "value": "password123"},
        {"key": "super_admin_token", "value": ""},
        {"key": "employee_email", "value": "fines.employee@syrtak.gov.sy"},
        {"key": "employee_password", "value": "password123"},
        {"key": "employee_token", "value": ""},
        {"key": "profile_reviewer_email", "value": "profile_document_reviewer@syrtak.gov.sy"},
        {"key": "fines_employee_email", "value": "fines.employee@syrtak.gov.sy"},
        {"key": "audit_employee_email", "value": "audit.employee@syrtak.gov.sy"},
        {"key": "reports_employee_email", "value": "reports.employee@syrtak.gov.sy"},
        {"key": "settings_employee_email", "value": "settings.employee@syrtak.gov.sy"},
        {"key": "application_manager_email", "value": "application.manager@syrtak.gov.sy"},
        {"key": "test_employee_email", "value": "test.employee@syrtak.gov.sy"},
        {"key": "license_employee_email", "value": "license.employee@syrtak.gov.sy"},
        {"key": "payment_employee_email", "value": "payment.employee@syrtak.gov.sy"},
        {"key": "employee_id", "value": ""},
        {"key": "role_id", "value": ""},
        {"key": "role_name", "value": ""},
        {"key": "permission_id", "value": ""},
        {"key": "profile_review_user_id", "value": ""},
        {"key": "document_id", "value": ""},
        {"key": "application_id", "value": ""},
        {"key": "fine_id", "value": ""},
        {"key": "license_id", "value": ""},
    ],
    "item": [
        {
            "name": "00 - System",
            "item": [
                req("Ping", "GET", "/ping"),
            ],
        },
        {
            "name": "01 - Dashboard Auth",
            "item": [
                req(
                    "Dashboard login super admin",
                    "POST",
                    "/dashboard/auth/login",
                    body=LOGIN_BODY("super_admin_email"),
                    tests=SAVE_SUPER,
                ),
                req("Dashboard me", "GET", "/dashboard/auth/me", auth="super_admin_token"),
                req("Dashboard logout", "POST", "/dashboard/auth/logout", auth="super_admin_token"),
                req(
                    "Dashboard login super admin (restore token)",
                    "POST",
                    "/dashboard/auth/login",
                    body=LOGIN_BODY("super_admin_email"),
                    tests=SAVE_SUPER,
                ),
            ],
        },
        {
            "name": "02 - Forgot Password",
            "item": [
                req(
                    "Dashboard forgot password",
                    "POST",
                    "/dashboard/auth/forgot-password",
                    body=json.dumps({"email": "{{employee_email}}"}),
                ),
                req(
                    "Verify forgot password OTP",
                    "POST",
                    "/dashboard/auth/verify-forgot-password-otp",
                    body=json.dumps({"email": "{{employee_email}}", "code": "123456"}),
                ),
                req(
                    "Reset password",
                    "POST",
                    "/dashboard/auth/reset-password",
                    body=json.dumps({
                        "email": "{{employee_email}}",
                        "reset_token": "PASTE_RESET_TOKEN",
                        "password": "password123",
                        "password_confirmation": "password123",
                    }),
                ),
            ],
        },
        {
            "name": "03 - Super Admin",
            "item": [
                req("List employees", "GET", "/dashboard/employees", auth="super_admin_token"),
                req("List roles", "GET", "/dashboard/roles", auth="super_admin_token", tests=SAVE_ROLE_ID),
                req("List permissions", "GET", "/dashboard/permissions", auth="super_admin_token"),
                req("Audit logs", "GET", "/admin/audit-logs", auth="super_admin_token"),
                req("Reports overview", "GET", "/admin/reports/overview", auth="super_admin_token"),
            ],
        },
        {
            "name": "04 - Employee Management",
            "item": [
                req(
                    "Create employee",
                    "POST",
                    "/dashboard/employees",
                    auth="super_admin_token",
                    body=json.dumps({
                        "name": "موظف الغرامات",
                        "email": "new.fines@syrtak.gov.sy",
                        "password": "password123",
                        "password_confirmation": "password123",
                        "role": "fines_employee",
                    }, ensure_ascii=False),
                    tests=SAVE_EMPLOYEE_ID,
                ),
                req(
                    "Update employee",
                    "PUT",
                    "/dashboard/employees/{{employee_id}}",
                    auth="super_admin_token",
                    body=json.dumps({"name": "موظف محدث", "role": "fines_employee", "is_active": True}, ensure_ascii=False),
                ),
                req("Toggle active", "PATCH", "/dashboard/employees/{{employee_id}}/toggle-active", auth="super_admin_token"),
                req(
                    "Reset employee password",
                    "POST",
                    "/dashboard/employees/{{employee_id}}/reset-password",
                    auth="super_admin_token",
                    body=json.dumps({"password": "password123", "password_confirmation": "password123"}),
                ),
                req(
                    "Assign role",
                    "POST",
                    "/dashboard/employees/{{employee_id}}/assign-role",
                    auth="super_admin_token",
                    body=json.dumps({"role": "fines_employee"}),
                ),
            ],
        },
        {
            "name": "05 - Roles & Permissions",
            "item": [
                req("Get roles", "GET", "/dashboard/roles", auth="super_admin_token"),
                req("Get role by id", "GET", "/dashboard/roles/{{role_id}}", auth="super_admin_token"),
                req("Get permissions", "GET", "/dashboard/permissions", auth="super_admin_token"),
            ],
        },
        {
            "name": "06 - Profile Reviewer Flow",
            "item": [
                req(
                    "Login profile reviewer",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("profile_reviewer_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("List profile reviews", "GET", "/admin/profile-reviews", auth="employee_token"),
            ],
        },
        {
            "name": "07 - Document Reviewer Flow",
            "item": [
                req("Pending documents", "GET", "/admin/documents/pending-review", auth="employee_token"),
            ],
        },
        {
            "name": "08 - Fines Employee Flow",
            "item": [
                req(
                    "Login fines employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("fines_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("List fines", "GET", "/admin/fines", auth="employee_token"),
            ],
        },
        {
            "name": "09 - Audit Employee Flow",
            "item": [
                req(
                    "Login audit employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("audit_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("Audit logs", "GET", "/admin/audit-logs", auth="employee_token"),
            ],
        },
        {
            "name": "10 - Reports Employee Flow",
            "item": [
                req(
                    "Login reports employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("reports_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("Reports overview", "GET", "/admin/reports/overview", auth="employee_token"),
            ],
        },
        {
            "name": "11 - License Employee Flow",
            "item": [
                req(
                    "Login license employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("license_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
            ],
        },
        {
            "name": "12 - Payment Employee Flow",
            "item": [
                req(
                    "Login payment employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("payment_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
            ],
        },
        {
            "name": "13 - Negative Permission Tests",
            "item": [
                req(
                    "Citizen cannot login dashboard",
                    "POST",
                    "/dashboard/auth/login",
                    body=json.dumps({"email": "citizen@example.com", "password": "password"}),
                ),
                req(
                    "Login fines employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("fines_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("Fines employee cannot access audit logs", "GET", "/admin/audit-logs", auth="employee_token"),
                req(
                    "Login audit employee",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("audit_employee_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req(
                    "Audit employee cannot manage fines",
                    "POST",
                    "/admin/fines",
                    auth="employee_token",
                    body=json.dumps({"citizen_id": 1, "amount": 100, "reason": "test"}),
                ),
                req(
                    "Login profile reviewer",
                    "POST",
                    "/dashboard/auth/login",
                    body=EMPLOYEE_LOGIN("profile_reviewer_email"),
                    tests=SAVE_EMPLOYEE,
                ),
                req("Profile reviewer cannot manage employees", "GET", "/dashboard/employees", auth="employee_token"),
            ],
        },
    ],
}

out = Path(__file__).resolve().parents[1] / "DLMS_Dashboard_Admin_Employee_Postman_Collection.json"
out.write_text(json.dumps(collection, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"Wrote {out}")
