import datetime
import pathlib
import shutil
import sqlite3
import subprocess
import unittest
from html.parser import HTMLParser
from tools import gen, test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent
MESSAGE = 'هر مفهوم، یک قدم برای ساختن بهتر'
PUBLIC = ('', 'courses', 'projects', 'pricing', 'glossary', 'learners', 'community', 'about', 'contact', 'help', 'terms', 'privacy')


class Footer(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.count = 0
        self.inside = False
        self.links = []
        self.ids = []
        self.nav_names = []
        self.text = []
        self.direction = None
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'footer':
            self.count += 1
            self.inside = True
            self.direction = attrs.get('dir')
        if self.inside:
            if 'id' in attrs:
                self.ids.append(attrs['id'])
            if tag == 'a':
                self.links.append(attrs.get('href', ''))
            if tag == 'nav':
                self.nav_names.append(attrs.get('aria-label'))

    def handle_endtag(self, tag):
        if tag == 'footer':
            self.inside = False

    def handle_data(self, data):
        if self.inside:
            self.text.append(data)


def check_footer(test, source):
    footer = Footer(source)
    test.assertEqual(footer.count, 1)
    test.assertEqual(footer.direction, 'rtl')
    test.assertIn(MESSAGE, ''.join(footer.text))
    test.assertIn(gen.fa(datetime.datetime.now().year), ''.join(footer.text))
    test.assertEqual(len(footer.ids), len(set(footer.ids)))
    test.assertEqual(len(footer.nav_names), 2)
    test.assertTrue(all(footer.nav_names))
    test.assertTrue(all(link and link != '#' for link in footer.links))
    test.assertNotIn('footer-callout', source)
    return footer


class FooterStaticTest(unittest.TestCase):
    def test_all_public_routes_share_footer_and_valid_destinations(self):
        routes = {route['path']: route for route in gen.build_routes()}
        for path in PUBLIC:
            with self.subTest(path=path):
                footer = check_footer(self, gen.render_page(routes[path]))
                for link in footer.links:
                    if link.startswith('https://'):
                        self.assertIn(link, [social[0] for social in gen.SOCIALS])
                    else:
                        self.assertTrue(link.startswith(gen.SITE_PATH + '/'))
                        self.assertIn(link.removeprefix(gen.SITE_PATH + '/'), routes)

    def test_current_year_is_generated_not_literal(self):
        class FutureDate:
            @staticmethod
            def now():
                return datetime.datetime(2032, 1, 1)
        original = gen.datetime
        try:
            gen.datetime = FutureDate
            self.assertIn('۲۰۳۲', gen.footer_html())
        finally:
            gen.datetime = original
        views = (ROOT / 'src/views.php').read_text()
        self.assertIn("fa(date('Y'))", views)

    def test_contrast_focus_and_touch_target_contracts(self):
        def luminance(color):
            channels = [int(color[i:i + 2], 16) / 255 for i in (0, 2, 4)]
            channels = [v / 12.92 if v <= .04045 else ((v + .055) / 1.055) ** 2.4 for v in channels]
            return sum(a * b for a, b in zip(channels, (.2126, .7152, .0722)))
        for foreground in ('d5cede', 'c5bacf', 'eee7f4', 'ffffff'):
            ratio = (luminance(foreground) + .05) / (luminance('21112f') + .05)
            self.assertGreaterEqual(ratio, 4.5)
        css = (ROOT / 'public/assets/style.css').read_text()
        self.assertIn('.footer a:focus-visible{outline:3px solid #e5b748', css)
        self.assertIn('.footer-column>a{display:flex;align-items:center;min-height:44px', css)
        self.assertIn('.footer-links{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))', css)


class FooterHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            connection.execute("INSERT OR IGNORE INTO courses(slug,title,description,status) VALUES ('ai-foundations','دورهٔ رایگان','محتوای نمونهٔ آزمون','published')")

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def test_public_pages_and_each_internal_destination(self):
        client = test_release.Client(self.base)
        destinations = set()
        for path in tuple('articles' if path == 'courses' else path for path in PUBLIC):
            with self.subTest(path=path):
                status, _, source = client.request('/' + path)
                self.assertEqual(status, 200)
                footer = check_footer(self, source)
                destinations.update(link for link in footer.links if not link.startswith('https://'))
        for path in destinations:
            with self.subTest(destination=path):
                status, headers, _ = client.request(path)
                if path == '/dashboard/support':
                    self.assertEqual(status, 303)
                    self.assertEqual(headers.get('Location'), '/login?next=dashboard%2Fsupport')
                else:
                    self.assertEqual(status, 200)

    def test_authenticated_panel_does_not_gain_a_public_footer(self):
        php = shutil.which('php')
        code = "function h($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');} require " + repr(str(ROOT / 'src/views.php')) + "; foot_page(true);"
        output = subprocess.check_output([php, '-r', code], text=True)
        self.assertEqual(output, '</div></body></html>')


if __name__ == '__main__':
    unittest.main()
