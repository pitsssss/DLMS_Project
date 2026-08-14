# Dashboard Test Result — Backend Implementation

Read-only dashboard queue for appointments awaiting a test result. **Record-result mutation is unchanged.**

## Endpoint contract

```
GET /api/dashboard/test-appointments
```

| Item | Value |
|---|---|
| Middleware | `auth:sanctum`, `dashboard`, `employee.session.track`, `permission:view_appointments,manage_appointments,record_test_result` (OR) |
| Record mutation | Still `POST /api/admin/test-appointments/{appointment}/record-result` + `permission:record_test_result` |
| Success | **200** |
| Message | `messages.tests.dashboard_list_retrieved` |

### Query filters

| Param | Default | Notes |
|---|---|---|
| `status` | `waiting_result` | Virtual: `booked` **and** no `test_result`. Also accepts `booked`, `completed`, `cancelled`, `no_show`. |
| `test_type_id` | — | `exists:test_types,id` |
| `test_type_code` | — | `exists:test_types,code` |
| `date` | — | Single business day (`Asia/Damascus`) on `scheduled_at` |
| `date_from` / `date_to` | — | Inclusive calendar range; ignored when `date` is set |
| `search` | — | `application_number` or citizen `name` |
| `page` / `per_page` | 1 / 20 | `per_page` 1–100 |

Default list matches overview KPI `tests_awaiting_result`: booked appointments with no result. Application status is **not** used to hide rows; it only drives `actions.can_record_result`.

### Item shape

```json
{
  "success": true,
  "message": "تم جلب مواعيد الاختبار بنجاح.",
  "data": {
    "items": [
      {
        "id": 20,
        "scheduled_at": "2026-08-14T11:00:00+00:00",
        "status": "booked",
        "status_label": "محجوز",
        "application": {
          "id": 10,
          "application_number": "APP-…",
          "status": { "value": "in_testing", "label": "قيد الاختبار" }
        },
        "citizen": { "id": 3, "name": "…" },
        "test_type": { "id": 1, "code": "vision", "name": "فحص النظر" },
        "previous_attempts_count": 0,
        "next_attempt_number": 1,
        "slot": {
          "id": 5,
          "date": "2026-08-14",
          "start_time": "09:00:00",
          "end_time": "11:00:00",
          "location": "المركز الرئيسي",
          "appointment_center": { "id": 1, "name": "…", "address": "…" }
        },
        "actions": {
          "can_record_result": true,
          "can_view_application": false
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Citizen payload is `id` + `name` only (same as slot bookings / overview upcoming appointments).

### `actions.can_record_result`

True only when all of:

1. Actor has `record_test_result`
2. Appointment `status === booked`
3. No existing test result
4. Application status is `in_testing` or `waiting_retest`

Same preconditions as `TestResultService::recordForAppointment()` via `isAppointmentRecordable()`.

`can_view_application` is true when the actor has `view_applications` or `manage_applications`. `test_employee` typically has this **false**.

`previous_attempts_count` = failed + no_show for that application/test type. `next_attempt_number` = all prior results + 1 (what record-result will store).

## Frontend integration

1. Auth: dashboard Sanctum token. Role `test_employee` or any user with `record_test_result` / `view_appointments` / `manage_appointments`.
2. Queue: `GET /api/dashboard/test-appointments` (no query = waiting result).
3. Optional filters: `test_type_code=vision|theory|practical`, `date=YYYY-MM-DD`, `search=APP-…`.
4. Row action: enable Record only if `actions.can_record_result`.
5. Submit: `POST /api/admin/test-appointments/{items[].id}/record-result` with `{ "result": "passed"|"failed"|"no_show", "notes": optional }`.
6. On 200, reload the list. Do not use the POST body for application status.
7. Handle 422 from record-result (`already_recorded`, `only_booked_result`, `not_testable_status`) by refreshing the list.

Do **not** call issue-license from this screen. Do **not** use application number on the record URL.

## What was not changed

- `TestAppointmentResultController` / `RecordTestResultRequest` / `recordForAppointment()` mutation path
- Permissions for POST record-result
- Domain transitions (pass / fail / no_show / max attempts)

`TestResultService` only gained **read** helpers: `recordableApplicationStatuses()`, `isAppointmentRecordable()`.

## Tests

`tests/Feature/DashboardTestAppointmentListTest.php`

1. Authorized examiner lists waiting appointments  
2. Appointment with a result is excluded  
3. Non-booked rows excluded from default filter (`status=completed` still works)  
4. Unauthorized employee / citizen / anonymous rejected  
5. Expected fields + `can_record_result`  
6. Pagination + `test_type_*` / `date` / `search`  
7. Record-result still 200 and then disappears from the queue  

Also re-ran `AppointmentFlowTest` pass/fail record-result cases.

**13 passed** (11 list + 2 existing record-result).

## Changed files

| File | Change |
|---|---|
| `app/Modules/Dashboard/Routes/dashboard.php` | `GET /test-appointments` |
| `app/Modules/Dashboard/Controllers/DashboardTestAppointmentController.php` | **new** |
| `app/Modules/Dashboard/Requests/ListDashboardTestAppointmentsRequest.php` | **new** |
| `app/Modules/Dashboard/Services/DashboardTestAppointmentService.php` | **new** |
| `app/Modules/Dashboard/Resources/DashboardTestAppointmentResource.php` | **new** |
| `app/Modules/Dashboard/Support/DashboardTestAppointmentActions.php` | **new** |
| `app/Modules/Tests/Services/TestResultService.php` | read helpers only |
| `resources/lang/en/messages.php` | `tests.dashboard_list_retrieved` |
| `resources/lang/ar/messages.php` | same |
| `resources/lang/en/validation.php` | filter attribute labels |
| `resources/lang/ar/validation.php` | same |
| `lang/en/validation.php` | same attributes |
| `tests/Feature/DashboardTestAppointmentListTest.php` | **new** |
