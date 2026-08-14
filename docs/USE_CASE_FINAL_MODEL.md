# SYRTAK / DLMS — Final Use Case Model

**Document type:** Approved modelling decisions for the business UML Use Case Diagram  
**Subject:** SYRTAK / Digital License Management System (DLMS)  
**Authority:** current implementation, then tests, then `docs/PROJECT_MASTER_CONTEXT.md`  
**Inputs:** `docs/USE_CASE_DIAGRAM_AUDIT.md` + modelling decisions in the review request  
**This step:** specification only — **no draw.io / XML**

IDs below replace the audit catalogue. They are not historical SRS numbers.

---

## 1. Final system boundary

**UML subject (system box):** `SYRTAK / Digital License Management System (DLMS)`

Everything implemented in this Laravel backend — including the AI Agent orchestrator, domain services, RBAC, scheduler, in-app notifications, and private document storage — is **inside** the subject.

| Inside the subject (not actors) | Outside the subject (may be actors) |
|---------------------------------|-------------------------------------|
| AI Agent orchestrator (`app/Modules/AIAgent`) | Guest, Citizen, Employee specializations |
| Scheduler / Artisan commands | Mail / SMTP |
| Queue worker | Payment Gateway (Stripe; mock is internal substitute) |
| Database notification writer | Gemini |
| `/dev-dashboard`, empty `/chatbot` | Firebase FCM |

**`Use AI Assistant` is a Citizen Use Case inside the subject.** Gemini is the only LLM actor.

Visual note on UC-OVERVIEW, UC-00, and UC-05:

> AI Assistant is an alternative assisted interaction channel for supported citizen operations. Mutating operations require citizen confirmation.

Do **not** draw `<<extend>>` from `Use AI Assistant` to each supported citizen Use Case merely because it is another UI channel.

There are **zero** `<<include>>` and **zero** `<<extend>>` relationships in the final model. Sign In and Recover Account Access are independent actor goals.

---

## 2. Final actor catalog

### 2.1 Human actors

| ID | Name | UML kind | Code identity | Primary goals |
|----|------|----------|---------------|---------------|
| A-GUEST | Guest | Concrete | Unauthenticated caller of public `/api` routes | Catalogs, public content, contact form, public verify, start register / sign-in / recover |
| A-CIT | Citizen | Concrete | `UserType::Citizen` + `EnsureCitizen` | License services via mobile API |
| A-EMP | Employee | **Abstract** | `EnsureDashboardUser`; `UserType::Employee` or `Admin`; `access_dashboard` unless bypass | Shared dashboard authentication and overview |
| A-REV | Profile & Document Reviewer | Concrete, specializes Employee | Role `profile_document_reviewer`; `review_profiles`, `review_documents` | Profile review and document review |
| A-APP | Application Manager | Concrete, specializes Employee | Role `application_manager`; `view_applications` | Inspect applications (read-only) |
| A-PAY | Payment Employee | Concrete, specializes Employee | Role `payment_employee`; `view_payments`, `manage_payments` | Inspect and verify application payments |
| A-TST | Test Employee | Concrete, specializes Employee | Role `test_employee`; appointments + `record_test_result` | Slot capacity and test results |
| A-LIC | License Employee | Concrete, specializes Employee | Role `license_employee`; `issue_license`, `manage_licenses`, `view_licenses` | Issue, inspect, print, block, unblock |
| A-FIN | Fines Employee | Concrete, specializes Employee | Role `fines_employee`; `manage_fines` | Office fine records (including mark `paid`) |
| A-RPT | Reports Employee | Concrete, specializes Employee | Role `reports_employee`; `view_reports` | Operational reports |
| A-AUD | Audit Employee | Concrete, specializes Employee | Role `audit_employee`; `view_audit_logs` | Audit records |
| A-SET | Settings Employee | Concrete, specializes Employee | Role `settings_employee`; `manage_settings`, contact-message permissions | Catalogs, fees, contact-message handling |
| A-ADM | Admin | Concrete, specializes Employee | Role `admin`; `User::isSuperAdmin() === true` | Permission bypass `*`; citizen/employee/RBAC administration |
| A-SA | Super Admin | Concrete, specializes **Admin** | Role `super_admin`; `isRootSuperAdmin()` | All Admin capabilities plus employee-session supervision |

**Not actors**

| Candidate | Reason |
|-----------|--------|
| AI Agent orchestrator | Internal to SYRTAK |
| Scheduler | Internal |
| Flutter / Next.js | Clients, not actors |
| Public Verifier | Guest performs `Verify Driving License` |
| Separate Profile Reviewer vs Document Reviewer | One seeded role; one actor |
| Legacy role `employee` | Bundled permissions, not a business actor |
| RBAC role `citizen` | Protected marker, not a dashboard actor |

### 2.2 External actors

| ID | Name | Appears on | Evidence |
|----|------|------------|----------|
| X-MAIL | Mail / SMTP | UC-00, UC-01, UC-04, UC-05 | `OtpMail`; `OtpPurpose::Register`, `ForgotPassword`, `DashboardForgotPassword` |
| X-PAY | Payment Gateway | UC-00, UC-02, UC-05 | Stripe Checkout + `POST /api/webhooks/stripe`; mock is not a second actor |
| X-LLM | Gemini | UC-00, UC-05 | `GeminiAgentClient`; NLU only; files never sent |
| X-FCM | Firebase Cloud Messaging | UC-00, **detail on UC-05** | Optional push after `Register Mobile Device for Push` |

---

## 3. Actor generalization hierarchy

Verified against `app/Models/User.php`, `EnsureSuperAdmin`, `EnsureRootSuperAdmin`.

```text
Employee (abstract)
 ├── Profile & Document Reviewer
 ├── Application Manager
 ├── Payment Employee
 ├── Test Employee
 ├── License Employee
 ├── Fines Employee
 ├── Reports Employee
 ├── Audit Employee
 ├── Settings Employee
 └── Admin
      └── Super Admin
```

Guest and Citizen are **not** in this tree.

### Super Admin specializes Admin — verification

| Capability | Role `admin` (A-ADM) | Role `super_admin` (A-SA) |
|------------|----------------------|---------------------------|
| `isSuperAdmin()` permission bypass `*` | Yes | Yes |
| `EnsureSuperAdmin` (access-control CRUD, direct permissions) | Yes | Yes |
| All permission-gated employee Use Cases via bypass | Yes | Yes |
| `isRootSuperAdmin()` / `EnsureRootSuperAdmin` (employee sessions) | **No** | **Yes** |

`isSuperAdmin()` = `hasRole('super_admin') || hasRole('admin')`.  
`isRootSuperAdmin()` = `hasRole('super_admin')` only.

**Conclusion:** Super Admin inherits every Admin capability and adds `Supervise Employee Sessions`. UML generalization **Super Admin → Admin** is valid. Admin does **not** inherit Super Admin’s session Use Case.

**Diagram note (UC-OVERVIEW / UC-00 / UC-04):**

> Admin and Super Admin may perform any permission-gated employee Use Case via authorization bypass (`*`). Specialized actors remain on the diagram to show separation of duties.

Do **not** draw a line from Admin to every employee oval.

---

## 4. Final business Use Case catalog

All items below are in-scope ovals. Dual `/api/admin/*` and `/api/dashboard/*` URLs are evidence for **one** Use Case.

### 4.1 Public

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-PUB-01 | Browse Service Catalogs | Guest | — | Public | `GET /license-types`, `/service-types`, `/test-types` |
| UC-PUB-02 | Verify Driving License | Guest | — | Public, throttle 30/min | `GET /licenses/verify/{token}`; effective-active only |
| UC-PUB-03 | Read Public Information | Guest | — | Public | `GET /content/faqs`, `/privacy-policy`, `/contact-info` |
| UC-PUB-04 | Submit Contact Inquiry | Guest | — | Public, throttle 20/min | `POST /contact-messages` |

### 4.2 Citizen account

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-CIT-01 | Register and Activate Account | Guest | Mail / SMTP | Public | `POST /auth/register`, `/auth/verify-otp`; email OTP |
| UC-CIT-02 | Sign In | Citizen | — | Public login | `POST /auth/login` |
| UC-CIT-03 | Recover Account Access | Guest / Citizen | Mail / SMTP | Public, throttle 5/min | forgot / verify OTP / reset-password |
| UC-CIT-04 | Sign Out | Citizen | — | `auth:sanctum` | `POST /auth/logout` |
| UC-CIT-05 | Complete Identity Profile | Citizen | — | `auth:sanctum` | `PUT /profile/complete`, `/update`; `GET /profile/status` |
| UC-CIT-06 | Manage Account Preferences | Citizen | — | `citizen` | `GET/PUT /settings*`; change-password |

Approved profile is a **precondition** of mutating license services, not an `<<include>>`.

### 4.3 Citizen license services

Canonical workflow for new / renew / replace:

**Application → Documents → Review → Payment → Approval → Employee Issuance**  
(new license inserts tests after payment: Book → Record result → Approval)

Direct `POST /licenses/{id}/renew` and `POST /licenses/{id}/replacement` are **excluded** from this catalogue (see §10).

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-CIT-07 | Apply for New Driving License | Citizen | — | `citizen` + `profile.approved` | `POST /applications` `new_license` → `draft`; tests required |
| UC-CIT-08 | Renew Driving License | Citizen | — | `citizen` + `profile.approved` | `POST /applications` `renew_license` + `related_license_id`; no tests |
| UC-CIT-09 | Replace Lost or Damaged License | Citizen | — | `citizen` + `profile.approved` | `lost_replacement` / `damaged_replacement`; distinct fees/docs |
| UC-CIT-10 | Provide Application Documents | Citizen | — | `citizen` + `profile.approved` to upload/submit | required-docs, upload, submit-for-review; Agent session upload |
| UC-CIT-11 | Pay Application Fees | Citizen | Payment Gateway | `citizen` + `profile.approved` | create payment; mock confirm or Stripe webhook |
| UC-CIT-12 | Book Driving Test | Citizen | — | `citizen` + `profile.approved` | slots + `POST .../appointments`; `new_license` only |
| UC-CIT-13 | Change Test Appointment | Citizen | — | `citizen` + `profile.approved` | reschedule / cancel; cancel does not change application status |
| UC-CIT-14 | Track License Application | Citizen | — | `citizen` (reads) | list/show application and nested status resources |
| UC-CIT-15 | View Own Licenses | Citizen | — | `citizen` | `GET /licenses`, `GET /licenses/{id}` |
| UC-CIT-16 | View Own Fines | Citizen | — | `citizen` | `GET /fines` (read-only; no checkout) |

Lost vs damaged stay **one** Use Case (two fee/document variants).

### 4.4 Citizen communications and assistant

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-CIT-17 | Manage Notifications | Citizen | — | `citizen` | list, unread-count, mark one / all read |
| UC-CIT-18 | Register Mobile Device for Push | Citizen | Firebase FCM (UC-05) | `citizen` | `POST/DELETE /devices/push-token` |
| UC-CIT-19 | Use AI Assistant | Citizen | Gemini | `citizen`; `AI_AGENT_ENABLED` | 7 `/api/ai-agent/*` routes; one oval |

**Supported operations of UC-CIT-19 (specification / note — not extra ovals or `<<extend>>` lines):**

- Reads: application status and next step, required documents, fee, payment status, profile status, fines, licenses (list), available tests, slots, current appointments, test results.
- Mutations after confirmation: create application (new / renew / lost / damaged), submit documents, start payment, book / reschedule / cancel appointment.
- Not supported: fine payment, license details, notification listing, direct license-mutation action names, conversational unblock, admin actions, RAG.

### 4.5 Employee access

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-EMP-01 | Authenticate to Employee Dashboard | Employee | Mail / SMTP (reset) | Dashboard auth then `dashboard` | `/dashboard/auth/*`; session heartbeat |
| UC-EMP-02 | View Operational Overview | Employee | — | `access_dashboard` | `GET /dashboard/overview` |

### 4.6 Review, applications, payments

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-REV-01 | Review Citizen Identity Profiles | Profile & Document Reviewer | — | `review_profiles` | `/admin/profile-reviews/*` |
| UC-REV-02 | Review Application Documents | Profile & Document Reviewer | — | `review_documents` | `/admin/documents/*` **and** `/dashboard/document-reviews/*` (one oval) |
| UC-APP-01 | Inspect License Applications | Application Manager | — | `view_applications` | `/dashboard/applications` by `application_number`; **read-only** |
| UC-PAY-01 | Process Application Payments | Payment Employee | Payment Gateway | `view_payments` / `manage_payments` | dashboard payments + `POST .../verify` |

Do **not** rename UC-APP-01 to Manage Applications.

### 4.7 Testing and licenses

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-TST-01 | Manage Test Appointment Capacity | Test Employee | — | `view_appointments` / `manage_appointments` | `/dashboard/appointment-slots*` |
| UC-TST-02 | Record Driving Test Result | Test Employee | — | `record_test_result` | `POST /admin/test-appointments/{id}/record-result`; dashboard list is supporting |
| UC-LIC-01 | Issue Driving License | License Employee | — | `issue_license` | `POST /admin/applications/{id}/issue-license` |
| UC-LIC-02 | View / Inspect Issued Licenses | License Employee | — | `view_licenses` or `manage_licenses` | `/dashboard/licenses` list/show/history |
| UC-LIC-03 | Print Driving License | License Employee | — | `view_licenses` or `manage_licenses` | `POST /dashboard/licenses/{id}/print` |
| UC-LIC-04 | Block Driving License | License Employee | — | `manage_licenses` | admin **and** dashboard `.../block` (one oval) |
| UC-LIC-05 | Unblock Driving License | License Employee | — | `manage_licenses` | admin **and** dashboard `.../unblock` (one oval) |

UC-LIC-05 is the **only** unblock business Use Case.

### 4.8 Fines, people, administration

| ID | Name | Primary | Secondary | Permission / gate | Evidence (summary) |
|----|------|---------|-----------|-------------------|--------------------|
| UC-FIN-01 | Manage Citizen Fines | Fines Employee | — | `manage_fines` | `/admin/fines` GET/POST/PUT; mark `paid`/`cancelled` is this UC |
| UC-USR-01 | Manage Citizen Accounts | **Admin** | — | `manage_users` | `/dashboard/citizens*` view/update/activate/deactivate; Super Admin inherits |
| UC-HR-01 | Manage Employee Accounts | Admin | — | `manage_employees` | `/dashboard/employees*`; Super Admin inherits |
| UC-RBAC-01 | Administer Roles and Permissions | Admin | — | `super_admin` middleware (`admin` **or** `super_admin`) | `/dashboard/access-control/*`; Super Admin inherits |
| UC-SES-01 | Supervise Employee Sessions | **Super Admin only** | — | `root_super_admin` | `/dashboard/employee-sessions*`; Admin cannot |
| UC-RPT-01 | View Operational Reports | Reports Employee | — | `view_reports` + domain permission per slice | `/dashboard/reports/*`; `/admin/reports/overview` |
| UC-AUD-01 | View Audit Records | Audit Employee | — | `view_audit_logs` | `/admin/audit-logs`; nested audit fragments |
| UC-SET-01 | Configure Catalogs and Fees | Settings Employee | — | `manage_settings` | license types, service types, fees |
| UC-MSG-01 | Handle Contact Messages | Settings Employee | — | `view_contact_messages` / `manage_contact_messages` | dashboard contact-messages |

---

## 5. Use Cases removed from the audit (and exact reason)

| Audit ID / name | Reason for removal from the business diagram |
|-----------------|----------------------------------------------|
| UC-CIT-17 Request unblocking (ack-only) | Not a complete unblock workflow; no status change |
| Failed `license_unblock` application create | Eligibility currently fails (BR-29); would invent a workflow |
| UC-SYS-01 Reconcile stale payments | Internal scheduled process, not an actor goal |
| UC-SYS-02 Expire licenses | Internal scheduled process |
| UC-SYS-03 Reconcile employee sessions | Internal scheduled process |
| UC-SYS-04 Notify citizen | Supporting side effect, not a main business Use Case |
| `administrative_review` resolution | No employee completion API |
| Pay Fine / Collect Fine Payment / Fine Checkout | No citizen payment workflow |
| Application reject / cancel | Enum only; no production `transitionStatus` caller |
| Unused RBAC permissions as ovals | `send_notifications`, granular `create_employees`, … have no distinct product goals |
| AI-agent monitoring / log permissions | No APIs |
| Dashboard create-citizen | `StoreDashboardCitizenRequest` unused; no POST route |
| Test-fee payment | Catalog-only; not charged at booking |
| License suspension | Enum/report count only; no suspend API |
| Direct renew / replacement Use Cases | Technical/legacy alternative — §10 |
| Dev dashboard / empty chatbot | Not product |
| One oval per Agent intent | Channel internals of UC-CIT-19 |

**Retained but split**

| Audit | Final |
|-------|--------|
| UC-LIC-02 Inspect and print issued licenses | UC-LIC-02 View / Inspect Issued Licenses **and** UC-LIC-03 Print Driving License |

**Renumbered after dropping ack-only unblock**

Audit `UC-CIT-18/19/20` → final `UC-CIT-17/18/19`.

---

## 6. Final actor → Use Case association matrix

`P` = primary. `S` = secondary (external system). Inheritance: specialized employees inherit UC-EMP-01 and UC-EMP-02 from Employee; Super Admin inherits Admin associations except as noted.

| Use Case | Guest | Citizen | Emp | Rev | App | Pay | Tst | Lic | Fin | Rpt | Aud | Set | Admin | SA | Mail | GW | Gemini | FCM |
|----------|:-----:|:-------:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:-----:|:--:|:----:|:--:|:------:|:---:|
| UC-PUB-01 Browse Catalogs | P | | | | | | | | | | | | | | | | | |
| UC-PUB-02 Verify License | P | | | | | | | | | | | | | | | | | |
| UC-PUB-03 Read Public Info | P | | | | | | | | | | | | | | | | | |
| UC-PUB-04 Submit Contact | P | | | | | | | | | | | | | | | | | |
| UC-CIT-01 Register | P | | | | | | | | | | | | | | S | | | |
| UC-CIT-02 Sign In | | P | | | | | | | | | | | | | | | | |
| UC-CIT-03 Recover Access | P | P | | | | | | | | | | | | | S | | | |
| UC-CIT-04 Sign Out | | P | | | | | | | | | | | | | | | | |
| UC-CIT-05 Complete Profile | | P | | | | | | | | | | | | | | | | |
| UC-CIT-06 Preferences | | P | | | | | | | | | | | | | | | | |
| UC-CIT-07 Apply New License | | P | | | | | | | | | | | | | | | | |
| UC-CIT-08 Renew License | | P | | | | | | | | | | | | | | | | |
| UC-CIT-09 Replace License | | P | | | | | | | | | | | | | | | | |
| UC-CIT-10 Provide Documents | | P | | | | | | | | | | | | | | | | |
| UC-CIT-11 Pay Fees | | P | | | | | | | | | | | | | | S | | |
| UC-CIT-12 Book Test | | P | | | | | | | | | | | | | | | | |
| UC-CIT-13 Change Appointment | | P | | | | | | | | | | | | | | | | |
| UC-CIT-14 Track Application | | P | | | | | | | | | | | | | | | | |
| UC-CIT-15 View Own Licenses | | P | | | | | | | | | | | | | | | | |
| UC-CIT-16 View Own Fines | | P | | | | | | | | | | | | | | | | |
| UC-CIT-17 Manage Notifications | | P | | | | | | | | | | | | | | | | |
| UC-CIT-18 Register Push Device | | P | | | | | | | | | | | | | | | | S* |
| UC-CIT-19 Use AI Assistant | | P | | | | | | | | | | | | | | | S | |
| UC-EMP-01 Authenticate Dashboard | | | P | | | | | | | | | | | | S | | | |
| UC-EMP-02 Overview | | | P | | | | | | | | | | | | | | | |
| UC-REV-01 Review Profiles | | | | P | | | | | | | | | | | | | | |
| UC-REV-02 Review Documents | | | | P | | | | | | | | | | | | | | |
| UC-APP-01 Inspect Applications | | | | | P | | | | | | | | | | | | | |
| UC-PAY-01 Process Payments | | | | | | P | | | | | | | | | | S | | |
| UC-TST-01 Manage Slots | | | | | | | P | | | | | | | | | | | |
| UC-TST-02 Record Test Result | | | | | | | P | | | | | | | | | | | |
| UC-LIC-01 Issue License | | | | | | | | P | | | | | | | | | | |
| UC-LIC-02 Inspect Licenses | | | | | | | | P | | | | | | | | | | |
| UC-LIC-03 Print License | | | | | | | | P | | | | | | | | | | |
| UC-LIC-04 Block License | | | | | | | | P | | | | | | | | | | |
| UC-LIC-05 Unblock License | | | | | | | | P | | | | | | | | | | |
| UC-FIN-01 Manage Fines | | | | | | | | | P | | | | | | | | | |
| UC-USR-01 Manage Citizen Accounts | | | | | | | | | | | | | P | (inh) | | | | |
| UC-HR-01 Manage Employees | | | | | | | | | | | | | P | (inh) | | | | |
| UC-RBAC-01 Roles & Permissions | | | | | | | | | | | | | P | (inh) | | | | |
| UC-SES-01 Supervise Sessions | | | | | | | | | | | | | | P | | | | |
| UC-RPT-01 Reports | | | | | | | | | | P | | | | | | | | |
| UC-AUD-01 Audit Records | | | | | | | | | | | P | | | | | | | |
| UC-SET-01 Catalogs & Fees | | | | | | | | | | | | P | | | | | | |
| UC-MSG-01 Handle Contact | | | | | | | | | | | | P | | | | | | |

\* FCM association is drawn on **UC-00** and detailed on **UC-05**. It is **not** drawn on UC-01.

Citizen is not drawn as primary on Guest public UCs even though an authenticated user could call the same public GETs.

---

## 7. Final approved `<<include>>` relationships

**Count: 0**

Strict UML: `<<include>>` only when the base Use Case **always** executes the included Use Case as reusable behaviour in the same execution.

| Rejected (from audit) | Why it is not `<<include>>` |
|-----------------------|-----------------------------|
| Apply/Renew/Replace → Provide Documents / Pay / Book Test | Chronological process chain. Each is a separately initiated actor goal. |
| Any UC → Notify citizen | Supporting side effect; notify UC removed |
| Use AI Assistant → Sign In | Precondition (must be authenticated) |
| Authenticate → every employee UC | Precondition |
| Complete Profile → Apply | Precondition (`profile.approved`) |
| Issue → Inspect applications | Related process, not reusable included behaviour |
| Register → (OTP as a Use Case) | OTP is interaction with Mail / SMTP, not a second Use Case |

Do not add `<<include>>` lines to “glue” the happy path visually. Sequence/activity diagrams already cover order.

---

## 8. Final approved `<<extend>>` relationships

**Count: 0**

UC-CIT-02 Sign In and UC-CIT-03 Recover Account Access are **independent actor goals**. Place them near each other on UC-01 for readability. Do **not** draw `<<extend>>` (or any other UML relationship) between them.

### Rejected extend candidates

| Candidate | Why rejected |
|-----------|----------------|
| Use AI Assistant `<<extend>>` every citizen service UC | Forbidden: alternative channel, not optional extra behaviour of each base UC |
| Confirm/cancel Agent action | Internal to UC-CIT-19; not a business oval |
| Stripe webhook / mock confirm `<<extend>>` Pay Fees | Provider scenarios of the same Use Case; Payment Gateway is a secondary actor |
| Process Payments `<<extend>>` Pay Fees | Different primary actor and different goal; related processes only |
| Direct renew/replace `<<extend>>` Renew/Replace | Direct APIs excluded from the business model (§10) |
| Retest `<<extend>>` Book Test | Scenario of UC-CIT-12 when status is `waiting_retest` |
| Re-upload `<<extend>>` Provide Documents | Scenario after `documents_rejected` |
| Print `<<extend>>` Inspect Licenses | Print is an independent employee goal (explicit split) |
| Push `<<extend>>` Manage Notifications | Separate citizen goal; FCM on UC-00 / UC-05 |
| Dashboard password reset `<<extend>>` Authenticate | Already inside UC-EMP-01 |
| Recover Account Access `<<extend>>` Sign In | Independent goals; no UML relationship |
| `administrative_review` | No Use Case |

---

## 9. External actor associations

| External actor | Associated Use Case(s) | Role | Draw on |
|----------------|------------------------|------|---------|
| Mail / SMTP | UC-CIT-01 Register and Activate Account | Delivers registration OTP | UC-01, UC-05 |
| Mail / SMTP | UC-CIT-03 Recover Account Access | Delivers reset OTP | UC-01, UC-05 |
| Mail / SMTP | UC-EMP-01 Authenticate to Employee Dashboard | Dashboard forgot-password OTP | UC-04, UC-05 |
| Payment Gateway | UC-CIT-11 Pay Application Fees | Capture / webhook completion | UC-02, UC-05 |
| Payment Gateway | UC-PAY-01 Process Application Payments | Completing/reconciling gateway-backed rows | UC-02, UC-05 |
| Gemini | UC-CIT-19 Use AI Assistant | NLU / structured intent | UC-05 |
| Firebase FCM | UC-CIT-18 Register Mobile Device for Push | Optional later delivery | UC-00, UC-05 |

Automatic in-app notification writes stay inside the subject and are **not** actor associations.

---

## 10. Verification: direct renew / replacement APIs

**Endpoints**

- `POST /api/licenses/{license}/renew`
- `POST /api/licenses/{license}/replacement` (`type`: `lost` \| `damaged`)

**Canonical business workflow (this model):**  
Application → Documents → Review → Payment → Approval → Employee Issuance.

### Production-client search

| Surface searched | Result |
|------------------|--------|
| This repo Flutter / mobile source | **Not present** (no `.dart` / `pubspec.yaml` under `d:\Projects`) |
| Sibling Next.js dashboard `d:\Projects\DLMS_Dashboard` | **No call sites.** `src/lib/api/endpoints.ts` has dashboard `block` / `unblock` / `print` only. “renewed” / “replacement” appear as **license status/report labels**, not citizen POST calls |
| Maintained Agent contract `FLUTTER_AI_AGENT.md` | Agent HTTP surface only; no direct license-mutation routes |
| Agent implementation | Renew / lost / damaged use `create_application` (`AgentOtherLicenseServicesHandler`, `AgentIntent`, `OtherLicenseServicesFlowTest` Agent cases) — **not** `LicenseService::renew` / `replace` |
| Maintained Flutter Postman kit `postman/SYRTAK_Flutter_API.postman_collection.json` (built by `postman/_build_syrtak_flutter_collection.py`) | Application path described as **“Preferred Flutter path”**. Direct renew: *“IMMEDIATE license mutation… Prefer Create Renewal Application”* / *“Quick renew shortcut if product uses Path B.”* Direct replacement: *“Prefer application workflow for Flutter.”* |
| `docs/PROJECT_MASTER_CONTEXT.md` | Application path is workflow **B**. Direct APIs listed under **C. Direct license APIs (also exist)** and noted as skipping `LicenseIssued` |
| `docs/FINAL_REPORT_PROJECT_AUDIT.md` | Dual path called confusing; recommends declaring application workflow official |
| PHPUnit | Direct **renew** used in `LicenseFlowTest::test_citizen_can_renew_eligible_license` and as a **fixture** in `DashboardIssuedLicensesTest`. Direct **replacement** HTTP route: **no Feature test found**. Application-path renew/replace **are** covered in `OtherLicenseServicesFlowTest` |
| `/dev-dashboard` | Local testing UI **does** POST both direct endpoints — **dev-only**, not a production client |

### Classification

**Implemented technical/legacy alternative — excluded from the final business Use Case Diagram.**

No production Flutter or Next.js call site was found. The maintained citizen kit tells Flutter to prefer the application workflow. The live Agent follows that workflow. Tests and the local dev dashboard are the demonstrated consumers of the direct APIs.

Do **not** add extra Use Cases or `<<extend>>` ovals for these endpoints.

If a later Flutter repo shows those POSTs as shipped CTAs, revisit this section; do not silently change the model here.

---

## 11. Coverage check against implemented business capabilities

| Implemented capability | Final UC / handling |
|------------------------|---------------------|
| Public catalogs | UC-PUB-01 |
| Public license verify | UC-PUB-02 |
| FAQ / privacy / contact info | UC-PUB-03 |
| Contact form | UC-PUB-04 → UC-MSG-01 |
| Register + email OTP | UC-CIT-01 |
| Login / logout | UC-CIT-02 / UC-CIT-04 |
| Forgot password | UC-CIT-03 (independent of Sign In) |
| Profile complete/update/status | UC-CIT-05 |
| Language/theme/password | UC-CIT-06 |
| New license application | UC-CIT-07 |
| Renew application path | UC-CIT-08 |
| Lost/damaged application path | UC-CIT-09 |
| Upload + submit documents | UC-CIT-10 |
| Mock/Stripe application payment | UC-CIT-11 |
| Book / reschedule / cancel tests | UC-CIT-12 / UC-CIT-13 |
| Track application | UC-CIT-14 |
| View licenses / fines | UC-CIT-15 / UC-CIT-16 |
| Notification center | UC-CIT-17 |
| Push device register | UC-CIT-18 |
| AI Agent (executable subset) | UC-CIT-19 |
| Dashboard auth + heartbeat | UC-EMP-01 |
| Overview | UC-EMP-02 |
| Profile review | UC-REV-01 |
| Document review (both URL surfaces) | UC-REV-02 |
| View applications | UC-APP-01 |
| Payment inspect/verify | UC-PAY-01 |
| Slot management | UC-TST-01 |
| Record test result | UC-TST-02 |
| Issue / inspect / print / block / unblock | UC-LIC-01 … UC-LIC-05 |
| Admin fines including mark paid | UC-FIN-01 |
| Citizen activate/deactivate | UC-USR-01 |
| Employee CRUD | UC-HR-01 |
| Access control | UC-RBAC-01 |
| Root session revoke | UC-SES-01 |
| Reports / audit | UC-RPT-01 / UC-AUD-01 |
| License/service types + fees | UC-SET-01 |
| Dual admin/dashboard URLs | Same oval |
| Direct renew/replace APIs | Excluded (§10) |
| Ack-only unblock-request | Excluded |
| Scheduler / auto-notify | Excluded from main diagram |
| Fine checkout / reject-cancel app / RAG / chatbot | Not implemented — not drawn |

---

## 12. Exact diagram decomposition plan

Seven draw.io pages. Do not duplicate Admin onto every employee oval. **Zero** `<<include>>`. **Zero** `<<extend>>`. Actor/Use Case catalogue is unchanged.

Layout geometry lives in `docs/USE_CASE_LAYOUT_BLUEPRINT.md`.

### UC-OVERVIEW — Use Case Model Overview

**Purpose:** Navigation / model overview only. **Not** the complete system Use Case Diagram. **No 45 ovals.**

*Title on canvas:* `Use Case Model Overview`

*Actors (human only):* Guest, Citizen, Employee, Profile & Document Reviewer, Application Manager, Payment Employee, Test Employee, License Employee, Fines Employee, Reports Employee, Audit Employee, Settings Employee, Admin, Super Admin.

*Use Cases (ovals):* none. Five capability/package areas:

1. Public, Account & Citizen Services  
2. Applications, Documents & Payments  
3. Testing & License Operations  
4. Employee & Administration  
5. AI Assistant & External Integrations  

*Associations:* none (packages are not Use Cases).

*Relationships:* full Employee generalization tree, including Admin ← Super Admin.

*Notes (visual):*

- Subject title: `SYRTAK / Digital License Management System (DLMS)`
- “This page is a model overview, not the complete Use Case Diagram. See UC-00.”
- AI Assistant channel note (section 1)
- Admin/Super Admin bypass note (section 3)
- “Scheduled jobs and automatic notifications are internal and are not shown as Use Cases.”

### UC-00 — Complete System Use Case Diagram

**Purpose:** Authoritative master Use Case Diagram. Contains **all 45** business Use Cases exactly once.

*Title on canvas:* `Complete System Use Case Diagram`

*Actors:* all 14 human actors + Mail / SMTP, Payment Gateway, Gemini, Firebase FCM.

*Use Cases:* the full catalogue in §4 (PUB-01…04, CIT-01…19, EMP-01…02, REV-01…02, APP-01, PAY-01, TST-01…02, LIC-01…05, FIN-01, USR-01, HR-01, RBAC-01, SES-01, RPT-01, AUD-01, SET-01, MSG-01).

*Associations:* as in §6. Admin connects only to UC-USR-01, UC-HR-01, UC-RBAC-01. Super Admin connects directly only to UC-SES-01 (inherits Admin). FCM connects to UC-CIT-18 on this page.

*Include / extend:* none.

*Internal grouping (packages, not ovals/actors):* Public Information; Citizen Account; Citizen License Services; Citizen Communications & AI Assistant; Review & Applications; Payments; Testing; License Operations; Fines; Employee Access; Administration & RBAC; Reports, Audit & Settings.

*Notes:* AI Assistant channel note; Admin bypass note. Large landscape canvas (not A4).

---

### UC-01 — Public, Account & Citizen Services

*Actors:* Guest, Citizen, Mail / SMTP

*Use Cases:*

- UC-PUB-01 Browse Service Catalogs  
- UC-PUB-02 Verify Driving License  
- UC-PUB-03 Read Public Information  
- UC-PUB-04 Submit Contact Inquiry  
- UC-CIT-01 Register and Activate Account  
- UC-CIT-02 Sign In  
- UC-CIT-03 Recover Account Access  
- UC-CIT-04 Sign Out  
- UC-CIT-05 Complete Identity Profile  
- UC-CIT-06 Manage Account Preferences  
- UC-CIT-15 View Own Licenses  
- UC-CIT-16 View Own Fines  
- UC-CIT-17 Manage Notifications  
- UC-CIT-18 Register Mobile Device for Push  

*Associations:*

- Guest — PUB-01, PUB-02, PUB-03, PUB-04, CIT-01, CIT-03  
- Citizen — CIT-02, CIT-03, CIT-04, CIT-05, CIT-06, CIT-15, CIT-16, CIT-17, CIT-18  
- Mail / SMTP — CIT-01, CIT-03  

*Include / extend / generalization:* **none.** Place Sign In and Recover Account Access near each other with **no** UML relationship.

*Notes:*

- Do not draw FCM here (see UC-05; FCM appears on UC-00).  
- Do not draw Apply / Documents / Pay (UC-02) or tests (UC-03).  
- No Public Verifier actor.

---

### UC-02 — Applications, Documents & Payments

*Actors:* Citizen, Profile & Document Reviewer, Application Manager, Payment Employee, Payment Gateway

*Use Cases:*

- UC-CIT-07 Apply for New Driving License  
- UC-CIT-08 Renew Driving License  
- UC-CIT-09 Replace Lost or Damaged License  
- UC-CIT-10 Provide Application Documents  
- UC-CIT-11 Pay Application Fees  
- UC-CIT-14 Track License Application  
- UC-REV-01 Review Citizen Identity Profiles  
- UC-REV-02 Review Application Documents  
- UC-APP-01 Inspect License Applications  
- UC-PAY-01 Process Application Payments  

*Associations:*

- Citizen — CIT-07, CIT-08, CIT-09, CIT-10, CIT-11, CIT-14  
- Reviewer — REV-01, REV-02  
- Application Manager — APP-01  
- Payment Employee — PAY-01  
- Payment Gateway — CIT-11, PAY-01  

*Include / extend / generalization:* none on this page (Reviewer still specializes Employee; tree may be omitted if UC-00 is bound with the set).

*Notes:*

- Canonical path: Application → Documents → Review → Payment → Approval → Issuance (issuance on UC-03).  
- New license additionally requires tests (UC-03). Renew/replace do not.  
- Dual `/admin` and `/dashboard` document-review URLs = one oval (REV-02).  
- Inspect License Applications is read-only.  
- Direct renew/replacement APIs are not ovals.

---

### UC-03 — Testing & License Operations

*Actors:* Citizen, Test Employee, License Employee, Fines Employee

*Use Cases:*

- UC-CIT-12 Book Driving Test  
- UC-CIT-13 Change Test Appointment  
- UC-TST-01 Manage Test Appointment Capacity  
- UC-TST-02 Record Driving Test Result  
- UC-LIC-01 Issue Driving License  
- UC-LIC-02 View / Inspect Issued Licenses  
- UC-LIC-03 Print Driving License  
- UC-LIC-04 Block Driving License  
- UC-LIC-05 Unblock Driving License  
- UC-FIN-01 Manage Citizen Fines  

*Associations:*

- Citizen — CIT-12, CIT-13  
- Test Employee — TST-01, TST-02  
- License Employee — LIC-01, LIC-02, LIC-03, LIC-04, LIC-05  
- Fines Employee — FIN-01  

*Include / extend / generalization:* none.

*Notes:*

- Tests apply to `new_license` only.  
- Block and Unblock are separate ovals; citizen ack-only request is not shown.  
- Marking a fine `paid` belongs to Manage Citizen Fines — no Pay Fine oval.  
- Admin/dashboard block/unblock URLs = one oval each.

---

### UC-04 — Employee & Administration

*Actors:* Employee, Admin, Super Admin, Settings Employee, Reports Employee, Audit Employee, Mail / SMTP

*Use Cases:*

- UC-EMP-01 Authenticate to Employee Dashboard  
- UC-EMP-02 View Operational Overview  
- UC-USR-01 Manage Citizen Accounts  
- UC-HR-01 Manage Employee Accounts  
- UC-RBAC-01 Administer Roles and Permissions  
- UC-SES-01 Supervise Employee Sessions  
- UC-RPT-01 View Operational Reports  
- UC-AUD-01 View Audit Records  
- UC-SET-01 Configure Catalogs and Fees  
- UC-MSG-01 Handle Contact Messages  

*Associations:*

- Employee — EMP-01, EMP-02  
- Admin — USR-01, HR-01, RBAC-01  
- Super Admin — SES-01 (plus inherited Admin UCs; do not redraw USR/HR/RBAC unless needed for readability)  
- Reports Employee — RPT-01  
- Audit Employee — AUD-01  
- Settings Employee — SET-01, MSG-01  
- Mail / SMTP — EMP-01  

*Include / extend / generalization:*

- Employee ← Admin ← Super Admin (required on this page)  
- Settings / Reports / Audit specialize Employee (show if space; otherwise rely on UC-00)

*Notes:*

- Primary actor of Manage Citizen Accounts is Admin; Super Admin inherits.  
- Only Super Admin (role `super_admin`) has Supervise Employee Sessions.  
- Admin bypass note.

---

### UC-05 — AI Assistant & External Integrations

*Actors:* Citizen, Gemini, Mail / SMTP, Payment Gateway, Firebase FCM

*Use Cases (ovals on this page):*

- UC-CIT-19 Use AI Assistant *(defined here as the home oval)*  
- UC-CIT-01, UC-CIT-03, UC-EMP-01 *(shown as small reference ovals for Mail)*  
- UC-CIT-11, UC-PAY-01 *(reference ovals for Payment Gateway)*  
- UC-CIT-18 *(reference oval for FCM)*  

If the page would be crowded, keep **only** UC-CIT-19 as a full oval and list the other associations in a visible note table instead of extra ovals. Prefer the note-table option:

| External actor | Use Case |
|----------------|----------|
| Gemini | Use AI Assistant |
| Mail / SMTP | Register and Activate Account; Recover Account Access; Authenticate to Employee Dashboard |
| Payment Gateway | Pay Application Fees; Process Application Payments |
| Firebase FCM | Register Mobile Device for Push |

*Associations:*

- Citizen — UC-CIT-19  
- Gemini — UC-CIT-19  

*Include / extend / generalization:* **none.** Especially no `<<extend>>` from Use AI Assistant to other citizen UCs.

*Notes (must appear visually):*

- AI Assistant is an alternative assisted interaction channel for supported citizen operations. Mutating operations require citizen confirmation.  
- Supported operations: list from §4.4 (short form on the canvas).  
- Files are never sent to Gemini.  
- Database notifications are the source of truth; FCM is optional delivery.  
- Mock payment provider is internal, not an extra actor.

---

## Totals

| Measure | Count |
|---------|-------|
| Final human actors | **14** |
| Final external actors | **4** |
| Final business Use Cases | **45** |
| Approved `<<include>>` relationships | **0** |
| Approved `<<extend>>` relationships | **0** |

Human 14 = Guest, Citizen, Employee, 9 specialized staff roles, Admin, Super Admin.  
Use Cases 45 = 4 public + 19 citizen + 2 employee-access + 20 staff/ops.

---

## Remaining blockers before diagram generation

**None that change the model.** The review decisions are applied.

Non-blocking caveats (do not stall XML):

1. Flutter application source is not in this workspace. Direct renew/replace exclusion rests on dashboard code, Agent code, maintained Postman/Flutter kit wording, tests, and master context — not a decompiled mobile binary.  
2. UC-05 uses a **note table** for Mail / Payment Gateway / FCM associations (no duplicate ovals).  
3. Employee generalization tree is required on UC-OVERVIEW, UC-00, and UC-04. Optional on UC-02/UC-03.

Ready for draw.io/XML when requested. Modelling source: this file. Visual architecture: `docs/USE_CASE_LAYOUT_BLUEPRINT.md`.
