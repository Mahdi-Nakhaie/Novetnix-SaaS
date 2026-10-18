import json
import pathlib
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MIGRATION = (ROOT / "database/migrations/001_glossary.sqlite.sql").read_text(encoding="utf-8")


def normalize(value):
    return " ".join(value.lower().replace("ي", "ی").replace("ك", "ک").split())


class DatabaseGlossarySearchTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executescript(MIGRATION)
        self.db.executemany("INSERT INTO glossary_categories(slug,name) VALUES (?,?)", [("llm", "مدل‌های زبانی"), ("rag", "بازیابی و RAG")])
        rows = [
            ("transformer", "Transformer", "ترنسفورمر", "معماری مدل زبانی", "transformer ترنسفورمر یک معماری مدل زبانی", "llm"),
            ("rag", "RAG", "بازیابی افزوده", "بازیابی اطلاعات برای پاسخ دقیق", "rag بازیابی افزوده بازیابی اطلاعات برای پاسخ دقیق", "rag"),
        ]
        for slug, term_en, term_fa, definition, search_text, category in rows:
            self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,letter,short_definition,full_definition,canonical_key,search_text,publication_status) VALUES (?,?,?,?,?,?,?,?,?)", (slug, term_en, term_fa, term_en[0], definition, definition, normalize(term_en), normalize(search_text), "published"))
            term_id = self.db.execute("SELECT id FROM glossary_terms WHERE slug=?", (slug,)).fetchone()[0]
            category_id = self.db.execute("SELECT id FROM glossary_categories WHERE slug=?", (category,)).fetchone()[0]
            self.db.execute("INSERT INTO glossary_term_categories(term_id,category_id) VALUES (?,?)", (term_id, category_id))
        self.db.execute("UPDATE glossary_terms SET abbreviation='RAG' WHERE slug='rag'")

    def tearDown(self):
        self.db.close()

    def search(self, query, category=""):
        query = normalize(query)
        params = ["published"]
        where = ["t.publication_status=?"]
        if category:
            where.append("EXISTS (SELECT 1 FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=t.id AND c.slug=?)")
            params.append(category)
        if query:
            where.append("(t.search_text LIKE ? OR t.term_en LIKE ? OR t.term_fa LIKE ? OR COALESCE(t.abbreviation,'') LIKE ? OR t.short_definition LIKE ?)")
            like = "%" + query + "%"
            params.extend([like] * 5)
        return self.db.execute("SELECT t.slug FROM glossary_terms t WHERE " + " AND ".join(where) + " ORDER BY t.term_en", params).fetchall()

    def test_english_name(self):
        self.assertEqual(self.search("Transformer"), [("transformer",)])

    def test_persian_name(self):
        self.assertEqual(self.search("ترنسفورمر"), [("transformer",)])

    def test_abbreviation_or_short_name(self):
        self.assertEqual(self.search("RAG"), [("rag",)])

    def test_partial_name(self):
        self.assertEqual(self.search("form"), [("transformer",)])

    def test_no_results(self):
        self.assertEqual(self.search("وجود ندارد"), [])

    def test_arabic_yeh_and_kaf_normalize(self):
        self.assertEqual(self.search("ترنسفورمر يک"), [("transformer",)])
        self.assertEqual(normalize("ي ك"), "ی ک")

    def test_empty_query(self):
        self.assertEqual(self.search(""), [("rag",), ("transformer",)])

    def test_very_long_query_is_safe(self):
        self.assertEqual(self.search("x" * 10000), [])

    def test_backend_error_is_observable(self):
        self.db.close()
        with self.assertRaises(sqlite3.ProgrammingError):
            self.search("RAG")

    def test_category_filter_combines_with_query(self):
        self.assertEqual(self.search("بازیابی", "rag"), [("rag",)])
        self.assertEqual(self.search("بازیابی", "llm"), [])


if __name__ == "__main__":
    unittest.main()
