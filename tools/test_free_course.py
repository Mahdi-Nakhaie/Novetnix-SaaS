import os
import pathlib
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from html.parser import HTMLParser

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent


class CourseDocument(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.iframes = []
        self.ids = []
        self.links = []
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if tag == 'iframe':
            self.iframes.append(attrs)
        if tag == 'a':
            self.links.append(attrs.get('href', ''))


class FreeCourseHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base
        cls.temp = tempfile.TemporaryDirectory()
        cls.env = {'PATH': os.environ.get('PATH', ''),
                   'DB_DSN': 'sqlite:' + str(test_release.ReleaseHttpTest.database),
                   'NOVENTIX_RELEASE_TEST': '1', 'PUBLIC_SITE_URL': 'https://release.invalid'}
        cls.publish('initial.json')

    @classmethod
    def publish(cls, name):
        return subprocess.check_output([shutil.which('php'), str(ROOT / 'tools/publish_free_course.php'),
                                        str(pathlib.Path(cls.temp.name) / name)], env=cls.env, text=True)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()
        test_release.ReleaseHttpTest.tearDownClass()

    def test_course_page_is_public_accessible_and_indexable(self):
        status, headers, source = test_release.Client(self.base).request('/course/ai-foundations')
        self.assertEqual(status, 200)
        head = test_release.Head(source)
        self.assertEqual(head.h1, 1)
        self.assertEqual(head.canonical, 'https://release.invalid/course/ai-foundations')
        self.assertEqual(head.meta['robots'], 'index,follow')
        self.assertNotIn('noindex', headers.get('X-Robots-Tag', ''))
        self.assertIn('مبانی هوش مصنوعی', source)
        self.assertIn('جلسهٔ دوم', source)
        self.assertIn('به‌زودی', source)
        self.assertNotIn('href="#lesson-2"', source)
        document = CourseDocument(source)
        self.assertEqual(len(document.ids), len(set(document.ids)))
        self.assertEqual(len(document.iframes), 1)
        iframe = document.iframes[0]
        self.assertEqual(iframe['src'], 'https://www.aparat.com/video/video/embed/videohash/qbk51d2/vt/frame?titleShow=true')
        self.assertTrue(iframe['title'])
        self.assertIn('allowfullscreen', iframe)
        self.assertIn('fc-switch-player', document.ids)
        for link in document.links:
            if link.startswith('#'):
                self.assertIn(link[1:], document.ids)
        for asset in ('/assets/free-course.css', '/assets/free-course.js'):
            self.assertIn(asset, source)
            self.assertEqual(test_release.Client(self.base).request(asset)[0], 200)

    def test_player_csp_is_limited_to_course_routes(self):
        client = test_release.Client(self.base)
        _, headers, _ = client.request('/course/ai-foundations')
        policy = headers['Content-Security-Policy']
        self.assertIn("frame-src 'self' https://www.aparat.com", policy)
        self.assertNotIn("'unsafe-inline'", policy)
        _, headers, _ = client.request('/articles')
        self.assertNotIn('https://www.aparat.com', headers['Content-Security-Policy'])

    def test_publication_is_repeatable_and_preserves_lesson_identity(self):
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            before = connection.execute('SELECT id,position,content FROM lessons ORDER BY id').fetchall()
        self.publish('repeat.json')
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            after = connection.execute('SELECT id,position,content FROM lessons ORDER BY id').fetchall()
            statuses = connection.execute('SELECT position,status FROM lessons ORDER BY position').fetchall()
        self.assertEqual(before, after)
        self.assertEqual(statuses, [(1, 'published'), (2, 'draft')])
        self.assertTrue((pathlib.Path(self.temp.name) / 'repeat.json').is_file())
        status, headers, _ = test_release.Client(self.base).request('/dashboard/course/ai-foundations')
        self.assertEqual(status, 303)
        self.assertTrue(headers['Location'].startswith('/login'))


if __name__ == '__main__':
    unittest.main()
