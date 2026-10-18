import json
import pathlib
import unittest
from urllib.parse import urlparse

ROOT = pathlib.Path(__file__).resolve().parent.parent
DATA = json.loads((ROOT / "curriculum/glossary_initial_drafts.json").read_text(encoding="utf-8"))
LEGACY = json.loads((ROOT / "curriculum/glossary.json").read_text(encoding="utf-8"))
CONNECTION = (ROOT / "database/connection.php").read_text(encoding="utf-8")


class InitialGlossaryTest(unittest.TestCase):
    def test_package_has_150_unique_drafts_without_legacy_overlap(self):
        entries = DATA["entries"]
        keys = [entry["key"] for entry in entries]
        self.assertEqual(DATA["status"], "draft")
        self.assertEqual(len(entries), 150)
        self.assertEqual(len(keys), len(set(keys)))
        self.assertTrue(set(keys).isdisjoint({entry["key"] for entry in LEGACY["entries"]}))

    def test_entries_have_required_content_and_review_is_pending(self):
        for entry in DATA["entries"]:
            for field in ("term", "title", "short_definition", "full_definition", "beginner_explanation", "technical_explanation", "practical_example", "use_cases", "limitations", "category", "difficulty_level", "source", "reviewed_at"):
                self.assertIn(field, entry)
            self.assertTrue(entry["short_definition"].strip())
            self.assertTrue(entry["full_definition"].strip())
            self.assertTrue(entry["use_cases"])
            self.assertTrue(entry["limitations"])
            self.assertIsNone(entry["reviewed_at"])
            self.assertIn(entry["difficulty_level"], {"beginner", "intermediate", "advanced"})

    def test_sources_are_https_and_shared_sources_are_deduplicable(self):
        urls = []
        for entry in DATA["entries"]:
            source = entry["source"]
            parsed = urlparse(source["url"])
            self.assertEqual(parsed.scheme, "https")
            self.assertTrue(parsed.netloc)
            self.assertTrue(source["title"].strip())
            self.assertTrue(source["publisher"].strip())
            urls.append(source["url"])
        self.assertLess(len(set(urls)), len(urls))

    def test_runtime_seeds_as_draft_and_records_revision_and_source(self):
        self.assertIn("glossary_seed_initial_drafts", CONNECTION)
        self.assertIn("publication_status,reviewed_at", CONNECTION)
        self.assertIn("glossary_revisions", CONNECTION)
        self.assertIn("glossary_term_sources", CONNECTION)
        self.assertIn("glossary_seed_initial_drafts($db)", CONNECTION)
        self.assertIn("'transformers'=>'ترنسفورمرها'", CONNECTION)


if __name__ == "__main__":
    unittest.main()
