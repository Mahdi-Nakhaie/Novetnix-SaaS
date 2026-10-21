import json
import pathlib
import re
import shutil
import subprocess
import unittest
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlsplit

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parents[1]
DATA = json.loads((ROOT / 'curriculum/glossary-category-expansion.json').read_text())


class ExpansionContentTests(unittest.TestCase):
    def test_mappings_reference_real_unique_terms(self):
        legacy = json.loads((ROOT / 'curriculum/glossary.json').read_text())
        known = {e['key'] for e in legacy['entries']}
        fresh = {e['key'] for e in DATA['entries']}
        self.assertEqual(len(fresh), 10)
        self.assertFalse(known & fresh)
        for category, slugs in DATA['categories'].items():
            with self.subTest(category=category):
                self.assertGreaterEqual(len(set(slugs)), 5)
                self.assertTrue(set(slugs) <= known | fresh)

    def test_new_terms_have_substantive_definitions_and_valid_examples(self):
        for entry in DATA['entries']:
            self.assertGreater(len(entry['definition']), 70)
            self.assertGreater(len(entry['explanation']), 180)
            self.assertGreater(len(entry['example']), 60)
            if entry['code']:
                compile(entry['code'], entry['key'], 'exec')

    def test_motion_respects_accessibility_without_a_stop_control(self):
        css = (ROOT / 'public/assets/style.css').read_text()
        for rule in ('prefers-reduced-motion:reduce', 'animation:none', ':focus-within', ':focus-visible', 'animation-play-state:paused'):
            self.assertIn(rule, css)
        self.assertNotIn('glossary-motion-toggle', css)

    def test_full_width_search_and_five_column_rounded_categories(self):
        css = (ROOT / 'public/assets/style.css').read_text()
        self.assertIn('.glossary-search.glossary-search-panel{position:relative;display:block;width:100%;max-width:none;', css)
        self.assertIn('grid-template-areas:"icon input submit clear";gap:16px;width:100%', css)
        self.assertIn('grid-template-areas:"icon input clear" "submit submit submit"', css)
        self.assertIn('.glossary-topic-window .glossary-categories{display:grid;grid-template-columns:repeat(5,minmax(0,1fr))', css)
        self.assertIn('grid-template-columns:repeat(5,minmax(150px,1fr));overflow-x:auto', css)
        self.assertIn('padding:14px;border-radius:18px;', css)
        self.assertIn('width:34px;height:34px;border:1px solid #a782ca66;border-radius:50%', css)

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_actual_seed_is_additive_idempotent_and_preserves_editor_changes(self):
        code = '''
require %s;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(%s));
glossary_backfill_legacy($db);
glossary_seed_initial_drafts($db);
$before=$db->query('SELECT * FROM glossary_terms')->fetchAll();
glossary_expand_categories($db);
$after=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
$counts=$db->query("SELECT c.slug,COUNT(t.id) AS count FROM glossary_categories c LEFT JOIN glossary_term_categories tc ON tc.category_id=c.id LEFT JOIN glossary_terms t ON t.id=tc.term_id AND t.publication_status='published' GROUP BY c.id")->fetchAll();
glossary_expand_categories($db);
$repeat=$db->query('SELECT * FROM glossary_terms ORDER BY id')->fetchAll();
$db->exec("UPDATE glossary_terms SET short_definition='editor text',publication_status='draft' WHERE slug='python'");
$db->exec("DELETE FROM glossary_term_categories WHERE term_id=(SELECT id FROM glossary_terms WHERE slug='python')");
glossary_expand_categories($db);
$edited=$db->query("SELECT short_definition,publication_status FROM glossary_terms WHERE slug='python'")->fetch();
$links=$db->query("SELECT COUNT(*) FROM glossary_term_categories WHERE term_id=(SELECT id FROM glossary_terms WHERE slug='python')")->fetchColumn();
echo json_encode([$before,$after,$repeat,$counts,$edited,$links]);
''' % (json.dumps(str(ROOT / 'database/connection.php')), json.dumps(str(ROOT / 'database/migrations/001_glossary.sqlite.sql')))
        result = subprocess.run(['php', '-r', code], text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        before, after, repeat, counts, edited, links = json.loads(result.stdout)
        self.assertEqual(before, after[:len(before)])
        self.assertEqual(after, repeat)
        self.assertEqual(len(counts), 25)
        self.assertTrue(all(c['count'] >= 5 for c in counts), counts)
        self.assertEqual(edited, {'short_definition': 'editor text', 'publication_status': 'draft'})
        self.assertEqual(links, 0)

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_existing_draft_is_never_published(self):
        code = '''
require %s;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(%s));
glossary_backfill_legacy($db);
$db->exec("INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key) VALUES ('python','Python','پایتون','editor draft','python پایتون')");
glossary_expand_categories($db);
echo json_encode($db->query("SELECT publication_status,short_definition FROM glossary_terms WHERE slug='python'")->fetch(PDO::FETCH_ASSOC));
''' % (json.dumps(str(ROOT / 'database/connection.php')), json.dumps(str(ROOT / 'database/migrations/001_glossary.sqlite.sql')))
        result = subprocess.run(['php', '-r', code], text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'publication_status': 'draft', 'short_definition': 'editor draft'})


class Links(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.links = []
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            self.links.append(dict(attrs))


class ExpansionHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def test_window_alphabet_and_combined_links(self):
        client = test_release.Client(self.base)
        status, _, source = client.request('/glossary?q=Python&category=python&letter=P')
        self.assertEqual(status, 200)
        for text in ('topics.py', 'glossary-topic-window', 'glossary-all-topics', '/assets/style.css?v=17'):
            self.assertIn(text, source)
        self.assertNotIn('glossary-motion', source)
        self.assertNotIn('توقف حرکت', source)
        nav = re.search(r'<nav class="glossary-categories"[^>]*>(.*?)</nav>', source, re.S).group(1)
        category_links = Links(nav).links
        self.assertEqual(len(category_links), 25)
        self.assertTrue(all(parse_qs(urlsplit(a['href']).query).get('category') for a in category_links))
        self.assertNotIn('همه حوزه‌ها', nav)
        links = Links(source).links
        current = [a for a in links if a.get('aria-current') == 'page']
        self.assertEqual(len(current), 2)
        for link in current:
            params = parse_qs(urlsplit(link['href']).query)
            self.assertEqual(params, {'q': ['Python'], 'category': ['python'], 'letter': ['P']})
        self.assertIn('/glossary/python', source)
        self.assertIn('name="category" value="python"', source)
        self.assertIn('name="letter" value="P"', source)
        self.assertIn('aria-label="اصطلاحات حرف P، ۱ مورد"', source)
        self.assertIn('aria-label="اصطلاحات حرف V، ۱ مورد"', source)

    def test_every_expanded_category_and_new_detail_is_public(self):
        client = test_release.Client(self.base)
        for category in DATA['categories']:
            status, _, source = client.request('/glossary?category=' + category)
            self.assertEqual(status, 200)
            self.assertGreaterEqual(source.count('<article class="glossary-card">'), 5, category)
        for entry in DATA['entries']:
            status, _, source = client.request('/glossary/' + entry['key'])
            self.assertEqual(status, 200)
            self.assertIn(entry['title'], source)
            self.assertIn(entry['explanation'], source)
            self.assertEqual(test_release.Head(source).meta['robots'], 'index,follow')

    def test_empty_combination_does_not_break_navigation(self):
        status, _, source = test_release.Client(self.base).request('/glossary?category=python&letter=Z')
        self.assertEqual(status, 200)
        self.assertIn('حذف فیلترها', source)
        self.assertNotIn('<article class="glossary-card">', source)
        self.assertIn('glossary-topic-window', source)


if __name__ == '__main__':
    unittest.main()
