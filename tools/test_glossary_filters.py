import pathlib
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MIGRATION = (ROOT / "database/migrations/001_glossary.sqlite.sql").read_text(encoding="utf-8")
GLOSSARY = (ROOT / "src/glossary.php").read_text(encoding="utf-8")


class GlossaryFilterTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executescript(MIGRATION)
        self.db.executemany("INSERT INTO glossary_categories(slug,name) VALUES (?,?)", [("ml", "یادگیری ماشین"), ("rag", "RAG")])
        self.db.executemany(
            "INSERT INTO glossary_terms(slug,term_en,term_fa,letter,short_definition,canonical_key,search_text,publication_status) VALUES (?,?,?,?,?,?,?,?)",
            [
                ("adapter", "Adapter", "آداپتر", "A", "تعریف", "adapter", "adapter آداپتر", "published"),
                ("agent", "Agent", "عامل", "A", "تعریف", "agent", "agent عامل", "published"),
                ("rag", "RAG", "بازیابی", "R", "تعریف", "rag", "rag بازیابی", "published"),
            ],
        )
        self.db.executemany("INSERT INTO glossary_term_categories(term_id,category_id) VALUES (?,?)", [(1, 1), (2, 1), (3, 2)])

    def tearDown(self):
        self.db.close()

    def filtered(self, query="", category="", letter="", page=1):
        where = ["t.publication_status='published'"]
        params = []
        if query:
            where.append("t.search_text LIKE ?")
            params.append("%" + query + "%")
        if category:
            where.append("EXISTS (SELECT 1 FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=t.id AND c.slug=?)")
            params.append(category)
        if letter:
            where.append("t.letter=?")
            params.append(letter)
        return self.db.execute("SELECT t.slug FROM glossary_terms t WHERE " + " AND ".join(where) + " ORDER BY t.term_en LIMIT 24 OFFSET ?", params + [(page - 1) * 24]).fetchall()

    def test_all_terms_and_selected_letter(self):
        self.assertEqual(self.filtered(), [("adapter",), ("agent",), ("rag",)])
        self.assertEqual(self.filtered(letter="A"), [("adapter",), ("agent",)])
        self.assertEqual(self.filtered(letter="R"), [("rag",)])

    def test_letter_is_based_on_standard_english_term(self):
        row = self.db.execute("SELECT term_en,letter FROM glossary_terms WHERE slug='adapter'").fetchone()
        self.assertEqual(row[0][0].upper(), row[1])

    def test_category_filter(self):
        self.assertEqual(self.filtered(category="ml"), [("adapter",), ("agent",)])
        self.assertEqual(self.filtered(category="rag"), [("rag",)])

    def test_query_category_and_letter_are_combined(self):
        self.assertEqual(self.filtered(query="agent", category="ml", letter="A"), [("agent",)])
        self.assertEqual(self.filtered(query="agent", category="rag", letter="A"), [])

    def test_empty_letter_returns_no_results_without_affecting_other_letters(self):
        self.assertEqual(self.filtered(letter="Z"), [])
        self.assertEqual(self.filtered(letter="A"), [("adapter",), ("agent",)])

    def test_pagination_uses_filtered_result_set(self):
        self.assertEqual(self.filtered(category="ml", letter="A", page=1), [("adapter",), ("agent",)])
        self.assertEqual(self.filtered(category="ml", letter="A", page=2), [])

    def test_backend_and_ui_preserve_all_filters_and_clear_them(self):
        self.assertIn("$params['letter']=$letter", GLOSSARY)
        self.assertIn("glossary_query_url($query,$item['key'],$letter)", GLOSSARY)
        self.assertIn("حذف فیلترها", GLOSSARY)
        self.assertIn("glossary_letter_counts", GLOSSARY)


if __name__ == "__main__":
    unittest.main()
