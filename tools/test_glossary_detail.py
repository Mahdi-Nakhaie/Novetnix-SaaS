import pathlib
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MIGRATION = (ROOT / "database/migrations/001_glossary.sqlite.sql").read_text(encoding="utf-8")
GLOSSARY = (ROOT / "src/glossary.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")


class GlossaryDetailTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executescript(MIGRATION)
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.execute("INSERT INTO glossary_categories(slug,name) VALUES ('llm','مدل‌های زبانی')")
        self.db.execute("INSERT INTO glossary_categories(slug,name) VALUES ('rag','RAG')")
        self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,letter,abbreviation,short_definition,full_definition,code_example,canonical_key,search_text,publication_status,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", ("transformer", "Transformer", "ترنسفورمر", "T", "TF", "تعریف کوتاه", "تعریف کامل", "print('ok')", "transformer", "transformer ترنسفورمر", "published", "2026-10-18"))
        self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,letter,short_definition,canonical_key,search_text,publication_status) VALUES (?,?,?,?,?,?,?,?)", ("draft-term", "Draft", "پیش‌نویس", "D", "پیش‌نویس", "draft", "draft پیش‌نویس", "draft"))
        self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,letter,short_definition,canonical_key,search_text,publication_status) VALUES (?,?,?,?,?,?,?,?)", ("rag", "RAG", "بازیابی", "R", "تعریف", "rag", "rag بازیابی", "published"))
        self.db.executemany("INSERT INTO glossary_term_categories(term_id,category_id) VALUES (?,?)", [(1, 1), (3, 2)])
        self.db.execute("INSERT INTO glossary_term_relations(term_id,related_term_id) VALUES (1,3)")
        self.db.execute("INSERT INTO glossary_sources(title,publisher,url) VALUES (?,?,?)", ("منبع علمی", "Publisher", "https://example.com/source"))
        self.db.execute("INSERT INTO glossary_term_sources(term_id,source_id) VALUES (1,1)")

    def tearDown(self):
        self.db.close()

    def test_published_detail_is_available_by_stable_slug(self):
        row = self.db.execute("SELECT slug,term_en,term_fa,abbreviation,short_definition,full_definition,code_example,reviewed_at FROM glossary_terms WHERE slug=? AND publication_status='published'", ("transformer",)).fetchone()
        self.assertEqual(row[:4], ("transformer", "Transformer", "ترنسفورمر", "TF"))
        self.assertEqual(row[4:], ("تعریف کوتاه", "تعریف کامل", "print('ok')", "2026-10-18"))

    def test_missing_and_unpublished_slugs_are_not_public(self):
        for slug in ("missing", "draft-term"):
            self.assertIsNone(self.db.execute("SELECT id FROM glossary_terms WHERE slug=? AND publication_status='published'", (slug,)).fetchone())

    def test_relations_and_sources_are_loaded_from_database(self):
        self.assertEqual(self.db.execute("SELECT rt.slug FROM glossary_term_relations r JOIN glossary_terms rt ON rt.id=r.related_term_id WHERE r.term_id=1 AND rt.publication_status='published'").fetchall(), [("rag",)])
        self.assertEqual(self.db.execute("SELECT title,url FROM glossary_sources s JOIN glossary_term_sources ts ON ts.source_id=s.id WHERE ts.term_id=1").fetchone(), ("منبع علمی", "https://example.com/source"))

    def test_empty_optional_sections_are_supported(self):
        columns = {row[1] for row in self.db.execute("PRAGMA table_info(glossary_terms)")}
        self.assertIn("code_example", columns)
        self.assertEqual(self.db.execute("SELECT code_example FROM glossary_terms WHERE slug='rag'").fetchone(), ("",))

    def test_detail_rendering_uses_real_fields_and_escaped_output(self):
        for field in ("term_en", "term_fa", "short_definition", "full_definition", "beginner_explanation", "technical_explanation", "advanced_explanation", "how_it_works", "practical_example", "code_example", "use_cases", "advantages", "limitations", "sources", "reviewed_at"):
            self.assertIn(field, GLOSSARY)
        self.assertIn("h((string)$entry['code_example'])", GLOSSARY)
        self.assertIn("publication_status='published'", GLOSSARY)
        self.assertIn("page_not_found()", ROUTER)

    def test_detail_links_back_and_related_slugs(self):
        self.assertIn("بازگشت به واژه‌نامه", GLOSSARY)
        self.assertIn("url('glossary/'.$related['key'])", GLOSSARY)

    def test_invalid_route_pattern_is_constrained(self):
        self.assertIn("^glossary/([a-z0-9-]+)$", ROUTER)


if __name__ == "__main__":
    unittest.main()
