#!/usr/bin/env python3
"""Validate the generated static site: links, assets, structure, RTL/ltr rules."""
import pathlib
import re
import sys
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parent.parent
SITE = ROOT / "site"
BASE = "/Novetnix-SaaS/site"

VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link",
        "meta", "param", "source", "track", "wbr"}

failures = []


class Checker(HTMLParser):
    def __init__(self, page):
        super().__init__(convert_charrefs=True)
        self.page = page
        self.stack = []

    def handle_starttag(self, tag, attrs):
        if tag not in VOID:
            self.stack.append(tag)

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        if not self.stack:
            failures.append(f"{self.page}: stray </{tag}>")
            return
        if self.stack[-1] != tag:
            failures.append(f"{self.page}: </{tag}> closes <{self.stack[-1]}>")
            if tag in self.stack:
                while self.stack and self.stack.pop() != tag:
                    pass
            return
        self.stack.pop()


def page_url(path: pathlib.Path) -> str:
    rel = path.relative_to(SITE)
    if rel.name != "index.html" and rel.name != "404.html":
        return BASE + "/" + str(rel)
    parts = rel.parts[:-1]
    return BASE + "/" + ("/".join(parts) + "/" if parts else "")


pages = sorted(SITE.rglob("*.html"))
if not pages:
    print("no pages generated")
    sys.exit(1)

# map every generated URL (with and without trailing slash) to its file
valid = {BASE, BASE + "/"}
for p in pages:
    url = page_url(p)
    valid.add(url)
    valid.add(url.rstrip("/") if url != BASE + "/" else BASE)

for page in pages:
    text = page.read_text(encoding="utf-8")
    parser = Checker(page.relative_to(SITE))
    parser.feed(text)
    parser.close()
    if parser.stack:
        failures.append(f"{page.relative_to(SITE)}: unclosed {parser.stack}")

    if text.count('<html lang="fa" dir="rtl">') != 1:
        failures.append(f"{page.relative_to(SITE)}: missing RTL html root")
    if '<meta name="viewport"' not in text:
        failures.append(f"{page.relative_to(SITE)}: missing viewport meta")
    if "<title>" not in text or "<h1" not in text:
        failures.append(f"{page.relative_to(SITE)}: missing title or h1")

    for ref in re.findall(r'(?:href|src)="([^"]+)"', text):
        if ref.startswith(("http://", "https://", "mailto:", "#", "data:")):
            continue
        if not ref.startswith(BASE):
            failures.append(f"{page.relative_to(SITE)}: link escapes base path: {ref}")
            continue
        target = ref.split("#")[0].split("?")[0]
        if target.endswith(".html"):
            if not (SITE / target[len(BASE) + 1:]).exists():
                failures.append(f"{page.relative_to(SITE)}: broken file link {ref}")
        elif target.endswith((".css", ".js", ".png", ".ttf")):
            if not (SITE / target[len(BASE) + 1:]).exists():
                failures.append(f"{page.relative_to(SITE)}: missing asset {ref}")
        elif target not in valid:
            failures.append(f"{page.relative_to(SITE)}: broken page link {ref}")

    # numeric/code content must be LTR
    for tag in re.findall(r'<pre[^>]*>', text):
        if 'dir="ltr"' not in tag:
            failures.append(f"{page.relative_to(SITE)}: <pre> without dir=ltr")

# assets actually copied
for name in ("style.css", "app.js", "demo.js", "logo.png", "nova.png", "favicon.png", "vazirmatn.ttf"):
    if not (SITE / "assets" / name).exists():
        failures.append(f"assets/{name} missing from build")

# the demo layer must have its seed payload on every page that uses it
index = (SITE / "index.html").read_text(encoding="utf-8")
root_index = (ROOT / "index.html").read_text(encoding="utf-8")
if root_index != index:
    failures.append("root index.html differs from site/index.html")
if not (ROOT / ".nojekyll").exists():
    failures.append("root .nojekyll is missing")
if "NOVENTIX_SEED" not in index or "NOVENTIX_BASE" not in index:
    failures.append("index.html: seed payload missing")
if 'class="is-panel"' not in (SITE / "panel" / "student" / "index.html").read_text(encoding="utf-8"):
    failures.append("student panel lacks layout class")
if "url(vazirmatn.ttf)" not in (SITE / "assets" / "style.css").read_text(encoding="utf-8"):
    failures.append("font URL must be relative to stylesheet")

if failures:
    print(f"{len(failures)} problems:\n")
    for f in failures[:60]:
        print(" -", f)
    sys.exit(1)

print(f"verified {len(pages)} pages: links, assets, structure and RTL rules all pass")
