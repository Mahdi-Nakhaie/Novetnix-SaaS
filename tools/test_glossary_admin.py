import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
ADMIN = (ROOT / "src/glossary_admin.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")


class GlossaryAdminTest(unittest.TestCase):
    def test_admin_routes_are_in_existing_panel_and_backend_guarded(self):
        self.assertIn("'dashboard/glossary'", VIEWS)
        self.assertIn("glossary/new", ROUTER)
        self.assertIn("glossary/edit", ROUTER)
        for function in ("glossary_admin_save", "glossary_admin_publish", "glossary_admin_delete", "glossary_admin_category_save", "glossary_admin_relation_save", "glossary_admin_source_save"):
            start = ADMIN.index("function " + function)
            end = ADMIN.find("\n}", start)
            self.assertIn("require_admin()", ADMIN[start:end])

    def test_sensitive_actions_have_csrf_dispatch_and_publish_guards(self):
        self.assertIn("validate_csrf();", APP)
        self.assertIn("glossary_admin_post($path)", APP)
        self.assertIn("publication_status']==='published'", ADMIN)
        self.assertIn("reviewed_at", ADMIN)
        self.assertIn("glossary_term_sources", ADMIN)
        self.assertIn("DELETE", ADMIN)

    def test_crud_history_duplicate_and_relationship_support_exist(self):
        for statement in ("INSERT INTO glossary_terms", "UPDATE glossary_terms", "DELETE FROM glossary_terms", "glossary_revisions", "canonical_key", "glossary_term_relations", "glossary_sources", "glossary_categories"):
            self.assertIn(statement, ADMIN)
        self.assertNotIn("INSERT OR IGNORE", ADMIN)
        self.assertNotIn("GROUP_CONCAT(c.name,", ADMIN)

    def test_user_facing_output_is_escaped(self):
        self.assertGreaterEqual(ADMIN.count("h("), 20)
        self.assertIn("filter_var($url,FILTER_VALIDATE_URL)", ADMIN)
        self.assertIn("preg_match('/^[a-z0-9", ADMIN)


if __name__ == "__main__":
    unittest.main()
