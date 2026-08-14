#!/usr/bin/env python3
"""Generate SYRTAK/DLMS UML Use Case draw.io XML from the approved model.

Modelling: docs/USE_CASE_FINAL_MODEL.md
Layout:    docs/USE_CASE_LAYOUT_BLUEPRINT.md
"""
from __future__ import annotations

import html as htmlmod
import re
import xml.etree.ElementTree as ET
from pathlib import Path

OUT = Path(__file__).resolve().parent / "DLMS_USE_CASE_DIAGRAM.drawio"
EXPORTS = Path(__file__).resolve().parent / "exports"

FONT = "Segoe UI"
INK = "#0F172A"
MUTED = "#64748B"
LINE = "#475569"
BORDER = "#334155"
SUBJECT_STROKE = "#374151"
PKG_STROKE = "#9CA3AF"
NOTE_FILL = "#FFFBEB"
NOTE_STROKE = "#B45309"
OVAL_FILL = "#FFFFFF"

TINTS = {
    "A": "#E8EEF4",
    "B": "#E8F1FB",
    "C": "#E6F4F1",
    "D": "#F0EAF8",
    "E": "#FBF3E4",
    "F": "#E7F5EC",
    "G": "#E5F6F8",
    "H": "#E8EAF6",
    "I": "#F8E8EE",
    "J": "#EEF1F4",
    "K": "#F8EEE4",
    "L": "#EEF2E6",
}

UC_NAMES = {
    "UC-PUB-01": "Browse Service Catalogs",
    "UC-PUB-02": "Verify Driving License",
    "UC-PUB-03": "Read Public Information",
    "UC-PUB-04": "Submit Contact Inquiry",
    "UC-CIT-01": "Register and Activate Account",
    "UC-CIT-02": "Sign In",
    "UC-CIT-03": "Recover Account Access",
    "UC-CIT-04": "Sign Out",
    "UC-CIT-05": "Complete Identity Profile",
    "UC-CIT-06": "Manage Account Preferences",
    "UC-CIT-07": "Apply for New Driving License",
    "UC-CIT-08": "Renew Driving License",
    "UC-CIT-09": "Replace Lost or Damaged License",
    "UC-CIT-10": "Provide Application Documents",
    "UC-CIT-11": "Pay Application Fees",
    "UC-CIT-12": "Book Driving Test",
    "UC-CIT-13": "Change Test Appointment",
    "UC-CIT-14": "Track License Application",
    "UC-CIT-15": "View Own Licenses",
    "UC-CIT-16": "View Own Fines",
    "UC-CIT-17": "Manage Notifications",
    "UC-CIT-18": "Register Mobile Device for Push",
    "UC-CIT-19": "Use AI Assistant",
    "UC-EMP-01": "Authenticate to Employee Dashboard",
    "UC-EMP-02": "View Operational Overview",
    "UC-REV-01": "Review Citizen Identity Profiles",
    "UC-REV-02": "Review Application Documents",
    "UC-APP-01": "Inspect License Applications",
    "UC-PAY-01": "Process Application Payments",
    "UC-TST-01": "Manage Test Appointment Capacity",
    "UC-TST-02": "Record Driving Test Result",
    "UC-LIC-01": "Issue Driving License",
    "UC-LIC-02": "View / Inspect Issued Licenses",
    "UC-LIC-03": "Print Driving License",
    "UC-LIC-04": "Block Driving License",
    "UC-LIC-05": "Unblock Driving License",
    "UC-FIN-01": "Manage Citizen Fines",
    "UC-USR-01": "Manage Citizen Accounts",
    "UC-HR-01": "Manage Employee Accounts",
    "UC-RBAC-01": "Administer Roles and Permissions",
    "UC-SES-01": "Supervise Employee Sessions",
    "UC-RPT-01": "View Operational Reports",
    "UC-AUD-01": "View Audit Records",
    "UC-SET-01": "Configure Catalogs and Fees",
    "UC-MSG-01": "Handle Contact Messages",
}

ALL_UC_IDS = list(UC_NAMES.keys())
assert len(ALL_UC_IDS) == 45

UC00_ASSOCS = [
    ("Guest", "UC-PUB-01"), ("Guest", "UC-PUB-02"), ("Guest", "UC-PUB-03"),
    ("Guest", "UC-PUB-04"), ("Guest", "UC-CIT-01"), ("Guest", "UC-CIT-03"),
    ("Citizen", "UC-CIT-02"), ("Citizen", "UC-CIT-03"), ("Citizen", "UC-CIT-04"),
    ("Citizen", "UC-CIT-05"), ("Citizen", "UC-CIT-06"), ("Citizen", "UC-CIT-07"),
    ("Citizen", "UC-CIT-08"), ("Citizen", "UC-CIT-09"), ("Citizen", "UC-CIT-10"),
    ("Citizen", "UC-CIT-11"), ("Citizen", "UC-CIT-12"), ("Citizen", "UC-CIT-13"),
    ("Citizen", "UC-CIT-14"), ("Citizen", "UC-CIT-15"), ("Citizen", "UC-CIT-16"),
    ("Citizen", "UC-CIT-17"), ("Citizen", "UC-CIT-18"), ("Citizen", "UC-CIT-19"),
    ("Employee", "UC-EMP-01"), ("Employee", "UC-EMP-02"),
    ("Reviewer", "UC-REV-01"), ("Reviewer", "UC-REV-02"),
    ("AppMgr", "UC-APP-01"),
    ("PayEmp", "UC-PAY-01"),
    ("TestEmp", "UC-TST-01"), ("TestEmp", "UC-TST-02"),
    ("LicEmp", "UC-LIC-01"), ("LicEmp", "UC-LIC-02"), ("LicEmp", "UC-LIC-03"),
    ("LicEmp", "UC-LIC-04"), ("LicEmp", "UC-LIC-05"),
    ("FinesEmp", "UC-FIN-01"),
    ("Reports", "UC-RPT-01"),
    ("Audit", "UC-AUD-01"),
    ("Settings", "UC-SET-01"), ("Settings", "UC-MSG-01"),
    ("Admin", "UC-USR-01"), ("Admin", "UC-HR-01"), ("Admin", "UC-RBAC-01"),
    ("SuperAdmin", "UC-SES-01"),
    ("Mail", "UC-CIT-01"), ("Mail", "UC-CIT-03"), ("Mail", "UC-EMP-01"),
    ("Gateway", "UC-CIT-11"), ("Gateway", "UC-PAY-01"),
    ("Gemini", "UC-CIT-19"),
    ("FCM", "UC-CIT-18"),
]
assert len(UC00_ASSOCS) == 53

ASSOC_NONE = (
    "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;"
    "html=1;endArrow=none;startArrow=none;endFill=0;startFill=0;"
    f"strokeColor={LINE};strokeWidth=1.15;fontFamily={FONT};"
)
GEN_STYLE = (
    "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;"
    "html=1;endArrow=block;endFill=0;startArrow=none;endSize=14;"
    f"strokeColor={INK};strokeWidth=1.4;fontFamily={FONT};"
)


def esc(text: str) -> str:
    return htmlmod.escape(text, quote=True)


def uc_html(uid: str, name: str) -> str:
    return (
        f'<font style="font-size:9px;" color="{MUTED}">{esc(uid)}</font>'
        f"<br/><b>{esc(name)}</b>"
    )


class Page:
    def __init__(self, pid: str, name: str, width: int, height: int):
        self.pid = pid
        self.name = name
        self.width = width
        self.height = height
        self.cells: list[str] = []
        self._n = 0
        self.ids: dict[str, str] = {}
        self.geom: dict[str, tuple[float, float, float, float]] = {}
        self.assoc_list: list[tuple[str, str]] = []
        self.gen_list: list[tuple[str, str]] = []
        self.assoc_routes: list[dict] = []
        self.pkg_keys: list[str] = []
        self.note_keys: list[str] = []

    def nid(self, key: str) -> str:
        self._n += 1
        cid = f"{self.pid}_{self._n}"
        self.ids[key] = cid
        return cid

    def add(self, xml: str) -> None:
        self.cells.append(xml)

    def vertex(self, key: str, value: str, style: str, x, y, w, h) -> str:
        cid = self.nid(key)
        self.geom[key] = (float(x), float(y), float(w), float(h))
        self.add(
            f'<mxCell id="{cid}" value="{esc(value)}" style="{style}" vertex="1" parent="1">'
            f'<mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry"/>'
            f"</mxCell>"
        )
        return cid

    def html_vertex(self, key: str, inner_html: str, style: str, x, y, w, h) -> str:
        cid = self.nid(key)
        self.geom[key] = (float(x), float(y), float(w), float(h))
        self.add(
            f'<mxCell id="{cid}" value="{esc(inner_html)}" style="{style}" vertex="1" parent="1">'
            f'<mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry"/>'
            f"</mxCell>"
        )
        return cid

    def edge(self, key: str, source_key: str, target_key: str, style: str, points=None,
             exit_pt=None, entry_pt=None) -> str:
        cid = self.nid(key)
        st = style
        if exit_pt:
            st += f"exitX={exit_pt[0]};exitY={exit_pt[1]};exitDx=0;exitDy=0;"
        if entry_pt:
            st += f"entryX={entry_pt[0]};entryY={entry_pt[1]};entryDx=0;entryDy=0;"
        src = self.ids[source_key]
        tgt = self.ids[target_key]
        pts = ""
        if points:
            inner = "".join(f'<mxPoint x="{px}" y="{py}"/>' for px, py in points)
            pts = f'<Array as="points">{inner}</Array>'
        self.add(
            f'<mxCell id="{cid}" style="{st}" edge="1" parent="1" source="{src}" target="{tgt}">'
            f'<mxGeometry relative="1" as="geometry">{pts}</mxGeometry>'
            f"</mxCell>"
        )
        return cid

    def title(self, text: str, sub: str | None = None) -> None:
        self.html_vertex(
            "title",
            f"<b>{esc(text)}</b>",
            f"text;html=1;align=center;verticalAlign=middle;fontFamily={FONT};fontSize=22;fontStyle=1;fontColor={INK};",
            40, 10, self.width - 80, 34,
        )
        if sub:
            self.vertex(
                "subtitle",
                sub,
                f"text;html=1;align=center;verticalAlign=middle;fontFamily={FONT};fontSize=13;fontColor={MUTED};fontStyle=2;",
                40, 44, self.width - 80, 22,
            )

    def subject(self, x, y, w, h, label="SYRTAK / Digital License Management System (DLMS)") -> None:
        self.vertex(
            "subject",
            label,
            f"rounded=0;whiteSpace=wrap;html=1;align=center;verticalAlign=top;spacingTop=8;"
            f"fillColor=#FAFBFC;strokeColor={SUBJECT_STROKE};strokeWidth=1.8;"
            f"fontFamily={FONT};fontSize=13;fontStyle=1;fontColor={INK};dashed=0;",
            x, y, w, h,
        )

    def pkg(self, key: str, title: str, tint: str, x, y, w, h) -> None:
        self.pkg_keys.append(key)
        self.html_vertex(
            key,
            title.replace("\n", "<br/>"),
            f"rounded=1;whiteSpace=wrap;html=1;align=center;verticalAlign=top;spacingTop=6;"
            f"arcSize=4;fillColor={tint};fillOpacity=40;strokeColor={PKG_STROKE};strokeWidth=1;"
            f"fontFamily={FONT};fontSize=11;fontStyle=1;fontColor={MUTED};dashed=0;",
            x, y, w, h,
        )

    def uc(self, uid: str, x, y, w=210, h=72) -> None:
        style = (
            f"ellipse;whiteSpace=wrap;html=1;fillColor={OVAL_FILL};"
            f"strokeColor={BORDER};strokeWidth=1.5;fontFamily={FONT};fontSize=11;"
            f"fontColor={INK};align=center;verticalAlign=middle;"
        )
        self.html_vertex(f"uc:{uid}", uc_html(uid, UC_NAMES[uid]), style, x, y, w, h)

    def actor(self, key: str, label: str, x, y, abstract=False, w=48, h=78) -> None:
        lw = 180 if len(label) > 14 else 120
        if abstract:
            value = (
                f"<i>{label}</i><br/>"
                f'<font style="font-size:9px;" color="{MUTED}">{{abstract}}</font>'
            )
        else:
            value = label
        style = (
            f"shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;"
            f"outlineConnect=0;fillColor=#FFFFFF;strokeColor={INK};strokeWidth=1.5;"
            f"fontFamily={FONT};fontSize=11;fontColor={INK};labelWidth={lw};"
        )
        if abstract:
            style += "fontStyle=2;"
        self.html_vertex(f"act:{key}", value, style, x, y, w, h)

    def note(self, key: str, text: str, x, y, w, h) -> None:
        self.note_keys.append(key)
        style = (
            f"shape=note;whiteSpace=wrap;html=1;size=16;fillColor={NOTE_FILL};"
            f"strokeColor={NOTE_STROKE};align=left;verticalAlign=top;spacingLeft=10;"
            f"spacingRight=8;spacingTop=8;fontFamily={FONT};fontSize=11;fontColor={INK};"
        )
        self.html_vertex(key, text.replace("\n", "<br/>"), style, x, y, w, h)

    def assoc(self, actor_key: str, uid: str, points=None, exit_pt=None, entry_pt=None, tag="") -> None:
        self.assoc_list.append((actor_key, uid))
        self.assoc_routes.append({
            "actor": actor_key,
            "uid": uid,
            "points": list(points or []),
            "exit_pt": exit_pt or (1, 0.5),
            "entry_pt": entry_pt or (0, 0.5),
        })
        self.edge(
            f"a:{actor_key}:{uid}:{tag}",
            f"act:{actor_key}",
            f"uc:{uid}",
            ASSOC_NONE,
            points=points,
            exit_pt=exit_pt,
            entry_pt=entry_pt,
        )

    def generalize(self, child: str, parent: str, points=None, exit_pt=None, entry_pt=None) -> None:
        self.gen_list.append((child, parent))
        self.edge(
            f"g:{child}:{parent}",
            f"act:{child}",
            f"act:{parent}",
            GEN_STYLE,
            points=points,
            exit_pt=exit_pt,
            entry_pt=entry_pt,
        )

    def xml(self) -> str:
        body = "\n".join(self.cells)
        return (
            f'<diagram id="{self.pid}" name="{esc(self.name)}">\n'
            f'<mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" '
            f'connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="{self.width}" '
            f'pageHeight="{self.height}" math="0" shadow="0" background="#FFFFFF">\n'
            f"<root>\n<mxCell id=\"0\"/>\n<mxCell id=\"1\" parent=\"0\"/>\n"
            f"{body}\n</root>\n</mxGraphModel>\n</diagram>"
        )


def grid_in(pkg, items, cols, oval_w=200, oval_h=70, top=40, pad=20):
    x, y, w, h = pkg
    rows = (len(items) + cols - 1) // cols
    usable_w = w - 2 * pad
    usable_h = h - top - pad
    gap_x = max(12, (usable_w - cols * oval_w) / max(cols, 1))
    gap_y = max(10, (usable_h - rows * oval_h) / max(rows, 1)) if rows else 0
    out = {}
    for i, uid in enumerate(items):
        r, c = divmod(i, cols)
        ox = x + pad + c * (oval_w + gap_x)
        oy = y + top + r * (oval_h + gap_y)
        out[uid] = (ox, oy, oval_w, oval_h)
    return out


def vstack_in(pkg, items, oval_w=210, oval_h=68, top=40, pad=22, align="center"):
    x, y, w, h = pkg
    n = len(items)
    usable_h = h - top - pad
    gap = (usable_h - n * oval_h) / max(n, 1)
    gap = max(14, min(28, gap))
    ox = x + pad if align == "left" else x + (w - oval_w) / 2
    out = {}
    for i, uid in enumerate(items):
        oy = y + top + i * (oval_h + gap)
        out[uid] = (ox, oy, oval_w, oval_h)
    return out


def row_in(pkg, items, oval_w=220, oval_h=70, top=52, pad=28):
    x, y, w, h = pkg
    n = len(items)
    gap = max(48, (w - 2 * pad - n * oval_w) / max(n - 1, 1))
    ox0 = x + pad
    oy = y + top
    out = {}
    for i, uid in enumerate(items):
        out[uid] = (ox0 + i * (oval_w + gap), oy, oval_w, oval_h)
    return out


def left_mid(g):
    x, y, w, h = g
    return x, y + h / 2


def right_mid(g):
    x, y, w, h = g
    return x + w, y + h / 2


def attach(g, pt):
    x, y, w, h = g
    fx, fy = pt
    return x + w * fx, y + h * fy


def _seg_hits_rect(x1, y1, x2, y2, rx, ry, rw, rh, inset=1.0) -> bool:
    """True if an orthogonal (or short diagonal) segment enters a rectangle."""
    left, right = rx + inset, rx + rw - inset
    top, bottom = ry + inset, ry + rh - inset
    if right <= left or bottom <= top:
        return False
    if abs(x1 - x2) < 0.6:
        x = (x1 + x2) / 2
        ylo, yhi = min(y1, y2), max(y1, y2)
        return left < x < right and ylo < bottom and yhi > top
    if abs(y1 - y2) < 0.6:
        y = (y1 + y2) / 2
        xlo, xhi = min(x1, x2), max(x1, x2)
        return top < y < bottom and xlo < right and xhi > left
    sl, sr = min(x1, x2), max(x1, x2)
    st, sb = min(y1, y2), max(y1, y2)
    return not (sr <= left or sl >= right or sb <= top or st >= bottom)


def route_hits(page: Page) -> list[str]:
    """Geometric trace: actor–UC polylines must not enter unrelated ovals/actors/titles/notes."""
    hits = []
    ovals = {k[3:]: g for k, g in page.geom.items() if k.startswith("uc:")}
    actors = {k[4:]: g for k, g in page.geom.items() if k.startswith("act:")}
    for route in page.assoc_routes:
        actor, uid = route["actor"], route["uid"]
        if f"act:{actor}" not in page.geom or f"uc:{uid}" not in page.geom:
            hits.append(f"{page.pid}: missing geom for {actor}->{uid}")
            continue
        start = attach(page.geom[f"act:{actor}"], route["exit_pt"])
        end = attach(page.geom[f"uc:{uid}"], route["entry_pt"])
        poly = [start, *route["points"], end]
        segs = [(poly[i], poly[i + 1]) for i in range(len(poly) - 1)]
        for other, og in ovals.items():
            if other == uid:
                continue
            for (a, b) in segs:
                if _seg_hits_rect(a[0], a[1], b[0], b[1], *og):
                    hits.append(f"{page.pid}: {actor}->{uid} through oval {other}")
                    break
        for other, ag in actors.items():
            lx, ly, lw, lh = ag
            label = (lx - 66, ly + lh, 180, 22)
            rects = [(label, "actor-label")]
            if other != actor:
                rects.insert(0, (ag, "actor"))
            for rect, kind in rects:
                for (a, b) in segs:
                    if _seg_hits_rect(a[0], a[1], b[0], b[1], *rect):
                        hits.append(f"{page.pid}: {actor}->{uid} through {kind} {other}")
                        break
        for pk in page.pkg_keys:
            px, py, pw, ph = page.geom[pk]
            title = (px + pw * 0.22, py + 2, pw * 0.56, 22)
            for (a, b) in segs:
                if _seg_hits_rect(a[0], a[1], b[0], b[1], *title, inset=0.5):
                    hits.append(f"{page.pid}: {actor}->{uid} through package title {pk}")
                    break
        for nk in page.note_keys:
            ng = page.geom[nk]
            for (a, b) in segs:
                if _seg_hits_rect(a[0], a[1], b[0], b[1], *ng):
                    hits.append(f"{page.pid}: {actor}->{uid} through note {nk}")
                    break
        if not route["points"]:
            sx, sy = start
            ex, ey = end
            if abs(sx - ex) > 8 and abs(sy - ey) > 8:
                hits.append(f"{page.pid}: {actor}->{uid} has no waypoints (router may jog through ovals)")
    return hits


# ---------------------------------------------------------------------------
# PAGE: OVERVIEW
# ---------------------------------------------------------------------------
def page_overview() -> Page:
    p = Page("overview", "UC-OVERVIEW — Use Case Model Overview", 1700, 1080)
    p.title("Use Case Model Overview", "Navigation page — not the complete Use Case Diagram. See UC-00.")
    p.subject(150, 130, 780, 600)
    p.actor("Guest", "Guest", 40, 200)
    p.actor("Citizen", "Citizen", 40, 480)

    pkgs = [
        ("p1", "Public, Account & Citizen Services\n→ UC-01", 180, 175, 230, 210),
        ("p2", "Applications, Documents & Payments\n→ UC-02", 425, 175, 230, 210),
        ("p3", "Testing & License Operations\n→ UC-03", 670, 175, 230, 210),
        ("p4", "Employee & Administration\n→ UC-04", 250, 420, 250, 210),
        ("p5", "AI Assistant & External Integrations\n→ UC-05", 530, 420, 250, 210),
    ]
    tints = ["#E8F1FB", "#E7F5EC", "#E5F6F8", "#F8EEE4", "#F0EAF8"]
    for (k, t, x, y, w, h), tint in zip(pkgs, tints):
        p.pkg(k, t, tint, x, y, w, h)

    p.actor("Employee", "Employee", 1080, 140, abstract=True)
    left_col = [
        ("Reviewer", "Profile & Document Reviewer", 980, 280),
        ("AppMgr", "Application Manager", 980, 390),
        ("PayEmp", "Payment Employee", 980, 500),
        ("TestEmp", "Test Employee", 980, 610),
        ("LicEmp", "License Employee", 980, 720),
        ("FinesEmp", "Fines Employee", 980, 850),
    ]
    right_col = [
        ("Reports", "Reports Employee", 1380, 280),
        ("Audit", "Audit Employee", 1380, 390),
        ("Settings", "Settings Employee", 1380, 500),
        ("Admin", "Admin", 1380, 640),
        ("SuperAdmin", "Super Admin", 1380, 780),
    ]
    for key, lab, x, y in left_col + right_col:
        p.actor(key, lab, x, y)

    spine = 1240
    for key, _, _, y in left_col:
        p.generalize(
            key, "Employee",
            points=[(spine, y + 39), (spine, 218)],
            exit_pt=(1, 0.45), entry_pt=(0.5, 1),
        )
    for key, _, _, y in right_col:
        if key == "SuperAdmin":
            continue
        p.generalize(
            key, "Employee",
            points=[(spine, y + 39), (spine, 218)],
            exit_pt=(0, 0.45), entry_pt=(0.5, 1),
        )
    p.generalize("SuperAdmin", "Admin", exit_pt=(0.5, 0), entry_pt=(0.5, 1))

    p.note(
        "n1",
        "AI Assistant is an alternative assisted interaction channel for supported citizen operations.\n"
        "Mutating operations require citizen confirmation.",
        165, 760, 370, 88,
    )
    p.note(
        "n2",
        "Admin and Super Admin may perform permission-gated employee operations through authorization bypass; "
        "specialized actors remain shown to represent separation of duties.",
        545, 760, 370, 88,
    )
    p.vertex(
        "n3",
        "Scheduled jobs and automatic notifications are internal and are not shown as Use Cases.",
        f"text;html=1;align=left;fontFamily={FONT};fontSize=11;fontColor={MUTED};",
        165, 860, 750, 22,
    )
    return p


# ---------------------------------------------------------------------------
# PAGE: UC-00
# ---------------------------------------------------------------------------
def page_uc00() -> Page:
    p = Page("uc00", "UC-00 — Complete System Use Case Diagram", 5400, 3600)
    p.title("Complete System Use Case Diagram")
    p.vertex(
        "hint",
        "Master diagram — all 45 business Use Cases. Not scaled for A4. Readability over compactness.",
        f"text;html=1;align=center;fontFamily={FONT};fontSize=12;fontColor={MUTED};fontStyle=2;",
        40, 44, 5320, 20,
    )

    SX, SY, SW, SH = 380, 190, 3360, 3040
    p.subject(SX, SY, SW, SH)

    # 4×3 grid with wide inter-row and inter-column gutters for independent routing
    pkgs = {
        "A": ("A. Public Information", TINTS["A"], 420, 230, 580, 520),
        "B": ("B. Citizen Account", TINTS["B"], 420, 820, 580, 620),
        "C": ("C. Citizen License Services", TINTS["C"], 420, 1520, 580, 740),
        "D": ("D. Citizen Communications & AI Assistant", TINTS["D"], 420, 2340, 580, 520),
        "E": ("E. Review & Applications", TINTS["E"], 1100, 230, 980, 360),
        "F": ("F. Payments", TINTS["F"], 1100, 820, 980, 440),
        "G": ("G. Testing", TINTS["G"], 1100, 1520, 980, 480),
        "H": ("H. License Operations", TINTS["H"], 2440, 230, 980, 560),
        "I": ("I. Fines", TINTS["I"], 2440, 860, 980, 280),
        "J": ("J. Employee Access", TINTS["J"], 2440, 1520, 980, 420),
        "K": ("K. Administration & RBAC", TINTS["K"], 1100, 2340, 980, 520),
        "L": ("L. Reports, Audit & Settings", TINTS["L"], 2440, 2340, 980, 520),
    }
    pkg_geom = {}
    for k, (title, tint, x, y, w, h) in pkgs.items():
        p.pkg(f"pkg{k}", title, tint, x, y, w, h)
        pkg_geom[k] = (x, y, w, h)

    pos = {}
    pos.update(vstack_in(pkg_geom["A"], ["UC-PUB-01", "UC-PUB-02", "UC-PUB-03", "UC-PUB-04"], oval_w=200, oval_h=70, align="left"))
    pos.update(vstack_in(pkg_geom["B"], [
        "UC-CIT-01", "UC-CIT-02", "UC-CIT-03", "UC-CIT-04", "UC-CIT-05", "UC-CIT-06",
    ], oval_w=200, oval_h=68, align="left"))
    pos.update(vstack_in(pkg_geom["C"], [
        "UC-CIT-07", "UC-CIT-08", "UC-CIT-09", "UC-CIT-10", "UC-CIT-14", "UC-CIT-15", "UC-CIT-16",
    ], oval_w=200, oval_h=68, align="left"))
    pos.update(vstack_in(pkg_geom["D"], ["UC-CIT-17", "UC-CIT-18", "UC-CIT-19"], oval_w=220, oval_h=70, align="left"))
    pos.update(vstack_in(pkg_geom["E"], ["UC-REV-01", "UC-REV-02", "UC-APP-01"], oval_w=230, oval_h=70, align="right"))
    pos.update({
        "UC-CIT-11": (1140, 940, 220, 70),
        "UC-PAY-01": (1700, 940, 220, 70),
    })
    pos.update({
        "UC-CIT-12": (1140, 1620, 220, 70),
        "UC-CIT-13": (1140, 1780, 220, 70),
        "UC-TST-01": (1700, 1620, 220, 70),
        "UC-TST-02": (1700, 1780, 220, 70),
    })
    pos.update(vstack_in(pkg_geom["H"], [
        "UC-LIC-01", "UC-LIC-02", "UC-LIC-03", "UC-LIC-04", "UC-LIC-05",
    ], oval_h=68, align="right"))
    pos.update(vstack_in(pkg_geom["I"], ["UC-FIN-01"], align="right"))
    pos.update(vstack_in(pkg_geom["J"], ["UC-EMP-01", "UC-EMP-02"], align="right"))
    pos.update(vstack_in(pkg_geom["K"], ["UC-USR-01", "UC-HR-01", "UC-RBAC-01", "UC-SES-01"], oval_h=68, align="right"))
    pos.update(vstack_in(pkg_geom["L"], ["UC-RPT-01", "UC-AUD-01", "UC-SET-01", "UC-MSG-01"], oval_h=68, align="right"))

    assert set(pos) == set(ALL_UC_IDS), set(ALL_UC_IDS) - set(pos)
    for uid, g in pos.items():
        p.uc(uid, *g)

    def ug(uid):
        return p.geom[f"uc:{uid}"]

    # Actors — left corridor, center-gutter staff, right domain staff, hierarchy further right
    p.actor("Guest", "Guest", 50, 280)
    p.actor("Citizen", "Citizen", 50, 1700)
    p.actor("Mail", "Mail / SMTP", 160, 40)
    p.actor("FCM", "Firebase FCM", 50, 2500)
    p.actor("Gemini", "Gemini", 50, 2720)

    p.actor("Gateway", "Payment Gateway", 2170, 40)
    p.actor("Reviewer", "Profile & Document Reviewer", 2280, 260)
    p.actor("AppMgr", "Application Manager", 2280, 500)
    p.actor("PayEmp", "Payment Employee", 2280, 1180)
    p.actor("TestEmp", "Test Employee", 2280, 2050)

    p.actor("LicEmp", "License Employee", 3580, 380)
    p.actor("FinesEmp", "Fines Employee", 3580, 920)
    p.actor("Employee", "Employee", 3580, 1620, abstract=True)
    p.actor("Reports", "Reports Employee", 3580, 2400)
    p.actor("Audit", "Audit Employee", 3580, 2580)
    p.actor("Settings", "Settings Employee", 3580, 2760)
    p.actor("Admin", "Admin", 4040, 2140)
    p.actor("SuperAdmin", "Super Admin", 4040, 2720)

    GUEST_LANE, CIT_LANE, MAIL_LANE = 300, 328, 290
    SPINE = 2095          # empty gutter between center and right columns
    STAFF_PAD = 3460      # empty strip left of right-hand actors
    GAP_AB, GAP_BC, GAP_JL = 780, 1480, 2210
    F_HEAD = 880          # Payments interior below title, above ovals
    GUTTER_PAD = 2190     # left of gutter-actor labels

    def from_left(actor, uid, lane, exit_y, entry_y=0.5):
        sy = attach(p.geom[f"act:{actor}"], (1, exit_y))[1]
        cy = left_mid(ug(uid))[1]
        p.assoc(
            actor, uid,
            points=[(lane, sy), (lane, cy)],
            exit_pt=(1, exit_y), entry_pt=(0, entry_y),
        )

    def from_right(actor, uid, pad_x, exit_y, entry_y=0.5):
        sy = attach(p.geom[f"act:{actor}"], (0, exit_y))[1]
        cy = left_mid(ug(uid))[1]
        p.assoc(
            actor, uid,
            points=[(pad_x, sy), (pad_x, cy)],
            exit_pt=(0, exit_y), entry_pt=(1, entry_y),
        )

    # Guest — vertical corridor left of all ovals, then unique-y horizontals
    for i, uid in enumerate(["UC-PUB-01", "UC-PUB-02", "UC-PUB-03", "UC-PUB-04", "UC-CIT-01", "UC-CIT-03"]):
        from_left("Guest", uid, GUEST_LANE, 0.12 + i * 0.13, 0.38 if uid == "UC-CIT-03" else 0.5)

    # Citizen — same left-corridor rule for every left-column oval
    left_cit = [
        "UC-CIT-02", "UC-CIT-03", "UC-CIT-04", "UC-CIT-05", "UC-CIT-06",
        "UC-CIT-07", "UC-CIT-08", "UC-CIT-09", "UC-CIT-10",
        "UC-CIT-14", "UC-CIT-15", "UC-CIT-16",
        "UC-CIT-17", "UC-CIT-18", "UC-CIT-19",
    ]
    for i, uid in enumerate(left_cit):
        from_left("Citizen", uid, CIT_LANE, 0.08 + i * 0.055, 0.62 if uid == "UC-CIT-03" else 0.5)

    # CIT-11 / 12 / 13 live in center packages — use inter-row gutters, never a first-column oval
    c11 = ug("UC-CIT-11")
    cit_sy_11 = attach(p.geom["act:Citizen"], (1, 0.88))[1]
    p.assoc(
        "Citizen", "UC-CIT-11",
        points=[
            (CIT_LANE, cit_sy_11), (CIT_LANE, GAP_AB),
            (c11[0] - 16, GAP_AB), (c11[0] - 16, left_mid(c11)[1]),
        ],
        exit_pt=(1, 0.88), entry_pt=(0, 0.5),
    )
    for i, uid in enumerate(["UC-CIT-12", "UC-CIT-13"]):
        g = ug(uid)
        drop = g[0] - 16 - i * 10
        ey = 0.92 + i * 0.02
        sy = attach(p.geom["act:Citizen"], (1, ey))[1]
        gy = GAP_BC + i * 8
        p.assoc(
            "Citizen", uid,
            points=[(CIT_LANE, sy), (CIT_LANE, gy), (drop, gy), (drop, left_mid(g)[1])],
            exit_pt=(1, ey), entry_pt=(0, 0.5),
        )

    # Mail
    c01 = ug("UC-CIT-01")
    p.assoc(
        "Mail", "UC-CIT-01",
        points=[(MAIL_LANE, 72), (MAIL_LANE, left_mid(c01)[1])],
        exit_pt=(1, 0.4), entry_pt=(0, 0.28),
    )
    c03 = ug("UC-CIT-03")
    p.assoc(
        "Mail", "UC-CIT-03",
        points=[(MAIL_LANE, 84), (920, 84), (920, left_mid(c03)[1])],
        exit_pt=(1, 0.65), entry_pt=(1, 0.5),
    )
    emp1 = ug("UC-EMP-01")
    mail_ex = attach(p.geom["act:Mail"], (1, 0.2))
    p.assoc(
        "Mail", "UC-EMP-01",
        points=[(300, mail_ex[1]), (300, 22), (STAFF_PAD, 22), (STAFF_PAD, left_mid(emp1)[1])],
        exit_pt=(1, 0.2), entry_pt=(1, 0.5),
    )

    from_left("FCM", "UC-CIT-18", CIT_LANE + 4, 0.5, 0.72)
    from_left("Gemini", "UC-CIT-19", CIT_LANE + 4, 0.5, 0.72)

    # Gateway: spine in the center-right gutter (never through E)
    pay = ug("UC-PAY-01")
    gw_sy = attach(p.geom["act:Gateway"], (0, 0.12))[1]
    p.assoc(
        "Gateway", "UC-PAY-01",
        points=[(SPINE, gw_sy), (SPINE, left_mid(pay)[1])],
        exit_pt=(0, 0.12), entry_pt=(1, 0.5),
    )
    p.assoc(
        "Gateway", "UC-CIT-11",
        points=[(SPINE, gw_sy + 12), (SPINE, F_HEAD), (c11[0] + c11[2] / 2, F_HEAD)],
        exit_pt=(0, 0.28), entry_pt=(0.5, 0),
    )

    # Reviewer / AppMgr / PayEmp / TestEmp: short left into their domains from the gutter
    from_right("Reviewer", "UC-REV-01", GUTTER_PAD, 0.35)
    from_right("Reviewer", "UC-REV-02", GUTTER_PAD, 0.7)
    from_right("AppMgr", "UC-APP-01", GUTTER_PAD, 0.5)
    from_right("PayEmp", "UC-PAY-01", GUTTER_PAD, 0.5)
    from_right("TestEmp", "UC-TST-01", GUTTER_PAD, 0.35)
    from_right("TestEmp", "UC-TST-02", GUTTER_PAD, 0.7)

    for i, uid in enumerate(["UC-LIC-01", "UC-LIC-02", "UC-LIC-03", "UC-LIC-04", "UC-LIC-05"]):
        from_right("LicEmp", uid, STAFF_PAD, 0.12 + i * 0.18)
    from_right("FinesEmp", "UC-FIN-01", STAFF_PAD, 0.5)
    from_right("Employee", "UC-EMP-01", STAFF_PAD, 0.35)
    from_right("Employee", "UC-EMP-02", STAFF_PAD, 0.7)
    from_right("Reports", "UC-RPT-01", STAFF_PAD, 0.5)
    from_right("Audit", "UC-AUD-01", STAFF_PAD, 0.5)
    from_right("Settings", "UC-SET-01", STAFF_PAD, 0.35)
    from_right("Settings", "UC-MSG-01", STAFF_PAD, 0.7)

    # Admin / Super Admin: above L via GAP_JL, then down the center-right gutter into K
    for i, uid in enumerate(["UC-USR-01", "UC-HR-01", "UC-RBAC-01"]):
        g = ug(uid)
        ey = 0.22 + i * 0.22
        sy = attach(p.geom["act:Admin"], (0, ey))[1]
        gy = 2188 + i * 10
        p.assoc(
            "Admin", uid,
            points=[(3920, sy), (3920, gy), (SPINE, gy), (SPINE, left_mid(g)[1])],
            exit_pt=(0, ey), entry_pt=(1, 0.5),
        )
    ses = ug("UC-SES-01")
    sa_sy = attach(p.geom["act:SuperAdmin"], (1, 0.4))[1]
    p.assoc(
        "SuperAdmin", "UC-SES-01",
        points=[(4180, sa_sy), (4180, 2260), (SPINE, 2260), (SPINE, left_mid(ses)[1])],
        exit_pt=(1, 0.4), entry_pt=(1, 0.5),
    )

    # Reduced generalization on the master (full tree remains on UC-OVERVIEW / UC-04)
    p.generalize(
        "Admin", "Employee",
        points=[(4420, 2179), (4420, 1659)],
        exit_pt=(1, 0.5), entry_pt=(1, 0.5),
    )
    p.generalize("SuperAdmin", "Admin", exit_pt=(0.5, 0), entry_pt=(0.5, 1))

    p.note(
        "n_ai",
        "AI Assistant is an alternative assisted interaction channel for supported citizen operations.\n"
        "Mutating operations require citizen confirmation.",
        420, 2940, 420, 88,
    )
    p.note(
        "n_admin",
        "Admin and Super Admin may perform permission-gated employee operations through authorization bypass; "
        "specialized actors remain shown to represent separation of duties.",
        1100, 2940, 520, 88,
    )
    p.note(
        "n_rel",
        "No «include» / «extend» relationships are used in this model.\n"
        "Process order is documented in Activity / Sequence Diagrams.",
        1680, 2940, 420, 88,
    )
    p.note(
        "n_inherit",
        "Specialized staff actors inherit from Employee; full hierarchy is shown in UC-OVERVIEW and UC-04.",
        3580, 3100, 460, 72,
    )
    return p


def page_uc01() -> Page:
    """Public / Account / Self-service: rows and wide column gutters; stubs never cross ovals."""
    p = Page("uc01", "UC-01 — Public, Account & Citizen Services", 2100, 1280)
    p.title("Public, Account & Citizen Services")
    p.subject(210, 80, 1640, 1100)
    p.actor("Guest", "Guest", 50, 140)
    p.actor("Citizen", "Citizen", 50, 820)
    p.actor("Mail", "Mail / SMTP", 1930, 500)

    p.pkg("pub", "Public", TINTS["A"], 250, 115, 1560, 250)
    p.pkg("acc", "Authentication & Account", TINTS["B"], 250, 395, 1560, 430)
    p.pkg("self", "Citizen Self-Service / Information", TINTS["C"], 250, 860, 1560, 270)

    pub = row_in((250, 115, 1560, 250), ["UC-PUB-01", "UC-PUB-02", "UC-PUB-03", "UC-PUB-04"], oval_w=230, oval_h=72, top=70, pad=40)
    for uid, g in pub.items():
        p.uc(uid, *g)

    # Two columns with a wide empty gutter so right-column targets are never reached through left ovals
    left_x, right_x, ow, oh = 290, 1180, 230, 72
    acc_y = [470, 600, 730]
    acc_map = {
        "UC-CIT-01": (left_x, acc_y[0], ow, oh),
        "UC-CIT-03": (left_x, acc_y[1], ow, oh),
        "UC-CIT-05": (left_x, acc_y[2], ow, oh),
        "UC-CIT-02": (right_x, acc_y[0], ow, oh),
        "UC-CIT-04": (right_x, acc_y[1], ow, oh),
        "UC-CIT-06": (right_x, acc_y[2], ow, oh),
    }
    for uid, g in acc_map.items():
        p.uc(uid, *g)

    slf = row_in((250, 860, 1560, 270), ["UC-CIT-15", "UC-CIT-16", "UC-CIT-17", "UC-CIT-18"], oval_w=230, oval_h=72, top=70, pad=40)
    for uid, g in slf.items():
        p.uc(uid, *g)

    def ug(uid):
        return p.geom[f"uc:{uid}"]

    lane = 185  # independent left corridor, left of subject (210)
    gutter_x = 900

    def gsy(exit_y):
        return attach(p.geom["act:Guest"], (1, exit_y))[1]

    def csy(exit_y):
        return attach(p.geom["act:Citizen"], (1, exit_y))[1]

    # Guest → Public: channel in the gap between package title and ovals; one drop per oval
    for i, uid in enumerate(["UC-PUB-01", "UC-PUB-02", "UC-PUB-03", "UC-PUB-04"]):
        g = ug(uid)
        ey = 0.12 + i * 0.14
        ch = 152 + i * 6
        cx = g[0] + g[2] / 2
        sy = gsy(ey)
        p.assoc(
            "Guest", uid,
            points=[(lane, sy), (lane, ch), (cx, ch)],
            exit_pt=(1, ey), entry_pt=(0.5, 0),
        )

    # Guest → CIT-01 / CIT-03 from the left (left column only)
    for i, uid in enumerate(["UC-CIT-01", "UC-CIT-03"]):
        ey = 0.68 + i * 0.14
        sy = gsy(ey)
        cy = left_mid(ug(uid))[1]
        p.assoc(
            "Guest", uid,
            points=[(lane, sy), (lane, cy)],
            exit_pt=(1, ey), entry_pt=(0, 0.42 if uid == "UC-CIT-01" else 0.28),
        )

    # Citizen → left-column account UCs from the left
    for i, uid in enumerate(["UC-CIT-03", "UC-CIT-05"]):
        ey = 0.16 + i * 0.1
        sy = csy(ey)
        cy = left_mid(ug(uid))[1]
        p.assoc(
            "Citizen", uid,
            points=[(lane, sy), (lane, cy)],
            exit_pt=(1, ey), entry_pt=(0, 0.72 if uid == "UC-CIT-03" else 0.5),
        )

    # Citizen → right-column account UCs via the empty column gutter (never through left ovals)
    # 438 = account interior below title (395+26) and above row-0 ovals (470)
    # 568 = between row0 bottom 542 and row1 top 600
    # 698 = between row1 bottom 672 and row2 top 730
    row_gutters = {"UC-CIT-02": 438, "UC-CIT-04": 568, "UC-CIT-06": 698}
    for i, uid in enumerate(["UC-CIT-02", "UC-CIT-04", "UC-CIT-06"]):
        g = ug(uid)
        ey = 0.38 + i * 0.1
        sy = csy(ey)
        gy = row_gutters[uid]
        if uid == "UC-CIT-02":
            cx = g[0] + g[2] / 2
            p.assoc(
                "Citizen", uid,
                points=[(lane, sy), (lane, gy), (cx, gy)],
                exit_pt=(1, ey), entry_pt=(0.5, 0),
            )
        else:
            p.assoc(
                "Citizen", uid,
                points=[(lane, sy), (lane, gy), (gutter_x, gy), (gutter_x, left_mid(g)[1])],
                exit_pt=(1, ey), entry_pt=(0, 0.5),
            )

    # Citizen → self-service: channel between package title and ovals, drop onto each
    for i, uid in enumerate(["UC-CIT-15", "UC-CIT-16", "UC-CIT-17", "UC-CIT-18"]):
        g = ug(uid)
        ey = 0.70 + i * 0.06
        sy = csy(ey)
        ch = 900 + i * 6
        cx = g[0] + g[2] / 2
        p.assoc(
            "Citizen", uid,
            points=[(lane, sy), (lane, ch), (cx, ch)],
            exit_pt=(1, ey), entry_pt=(0.5, 0),
        )

    # Mail stays in the account title/interior band (y=418), never at CIT-02 height
    c01 = ug("UC-CIT-01")
    m0 = attach(p.geom["act:Mail"], (0, 0.3))
    p.assoc(
        "Mail", "UC-CIT-01",
        points=[(1880, m0[1]), (1880, 360), (c01[0] + c01[2] / 2, 360)],
        exit_pt=(0, 0.3), entry_pt=(0.5, 0),
    )
    c03 = ug("UC-CIT-03")
    m1 = attach(p.geom["act:Mail"], (0, 0.7))
    p.assoc(
        "Mail", "UC-CIT-03",
        points=[(1880, m1[1]), (1880, 442), (gutter_x, 442), (gutter_x, left_mid(c03)[1])],
        exit_pt=(0, 0.7), entry_pt=(1, 0.5),
    )
    return p


def page_uc02() -> Page:
    p = Page("uc02", "UC-02 — Applications, Documents & Payments", 2000, 1250)
    p.title("Applications, Documents & Payments")
    p.subject(180, 150, 1280, 900)

    p.actor("Citizen", "Citizen", 40, 520)
    p.actor("Reviewer", "Profile & Document Reviewer", 700, 40)
    p.actor("AppMgr", "Application Manager", 700, 1100)
    p.actor("PayEmp", "Payment Employee", 1550, 430)
    p.actor("Gateway", "Payment Gateway", 1180, 40)

    p.pkg("apps", "Application Services", TINTS["C"], 220, 190, 280, 520)
    p.pkg("docs", "Documents / Review", TINTS["E"], 560, 190, 400, 560)
    p.pkg("pay", "Payments", TINTS["F"], 1040, 190, 380, 400)

    apps = {
        "UC-CIT-14": (245, 240, 230, 68),
        "UC-CIT-07": (245, 340, 230, 70),
        "UC-CIT-08": (245, 440, 230, 70),
        "UC-CIT-09": (245, 540, 230, 70),
    }
    docs = {
        "UC-REV-01": (590, 250, 230, 70),
        "UC-CIT-10": (590, 370, 230, 70),
        "UC-REV-02": (590, 490, 230, 70),
        "UC-APP-01": (590, 610, 230, 70),
    }
    pays = {
        "UC-CIT-11": (1115, 250, 230, 70),
        "UC-PAY-01": (1115, 400, 230, 70),
    }
    for d in (apps, docs, pays):
        for uid, g in d.items():
            p.uc(uid, *g)

    def ug(uid):
        return p.geom[f"uc:{uid}"]

    bus = 155
    for i, uid in enumerate(["UC-CIT-14", "UC-CIT-07", "UC-CIT-08", "UC-CIT-09"]):
        ey = 0.18 + i * 0.14
        sy = attach(p.geom["act:Citizen"], (1, ey))[1]
        cy = left_mid(ug(uid))[1]
        p.assoc("Citizen", uid, points=[(bus, sy), (bus, cy)],
                exit_pt=(1, ey), entry_pt=(0, 0.5))

    # CIT-10 via the empty vertical gutter between Application and Documents (x=520)
    c10 = ug("UC-CIT-10")
    s10 = attach(p.geom["act:Citizen"], (1, 0.12))[1]
    p.assoc(
        "Citizen", "UC-CIT-10",
        points=[(bus, s10), (bus, 168), (520, 168), (520, left_mid(c10)[1])],
        exit_pt=(1, 0.12), entry_pt=(0, 0.5),
    )
    # CIT-11 via the empty bottom corridor, then up the gutter between Documents and Payments (x=1000)
    c11 = ug("UC-CIT-11")
    s11 = attach(p.geom["act:Citizen"], (1, 0.88))[1]
    p.assoc(
        "Citizen", "UC-CIT-11",
        points=[(bus, s11), (bus, 780), (1000, 780), (1000, left_mid(c11)[1])],
        exit_pt=(1, 0.88), entry_pt=(0, 0.5),
    )

    # Reviewer above Documents: drop in the empty right padding of the docs package (x=930)
    docs_lane = 930
    rv0 = attach(p.geom["act:Reviewer"], (1, 0.45))[1]
    for i, uid in enumerate(["UC-REV-01", "UC-REV-02"]):
        g = ug(uid)
        p.assoc(
            "Reviewer", uid,
            points=[(docs_lane, rv0 + i * 10), (docs_lane, left_mid(g)[1])],
            exit_pt=(1, 0.35 + i * 0.25), entry_pt=(1, 0.5),
        )
    am_sy = attach(p.geom["act:AppMgr"], (0.5, 0))[1]
    p.assoc(
        "AppMgr", "UC-APP-01",
        points=[(docs_lane, am_sy), (docs_lane, left_mid(ug("UC-APP-01"))[1])],
        exit_pt=(0.5, 0), entry_pt=(1, 0.5),
    )

    pe_sy = attach(p.geom["act:PayEmp"], (0, 0.5))[1]
    pay = ug("UC-PAY-01")
    p.assoc(
        "PayEmp", "UC-PAY-01",
        points=[(1480, pe_sy), (1480, left_mid(pay)[1])],
        exit_pt=(0, 0.5), entry_pt=(1, 0.5),
    )
    gw0 = attach(p.geom["act:Gateway"], (0.35, 1))[1]
    p.assoc(
        "Gateway", "UC-CIT-11",
        points=[(1440, gw0), (1440, left_mid(c11)[1])],
        exit_pt=(0.35, 1), entry_pt=(1, 0.5),
    )
    gw1 = attach(p.geom["act:Gateway"], (0.75, 1))[1]
    p.assoc(
        "Gateway", "UC-PAY-01",
        points=[(1280, gw1), (1480, gw1), (1480, 168), (1480, left_mid(pay)[1])],
        exit_pt=(0.75, 1), entry_pt=(1, 0.5),
    )

    p.note(
        "n1",
        "Spatial grouping is not UML workflow.\nProcess order is in Activity / Sequence Diagrams.",
        1550, 700, 360, 72,
    )
    p.note("n2", "Inspect License Applications is read-only.", 1550, 800, 280, 56)
    return p


def page_uc03() -> Page:
    p = Page("uc03", "UC-03 — Testing & License Operations", 2000, 1200)
    p.title("Testing & License Operations")
    p.subject(180, 140, 1280, 920)

    p.actor("Citizen", "Citizen", 40, 360)
    p.actor("TestEmp", "Test Employee", 1010, 400)
    p.actor("LicEmp", "License Employee", 1550, 380)
    p.actor("FinesEmp", "Fines Employee", 1550, 860)

    p.pkg("apt", "Citizen appointments", TINTS["G"], 220, 180, 280, 300)
    p.pkg("tst", "Test operations", TINTS["G"], 540, 180, 420, 240)
    p.pkg("lic", "License issuance / management", TINTS["H"], 1100, 180, 340, 560)
    p.pkg("fin", "Fines", TINTS["I"], 1100, 780, 340, 200)

    p.uc("UC-CIT-12", 250, 230, 220, 70)
    p.uc("UC-CIT-13", 250, 340, 220, 70)
    p.uc("UC-TST-01", 555, 250, 180, 72)
    p.uc("UC-TST-02", 760, 250, 180, 72)
    for i, uid in enumerate(["UC-LIC-01", "UC-LIC-02", "UC-LIC-03", "UC-LIC-04", "UC-LIC-05"]):
        p.uc(uid, 1155, 230 + i * 96, 230, 70)
    p.uc("UC-FIN-01", 1155, 830, 230, 70)

    def ug(uid):
        return p.geom[f"uc:{uid}"]

    bus = 155
    for i, uid in enumerate(["UC-CIT-12", "UC-CIT-13"]):
        ey = 0.35 + i * 0.3
        sy = attach(p.geom["act:Citizen"], (1, ey))[1]
        p.assoc(
            "Citizen", uid,
            points=[(bus, sy), (bus, left_mid(ug(uid))[1])],
            exit_pt=(1, ey), entry_pt=(0, 0.5),
        )
    t1 = ug("UC-TST-01")
    t2 = ug("UC-TST-02")
    te0 = attach(p.geom["act:TestEmp"], (0, 0.35))[1]
    te1 = attach(p.geom["act:TestEmp"], (0, 0.7))[1]
    p.assoc(
        "TestEmp", "UC-TST-02",
        points=[(950, te1), (950, left_mid(t2)[1])],
        exit_pt=(0, 0.7), entry_pt=(1, 0.5),
    )
    p.assoc(
        "TestEmp", "UC-TST-01",
        points=[(1088, te0), (1088, 430), (t1[0] - 12, 430), (t1[0] - 12, left_mid(t1)[1])],
        exit_pt=(1, 0.35), entry_pt=(0, 0.5),
    )
    lic_pad = 1480
    for i, uid in enumerate(["UC-LIC-01", "UC-LIC-02", "UC-LIC-03", "UC-LIC-04", "UC-LIC-05"]):
        ey = 0.12 + i * 0.18
        sy = attach(p.geom["act:LicEmp"], (0, ey))[1]
        p.assoc(
            "LicEmp", uid,
            points=[(lic_pad, sy), (lic_pad, left_mid(ug(uid))[1])],
            exit_pt=(0, ey), entry_pt=(1, 0.5),
        )
    fe = attach(p.geom["act:FinesEmp"], (0, 0.5))[1]
    p.assoc(
        "FinesEmp", "UC-FIN-01",
        points=[(lic_pad, fe), (lic_pad, left_mid(ug("UC-FIN-01"))[1])],
        exit_pt=(0, 0.5), entry_pt=(1, 0.5),
    )

    p.note("n1", "Tests apply to new-license applications only.", 220, 520, 300, 56)
    p.note("n2", "Process order is documented in Activity / Sequence Diagrams.", 220, 600, 300, 56)
    return p


def page_uc04() -> Page:
    """Associations travel LEFT of actors; generalization travels RIGHT of actors."""
    p = Page("uc04", "UC-04 — Employee & Administration", 2200, 1480)
    p.title("Employee & Administration")
    p.subject(80, 80, 900, 1280)
    p.actor("Mail", "Mail / SMTP", 20, 160)

    p.pkg("acc", "Employee Access", TINTS["J"], 120, 120, 400, 250)
    p.pkg("ov", "Oversight", TINTS["L"], 120, 390, 400, 250)
    p.pkg("st", "Settings", TINTS["L"], 120, 660, 400, 250)
    p.pkg("adm", "Administration", TINTS["K"], 120, 930, 400, 400)

    p.uc("UC-EMP-01", 160, 165, 240, 70)
    p.uc("UC-EMP-02", 160, 265, 240, 70)
    p.uc("UC-RPT-01", 160, 435, 240, 70)
    p.uc("UC-AUD-01", 160, 535, 240, 70)
    p.uc("UC-SET-01", 160, 705, 240, 70)
    p.uc("UC-MSG-01", 160, 805, 240, 70)
    p.uc("UC-USR-01", 160, 975, 240, 68)
    p.uc("UC-HR-01", 160, 1060, 240, 68)
    p.uc("UC-RBAC-01", 160, 1145, 240, 68)
    p.uc("UC-SES-01", 160, 1230, 240, 68)

    # Actor column aligned with their Use Cases — associations exit LEFT
    ax = 1120
    p.actor("Employee", "Employee", ax, 190, abstract=True)
    p.actor("Reports", "Reports Employee", ax, 430)
    p.actor("Audit", "Audit Employee", ax, 530)
    p.actor("Settings", "Settings Employee", ax, 730)
    p.actor("Admin", "Admin", ax, 1040)
    p.actor("SuperAdmin", "Super Admin", ax, 1225)

    # Generalization spine — separate visual concept, to the RIGHT of actors
    gen_spine = 1580
    for key, y, ey in (("Reports", 430, 0.35), ("Audit", 530, 0.5), ("Settings", 730, 0.65), ("Admin", 1040, 0.8)):
        p.generalize(
            key, "Employee",
            points=[(gen_spine, y + 39), (gen_spine, 229)],
            exit_pt=(1, 0.45), entry_pt=(1, ey),
        )
    p.generalize(
        "SuperAdmin", "Admin",
        points=[(ax + 24, 1205)],
        exit_pt=(0.5, 0), entry_pt=(0.5, 1),
    )

    corridor = 1020

    def left_in(actor, uid, exit_y=0.5):
        sy = attach(p.geom[f"act:{actor}"], (0, exit_y))[1]
        cy = left_mid(p.geom[f"uc:{uid}"])[1]
        p.assoc(
            actor, uid,
            points=[(corridor, sy), (corridor, cy)],
            exit_pt=(0, exit_y), entry_pt=(1, 0.5),
        )

    left_in("Employee", "UC-EMP-01", 0.35)
    left_in("Employee", "UC-EMP-02", 0.7)
    left_in("Reports", "UC-RPT-01")
    left_in("Audit", "UC-AUD-01")
    left_in("Settings", "UC-SET-01", 0.35)
    left_in("Settings", "UC-MSG-01", 0.7)
    left_in("Admin", "UC-USR-01", 0.22)
    left_in("Admin", "UC-HR-01", 0.45)
    left_in("Admin", "UC-RBAC-01", 0.72)
    left_in("SuperAdmin", "UC-SES-01", 0.45)

    msy = attach(p.geom["act:Mail"], (1, 0.5))[1]
    p.assoc(
        "Mail", "UC-EMP-01",
        points=[(90, msy), (90, left_mid(p.geom["uc:UC-EMP-01"])[1])],
        exit_pt=(1, 0.5), entry_pt=(0, 0.5),
    )

    p.note(
        "n1",
        "Admin and Super Admin may perform permission-gated employee operations through authorization bypass; "
        "specialized actors remain shown to represent separation of duties.",
        1480, 1280, 480, 88,
    )
    return p


def page_uc05() -> Page:
    p = Page("uc05", "UC-05 — AI Assistant & External Integrations", 1400, 900)
    p.title("AI Assistant & External Integrations")
    p.subject(280, 120, 840, 260)
    p.actor("Citizen", "Citizen", 90, 200)
    p.actor("Gemini", "Gemini", 1220, 200)
    p.uc("UC-CIT-19", 520, 190, 360, 100)
    c19 = p.geom["uc:UC-CIT-19"]
    csy = attach(p.geom["act:Citizen"], (1, 0.5))[1]
    gsy = attach(p.geom["act:Gemini"], (0, 0.5))[1]
    p.assoc(
        "Citizen", "UC-CIT-19",
        points=[(250, csy), (250, left_mid(c19)[1])],
        exit_pt=(1, 0.5), entry_pt=(0, 0.5),
    )
    p.assoc(
        "Gemini", "UC-CIT-19",
        points=[(1180, gsy), (1180, left_mid(c19)[1])],
        exit_pt=(0, 0.5), entry_pt=(1, 0.5),
    )

    p.note(
        "n1",
        "AI Assistant is an alternative assisted interaction channel for supported citizen operations.\n"
        "Mutating operations require citizen confirmation.",
        80, 420, 430, 88,
    )
    p.note("n2", "Files are never sent to Gemini.", 900, 48, 280, 52)
    table = (
        "<b>External integrations (reference — ovals live on UC-00)</b><br/><br/>"
        "<b>Mail / SMTP</b><br/>"
        "• Register and Activate Account<br/>"
        "• Recover Account Access<br/>"
        "• Authenticate to Employee Dashboard<br/><br/>"
        "<b>Payment Gateway</b><br/>"
        "• Pay Application Fees<br/>"
        "• Process Application Payments<br/><br/>"
        "<b>Firebase FCM</b><br/>"
        "• Register Mobile Device for Push / optional push delivery"
    )
    p.html_vertex(
        "table",
        table,
        f"shape=note;whiteSpace=wrap;html=1;size=16;align=left;verticalAlign=top;spacingLeft=12;spacingTop=10;"
        f"fillColor={NOTE_FILL};strokeColor={NOTE_STROKE};fontFamily={FONT};fontSize=11;fontColor={INK};",
        80, 530, 700, 280,
    )
    p.note(
        "n3",
        "Database notifications are the source of truth; FCM is an optional delivery channel.\n"
        "Mock payment provider is internal and is not an actor.",
        800, 530, 520, 96,
    )
    return p


def build() -> tuple[str, list[Page]]:
    pages = [
        page_overview(),
        page_uc00(),
        page_uc01(),
        page_uc02(),
        page_uc03(),
        page_uc04(),
        page_uc05(),
    ]
    inner = "\n".join(pg.xml() for pg in pages)
    xml = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<mxfile host="Electron" agent="SYRTAK-Use-Case-Diagram-Generator" version="24.7.17" pages="7">\n'
        f"{inner}\n</mxfile>\n"
    )
    return xml, pages


FORBIDDEN_ACTORS = ["AI Agent", "Scheduler", "Flutter", "Next.js"]


def validate(xml: str, pages: list[Page]) -> list[str]:
    errors = []
    try:
        root = ET.fromstring(xml)
    except ET.ParseError as e:
        return [f"XML not well-formed: {e}"]
    diagrams = root.findall("diagram")
    if len(diagrams) != 7:
        errors.append(f"Page count {len(diagrams)} != 7")
    expected_names = [
        "UC-OVERVIEW — Use Case Model Overview",
        "UC-00 — Complete System Use Case Diagram",
        "UC-01 — Public, Account & Citizen Services",
        "UC-02 — Applications, Documents & Payments",
        "UC-03 — Testing & License Operations",
        "UC-04 — Employee & Administration",
        "UC-05 — AI Assistant & External Integrations",
    ]
    names = [d.get("name") for d in diagrams]
    if names != expected_names:
        errors.append(f"Page names mismatch: {names}")

    all_ids = []
    for d in diagrams:
        ids = [c.get("id") for c in d.iter("mxCell") if c.get("id")]
        all_ids.extend(ids)
        if len(ids) != len(set(ids)):
            errors.append(f"Duplicate mxCell ids on {d.get('name')}")

    uc00 = diagrams[1]
    uc_ids_found = []
    for cell in uc00.iter("mxCell"):
        val = cell.get("value") or ""
        style = cell.get("style") or ""
        if "ellipse" in style:
            m = re.search(r"UC-[A-Z]+-\d+", val)
            if m:
                uc_ids_found.append(m.group(0))
    if len(uc_ids_found) != 45:
        errors.append(f"UC-00 ellipse/UC count {len(uc_ids_found)} != 45")
    missing = [u for u in ALL_UC_IDS if u not in uc_ids_found]
    extra = [u for u in uc_ids_found if u not in ALL_UC_IDS]
    dups = sorted({u for u in uc_ids_found if uc_ids_found.count(u) > 1})
    if missing:
        errors.append(f"UC-00 missing: {missing}")
    if extra:
        errors.append(f"UC-00 extra: {extra}")
    if dups:
        errors.append(f"UC-00 duplicate UCs: {dups}")

    include_edges = extend_edges = 0
    for d in diagrams:
        for cell in d.iter("mxCell"):
            style = cell.get("style") or ""
            val = (cell.get("value") or "").lower()
            if cell.get("edge") == "1":
                if "<<include>>" in val or "include" in style.lower():
                    include_edges += 1
                    errors.append(f"include edge on {d.get('name')}")
                if "<<extend>>" in val or "dashed=1" in style and "extend" in val:
                    extend_edges += 1
                    errors.append(f"extend edge on {d.get('name')}")
                if "endArrow=block" in style and "endFill=0" in style:
                    continue
                if "endArrow=none" not in style:
                    if any(a in style for a in ("endArrow=classic", "endArrow=open", "endArrow=block")):
                        errors.append(f"arrowed association on {d.get('name')} id={cell.get('id')}")

    if include_edges:
        errors.append(f"<<include>> count {include_edges} != 0")
    if extend_edges:
        errors.append(f"<<extend>> count {extend_edges} != 0")

    for cell in root.iter("mxCell"):
        st = cell.get("style") or ""
        val = cell.get("value") or ""
        if "umlActor" in st:
            plain = re.sub(r"<[^>]+>", "", val)
            for bad in FORBIDDEN_ACTORS:
                if bad.lower() in plain.lower():
                    errors.append(f"Forbidden actor: {bad}")

    required_actors = [
        "Guest", "Citizen", "Employee", "Profile & Document Reviewer", "Application Manager",
        "Payment Employee", "Test Employee", "License Employee", "Fines Employee",
        "Reports Employee", "Audit Employee", "Settings Employee", "Admin", "Super Admin",
        "Mail / SMTP", "Payment Gateway", "Gemini", "Firebase FCM",
    ]
    actors = []
    for cell in uc00.iter("mxCell"):
        st = cell.get("style") or ""
        val = cell.get("value") or ""
        if "umlActor" in st:
            actors.append(re.sub(r"<[^>]+>", "", val).replace("{abstract}", "").strip())
    for a in required_actors:
        hits = [x for x in actors if a == x or (a in x and a != "Admin")]
        if a == "Admin":
            hits = [x for x in actors if x == "Admin"]
        elif a == "Super Admin":
            hits = [x for x in actors if x == "Super Admin"]
        else:
            hits = [x for x in actors if x == a]
        if len(hits) != 1:
            errors.append(f"UC-00 actor '{a}' count={len(hits)} actors={actors}")

    uc00_page = pages[1]
    got = sorted(uc00_page.assoc_list)
    exp = sorted(UC00_ASSOCS)
    if got != exp:
        errors.append(f"UC-00 assoc mismatch extra={set(got)-set(exp)} missing={set(exp)-set(got)}")
    if len(uc00_page.assoc_list) != 53:
        errors.append(f"UC-00 associations {len(uc00_page.assoc_list)} != 53")
    if len(uc00_page.gen_list) != 2:
        errors.append(f"UC-00 generalizations {len(uc00_page.gen_list)} != 2 (Admin→Employee, SuperAdmin→Admin)")
    if set(uc00_page.gen_list) != {("Admin", "Employee"), ("SuperAdmin", "Admin")}:
        errors.append(f"UC-00 gen set {uc00_page.gen_list}")

    for pg in pages:
        if pg.pid == "overview":
            continue
        hits = route_hits(pg)
        errors.extend(hits[:40])
        if len(hits) > 40:
            errors.append(f"{pg.pid}: … {len(hits) - 40} more route hits")

    admin_targets = {uid for act, uid in uc00_page.assoc_list if act == "Admin"}
    if admin_targets != {"UC-USR-01", "UC-HR-01", "UC-RBAC-01"}:
        errors.append(f"Admin associations {admin_targets}")
    sa_targets = {uid for act, uid in uc00_page.assoc_list if act == "SuperAdmin"}
    if sa_targets != {"UC-SES-01"}:
        errors.append(f"Super Admin associations {sa_targets}")

    ov = pages[0]
    ov_ellipses = sum(1 for c in ov.cells if "ellipse;" in c)
    if ov_ellipses:
        errors.append(f"UC-OVERVIEW has {ov_ellipses} Use Case ovals (must be 0)")

    uc05 = pages[6]
    uc05_ellipses = sum(1 for c in uc05.cells if "ellipse;" in c)
    if uc05_ellipses != 1:
        errors.append(f"UC-05 ellipse count {uc05_ellipses} != 1")

    return errors


def main() -> None:
    EXPORTS.mkdir(parents=True, exist_ok=True)
    xml, pages = build()
    OUT.write_text(xml, encoding="utf-8")
    errs = validate(xml, pages)
    print(f"Wrote {OUT} ({OUT.stat().st_size} bytes)")
    print(f"Pages: {len(pages)}")
    print(f"UC-00 UCs: 45, assocs: {len(pages[1].assoc_list)}, gens: {len(pages[1].gen_list)}")
    if errs:
        print("VALIDATION ERRORS:")
        for e in errs:
            print(" -", e)
        raise SystemExit(1)
    print("VALIDATION OK")


if __name__ == "__main__":
    main()
