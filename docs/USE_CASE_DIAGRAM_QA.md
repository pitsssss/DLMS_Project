# SYRTAK / DLMS — Use Case Diagram QA

**Artifact:** `docs/diagrams/use-case/DLMS_USE_CASE_DIAGRAM.drawio`  
**Exports:** `docs/diagrams/use-case/exports/`  
**Modelling source:** `docs/USE_CASE_FINAL_MODEL.md`  
**Layout source:** `docs/USE_CASE_LAYOUT_BLUEPRINT.md`  
**Rendered:** PNG + SVG via diagrams.net viewer + system Chrome (`docs/diagrams/use-case/render_drawio.mjs`). Draw.io desktop CLI was not installed.  
**Visual inspection:** rendered PNG files were opened and inspected (full page + zoomed crops of dense UC-00 regions).

---

## Model validation

| Check | Result |
|-------|--------|
| Pages | **7/7** |
| Page names | PASS (UC-OVERVIEW … UC-05, exact titles) |
| UC-00 Use Cases | **45/45** |
| Duplicate UCs on UC-00 | **0** |
| Extra UCs on UC-00 | **0** |
| `<<include>>` relationship edges | **0** |
| `<<extend>>` relationship edges | **0** |
| AI Agent / Scheduler / Flutter / Next.js actors | **0** |
| UC-OVERVIEW Use Case ovals | **0** |
| UC-05 full ovals | **1** (UC-CIT-19 only) |
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

### Generalization validation

| Edge | Triangle direction | Result |
|------|--------------------|--------|
| 9 specialists → Employee | hollow block toward Employee | PASS |
| Admin → Employee | hollow block toward Employee | PASS |
| Super Admin → Admin | hollow block toward Admin | PASS |
| Total generalization edges on UC-00 | 11 | PASS |

Citizen “bus” and License Employee multi-links are **independent connectors** (parallel tracks), not a single merged UML edge.

---

## Visual validation

Inspected from the rendered PNGs (not XML-only).

| Page | Overlap | Connector issues | Text clipping | UML errors | Result |
|------|---------|------------------|---------------|------------|--------|
| UC-OVERVIEW | None after canvas height increase | Generalization spine outside subject; no actor–package lines | None | Hollow triangles toward parents | **FIXED** then **PASS** |
| UC-00 | Admin note overlapped SES-01 on first render | Guest→CIT-03 crossed region A on first render | `<<include>>` HTML ate stereotype text on first render | None remaining | **FIXED** then **PASS** |
| UC-01 | None | Mail crossed right-column ovals on first render; Guest→CIT-03 now top; Citizen left; Mail via gutter | None | No Sign In–Recover relationship | **FIXED** then **PASS** |
| UC-02 | None | CIT-10 association not visible / CIT-11 through review on first render; rerouted via gutters | None | No workflow arrows between spatial groups | **FIXED** then **PASS** |
| UC-03 | None | License Employee five independent horizontals; Citizen only Book / Change | None | No TST-02→LIC-01 arrow | **PASS** |
| UC-04 | None | Mail from left; Employee/Admin/SA from right; gen tree outside | None | Super Admin △ → Admin △ → Employee | **PASS** |
| UC-05 | Files note overlapped subject on first render; moved above | Only Citizen—CIT-19—Gemini; no extra ovals | None | Integration table is a UML note | **FIXED** then **PASS** |

UC-00 remains a large landscape master (4400 × 2680 conceptual px, export ~8784 × 5100 px at 2×). It is not A4-scaled.

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

## Visual fixes performed

One repair iteration after the first render (XML edit → re-render → re-inspect):

1. **UC-00** — Moved the three required notes below the system boundary so the Admin bypass note no longer covered UC-SES-01; increased canvas height to 2680.
2. **UC-00** — Rerouted Guest → UC-CIT-03 down the left corridor then onto the **top** of the oval (first render crossed region A).
3. **UC-00** — Offset Reviewer / Application Manager top-channel drop lines so they do not share one vertical with Payments.
4. **UC-00** — Displayed the include/extend prohibition with UML guillemets (`«include»` / `«extend»`) so HTML rendering would not swallow `<<…>>`.
5. **UC-01** — Routed Mail / SMTP along the account-package title band and inter-column gutter so lines do not pass through Sign In / Sign Out; Guest → Recover attaches from the top.
6. **UC-02** — Independent Citizen tracks: short stubs into Application Services; CIT-10 via the apps/docs gutter; CIT-11 via the bottom corridor (first render hid CIT-10 and crossed the review column).
7. **UC-05** — Moved “Files are never sent to Gemini.” above the subject so it does not overlap the boundary.
8. **UC-OVERVIEW** — Increased canvas height so the Fines Employee label and bottom notes are not clipped.

No further visual defects requiring a second repair loop were found after re-render.

---

## Final result (first pass)

**Not accepted.** Visual inspection still showed Actor–Use Case associations passing through unrelated Use Case ovals. See **Second Visual QA Pass** below.

---

## Second Visual QA Pass

The first pass treated correct XML `source`/`target` as sufficient. This pass treats the **rendered path** as the source of truth: a page fails if any Actor–Use Case polyline intersects the bounding box of an unrelated oval, actor figure, actor label, package title, or note.

The UML model is unchanged: 45 Use Cases, 53 UC-00 associations, `«include»` = 0, `«extend»` = 0, no added/removed/renamed actors, Use Cases, associations, or generalizations.

### Problems found (from the rejected first-pass renders)

| Page | Defect |
|------|--------|
| UC-01 | Guest → Verify Driving License through Browse Service Catalogs; Guest → Submit Contact Inquiry through Read Public Information; Citizen → Sign In through Register; Citizen → Sign Out through Recover; Citizen → Manage Account Preferences through Complete Identity Profile; self-service row lines through neighbouring ovals; Mail through Sign In |
| UC-02 | Reviewer / Application Manager routes through Payments (Pay Application Fees / Process Application Payments) |
| UC-03 | Test Employee → TST-01 / TST-02 through License Operations / Issue Driving License |
| UC-04 | Generalization spine and actor–UC associations shared one congested visual column; Admin lines crossed Employee Access ovals |
| UC-00 | Same class of defects at master scale: Guest/Citizen fan-out through first-column ovals; Review associations through Payments; Test Employee through License; right-side staff through unrelated domains |
| Presentation notes | Implementation-analysis notes (unblock-request, legacy APIs, Sign In vs Recover, FCM-not-on-this-page, “no workflow arrow…”) were still on report pages |

### Exact routing / layout repairs

**UC-01** — Public and Self-Service are single rows. Guest/Citizen use a left corridor, then a title-to-oval channel with one vertical drop per oval (never a horizontal through a neighbour). Account is two columns with a wide gutter at `x=900`: left-column UCs are entered from the left; Sign In drops from the account interior above the first row; Sign Out / Preferences enter from the gutter at inter-row y. Mail stays in the band *above* Sign In (`y=360` / `y=442`), never at Sign In height.

**UC-02** — Reviewer sits above Documents / Review; Application Manager below; Payment Employee right of Payments; Gateway above/right of Payments. Reviewer/AppMgr use the Documents right-padding lane (`x=930`). Citizen → CIT-10 uses the apps/docs gutter; Citizen → CIT-11 uses the bottom corridor then the docs/payments gutter. Review lines never enter the Payments package.

**UC-03** — Test Employee sits to the right of Test operations and *before* License Operations. TST-02 is a short left entry; TST-01 goes under the Test package and up the left side of TST-01. License Employee stays on the License stack; Fines Employee on Fines. Test lines do not enter the License package.

**UC-04** — Two visual concepts: (1) Use Cases stacked by package on the left, associations as short horizontals from a left-of-actor corridor; (2) generalization tree on a separate spine to the *right* of the actors. Super Admin → Admin is a local vertical. Admin associations no longer cross Employee Access ovals.

**UC-00** — Enlarged to 5400×3600. Left packages A–D are left-aligned stacks so Guest/Citizen horizontals at each oval’s `cy` cannot hit another oval. CIT-11 / 12 / 13 use inter-row gutters (never a first-column oval). E is a vertical stack; Reviewer / AppMgr / PayEmp / TestEmp live in the center–right gutter with short left entries. License / Fines / Employee / Reports / Audit / Settings stay on the right of their packages. External systems use perimeter corridors (Mail top channel `y=22`, Gateway spine `x=2095` left of its label). Master generalization is reduced to **Admin → Employee** and **Super Admin → Admin**, with the note: *“Specialized staff actors inherit from Employee; full hierarchy is shown in UC-OVERVIEW and UC-04.”* The full tree remains on UC-OVERVIEW / UC-04.

**Notes** — Presentation pages keep only architectural notes (AI channel/confirmation, files never sent to Gemini, Admin bypass, process order in Activity/Sequence, include/extend = 0). Modelling commentary stays in this QA file.

### Pages re-rendered

All seven pages, PNG + SVG:

`UC-OVERVIEW`, `UC-00`, `UC-01`, `UC-02`, `UC-03`, `UC-04`, `UC-05`

Generator waypoint check (orthogonal polylines vs oval / actor / label / title / note boxes) and a second trace of the **rendered SVG path elements** against ellipse bounding boxes both report **0 through-oval hits** on every page.

### Final result

| Page | Result |
|------|--------|
| UC-OVERVIEW | **PASS** — navigation only; full Employee tree; no UC ovals |
| UC-00 | **PASS** — 45 UCs, 53 independent associations, 2 displayed generalizations + hierarchy note |
| UC-01 | **PASS** — Guest and Citizen edges do not enter unrelated ovals |
| UC-02 | **PASS** — Reviewer/AppMgr isolated from Payments |
| UC-03 | **PASS** — Test Employee isolated from License Operations |
| UC-04 | **PASS** — generalization spine separate from actor–UC associations |
| UC-05 | **PASS** — CIT-19 only; architectural notes only |

**PASS** (second visual QA pass).

