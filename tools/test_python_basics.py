import contextlib
import io
import json
import pathlib
import re
import shutil
import subprocess
import unittest
import xml.etree.ElementTree as ET

from tools import test_learning_projects, test_release

grading = test_learning_projects.grading
ROOT = pathlib.Path(__file__).resolve().parents[1]
PROJECTS = json.loads((ROOT / 'curriculum/catalog.json').read_text())
ARTICLES = json.loads((ROOT / 'content/articles/catalog.json').read_text())
BEGINNERS = [p for p in PROJECTS if p['key'].startswith('py-')]
LESSONS = [a for a in ARTICLES if a.get('series') == 'python-basics']
TOKEN = 'ac80Ad6zRSLvvfcYFAMfV2JVVj8rUKlz49RpTpzwv70'


class BeginnerContentTests(unittest.TestCase):
    def test_exact_counts_order_and_prerequisites(self):
        self.assertEqual((len(PROJECTS), len(ARTICLES), len(BEGINNERS), len(LESSONS)), (36, 36, 24, 24))
        self.assertEqual([a['sequence'] for a in LESSONS], list(range(1, 25)))
        for index, (project, article) in enumerate(zip(BEGINNERS, LESSONS), 12):
            with self.subTest(project=project['key']):
                self.assertEqual(article['project_index'], index)
                self.assertEqual(project['prerequisite_articles'], [article['slug']])
                self.assertTrue(project['prerequisites'])
                self.assertEqual(project['level'], 'از صفر')
                self.assertTrue(project['input'].startswith('request.json:'))
                self.assertTrue((ROOT / 'curriculum/solutions' / (project['key']+'.py')).is_file())
                self.assertEqual(article['published_at'], '2026-10-20')

    def test_all_beginner_article_examples_execute_and_match_reference_example(self):
        for project, article in zip(BEGINNERS, LESSONS):
            with self.subTest(article=article['slug']):
                source = (ROOT / 'content/articles' / (article['slug']+'.txt')).read_text()
                examples = re.findall(r'```python\n(.*?)```', source, re.S)
                self.assertTrue(examples)
                namespace = {}
                with contextlib.redirect_stdout(io.StringIO()):
                    for example in examples:
                        exec(compile(example, article['slug'], 'exec'), namespace)
                actual = namespace['solve'](project['example']['input'])
                self.assertTrue(grading.close(actual, project['example']['output']))

    def test_all_new_projects_have_multiple_non_example_cases(self):
        for project in BEGINNERS:
            with self.subTest(project=project['key']):
                cases = grading.cases(project['key'], 123)
                self.assertGreaterEqual(len(cases), 3)
                self.assertTrue(any(payload != project['example']['input'] for payload, expected in cases))
                for payload, expected in cases:
                    self.assertFalse(grading.validate(project['key'], payload, {}, expected, {}))

    def test_booleans_are_not_numbers_and_wrong_types_fail(self):
        self.assertTrue(grading.close({'passed': True}, {'passed': True}))
        self.assertFalse(grading.close({'passed': 1}, {'passed': True}))
        self.assertFalse(grading.close({'passed': 'true'}, {'passed': True}))
        self.assertFalse(grading.close({'total': True}, {'total': 1}))

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
    def test_php_and_python_assessment_versions_match(self):
        code = 'require '+json.dumps(str(ROOT / 'src/data.php'))+'; require '+json.dumps(str(ROOT / 'src/learning_projects.php'))+'; echo lp_version();'
        result = subprocess.check_output(['php', '-r', code], text=True)
        self.assertEqual(result, grading.version())


class BeginnerHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def test_all_new_articles_and_projects_are_public_and_cross_linked(self):
        client = test_release.Client(self.base)
        article_index = client.request('/articles')[2]
        project_index = client.request('/projects')[2]
        for index, (project, article) in enumerate(zip(BEGINNERS, LESSONS), 12):
            with self.subTest(project=project['key']):
                self.assertIn('/articles/'+article['slug'], article_index)
                self.assertIn('/project/'+str(index), project_index)
                status, _, source = client.request('/articles/'+article['slug'])
                self.assertEqual(status, 200)
                self.assertIn('href="/project/'+str(index)+'"', source)
                self.assertEqual(test_release.Head(source).meta['robots'], 'index,follow')
                status, _, source = client.request('/project/'+str(index))
                self.assertEqual(status, 200)
                self.assertEqual(test_release.Head(source).meta['robots'], 'index,follow')
                self.assertIn('پیش‌نیاز و ترتیب یادگیری', source)
                self.assertIn('href="/articles/'+article['slug']+'"', source)
                self.assertIn(project['title'], source)

    def test_verification_is_in_document_head_and_sitemap_includes_every_new_page(self):
        client = test_release.Client(self.base)
        for path in ('/', '/articles', '/projects', '/articles/python-basics-hello'):
            source = client.request(path)[2]
            head = source.split('</head>', 1)[0]
            self.assertEqual(test_release.Head(source).meta['google-site-verification'], TOKEN)
            self.assertEqual(head.count('name="google-site-verification"'), 1)
        status, _, xml = client.request('/sitemap.xml')
        self.assertEqual(status, 200)
        paths = {node.text for node in ET.fromstring(xml).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')}
        for index, article in enumerate(LESSONS, 12):
            self.assertIn('https://release.invalid/articles/'+article['slug'], paths)
            self.assertIn('https://release.invalid/project/'+str(index), paths)

    def test_beginner_starter_is_private_and_does_not_expose_grading_material(self):
        client = test_release.Client(self.base)
        test_release.ReleaseHttpTest.register(self, client, '09990000661')
        client.request('/project/12')
        self.assertEqual(client.post('/projects/start', {'index': '12'})[0], 303)
        status, _, source = client.request('/project/12')
        self.assertEqual(status, 200)
        project_id = re.search(r'data-lp-id="(\d+)"', source).group(1)
        state = json.loads(client.request('/workspace/data?id='+project_id)[2])
        self.assertEqual(set(state['files']), {'main.py', 'request.json'})
        self.assertNotIn('beginner_cases.json', json.dumps(state))
        other = test_release.Client(self.base)
        test_release.ReleaseHttpTest.register(self, other, '09990000662')
        self.assertNotEqual(other.request('/workspace/data?id='+project_id)[0], 200)


if __name__ == '__main__':
    unittest.main()
