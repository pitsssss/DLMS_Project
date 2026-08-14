# SYRTAK / DLMS — Use Case Diagram QA

**Artifact:** `docs/diagrams/use-case/DLMS_USE_CASE_DIAGRAM.drawio`  
**Exports:** `docs/diagrams/use-case/exports/`  
**Modelling source:** `docs/USE_CASE_FINAL_MODEL.md`  
**Layout source:** `docs/USE_CASE_LAYOUT_BLUEPRINT.md`  
**Rendered:** PNG + SVG via diagrams.net viewer + system Chrome (`docs/diagrams/use-case/render_drawio.mjs`).  
**Authoritative QA:** **Second Visual QA Pass** (this document’s top-level result).  
**Visual inspection:** rendered PNG and SVG paths were traced; a page fails if an Actor–Use Case line intersects an unrelated oval, actor figure, actor label, package title, or note.

The UML model is unchanged: 45 Use Cases, 53 UC-00 associations, `«include»` = 0, `«extend»` = 0. No actors, Use Case names, associations, or generalizations were added, removed, or renamed.

---

## Model validation

| Check | Result |
|-------|--------|
| Pages | **7/7** |
| Page names | PASS (UC-OVERVIEW … UC-05, exact titles) |
| UC-00 Use Cases | **45/45** |
| Duplicate UCs on UC-00 | **0** |
| Extra UCs on UC-00 | **0** |
| `«include»` relationship edges | **0** |
| `«extend»` relationship edges | **0** |
| AI Agent / Scheduler / Flutter / Next.js actors | **0** |
| UC-OVERVIEW Use Case ovals | **0** |
| UC-05 full ovals | **1** (UC-CIT-19 only) |
| UC-00 canvas | **5400 × 3600** (not A4-scaled) |
| XML well-formed | PASS |
| Duplicate mxCell IDs | **0** |

### Actor validation (UC-00)

Each required actor appears **exactly once**:

Guest, Citizen, Employee `{abstract}`, Profile & Document Reviewer, Application Manager, Payment Employee, Test Employee, License Employee, Fines Employee, Reports Employee, Audit Employee, Settings Employee, Admin, Super Admin, Mail / SMTP, Payment Gateway, Gemini, Firebase FCM.

### Association validation (UC-00)

| Item | Result |
|------|--------|
| Association count | **53/53** |
| Admin direct associations | UC-USR-01, UC-HR-01, UC-RBAC-01 only |
| Super Admin direct associations | UC-SES-01 only |
| Employee direct associations | UC-EMP-01, UC-EMP-02 |
| Mail / SMTP | UC-CIT-01, UC-CIT-03, UC-EMP-01 |
| Payment Gateway | UC-CIT-11, UC-PAY-01 |
| Gemini | UC-CIT-19 |
| Firebase FCM | UC-CIT-18 |
| Association arrowheads | none (`endArrow=none`) |

Citizen multi-links and License Employee multi-links are **independent connectors** (parallel tracks), not a single merged UML edge.

### Generalization validation

The **underlying** actor hierarchy is unchanged. The **displayed** tree on UC-00 is reduced for readability.

| Location | Displayed edges | Result |
|----------|-----------------|--------|
| UC-00 | **2:** Admin → Employee; Super Admin → Admin | PASS |
| UC-OVERVIEW | Full specialist tree + Super Admin → Admin | PASS |
| UC-04 | Full specialist tree + Super Admin → Admin | PASS |

UC-00 carries the note: *“Specialized staff actors inherit from Employee; full hierarchy is shown in UC-OVERVIEW and UC-04.”*

Hollow triangles point toward the parent (Employee or Admin).

---

## Visual validation (Second Visual QA Pass)

Inspected from the rendered PNGs and SVG path geometry (not XML `source`/`target` alone).

| Page | Overlap | Connector routing | Text clipping | UML | Result |
|------|---------|-------------------|---------------|-----|--------|
| UC-OVERVIEW | None | Full Employee tree on a separate spine; no actor–package lines | None | Hollow triangles toward parents | **PASS** |
| UC-00 | Notes below packages, not on ovals | Left-corridor / gutter routing; no through-unrelated-oval hits | None | 45 UCs; 53 associations; 2 displayed gens | **PASS** |
| UC-01 | None | Guest/Citizen drops from title-band channels and column gutters | None | No Sign In–Recover relationship | **PASS** |
| UC-02 | None | Reviewer/AppMgr isolated from Payments | None | No workflow arrows between spatial groups | **PASS** |
| UC-03 | None | Test Employee isolated from License Operations | None | No TST-02→LIC-01 arrow | **PASS** |
| UC-04 | None | Associations left of actors; generalization spine to the right | None | Super Admin △ → Admin △ → Employee | **PASS** |
| UC-05 | Files note above subject | Only Citizen—CIT-19—Gemini | None | Integration table is a UML note | **PASS** |

UC-00 is a large landscape master: **5400 × 3600** conceptual px. It is not A4-scaled.

---

## UC-00 completeness

| UC ID | Name | Result |
|-------|------|--------|
| UC-PUB-01 | Browse Service Catalogs | PASS |
| UC-PUB-02 | Verify Driving License | PASS |
| UC-PUB-03 | Read Public Information | PASS |
| UC-PUB-04 | Submit Contact Inquiry | PASS |
| UC-CIT-01 | Register and Activate Account | PASS |
| UC-CIT-02 | Sign In | PASS |
| UC-CIT-03 | Recover Account Access | PASS |
| UC-CIT-04 | Sign Out | PASS |
| UC-CIT-05 | Complete Identity Profile | PASS |
| UC-CIT-06 | Manage Account Preferences | PASS |
| UC-CIT-07 | Apply for New Driving License | PASS |
| UC-CIT-08 | Renew Driving License | PASS |
| UC-CIT-09 | Replace Lost or Damaged License | PASS |
| UC-CIT-10 | Provide Application Documents | PASS |
| UC-CIT-11 | Pay Application Fees | PASS |
| UC-CIT-12 | Book Driving Test | PASS |
| UC-CIT-13 | Change Test Appointment | PASS |
| UC-CIT-14 | Track License Application | PASS |
| UC-CIT-15 | View Own Licenses | PASS |
| UC-CIT-16 | View Own Fines | PASS |
| UC-CIT-17 | Manage Notifications | PASS |
| UC-CIT-18 | Register Mobile Device for Push | PASS |
| UC-CIT-19 | Use AI Assistant | PASS |
| UC-EMP-01 | Authenticate to Employee Dashboard | PASS |
| UC-EMP-02 | View Operational Overview | PASS |
| UC-REV-01 | Review Citizen Identity Profiles | PASS |
| UC-REV-02 | Review Application Documents | PASS |
| UC-APP-01 | Inspect License Applications | PASS |
| UC-PAY-01 | Process Application Payments | PASS |
| UC-TST-01 | Manage Test Appointment Capacity | PASS |
| UC-TST-02 | Record Driving Test Result | PASS |
| UC-LIC-01 | Issue Driving License | PASS |
| UC-LIC-02 | View / Inspect Issued Licenses | PASS |
| UC-LIC-03 | Print Driving License | PASS |
| UC-LIC-04 | Block Driving License | PASS |
| UC-LIC-05 | Unblock Driving License | PASS |
| UC-FIN-01 | Manage Citizen Fines | PASS |
| UC-USR-01 | Manage Citizen Accounts | PASS |
| UC-HR-01 | Manage Employee Accounts | PASS |
| UC-RBAC-01 | Administer Roles and Permissions | PASS |
| UC-SES-01 | Supervise Employee Sessions | PASS |
| UC-RPT-01 | View Operational Reports | PASS |
| UC-AUD-01 | View Audit Records | PASS |
| UC-SET-01 | Configure Catalogs and Fees | PASS |
| UC-MSG-01 | Handle Contact Messages | PASS |

**45/45 PASS.**

---

## Second Visual QA Pass — layout repairs

The first pass treated correct XML `source`/`target` as sufficient and was **not accepted**. This pass repaired **rendered paths**.

### Defects in the rejected first-pass renders

| Page | Defect |
|------|--------|
| UC-01 | Guest → Verify Driving License through Browse Service Catalogs; Guest → Submit Contact Inquiry through Read Public Information; Citizen → Sign In through Register; Citizen → Sign Out through Recover; Citizen → Manage Account Preferences through Complete Identity Profile; self-service row lines through neighbouring ovals; Mail through Sign In |
| UC-02 | Reviewer / Application Manager routes through Payments |
| UC-03 | Test Employee → TST-01 / TST-02 through License Operations |
| UC-04 | Generalization spine and actor–UC associations shared one congested column; Admin lines crossed Employee Access ovals |
| UC-00 | Same class of defects at master scale |
| Presentation notes | Implementation-analysis notes (unblock-request, legacy APIs, Sign In vs Recover, FCM-not-on-this-page, “no workflow arrow…”) were still on report pages |

### Routing / layout repairs (model unchanged)

**UC-01** — Public and Self-Service are single rows with one vertical drop per oval. Account is two columns with a wide gutter: left-column UCs from the left; right-column UCs via the gutter, never through a first-column oval. Mail stays above Sign In.

**UC-02** — Reviewer above Documents / Review; Application Manager below; Payment Employee right of Payments; Gateway above/right of Payments. Review lines do not enter the Payments package.

**UC-03** — Test Employee to the right of Test operations, before License Operations. Test lines do not enter the License package.

**UC-04** — Associations left of the actor column; generalization tree on a separate spine to the right.

**UC-00** — Canvas **5400 × 3600**. Left packages are left-aligned stacks. CIT-11 / 12 / 13 use inter-row gutters. Staff actors sit in dedicated gutters. Displayed generalizations: Admin → Employee and Super Admin → Admin only.

**Notes** — Presentation pages keep architectural notes only (AI channel/confirmation, files never sent to Gemini, Admin bypass, process order in Activity/Sequence, include/extend = 0).

### Pages re-rendered

All seven pages, PNG + SVG: `UC-OVERVIEW`, `UC-00`, `UC-01`, `UC-02`, `UC-03`, `UC-04`, `UC-05`.

Generator waypoint check and rendered SVG path-vs-ellipse trace: **0 through-unrelated-oval hits** on every page.

---

## Final result

| Page | Result |
|------|--------|
| UC-OVERVIEW | **PASS** — navigation only; full Employee tree; no UC ovals |
| UC-00 | **PASS** — 5400 × 3600; 45 UCs; 53 associations; **2** displayed generalizations (Admin → Employee, Super Admin → Admin) + hierarchy note |
| UC-01 | **PASS** — Guest and Citizen edges do not enter unrelated ovals |
| UC-02 | **PASS** — Reviewer/AppMgr isolated from Payments |
| UC-03 | **PASS** — Test Employee isolated from License Operations |
| UC-04 | **PASS** — generalization spine separate from actor–UC associations; full hierarchy shown |
| UC-05 | **PASS** — CIT-19 only; architectural notes only |

**PASS** — Second Visual QA Pass (current artifact).

---

## Appendix — First Visual QA Pass (superseded)

This section is historical. It does **not** describe the current draw.io artifact. The first pass was **not accepted** because rendered association paths still crossed unrelated Use Case ovals.

At that time UC-00 was a smaller canvas (**4400 × 2680** conceptual px, export ~8784 × 5100 px at 2×) and **displayed 11 generalization edges** on UC-00 (9 specialists → Employee, plus Admin → Employee and Super Admin → Admin). The current master displays **2** of those edges; the full tree remains on UC-OVERVIEW and UC-04. The underlying UML hierarchy was not changed.

First-pass visual table (superseded):

| Page | First-pass note | First-pass result |
|------|-----------------|-------------------|
| UC-OVERVIEW | Canvas height increase; gen spine outside subject | FIXED then claimed PASS |
| UC-00 | Notes moved; Guest→CIT-03 rerouted; guillemets for include/extend | FIXED then claimed PASS |
| UC-01 | Mail/Guest/Citizen reroutes | FIXED then claimed PASS |
| UC-02 | CIT-10 / CIT-11 gutter reroutes | FIXED then claimed PASS |
| UC-03 | Independent License Employee horizontals | claimed PASS |
| UC-04 | Mail left; gen tree outside | claimed PASS |
| UC-05 | Files note moved above subject | FIXED then claimed PASS |

First-pass repair list (superseded; later replaced by the second-pass re-layout):

1. UC-00 notes moved below the subject; canvas height 2680.
2. UC-00 Guest → UC-CIT-03 via left corridor / top of oval.
3. UC-00 Reviewer / Application Manager drop lines offset from Payments.
4. Include/extend prohibition shown with guillemets (`«include»` / `«extend»`).
5. UC-01 Mail via account title band / gutter.
6. UC-02 Citizen CIT-10 / CIT-11 via gutters.
7. UC-05 “Files are never sent to Gemini.” moved above the subject.
8. UC-OVERVIEW canvas height increased.

The first-pass claim that “no second repair loop was required” is **incorrect** and is withdrawn. A dedicated second visual-layout pass was required and is the current result.
