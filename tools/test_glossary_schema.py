import json
import pathlib
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MIGRATION = (ROOT / "database/migrations/001_glossary.sqlite.sql").read_text(encoding="utf-8")


class GlossarySchemaTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.executescript(MIGRATION)

    def tearDown(self):
        self.db.close()

    def test_terms_support_stable_slug_search_fields_status_and_duplicates(self):
        self.db.execute(
            "INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key,publication_status,alternative_translations,use_cases,advantages,limitations) VALUES (?,?,?,?,?,?,?,?,?,?)",
            ("machine-learning", "Machine Learning", "یادگیری ماشین", "تعریف کوتاه", "machine learning", "published", "[]", "[]", "[]", "[]"),
        )
        self.db.execute(
            "INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key,duplicate_of_id) VALUES (?,?,?,?,?,?)",
            ("ml", "ML", "یادگیری ماشین", "تعریف تکراری", "machine learning", 1),
        )
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute(
                "INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key) VALUES (?,?,?,?,?)",
                ("machine-learning", "Duplicate", "تکراری", "تعریف", "duplicate"),
            )
        self.assertEqual(self.db.execute("SELECT duplicate_of_id FROM glossary_terms WHERE id=2").fetchone(), (1,))
        self.assertEqual(self.db.execute("SELECT publication_status FROM glossary_terms WHERE id=1").fetchone(), ("published",))
        indexes = {row[1] for row in self.db.execute("PRAGMA index_list(glossary_terms)")}
        self.assertTrue({"idx_glossary_terms_term_en", "idx_glossary_terms_term_fa", "idx_glossary_terms_status_updated", "idx_glossary_terms_canonical_key"} <= indexes)

    def test_categories_relations_sources_and_revisions_enforce_relationships(self):
        self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key) VALUES ('rag','RAG','راگ','تعریف','rag')")
        self.db.execute("INSERT INTO glossary_terms(slug,term_en,term_fa,short_definition,canonical_key) VALUES ('retrieval','Retrieval','بازیابی','تعریف','retrieval')")
        self.db.executemany("INSERT INTO glossary_categories(slug,name) VALUES (?,?)", [("rag", "بازیابی"), ("llm", "مدل زبانی")])
        self.db.executemany("INSERT INTO glossary_term_categories(term_id,category_id) VALUES (1,?)", [(1,), (2,)])
        self.db.execute("INSERT INTO glossary_term_relations(term_id,related_term_id,relation_type) VALUES (1,2,'prerequisite')")
        self.db.execute("INSERT INTO glossary_sources(title,source_type,identifier) VALUES ('Reference','documentation','docs:rag')")
        self.db.execute("INSERT INTO glossary_term_sources(term_id,source_id) VALUES (1,1)")
        self.db.execute("INSERT INTO glossary_revisions(term_id,editor_id,snapshot_json) VALUES (1,1,?)", (json.dumps({"slug": "rag"}),))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM glossary_term_categories WHERE term_id=1").fetchone()[0], 2)
        self.assertEqual(self.db.execute("SELECT relation_type FROM glossary_term_relations").fetchone(), ("prerequisite",))
        self.assertEqual(self.db.execute("SELECT title FROM glossary_sources").fetchone(), ("Reference",))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM glossary_revisions WHERE term_id=1").fetchone()[0], 1)
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO glossary_term_relations(term_id,related_term_id) VALUES (1,1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO glossary_term_categories(term_id,category_id) VALUES (99,1)")

    def test_migration_is_repeatable_and_does_not_change_existing_users(self):
        self.db.executescript(MIGRATION)
        self.db.executescript(MIGRATION)
        self.assertEqual(self.db.execute("SELECT phone FROM users").fetchall(), [("09123456789",)])
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM glossary_terms").fetchone(), (0,))


if __name__ == "__main__":
    unittest.main()
