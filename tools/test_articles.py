import ast
import contextlib
import html
import io
import collections
import importlib.util
import json
import pathlib
import re
import sqlite3
import tempfile
import unittest
import urllib.parse
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent
CONTENT = ROOT / 'content/articles'
CATALOG = json.loads((CONTENT / 'catalog.json').read_text())


def prose(text):
    return re.sub(r'```.*?```', '', text, flags=re.S)


class Document(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.ids = []
        self.links = []
        self.json_ld = []
        self.in_json = False
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if tag == 'a':
            self.links.append(attrs.get('href', ''))
        if tag == 'script' and attrs.get('type') == 'application/ld+json':
            self.in_json = True

    def handle_endtag(self, tag):
        if tag == 'script':
            self.in_json = False

    def handle_data(self, data):
        if self.in_json:
            self.json_ld.append(json.loads(data))


class ArticleContentTest(unittest.TestCase):
    def test_home_section_actions_are_separated_from_cards(self):
        css = (ROOT / 'public/assets/style.css').read_text()
        source = (ROOT / 'src/views.php').read_text()
        self.assertEqual(source.count('class="section-row home-section-row"'), 2)
        for destination, label in (('articles', 'همه مقاله‌ها ←'), ('projects', 'همه پروژه‌ها ←')):
            self.assertIn("linkto('" + destination + "','" + label + "','btn btn-outline home-section-link')", source)
        self.assertIn('.home-section-row{align-items:center;gap:1.5rem 2rem;margin-block-end:clamp(2rem,4vw,3rem)}', css)
        self.assertIn('.home-section-row .section-header{margin-bottom:0;min-width:0}', css)
        self.assertIn('.home-section-row{align-items:flex-start;flex-direction:column;gap:1.25rem}', css)

    def test_projects_intro_matches_first_card_row_and_brand_assets_are_used(self):
        source = (ROOT / 'src/router.php').read_text()
        views = (ROOT / 'src/views.php').read_text()
        css = (ROOT / 'public/assets/style.css').read_text()
        self.assertIn('page-hero container projects-hero', source)
        self.assertIn('.projects-hero>p{white-space:nowrap', css)
        self.assertIn('/assets/noventix-logo.webp', views)
        self.assertIn('/assets/favicon.webp', views)
        self.assertIn('/assets/noventix-logo.webp', (ROOT / 'src/seo.php').read_text())
        self.assertIn('footer-brand-name', views)
        self.assertIn('.footer-brand-name{font-family:ui-monospace', css)

        css = (ROOT / 'public/assets/style.css').read_text()
        layer = css.split('/* Hover interaction layer */', 1)[1].split('body.is-panel', 1)[0]
        effect = re.search(r'\.feature::after[^{}]*\{([^}]+)\}', layer).group(1)
        for rule in ('position:absolute', 'inset:0;', 'border-radius:inherit', 'clip-path:inset(0 round var(--r-lg))', 'background:linear-gradient(135deg', 'pointer-events:none'):
            self.assertIn(rule, effect)
        for rule in ('radial-gradient', 'border-radius:50%', 'translate(', 'inset:-'):
            self.assertNotIn(rule, effect)
        self.assertNotIn('hover::after{opacity:1;transform:', layer)

    def test_home_hero_code_is_real_and_matches_displayed_output(self):
        source = (ROOT / 'src/views.php').read_text()
        lines = re.findall(r'<span class="hero-code-line">(.*?)</span>(?:\n|</code>)', source)
        self.assertEqual(len(lines), 7)
        code = '\n'.join(html.unescape(re.sub(r'<[^>]+>', '', line)) for line in lines)
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            exec(compile(code, 'first_project.py', 'exec'), {})
        self.assertEqual(output.getvalue(), 'Average: 16.0\n')
        self.assertIn('<samp dir="ltr">Average: 16.0</samp>', source)
        self.assertNotIn('data-three-scene', source)
        self.assertNotIn('/assets/three.min.js', source)
        self.assertNotIn('/assets/three-scene.js', source)

    def test_article_actions_have_spacing_and_readable_primary_labels(self):
        css = (ROOT / 'public/assets/articles.css').read_text()
        source = (ROOT / 'src/articles.php').read_text()
        self.assertEqual(source.count('<div class="article-actions">'), 2)
        self.assertIn('.article-actions{display:flex;flex-wrap:wrap;align-items:center;gap:16px 24px;margin-block-start:24px}', css)
        self.assertIn('.article-actions>.btn{max-width:100%;white-space:normal;', css)
        self.assertIn('.article-related .btn-primary:focus-visible{color:#fff}', css)
        self.assertIn('.article-actions{align-items:stretch;flex-direction:column}', css)
        self.assertIn('/assets/articles.css?v=3', (ROOT / 'src/views.php').read_text())

    def test_original_long_form_and_beginner_articles_and_metadata(self):
        self.assertEqual(len(CATALOG), 36)
        self.assertEqual(sum(a.get('series') == 'python-basics' for a in CATALOG), 24)
        for key in ('slug', 'title', 'seo_title', 'description'):
            self.assertEqual(len({a[key] for a in CATALOG}), 36)
        for article in CATALOG:
            with self.subTest(article=article['slug']):
                self.assertRegex(article['slug'], r'^[a-z0-9-]+$')
                text = (CONTENT / (article['slug'] + '.txt')).read_text()
                words = prose(text).split()
                beginner = article.get('series') == 'python-basics'
                self.assertGreaterEqual(len(words), 450 if beginner else 1850)
                self.assertLessEqual(len(words), 800 if beginner else 2200)
                self.assertGreaterEqual(len(article['description']), 90 if beginner else 140)
                self.assertLessEqual(len(article['description']), 220 if beginner else 160)
                self.assertLessEqual(len(article['seo_title'] + ' | Noventix'), 65)
                self.assertGreaterEqual(text.count('\n## '), 7 if beginner else 12)
                self.assertGreaterEqual(text.count('\n### '), 2)
                paragraphs = [p for p in prose(text).split('\n\n') if len(p.split()) > 30]
                self.assertEqual(len(paragraphs), len(set(paragraphs)))
                self.assertNotIn('TODO', text)
                for slug in article['related']:
                    self.assertIn(slug, {a['slug'] for a in CATALOG})
                    self.assertNotEqual(slug, article['slug'])
                for source in article['sources']:
                    self.assertEqual(urllib.parse.urlparse(source['url']).scheme, 'https')

    def test_python_examples_compile(self):
        for article in CATALOG:
            text = (CONTENT / (article['slug'] + '.txt')).read_text()
            for number, code in enumerate(re.findall(r'```python\n(.*?)```', text, re.S)):
                with self.subTest(article=article['slug'], example=number):
                    ast.parse(code)

    def test_small_examples_execute(self):
        dependencies = {'python-for-data': [], 'math-for-ml': ['numpy'], 'numpy-arrays': ['numpy'], 'pandas-cleaning': ['pandas']}
        for slug, modules in dependencies.items():
            with self.subTest(article=slug):
                missing = [module for module in modules if importlib.util.find_spec(module) is None]
                if missing:
                    self.skipTest('Example dependencies unavailable: ' + ', '.join(missing))
                namespace = {}
                text = (CONTENT / (slug + '.txt')).read_text()
                for code in re.findall(r'```python\n(.*?)```', text, re.S):
                    exec(compile(code, slug, 'exec'), namespace)

    def test_research_does_not_disguise_unavailable_sources_as_completed(self):
        report = json.loads((ROOT / 'outputs/research/reviews.json').read_text())
        counts = collections.Counter(r['topic'] for r in report['reviews'])
        self.assertEqual(set(counts), {a['slug'] for a in CATALOG if a.get('series') != 'python-basics'})
        self.assertTrue(all(count == 10 for count in counts.values()))
        allowed = {'extracted', 'unavailable', 'insufficient_content', 'not_html'}
        self.assertTrue(all(r['review_status'] in allowed for r in report['reviews']))
        brief = json.loads((ROOT / 'outputs/research/editorial-review.json').read_text())
        self.assertEqual(brief['coverage']['usable_extractions'], sum(r['review_status'] == 'extracted' for r in report['reviews']))


class ArticleHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def test_home_article_and_project_buttons_precede_their_card_grids(self):
        status, _, source = test_release.Client(self.base).request('/')
        self.assertEqual(status, 200)
        rows = re.findall(r'<div class="section-row home-section-row">(.*?)</div><div class="(article-grid|project-grid)"', source, re.S)
        self.assertEqual(len(rows), 2)
        for (row, grid), destination, label in zip(rows, ('articles', 'projects'), ('همه مقاله‌ها ←', 'همه پروژه‌ها ←')):
            self.assertIn('href="/' + destination + '"', row)
            self.assertIn('class="btn btn-outline home-section-link"', row)
            self.assertIn(label, row)
        self.assertEqual(source.count('<article class="article-card">'), 6)
        self.assertEqual(source.count('<article class="project-card">'), 3)

    def test_home_hero_uses_accessible_code_instead_of_webgl(self):
        client = test_release.Client(self.base)
        status, _, source = client.request('/')
        self.assertEqual(status, 200)
        self.assertIn('hero-code-window', source)
        self.assertIn('خروجی نمونه', source)
        self.assertIn('این باکس نمایشی است', source)
        self.assertIn('<pre dir="ltr" tabindex="0"', source)
        self.assertIn('/assets/style.css?v=18', source)
        self.assertNotIn('<canvas', source)
        self.assertNotIn('three.min.js', source)
        self.assertNotIn('three-scene.js', source)
        self.assertNotIn('data-scene-toggle', source)

    def test_index_redirect_and_public_home(self):
        client = test_release.Client(self.base)
        status, headers, _ = client.request('/courses')
        self.assertEqual(status, 301)
        self.assertEqual(headers['Location'], '/articles')
        status, headers, text = client.request('/articles')
        self.assertEqual(status, 200)
        self.assertEqual(test_release.Head(text).h1, 1)
        self.assertEqual(test_release.Head(text).canonical, 'https://release.invalid/articles')
        self.assertEqual(test_release.Head(text).meta['robots'], 'index,follow')
        self.assertNotIn('noindex', headers.get('X-Robots-Tag', ''))
        for article in CATALOG:
            self.assertIn('/articles/' + article['slug'], text)
        self.assertEqual(text.count('nav-free-course'), 2)
        self.assertNotIn('article-course-note', text)
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            connection.execute("INSERT OR IGNORE INTO courses(slug,title,description,status) VALUES ('ai-foundations','دورهٔ رایگان','دورهٔ منتشرشده','published')")
        status, _, with_course = client.request('/articles')
        self.assertEqual(status, 200)
        self.assertIn('/course/ai-foundations', with_course)
        self.assertIn('article-course-note', with_course)
        status, _, home = client.request('/')
        self.assertEqual(status, 200)
        self.assertIn('href="/articles"', home)
        self.assertIn('/articles/ai-foundations', home)
        self.assertIn('/assets/articles.css', home)

    def test_every_article_metadata_schema_toc_sources_and_links(self):
        client = test_release.Client(self.base)
        for article in CATALOG:
            with self.subTest(article=article['slug']):
                path = '/articles/' + article['slug']
                status, headers, text = client.request(path)
                self.assertEqual(status, 200)
                head = test_release.Head(text)
                self.assertEqual(head.h1, 1)
                self.assertEqual(head.meta['description'], article['description'])
                self.assertEqual(head.meta['robots'], 'index,follow')
                self.assertEqual(head.meta['og:type'], 'article')
                self.assertEqual(head.canonical, 'https://release.invalid' + path)
                self.assertNotIn('noindex', headers.get('X-Robots-Tag', ''))
                document = Document(text)
                self.assertEqual(len(document.ids), len(set(document.ids)))
                for link in document.links:
                    self.assertTrue(link and link != '#')
                    if link.startswith('#'):
                        self.assertIn(link[1:], document.ids)
                self.assertEqual(len(document.json_ld), 1)
                schema, breadcrumbs = document.json_ld[0]['@graph']
                self.assertEqual(schema['@type'], 'Article')
                self.assertEqual(schema['headline'], article['title'])
                self.assertGreaterEqual(schema['wordCount'], 450 if article.get('series') == 'python-basics' else 1850)
                self.assertEqual(breadcrumbs['@type'], 'BreadcrumbList')
                self.assertEqual(breadcrumbs['itemListElement'][-1]['item'], head.canonical)
                for source in article['sources']:
                    self.assertIn(source['url'].replace('&', '&amp;'), text)

    def test_missing_articles_are_404_and_not_indexable(self):
        client = test_release.Client(self.base)
        for path in ('/articles/missing', '/articles/../../missing', '/articles/ai-foundations/extra'):
            status, headers, text = client.request(path)
            self.assertEqual(status, 404)
            self.assertIn('noindex', headers.get('X-Robots-Tag', ''))
            self.assertIsNone(test_release.Head(text).canonical)

    def test_sitemap_and_course_authorization_remain_valid(self):
        client = test_release.Client(self.base)
        status, _, xml = client.request('/sitemap.xml')
        self.assertEqual(status, 200)
        urls = [node.text for node in ET.fromstring(xml).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
        self.assertEqual(len(urls), len(set(urls)))
        self.assertNotIn('https://release.invalid/courses', urls)
        for article in CATALOG:
            self.assertIn('https://release.invalid/articles/' + article['slug'], urls)
        status, headers, _ = client.request('/dashboard/learning')
        self.assertEqual(status, 303)
        self.assertTrue(headers['Location'].startswith('/login'))
        fixture = test_release.ReleaseHttpTest
        with sqlite3.connect(fixture.database) as connection:
            connection.execute("INSERT OR IGNORE INTO courses(slug,title,description,status) VALUES ('article-regression','دورهٔ مستقل','محتوای دوره','published')")
        status, _, text = client.request('/course/article-regression')
        self.assertEqual(status, 200)
        self.assertIn('دورهٔ مستقل', text)
        self.assertIn('href="/login', text)


if __name__ == '__main__':
    unittest.main()
