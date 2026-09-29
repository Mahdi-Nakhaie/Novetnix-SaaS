#!/usr/bin/env python3
"""Diff tools/catalog.py against src/data.php so the two cannot drift."""
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import catalog  # noqa: E402

SRC = pathlib.Path(__file__).resolve().parent.parent / "src" / "data.php"
text = SRC.read_text(encoding="utf-8")

failures = []


def strings_in(const_name):
    marker = f"const {const_name}"
    start = text.index(marker)
    # constant runs until the next top-level "const " or end of file
    nxt = text.find("\nconst ", start + 1)
    segment = text[start:nxt if nxt > 0 else len(text)]
    out, i, n = [], 0, len(segment)
    while i < n:
        if segment[i] == "'":
            j, buf = i + 1, []
            while j < n:
                if segment[j] == "\\" and j + 1 < n:
                    buf.append(segment[j + 1]); j += 2; continue
                if segment[j] == "'":
                    break
                buf.append(segment[j]); j += 1
            out.append("".join(buf)); i = j + 1; continue
        i += 1
    return out


def segment(const_name):
    start = text.index(f"const {const_name}")
    nxt = text.find("\nconst ", start + 1)
    return text[start:nxt if nxt > 0 else len(text)]


def require(label, expected, actual):
    if expected != actual:
        failures.append(f"{label}\n  php : {expected}\n  py  : {actual}")


# --- plans: numeric fields are bare integers, so read them with a regex
plans_seg = segment("PLANS")
php_plans = strings_in("PLANS")
for p in catalog.PLANS:
    if p["name"] not in php_plans:
        failures.append(f"plan name missing from PHP: {p['name']}")

block = re.search(r"'"+catalog.PLANS[0]["id"]+r"' => \[(.*?)\n\];", plans_seg, re.S)
for p in catalog.PLANS:
    row = re.search(r"'"+p["id"]+r"' => \[(.*?)\],\n", plans_seg, re.S)
    if not row:
        failures.append(f"plan row not found in PHP: {p['id']}"); continue
    body = row.group(1)
    for field in ("price", "projects", "nova"):
        m = re.search(r"'"+field+r"'=>(<?-?\d+)", body)
        if not m:
            failures.append(f"plan {p['id']}.{field} not found in PHP"); continue
        require(f"plan {p['id']}.{field}", int(m.group(1)), p[field])
    for feature in p["features"]:
        if feature not in body:
            failures.append(f"plan {p['id']} feature missing from PHP: {feature}")

# --- comparison rows
php_cmp = strings_in("COMPARISON")
for feature, values in catalog.COMPARISON:
    if feature not in php_cmp:
        failures.append(f"comparison row missing from PHP: {feature}")
    for v in values:
        if v not in php_cmp:
            failures.append(f"comparison value missing from PHP: {v}")

# --- courses
php_courses = strings_in("COURSES")
for slug, c in catalog.COURSES.items():
    if slug not in php_courses:
        failures.append(f"course slug missing from PHP: {slug}")
    for field in ("title", "topic", "level", "intro"):
        if c[field] not in php_courses:
            failures.append(f"course {slug}.{field} missing from PHP: {c[field]}")
    for heading, body in c["sections"]:
        for part in (heading, body):
            if part not in php_courses:
                failures.append(f"course {slug} section text missing from PHP: {part[:40]}…")

# --- projects
php_projects = strings_in("PROJECTS")
for title, topic, level, desc, skills in catalog.PROJECTS:
    for part in (title, topic, level, desc, skills):
        if part not in php_projects:
            failures.append(f"project text missing from PHP: {part[:40]}…")

# --- challenges, badges, tracks, announcements, content, settings
php_ch = strings_in("CHALLENGES")
for kind, title, xp, topic in catalog.CHALLENGES:
    for part in (kind, title, topic):
        if part not in php_ch:
            failures.append(f"challenge text missing from PHP: {part[:40]}…")

php_badges = strings_in("BADGES")
for title, icon, need in catalog.BADGES:
    for part in (title, icon, need):
        if part not in php_badges:
            failures.append(f"badge text missing from PHP: {part}")

php_tracks = strings_in("TRACKS")
for name, percent in catalog.TRACKS:
    if name not in php_tracks:
        failures.append(f"track missing from PHP: {name}")

php_ann = strings_in("ANNOUNCEMENTS")
for title, body, ago in catalog.ANNOUNCEMENTS:
    for part in (title, body, ago):
        if part not in php_ann:
            failures.append(f"announcement text missing from PHP: {part[:40]}…")

php_content = strings_in("CONTENT_ITEMS")
for title, kind, status in catalog.CONTENT_ITEMS:
    for part in (title, kind, status):
        if part not in php_content:
            failures.append(f"content item text missing from PHP: {part[:40]}…")

php_settings = strings_in("SETTINGS_GROUPS")
for title, hint, key in catalog.SETTINGS_GROUPS:
    for part in (title, hint, key):
        if part not in php_settings:
            failures.append(f"setting missing from PHP: {part}")

php_prio = strings_in("TICKET_PRIORITIES")
require("ticket priorities", php_prio, catalog.TICKET_PRIORITIES)

if failures:
    print(f"{len(failures)} catalogue mismatches:\n")
    for f in failures:
        print(" -", f)
    sys.exit(1)

print("catalogue matches src/data.php")
