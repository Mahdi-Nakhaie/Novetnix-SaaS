import json
import pathlib
import re
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
DATA = json.loads((ROOT / 'curriculum/glossary.json').read_text(encoding='utf-8'))
GLOSSARY = (ROOT / 'src/glossary.php').read_text(encoding='utf-8')
ROUTER = (ROOT / 'src/router.php').read_text(encoding='utf-8')
MESSAGING = (ROOT / 'src/messaging.php').read_text(encoding='utf-8')
SCHEMA = (ROOT / 'database/schema.sqlite.sql').read_text(encoding='utf-8')


class GlossaryTest(unittest.TestCase):
    def test_catalog_is_large_unique_and_linked(self):
        entries = DATA['entries']
        keys = {entry['key'] for entry in entries}
        self.assertGreaterEqual(len(entries), 300)
        self.assertEqual(len(keys), len(entries))
        self.assertEqual({entry['category'] for entry in entries}, {category['key'] for category in DATA['categories']})
        self.assertTrue(all(entry['definition'].strip() and entry['title'].strip() for entry in entries))
        self.assertTrue(all(related in keys for entry in entries for related in entry['related']))

    def test_search_uses_database_fields_and_bound_parameters(self):
        self.assertIn("glossary_search_parts", GLOSSARY)
        self.assertIn("t.search_text LIKE ?", GLOSSARY)
        self.assertIn("t.term_en LIKE ?", GLOSSARY)
        self.assertIn("t.term_fa LIKE ?", GLOSSARY)
        self.assertIn("glossary_normalize", GLOSSARY)
        self.assertIn("q($sql,array_merge($params,$orderParams,[$perPage,$offset]))", GLOSSARY)
        self.assertNotIn("$_POST", GLOSSARY)

    def test_routes_include_indexable_directory_and_detail_pages(self):
        self.assertIn("if ($path==='glossary')", ROUTER)
        self.assertIn("^glossary/([a-z0-9-]+)$", ROUTER)
        self.assertIn("page_glossary_entry($entry)", ROUTER)
        self.assertIn("$indexable=$query==='' && $category==='' && $letter===''", ROUTER)

    def test_rendered_terms_and_related_links_are_escaped(self):
        self.assertIn("h($entry['title'])", GLOSSARY)
        self.assertIn("h($entry['definition'])", GLOSSARY)
        self.assertIn("h($related['title'])", GLOSSARY)


class PendingMessageVisibilityTest(unittest.TestCase):
    def test_recipient_queries_exclude_messages_with_pending_request(self):
        self.assertIn("NOT EXISTS (SELECT 1 FROM message_requests", MESSAGING)
        self.assertIn("messages_thread", MESSAGING)

    def test_schema_supports_pending_request_and_unique_message(self):
        db = sqlite3.connect(':memory:')
        db.execute('PRAGMA foreign_keys=ON')
        db.executescript(SCHEMA)
        db.executemany('INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)', [('09120000001','A',1),('09120000002','B',1)])
        db.execute('INSERT INTO message_requests(sender_id,recipient_id) VALUES (1,2)')
        db.execute("INSERT INTO direct_messages(sender_id,recipient_id,entity_hash,body) VALUES (1,2,'x','سلام')")
        visible = db.execute('SELECT dm.id FROM direct_messages dm WHERE (dm.recipient_id=? AND NOT EXISTS (SELECT 1 FROM message_requests mr WHERE mr.sender_id=dm.sender_id AND mr.recipient_id=dm.recipient_id)) OR dm.sender_id=?', (2,2)).fetchall()
        self.assertEqual(visible, [])
        db.execute('DELETE FROM message_requests WHERE sender_id=1 AND recipient_id=2')
        self.assertEqual(len(db.execute('SELECT dm.id FROM direct_messages dm WHERE (dm.recipient_id=? AND NOT EXISTS (SELECT 1 FROM message_requests mr WHERE mr.sender_id=dm.sender_id AND mr.recipient_id=dm.recipient_id)) OR dm.sender_id=?', (2,2)).fetchall()), 1)
        db.close()


if __name__ == '__main__':
    unittest.main()
