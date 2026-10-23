import contextlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(os.environ.get('NOVENTIX_TEST_ROOT', Path(__file__).resolve().parents[1]))
PROJECTS = json.loads((ROOT / 'curriculum/catalog.json').read_text())
ARTICLES = json.loads((ROOT / 'content/articles/catalog.json').read_text())
GLOSSARY = json.loads((ROOT / 'curriculum/glossary-practice-expansion.json').read_text())


class PracticeExpansionTests(unittest.TestCase):
    def test_counts_unique_identifiers_and_cross_links(self):
        self.assertEqual(len(PROJECTS), 48)
        self.assertEqual(len(ARTICLES), 48)
        self.assertEqual(len({p['key'] for p in PROJECTS}), 48)
        self.assertEqual(len({a['slug'] for a in ARTICLES}), 48)
        cases = json.loads((ROOT / 'curriculum/beginner_cases.json').read_text())
        guidance = json.loads((ROOT / 'curriculum/project-guidance.json').read_text())
        self.assertEqual({p['key'] for p in PROJECTS}, set(guidance))
        for index, project in enumerate(PROJECTS[36:], 36):
            with self.subTest(project=project['key']):
                article = next(a for a in ARTICLES if a.get('project_index') == index)
                self.assertEqual(project['prerequisite_articles'], [article['slug']])
                self.assertEqual(article['series'], 'python-practice')
                self.assertGreaterEqual(len(cases[project['key']]), 4)
                self.assertIn([project['example']['input'], project['example']['output']], cases[project['key']])

    def test_all_new_cases_and_article_examples_execute(self):
        cases = json.loads((ROOT / 'curriculum/beginner_cases.json').read_text())
        for project in PROJECTS[36:]:
            with self.subTest(project=project['key']):
                namespace = {'__name__': 'reference'}
                exec(compile((ROOT / 'curriculum/solutions' / (project['key'] + '.py')).read_text(), project['key'], 'exec'), namespace)
                for payload, expected in cases[project['key']]:
                    self.assertEqual(namespace['solve'](payload), expected)
        for article in ARTICLES[-12:]:
            text = (ROOT / 'content/articles' / (article['slug'] + '.txt')).read_text()
            project = PROJECTS[article['project_index']]
            for code in re.findall(r'```python\n(.*?)```', text, re.S):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    exec(compile(code, article['slug'], 'exec'), {})
                self.assertTrue(output.getvalue().strip())
            expected = json.loads(re.findall(r'```json\n(.*?)```', text, re.S)[0])
            self.assertEqual(expected, project['example']['output'])
            self.assertTrue(130 <= len(article['description']) <= 160)
            self.assertLessEqual(len(article['seo_title'] + ' | Noventix'), 65)
            self.assertGreaterEqual(len(re.sub(r'```.*?```', '', text, flags=re.S).split()), 600)
            self.assertGreaterEqual(text.count('\n## '), 9)
            for slug in article['related']:
                self.assertIn(slug, {a['slug'] for a in ARTICLES})

    def test_glossary_exactly_one_hundred_new_classified_entries(self):
        entries = GLOSSARY['entries']
        self.assertEqual(len(entries), 100)
        for field in ('key', 'term', 'title'):
            self.assertEqual(len({e[field].casefold() for e in entries}), 100)
        old = json.loads((ROOT / 'curriculum/glossary.json').read_text())['entries']
        drafts = json.loads((ROOT / 'curriculum/glossary_initial_drafts.json').read_text())['entries']
        expanded = json.loads((ROOT / 'curriculum/glossary-category-expansion.json').read_text())['entries']
        self.assertFalse({e['key'] for e in entries} & {e['key'] for e in old + drafts + expanded})
        self.assertFalse({e['term'].casefold() for e in entries} & {e['term'].casefold() for e in old + drafts + expanded})
        classified = [key for keys in GLOSSARY['categories'].values() for key in keys]
        self.assertEqual(len(classified), 100)
        self.assertEqual(set(classified), {e['key'] for e in entries})
        for entry in entries:
            self.assertRegex(entry['key'], r'^[a-z0-9-]+$')
            self.assertTrue(entry['definition'].startswith(entry['title'] + '؛'))
            self.assertGreater(len(entry['definition']), 55)
            self.assertGreater(len(entry['explanation']), 120)
            self.assertGreater(len(entry['example']), 35)

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_transactional_seed_preserves_existing_rows_and_editor_changes(self):
        code = r'''
require %s;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(%s));
glossary_backfill_legacy($db);
glossary_seed_initial_drafts($db);
glossary_expand_categories($db);
$before=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
glossary_expand_categories($db,'practice-expansion-20261023','glossary-practice-expansion.json');
$after=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
$db->exec("UPDATE glossary_terms SET short_definition='editor text',publication_status='draft' WHERE slug='median'");
$db->exec("DELETE FROM glossary_term_categories WHERE term_id=(SELECT id FROM glossary_terms WHERE slug='median')");
$edited=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
glossary_expand_categories($db,'practice-expansion-20261023','glossary-practice-expansion.json');
$repeat=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
$links=(int)$db->query("SELECT COUNT(*) FROM glossary_term_categories WHERE term_id=(SELECT id FROM glossary_terms WHERE slug='median')")->fetchColumn();
echo json_encode([$before,$after,$edited,$repeat,$links]);
''' % (json.dumps(str(ROOT / 'database/connection.php')), json.dumps(str(ROOT / 'database/migrations/001_glossary.sqlite.sql')))
        process = subprocess.run(['php', '-r', code], capture_output=True, text=True, timeout=30)
        self.assertEqual(process.returncode, 0, process.stderr)
        before, after, edited, repeat, links = json.loads(process.stdout)
        self.assertEqual(after[:len(before)], before)
        self.assertEqual(len(after) - len(before), 100)
        self.assertEqual(sum(t['publication_status'] == 'draft' for t in before), sum(t['publication_status'] == 'draft' for t in after))
        self.assertEqual(edited, repeat)
        self.assertEqual(links, 0)


if __name__ == '__main__':
    unittest.main()
