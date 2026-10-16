"""Rebuild the source/control inventory and static SEO evidence without a browser."""
import json
import pathlib
import re
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parent.parent


class Page(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.links, self.forms, self.buttons, self.images, self.headings = [], [], [], [], []
        self.metadata = {}
        self.title = ''
        self.current = None
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a':
            self.links.append({'href': attrs.get('href'), 'label': ''})
            self.current = self.links[-1]
        elif tag == 'form':
            self.forms.append({'action': attrs.get('action'), 'method': attrs.get('method', 'get'), 'fields': []})
        elif tag in ('input', 'textarea', 'select') and self.forms:
            if attrs.get('name'):
                self.forms[-1]['fields'].append(attrs['name'])
        elif tag == 'button':
            self.buttons.append({'type': attrs.get('type', 'submit'), 'label': '', 'disabled': 'disabled' in attrs})
            self.current = self.buttons[-1]
        elif tag in ('h1', 'h2', 'h3'):
            self.headings.append({'level': int(tag[1]), 'label': ''})
            self.current = self.headings[-1]
        elif tag == 'meta':
            self.metadata[attrs.get('name', attrs.get('property', 'charset'))] = attrs.get('content', attrs.get('charset'))
        elif tag == 'img':
            self.images.append({'src': attrs.get('src'), 'alt': attrs.get('alt')})
        elif tag == 'title':
            self.current = {'label': ''}

    def handle_data(self, text):
        if self.current is not None:
            self.current['label'] += text

    def handle_endtag(self, tag):
        if tag == 'title' and self.current:
            self.title = self.current['label']
        if tag in ('a', 'button', 'h1', 'h2', 'h3', 'title'):
            if self.current:
                self.current['label'] = self.current['label'].strip()
            self.current = None


def main():
    pages, seo = [], []
    for path in sorted((ROOT / 'site').rglob('*.html')):
        relative = str(path.relative_to(ROOT))
        page = Page(path.read_text())
        pages.append({'file': relative, 'links': page.links, 'forms': page.forms, 'buttons': page.buttons,
                      'interaction_result': 'NOT TESTED', 'reason': 'Static demo; HTML inspection is not interaction or server authentication.'})
        checks = {
            'title_present': 'PASS' if page.title else 'FAIL',
            'description_present': 'PASS' if page.metadata.get('description') else 'FAIL',
            'single_h1': 'PASS' if sum(item['level'] == 1 for item in page.headings) == 1 else 'FAIL',
            'og_metadata': 'PASS' if page.metadata.get('og:title') and page.metadata.get('og:description') and page.metadata.get('og:locale') == 'fa_IR' else 'FAIL',
            'twitter_metadata': 'PASS' if page.metadata.get('twitter:card') and page.metadata.get('twitter:title') else 'FAIL',
            'image_alt_attribute': 'PASS' if all(image['alt'] is not None for image in page.images) else 'FAIL',
            'demo_noindex': 'PASS' if page.metadata.get('robots') == 'noindex,follow' else 'FAIL',
            'canonical_production_origin': 'NOT TESTED', 'http_status_on_deployed_host': 'NOT TESTED',
            'viewport_rendering': 'NOT TESTED', 'web_vitals': 'NOT TESTED', 'search_indexation': 'NOT TESTED',
        }
        seo.append({'file': relative, 'index_policy': 'Demo intentionally excluded; no invented canonical or sitemap origin.',
                    'checks': checks, 'status': 'FAIL' if 'FAIL' in checks.values() else 'PASS',
                    'scope': 'Static source checks only', 'headings': page.headings})
    forms, posts, links = [], [], []
    for path in sorted((ROOT / 'src').glob('*.php')):
        text = path.read_text()
        for target, output in ((r"form_start\('([^']*)'", forms), (r"\$path==='([^']+)'", posts), (r"linkto\('([^']*)'", links)):
            for match in re.finditer(target, text):
                output.append({'target': match[1], 'source': str(path.relative_to(ROOT)), 'line': text.count('\n', 0, match.start()) + 1})
    inventory = {'scope': 'All generated HTML controls plus literal PHP form/link/route references; computed PHP destinations require the route-family inventory in release-audit.md.',
                 'static_pages': pages, 'php_form_actions': forms, 'php_path_branches': posts, 'php_link_targets': links}
    (ROOT / 'docs/release-inventory.json').write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + '\n')
    (ROOT / 'docs/seo-audit.json').write_text(json.dumps({'scope': 'Every static HTML page; PHP HTTP evidence is in release-audit.md and tools/test_release.py.', 'pages': seo}, ensure_ascii=False, indent=2) + '\n')
    failures = sum(page['status'] == 'FAIL' for page in seo)
    print(f'Inventoried {len(pages)} static pages; {len(forms)} literal PHP form actions; static SEO failures: {failures}')
    if failures:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
