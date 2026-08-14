# SYRTAK / DLMS — Use Case Layout Blueprint

**Purpose:** Exact visual architecture for the forthcoming draw.io file.  
**Modelling source:** `docs/USE_CASE_FINAL_MODEL.md` (unchanged actor and Use Case catalogue).  
**This file does not generate XML.**

Final draw.io pages (in this order):

1. UC-OVERVIEW — Use Case Model Overview  
2. UC-00 — Complete System Use Case Diagram  
3. UC-01 — Public, Account & Citizen Services  
4. UC-02 — Applications, Documents & Payments  
5. UC-03 — Testing & License Operations  
6. UC-04 — Employee & Administration  
7. UC-05 — AI Assistant & External Integrations  

---

## Global visual rules

| Rule | Application |
|------|-------------|
| UML | 2.x Use Case notation |
| Subject | `SYRTAK / Digital License Management System (DLMS)` |
| Actors | Outside the boundary |
| Use Cases | Inside the boundary |
| Associations | Solid lines, **no arrows** |
| Generalization | Hollow triangle **toward the parent** |
| `<<include>>` / `<<extend>>` | **None** |
| AI Agent / Scheduler | Never actor figures |
| Flutter / Next.js | Never actors |
| Dual HTTP surfaces | Never duplicate ovals |
| Background | White / very light grey (`#FFFFFF` / `#FAFBFC`) |
| Text | Dark (`#1F2937`); 12–14 pt oval labels; 11–12 pt actor names |
| Borders | Thin `#374151` subject; `#9CA3AF` package frames |
| Accents | One muted tint per capability region (fill ≤ 8% opacity). No gradients, shadows, or icons in ovals |
| Ovals | Same width per page (UC-00: ~200×70 px conceptual; detail pages: ~220×78) |
| Stick figures | Same size; abstract Employee may be italic / dashed outline |
| Connectors | Orthogonal preferred; short straight allowed. No curves through ovals or labels |
| Packages | Frames with a small title. **Not** actors. **Not** Use Cases |

English labels exactly as in the final model.

---

## UC-OVERVIEW — Use Case Model Overview

### 1. Canvas

- Orientation: landscape  
- Recommended size: 1600 × 900 px (presentation / A4-landscape friendly)  
- Title (above subject): **Use Case Model Overview**  
- Subtitle: `Navigation page — not the complete Use Case Diagram. See UC-00.`

### 2. System boundary

Centered rectangle, ~70% of canvas width, ~60% of height. Top-center label: `SYRTAK / Digital License Management System (DLMS)`.

### 3. Actor positions

**Outside, left:** Guest (upper-left), Citizen (mid-left).  
**Outside, right:** Employee generalization tree occupying the right third of the canvas (outside the subject).

Tree layout (top → bottom, indent right for children):

```
Employee (abstract)
  Profile & Document Reviewer
  Application Manager
  Payment Employee
  Test Employee
  License Employee
  Fines Employee
  Reports Employee
  Audit Employee
  Settings Employee
  Admin
    Super Admin
```

Hollow triangles on the generalization segments, pointing **up/left toward Employee**, except Super Admin’s triangle pointing **toward Admin**.

No Mail / Gemini / Payment Gateway / FCM on this page.

### 4. Use Case grouping

Five **package frames only** inside the subject, in a 2+2+1 or 3+2 grid:

| Slot | Package name |
|------|----------------|
| Top-left | Public, Account & Citizen Services |
| Top-center | Applications, Documents & Payments |
| Top-right | Testing & License Operations |
| Bottom-left | Employee & Administration |
| Bottom-right | AI Assistant & External Integrations |

Optional small page tags in each frame (`→ UC-01` … `→ UC-05`). No ovals.

### 5. Placement order

Packages left-to-right, top-to-bottom as the table above. No Use Case ovals.

### 6. Actor association routes

**None.** Packages are not Use Cases. Do not draw lines from actors into the five frames.

### 7. Generalization routes

Only the Employee tree (section 3). Keep the tree entirely in the right margin so it does not cross the subject border.

### 8. Note positions

- Bottom of subject, left: AI Assistant channel note.  
- Bottom of subject, right: Admin bypass note.  
- Below subject: “Scheduled jobs and automatic notifications are internal and are not shown as Use Cases.”

### 9. Crossing risks

Low. Only generalization edges.

### 10. Mitigation

Keep the tree outside the box. Do not attach actors to packages.

### 11. Density

**Low**

### 12. Suitability

A4 report: **Yes**. Presentation: **Yes**. Large appendix: optional (this page is the cover).

---

## UC-00 — Complete System Use Case Diagram

Authoritative master. **All 45 ovals appear exactly once.** Optimize for SVG/PDF, large-screen presentation, and A1/A2 appendix — **not** A4.

### 1. Canvas

- Orientation: landscape  
- Recommended size: **4200 × 2400 px** (≈ A1 landscape at 150 dpi)  
- Title: **Complete System Use Case Diagram**  
- Page name in draw.io: `UC-00`

### 2. System boundary

Large inner rectangle with **~280 px margins** on left and right for actors, **~200 px** top for external systems, **~220 px** bottom-right for Employee / Admin / Super Admin.

Twelve **package frames** inside (not ovals):

```
        LEFT                         CENTER                      RIGHT
TOP     A Public Information         E Review & Applications     H License Operations
        B Citizen Account            F Payments                  I Fines
MID     C Citizen License Services   G Testing                   J Employee Access
BOT     D Citizen Communications     K Administration & RBAC     L Reports, Audit & Settings
        & AI Assistant
```

Adjust: put **K** and **L** on the bottom row spanning center-right if J sits above them next to Employee.

Muted region tints (fill only):

| Region | Tint (≈8%) |
|--------|------------|
| A | cool grey-blue |
| B | blue |
| C | teal |
| D | violet |
| E | amber |
| F | green |
| G | cyan |
| H | indigo |
| I | rose |
| J | slate |
| K | orange |
| L | olive |

### 3. Actor positions

| Actor | Perimeter slot |
|-------|----------------|
| Guest | Far left, upper third (aligned with A/B) |
| Citizen | Far left, middle-to-lower (aligned with B–D and CIT-12/13 in G) |
| Mail / SMTP | Top, above B (account) |
| Gemini | Top, above D (AI) |
| Payment Gateway | Top-right, above F |
| Firebase FCM | Top, slightly right of Gemini (above CIT-18) |
| Profile & Document Reviewer | Right, aligned with E |
| Application Manager | Right, just below Reviewer (E) |
| Payment Employee | Right, aligned with F |
| Test Employee | Right, aligned with G |
| License Employee | Right, aligned with H |
| Fines Employee | Right, aligned with I |
| Reports Employee | Lower-right, aligned with L reports |
| Audit Employee | Lower-right, aligned with L audit |
| Settings Employee | Lower-right, aligned with L settings |
| Employee (abstract) | Lower-right corner, **outside**, left of Admin |
| Admin | Lower-right, right of Employee |
| Super Admin | Directly below Admin |

Do **not** place specialized staff as children overlapping association lines. Generalization tree occupies the **lower-right pocket**; domain specialists stay on the **right edge** in domain order. Two visual groups of the same actors are forbidden — each actor figure appears **once**.

**Resolution:** draw each specialized actor once on the right edge. From Employee (lower-right) run generalization polylines **up the right gutter** (outside the boundary) to each specialist, with the Admin→Employee and Super Admin→Admin segments local to the lower-right pocket. If that gutter becomes spaghetti, show the **full named tree only on UC-OVERVIEW and UC-04**, and on UC-00 draw only:

- Employee → Admin → Super Admin (required, local lower-right)  
- plus a note: “Other staff actors specialize Employee (see UC-OVERVIEW).”

**Chosen for UC-00:** Employee → Admin → Super Admin **plus** generalization from Employee to the nine specialists along the right gutter, routed in one vertical “spine” outside the box (one orthogonal bus, triangles pointing to Employee). This keeps SoD visible on the master page.

### 4. Use Case grouping

Packages A–L as in section 2. Spatial grouping only — **no arrows between ovals**.

### 5. Exact Use Case placement

See the master placement table below (`RnCm` = row n, column m **inside that region**).

### 6. Actor association routes

- Guest: enter A and the top of B from the left (horizontal).  
- Citizen: a **left vertical bus** just inside the left padding; short horizontals into B/C/D and into G’s citizen column (CIT-12, CIT-13) and F’s CIT-11.  
- Mail: down into B to CIT-01 and CIT-03; a long orthogonal along the **top then right then down** to J / EMP-01 — **do not** cut through C or E.  
- Gemini: short vertical into CIT-19.  
- FCM: short vertical into CIT-18.  
- Payment Gateway: down into F (CIT-11 and PAY-01).  
- Right-side staff: short horizontals from the right padding into their region.  
- Employee: up/left into J (EMP-01, EMP-02).  
- Admin: into K (USR-01, HR-01, RBAC-01) only.  
- Super Admin: into SES-01 only.

### 7. Generalization routes

Right-gutter bus; hollow triangles toward Employee (and Super Admin → Admin).

### 8. Note positions

- Inside D, under CIT-19: AI Assistant channel note (two short lines).  
- Inside K / near Admin: bypass note (Admin is **not** wired to every staff UC).  
- Bottom-left inside subject: “No `<<include>>` / `<<extend>>`.”

### 9. Crossing hotspots

| Hotspot | Why |
|---------|-----|
| Citizen fan-out | 18 associations from one actor |
| Guest + Citizen both to CIT-03 | Shared recover goal |
| Mail → EMP-01 | Crosses the top of the subject toward J |
| CIT-11 (Citizen + Gateway) vs PAY-01 (Payment Employee + Gateway) | Two actors into F |
| Right gutter generalizations vs staff associations | Same corridor |

### 10. Mitigation

- Citizen **bus** (one vertical line, stubs).  
- Guest associations stay in the **upper-left** of A/B; Citizen stubs start **below** Guest’s band except CIT-03 (Guest stub above oval, Citizen stub below).  
- Mail→EMP-01 travels on the **outer top/right channel**, never through ovals.  
- In F, CIT-11 on the **left** of the frame, PAY-01 on the **right**.  
- Generalization bus **outside** the subject; associations **inside** the right padding.

### 11. Density

**High**

### 12. Suitability

A4 report: **No** (unreadable). Presentation: **Yes** (zoomable). Large appendix (A1/A2): **Yes**.

---

### UC-00 placement table

| UC ID | Name | Region | Approx. row/col | Associated actor(s) |
|-------|------|--------|-----------------|---------------------|
| UC-PUB-01 | Browse Service Catalogs | A Public Information | A R1 C1 | Guest |
| UC-PUB-02 | Verify Driving License | A Public Information | A R1 C2 | Guest |
| UC-PUB-03 | Read Public Information | A Public Information | A R2 C1 | Guest |
| UC-PUB-04 | Submit Contact Inquiry | A Public Information | A R2 C2 | Guest |
| UC-CIT-01 | Register and Activate Account | B Citizen Account | B R1 C1 | Guest, Mail / SMTP |
| UC-CIT-02 | Sign In | B Citizen Account | B R1 C2 | Citizen |
| UC-CIT-03 | Recover Account Access | B Citizen Account | B R2 C1 | Guest, Citizen, Mail / SMTP |
| UC-CIT-04 | Sign Out | B Citizen Account | B R2 C2 | Citizen |
| UC-CIT-05 | Complete Identity Profile | B Citizen Account | B R3 C1 | Citizen |
| UC-CIT-06 | Manage Account Preferences | B Citizen Account | B R3 C2 | Citizen |
| UC-CIT-07 | Apply for New Driving License | C Citizen License Services | C R1 C1 | Citizen |
| UC-CIT-08 | Renew Driving License | C Citizen License Services | C R2 C1 | Citizen |
| UC-CIT-09 | Replace Lost or Damaged License | C Citizen License Services | C R3 C1 | Citizen |
| UC-CIT-10 | Provide Application Documents | C Citizen License Services | C R1 C2 | Citizen |
| UC-CIT-14 | Track License Application | C Citizen License Services | C R2 C2 | Citizen |
| UC-CIT-15 | View Own Licenses | C Citizen License Services | C R3 C2 | Citizen |
| UC-CIT-16 | View Own Fines | C Citizen License Services | C R4 C1 | Citizen |
| UC-CIT-17 | Manage Notifications | D Communications & AI | D R1 C1 | Citizen |
| UC-CIT-18 | Register Mobile Device for Push | D Communications & AI | D R2 C1 | Citizen, Firebase FCM |
| UC-CIT-19 | Use AI Assistant | D Communications & AI | D R3 C1 (right-biased) | Citizen, Gemini |
| UC-REV-01 | Review Citizen Identity Profiles | E Review & Applications | E R1 C1 | Profile & Document Reviewer |
| UC-REV-02 | Review Application Documents | E Review & Applications | E R2 C1 | Profile & Document Reviewer |
| UC-APP-01 | Inspect License Applications | E Review & Applications | E R3 C1 | Application Manager |
| UC-CIT-11 | Pay Application Fees | F Payments | F R1 C1 (left) | Citizen, Payment Gateway |
| UC-PAY-01 | Process Application Payments | F Payments | F R1 C2 (right) | Payment Employee, Payment Gateway |
| UC-CIT-12 | Book Driving Test | G Testing | G R1 C1 | Citizen |
| UC-CIT-13 | Change Test Appointment | G Testing | G R2 C1 | Citizen |
| UC-TST-01 | Manage Test Appointment Capacity | G Testing | G R1 C2 | Test Employee |
| UC-TST-02 | Record Driving Test Result | G Testing | G R2 C2 | Test Employee |
| UC-LIC-01 | Issue Driving License | H License Operations | H R1 C1 | License Employee |
| UC-LIC-02 | View / Inspect Issued Licenses | H License Operations | H R2 C1 | License Employee |
| UC-LIC-03 | Print Driving License | H License Operations | H R3 C1 | License Employee |
| UC-LIC-04 | Block Driving License | H License Operations | H R4 C1 | License Employee |
| UC-LIC-05 | Unblock Driving License | H License Operations | H R5 C1 | License Employee |
| UC-FIN-01 | Manage Citizen Fines | I Fines | I R1 C1 | Fines Employee |
| UC-EMP-01 | Authenticate to Employee Dashboard | J Employee Access | J R1 C1 | Employee, Mail / SMTP |
| UC-EMP-02 | View Operational Overview | J Employee Access | J R2 C1 | Employee |
| UC-USR-01 | Manage Citizen Accounts | K Administration & RBAC | K R1 C1 | Admin |
| UC-HR-01 | Manage Employee Accounts | K Administration & RBAC | K R2 C1 | Admin |
| UC-RBAC-01 | Administer Roles and Permissions | K Administration & RBAC | K R3 C1 | Admin |
| UC-SES-01 | Supervise Employee Sessions | K Administration & RBAC | K R4 C1 | Super Admin |
| UC-RPT-01 | View Operational Reports | L Reports, Audit & Settings | L R1 C1 | Reports Employee |
| UC-AUD-01 | View Audit Records | L Reports, Audit & Settings | L R2 C1 | Audit Employee |
| UC-SET-01 | Configure Catalogs and Fees | L Reports, Audit & Settings | L R3 C1 | Settings Employee |
| UC-MSG-01 | Handle Contact Messages | L Reports, Audit & Settings | L R4 C1 | Settings Employee |

Sign In (B R1 C2) and Recover (B R2 C1) are **adjacent** with **no** connector between them.

### Ovals per region

| Region | Count |
|--------|------:|
| A Public Information | 4 |
| B Citizen Account | 6 |
| C Citizen License Services | 7 |
| D Citizen Communications & AI Assistant | 3 |
| E Review & Applications | 3 |
| F Payments | 2 |
| G Testing | 4 |
| H License Operations | 5 |
| I Fines | 1 |
| J Employee Access | 2 |
| K Administration & RBAC | 4 |
| L Reports, Audit & Settings | 4 |
| **Total** | **45** |

### Expected actor connector count (UC-00)

| Actor | Associations |
|-------|-------------:|
| Guest | 6 |
| Citizen | 18 |
| Employee | 2 |
| Profile & Document Reviewer | 2 |
| Application Manager | 1 |
| Payment Employee | 1 |
| Test Employee | 2 |
| License Employee | 5 |
| Fines Employee | 1 |
| Reports Employee | 1 |
| Audit Employee | 1 |
| Settings Employee | 2 |
| Admin | 3 |
| Super Admin | 1 |
| Mail / SMTP | 3 |
| Payment Gateway | 2 |
| Gemini | 1 |
| Firebase FCM | 1 |
| **Association lines** | **53** |
| Generalization edges | 11 (9 specialists + Admin → Employee + Super Admin → Admin) |

Admin is **not** connected to the 20 SoD staff Use Cases.

### UC-00 completeness checklist (45, each once)

- [ ] UC-PUB-01 Browse Service Catalogs  
- [ ] UC-PUB-02 Verify Driving License  
- [ ] UC-PUB-03 Read Public Information  
- [ ] UC-PUB-04 Submit Contact Inquiry  
- [ ] UC-CIT-01 Register and Activate Account  
- [ ] UC-CIT-02 Sign In  
- [ ] UC-CIT-03 Recover Account Access  
- [ ] UC-CIT-04 Sign Out  
- [ ] UC-CIT-05 Complete Identity Profile  
- [ ] UC-CIT-06 Manage Account Preferences  
- [ ] UC-CIT-07 Apply for New Driving License  
- [ ] UC-CIT-08 Renew Driving License  
- [ ] UC-CIT-09 Replace Lost or Damaged License  
- [ ] UC-CIT-10 Provide Application Documents  
- [ ] UC-CIT-11 Pay Application Fees  
- [ ] UC-CIT-12 Book Driving Test  
- [ ] UC-CIT-13 Change Test Appointment  
- [ ] UC-CIT-14 Track License Application  
- [ ] UC-CIT-15 View Own Licenses  
- [ ] UC-CIT-16 View Own Fines  
- [ ] UC-CIT-17 Manage Notifications  
- [ ] UC-CIT-18 Register Mobile Device for Push  
- [ ] UC-CIT-19 Use AI Assistant  
- [ ] UC-EMP-01 Authenticate to Employee Dashboard  
- [ ] UC-EMP-02 View Operational Overview  
- [ ] UC-REV-01 Review Citizen Identity Profiles  
- [ ] UC-REV-02 Review Application Documents  
- [ ] UC-APP-01 Inspect License Applications  
- [ ] UC-PAY-01 Process Application Payments  
- [ ] UC-TST-01 Manage Test Appointment Capacity  
- [ ] UC-TST-02 Record Driving Test Result  
- [ ] UC-LIC-01 Issue Driving License  
- [ ] UC-LIC-02 View / Inspect Issued Licenses  
- [ ] UC-LIC-03 Print Driving License  
- [ ] UC-LIC-04 Block Driving License  
- [ ] UC-LIC-05 Unblock Driving License  
- [ ] UC-FIN-01 Manage Citizen Fines  
- [ ] UC-USR-01 Manage Citizen Accounts  
- [ ] UC-HR-01 Manage Employee Accounts  
- [ ] UC-RBAC-01 Administer Roles and Permissions  
- [ ] UC-SES-01 Supervise Employee Sessions  
- [ ] UC-RPT-01 View Operational Reports  
- [ ] UC-AUD-01 View Audit Records  
- [ ] UC-SET-01 Configure Catalogs and Fees  
- [ ] UC-MSG-01 Handle Contact Messages  

**Forbidden on UC-00:** second copy of any oval; workflow arrows; Admin-to-every-UC lines; AI Agent / Scheduler / Flutter / Next.js actors; `<<include>>` / `<<extend>>`.

---

## UC-01 — Public, Account & Citizen Services

### 1. Canvas

- Landscape, **1600 × 1000 px**  
- Title: `Public, Account & Citizen Services`

### 2. System boundary

Centered; ~200 px left margin (Guest + Citizen), ~180 px right margin (Mail).

### 3. Actor positions

- **Guest:** far left, upper half  
- **Citizen:** far left, lower/middle  
- **Mail / SMTP:** far right, vertically centered on CIT-01 / CIT-03  

### 4. Use Case grouping (visual only)

| Band | Contents |
|------|----------|
| Upper: Public | PUB-01 … PUB-04 |
| Middle: Authentication & Account | CIT-01 … CIT-06 |
| Lower: Citizen Self-Service / Information | CIT-15, CIT-16, CIT-17, CIT-18 |

### 5. Placement order

Public 2×2: PUB-01 | PUB-02 ; PUB-03 | PUB-04.  
Account 3×2: CIT-01 Register | CIT-02 Sign In ; CIT-03 Recover | CIT-04 Sign Out ; CIT-05 Profile | CIT-06 Preferences.  
Self-service row: CIT-15 Licenses | CIT-16 Fines | CIT-17 Notifications | CIT-18 Push device.

Sign In and Recover sit in neighbouring cells. **No line between them.**

### 6. Association routes

- Guest → four Public ovals (upper-left stubs).  
- Guest → CIT-01 and CIT-03 (down from Guest, stay above Citizen’s band).  
- Citizen → CIT-02, CIT-03, CIT-04, CIT-05, CIT-06, CIT-15, CIT-16, CIT-17, CIT-18. Use a left inner bus.  
- Mail → CIT-01 and CIT-03 from the right.

### 7. Generalization

None.

### 8. Notes

- Bottom: “FCM is shown on UC-00 / UC-05, not on this page.”  
- Optional: “Sign In and Recover Account Access are independent goals.”

### 9. Crossing risks

Guest vs Citizen at CIT-03; Mail vs Citizen bus.

### 10. Mitigation

CIT-03: Guest attaches **top**, Citizen **left**, Mail **right**. Citizen bus starts at Sign In and runs downward.

### 11. Density

**Medium**

### 12. Suitability

A4: **Yes** (landscape). Presentation: **Yes**. Large appendix: optional.

---

## UC-02 — Applications, Documents & Payments

### 1. Canvas

- Landscape, **1800 × 1100 px**  
- Title: `Applications, Documents & Payments`

### 2. System boundary

Left margin ~180 px (Citizen). Right margin ~240 px (three staff + Payment Gateway stacked).

### 3. Actor positions

- **Citizen:** far left, vertically centered  
- **Profile & Document Reviewer:** right, upper (aligned with review ovals)  
- **Application Manager:** right, middle (Inspect Applications)  
- **Payment Employee:** right, lower (Process Payments)  
- **Payment Gateway:** far right or upper-right, aligned with the Payments band  

### 4. Grouping (spatial only — no process arrows)

Left: Application Services (CIT-07, 08, 09).  
Center: Documents / Review (CIT-10, REV-01, REV-02, APP-01).  
Right-inside: Payments (CIT-11, PAY-01).  
CIT-14 Track near Citizen, above or left of the application column.

### 5. Placement order

Vertical application group (left column, top→bottom):

1. Apply for New Driving License  
2. Renew Driving License  
3. Replace Lost or Damaged License  

Center: Provide Application Documents (left-center); Review Application Documents (right-center); Review Citizen Identity Profiles **above** document review (separate); Inspect License Applications below document review.

Right-inside: Pay Application Fees (upper, toward Citizen + Gateway); Process Application Payments (lower, toward Payment Employee + Gateway).

Track License Application: upper-left inside, near Citizen, **above** Apply.

### 6. Association routes

- Citizen → CIT-07, 08, 09, 10, 11, 14 (left bus + stubs).  
- Reviewer → REV-01 and REV-02 only (two horizontals).  
- Application Manager → APP-01.  
- Payment Employee → PAY-01.  
- Payment Gateway → CIT-11 and PAY-01 (from top-right / far right).

### 7. Generalization

None required (Reviewer specializes Employee on UC-OVERVIEW / UC-00 / UC-04).

### 8. Notes

- “Spatial order is not a workflow. See activity/sequence diagrams.”  
- “Inspect License Applications is read-only.”  
- “Direct renew/replacement APIs are not Use Cases.”

### 9. Crossing risks

Citizen→CIT-11 crossing Reviewer lines; Gateway vs Payment Employee.

### 10. Mitigation

Keep review associations in the **upper-right** of the center band. CIT-11 sits in the payments frame, not in the review frame. Gateway lines stay inside the payments frame.

### 11. Density

**Medium**

### 12. Suitability

A4 landscape: **Yes**. Presentation: **Yes**. Large appendix: optional.

---

## UC-03 — Testing & License Operations

### 1. Canvas

- Landscape, **1800 × 1100 px**  
- Title: `Testing & License Operations`

### 2. System boundary

Left ~180 px (Citizen). Right ~220 px (Test / License / Fines employees, top→bottom).

### 3. Actor positions

- **Citizen:** far left  
- **Test Employee:** right, upper (slots + record result)  
- **License Employee:** right, middle (five license ovals)  
- **Fines Employee:** right, lower (Manage Citizen Fines)

### 4. Grouping

- Citizen appointments (left): CIT-12, CIT-13  
- Test operations (center): TST-01, TST-02  
- License issuance/management (right-inside): LIC-01 … LIC-05 vertical  
- Fines (bottom-right inside): FIN-01  

### 5. Placement order

Left column: Book Driving Test; Change Test Appointment.  
Center: Manage Test Appointment Capacity; Record Driving Test Result (below).  
Right-inside, **vertical alignment**:

1. Issue Driving License  
2. View / Inspect Issued Licenses  
3. Print Driving License  
4. Block Driving License  
5. Unblock Driving License  

FIN-01 below the license column or in its own fines frame.

### 6. Association routes

- Citizen → CIT-12, CIT-13 only.  
- Test Employee → TST-01, TST-02.  
- License Employee → five license ovals (one vertical bus + stubs).  
- Fines Employee → FIN-01.

### 7. Generalization

None on this page.

### 8. Notes

- “Tests apply to new-license applications only.”  
- “No workflow arrow from Record Driving Test Result to Issue Driving License.”  
- “Citizen unblock-request is not a Use Case.”

### 9. Crossing risks

Low if bands stay stacked left→right.

### 10. Mitigation

Do not draw Citizen to TST or LIC ovals. Do not connect TST-02 to LIC-01.

### 11. Density

**Medium**

### 12. Suitability

A4 landscape: **Yes**. Presentation: **Yes**. Large appendix: optional.

---

## UC-04 — Employee & Administration

### 1. Canvas

- Landscape, **1800 × 1200 px**  
- Title: `Employee & Administration`

### 2. System boundary

Shifted **left-of-center** so the right third can hold the generalization tree **outside** the box.

### 3. Actor positions

**Outside left:** Mail / SMTP (aligned with EMP-01).

**Outside right (tree, unmistakable):**

```
                Employee (abstract)
                      △
     ┌────────┬───────┼────────┬─────────┐
     │        │       │        │         │
  Reports   Audit  Settings  Admin     (other specialists optional)
                       │        △
                       │     Super Admin
```

**Minimum visible generalizations:**

- Admin specializes Employee  
- Super Admin specializes Admin  
- Reports Employee specializes Employee  
- Audit Employee specializes Employee  
- Settings Employee specializes Employee  

Other specialists **may** be shown if the tree stays readable; otherwise omit them here (they exist on UC-OVERVIEW / UC-00).

Place Employee **mid-right**. Admin below-right of Employee. Super Admin below Admin. Reports / Audit / Settings as a column left of Admin, still outside the subject.

### 4. Grouping

Upper-left inside: Employee Access (EMP-01, EMP-02).  
Center: Administration (USR-01, HR-01, RBAC-01, SES-01).  
Lower: Oversight (RPT-01, AUD-01).  
Lower-right inside: Settings (SET-01, MSG-01).

### 5. Placement order

- EMP-01 Authenticate (top-left inside, near Mail)  
- EMP-02 Overview (below EMP-01)  
- USR-01, HR-01, RBAC-01 in a vertical stack (center, toward Admin)  
- SES-01 immediately under that stack, **closest to Super Admin**  
- RPT-01 toward Reports Employee  
- AUD-01 toward Audit Employee  
- SET-01 then MSG-01 toward Settings Employee  

### 6. Association routes

- Employee → EMP-01, EMP-02  
- Admin → USR-01, HR-01, RBAC-01 **only**  
- Super Admin → SES-01 **only**  
- Reports Employee → RPT-01  
- Audit Employee → AUD-01  
- Settings Employee → SET-01, MSG-01  
- Mail / SMTP → EMP-01  

### 7. Generalization routes

Vertical/orthogonal **outside** the boundary. Triangle on Super Admin→Admin points **up to Admin**. Triangles on Admin and Reports/Audit/Settings point **to Employee**. Never put the triangle on the child end.

### 8. Notes

- Admin bypass note (not wired to SoD UCs on other pages).  
- “Primary actor of Manage Citizen Accounts is Admin; Super Admin inherits.”  
- “Only role `super_admin` can Supervise Employee Sessions.”

### 9. Crossing risks

Mail vs Employee→EMP-01; Super Admin vs Admin stacks.

### 10. Mitigation

Mail from the **left**. Employee from the **right** into EMP-01/02. SES-01 lowest in the admin stack so Super Admin’s line does not cross Admin’s three lines (Admin attaches to the upper three ovals from the right; Super Admin attaches to SES-01 from below-right).

### 11. Density

**Medium**

### 12. Suitability

A4 landscape: **Yes**. Presentation: **Yes** (best page to explain RBAC). Large appendix: optional.

---

## UC-05 — AI Assistant & External Integrations

### 1. Canvas

- Landscape, **1400 × 900 px**  
- Title: `AI Assistant & External Integrations`

### 2. System boundary

Modest centered box. **One** oval inside: UC-CIT-19 Use AI Assistant.

### 3. Actor positions

- **Citizen:** left of the oval, vertically centered  
- **Gemini:** right of the oval, vertically centered  
- **Mail / SMTP, Payment Gateway, Firebase FCM:** along the **bottom** of the canvas, **outside** the subject, as labels for the integration table — **not** extra stick figures unless needed. Prefer **named table rows** with a small actor glyph per row to avoid a second Gemini/Citizen pair.

If stick figures are used for Mail / Gateway / FCM, place them under the table, not associated to duplicate ovals.

### 4. Grouping

Single centered Use Case. Integration reference **table** below or beside the subject (inside a UML note, not a package of ovals).

### 5. Placement order

1. Oval: Use AI Assistant  
2. Note A (above or under oval)  
3. Note B (files / Gemini)  
4. Integration table  
5. Note C (notifications vs FCM)

### 6. Association routes

**Only:**

`Citizen ——— Use AI Assistant ——— Gemini`

No arrows. No other association lines. **Do not** duplicate CIT-01, CIT-03, EMP-01, CIT-11, PAY-01, CIT-18 as ovals on this page.

### 7. Generalization

None.

### 8. Notes (must be visible)

**Note 1 (near oval):**

> AI Assistant is an alternative assisted interaction channel for supported citizen operations. Mutating operations require citizen confirmation.

**Note 2 (near Gemini):**

> Files are never sent to Gemini.

**Integration table (UML note):**

| External actor | Use Case |
|----------------|----------|
| Mail / SMTP | Register and Activate Account; Recover Account Access; Authenticate to Employee Dashboard |
| Payment Gateway | Pay Application Fees; Process Application Payments |
| Firebase FCM | Register Mobile Device for Push / optional push delivery |

**Note 3:**

> Database notifications are the source of truth; FCM is an optional delivery channel.

**Note 4 (small):** Mock payment provider is internal and is not an actor.

Optional short supported-operations list from the final model §4.4 (reads / mutations / not supported) in a second note if space remains.

### 9. Crossing risks

None if only two association segments exist.

### 10. Mitigation

Do not add ovals “for completeness.” Completeness of the 45 is **UC-00’s** job.

### 11. Density

**Low**

### 12. Suitability

A4: **Yes**. Presentation: **Yes** (clean spotlight). Large appendix: optional.

---

## End totals

| Item | Value |
|------|-------|
| Expected final draw.io page count | **7** |
| Expected master UC count (UC-00) | **45** |
| Duplicate UC count on UC-00 | **0** |
| Detail pages may **repeat** UCs for readability | UC-01…UC-05 are projections, not extra catalogue items |
| `<<include>>` | **0** |
| `<<extend>>` | **0** |

Detail-page repeats (same ID, not new Use Cases): e.g. CIT-07 appears on UC-00 and UC-02. That is standard multi-page decomposition, not duplication of the model.

---

## Remaining visual risks

1. **UC-00 Citizen fan-out (18 lines)** — must use a bus; otherwise spaghetti.  
2. **UC-00 right-gutter generalizations vs staff associations** — keep generalizations outside the subject.  
3. **Mail → EMP-01 on UC-00** — long orthogonal on the outer channel.  
4. **Guest + Citizen + Mail at CIT-03** — three-side attach order must be respected on UC-00 and UC-01.  
5. **UC-00 not A4-readable** — export SVG/PDF with zoom; print A1/A2 for committee appendix.  
6. **Specialist actors appearing twice** (right edge + tree) — UC-00 uses one figure per actor.  
7. **Temptation to add workflow arrows** on UC-02/UC-03 — forbidden.  
8. **Temptation to wire Admin to every staff UC** — forbidden; bypass is a note.  
9. **FCM omitted on UC-00** — must connect to CIT-18 on the master.  
10. **UC-05 accidentally duplicating six ovals** — table only.

No modelling blockers remain. XML/draw.io may be generated from this blueprint plus `docs/USE_CASE_FINAL_MODEL.md`.
