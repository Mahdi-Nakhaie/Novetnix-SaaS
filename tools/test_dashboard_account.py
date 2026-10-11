import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")


def body(source, name):
    start = source.index(f"function {name}(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


class DashboardAccountTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.executescript(SCHEMA)
        self.db.executemany(
            "INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)",
            [("09120000001", "کاربر الف", 1), ("09120000002", "کاربر ب", 1)],
        )
        self.user_query = re.search(
            r"SELECT id,phone,name,role,phone_verified_at FROM users WHERE id=\?",
            AUTH,
        ).group(0)
        self.panel = body(VIEWS, "panel")

    def tearDown(self):
        self.db.close()

    def test_session_user_query_is_parameterized_and_isolated(self):
        self.assertEqual(self.db.execute(self.user_query, (1,)).fetchone()[1:3], ("09120000001", "کاربر الف"))
        self.assertEqual(self.db.execute(self.user_query, (2,)).fetchone()[1:3], ("09120000002", "کاربر ب"))
        self.assertIsNone(self.db.execute(self.user_query, (999,)).fetchone())
        self.assertIn("$_SESSION['uid']", body(AUTH, "user"))
        self.assertNotIn("$_GET['user_id']", body(AUTH, "user"))
        self.assertNotIn("$_POST['user_id']", body(AUTH, "user"))

    def test_dashboard_account_uses_real_safe_fields(self):
        for field in ("$me['name']", "$me['phone']", "$me['phone_verified_at']"):
            self.assertIn(field, self.panel)
        self.assertIn("account-summary", self.panel)
        self.assertIn("ثبت نشده", self.panel)
        self.assertIn("h(trim((string)$me['name'])", self.panel)
        self.assertIn("h(trim((string)$me['phone'])", self.panel)
        self.assertIn("password_hash", AUTH)
        self.assertNotIn("password_hash", self.panel)
        self.assertNotIn("otp", self.panel.lower())
        self.assertNotIn("$_GET['user_id']", self.panel)
        self.assertNotIn("$_POST['user_id']", self.panel)

    def test_incomplete_account_values_have_safe_display_contract(self):
        self.db.execute("UPDATE users SET name='' WHERE id=1")
        row = self.db.execute(self.user_query, (1,)).fetchone()
        self.assertEqual(row[2], "")
        self.assertIn("$me['phone_verified_at']!==null?'تأیید شده':'تأیید نشده'", self.panel)
        self.assertIn("$role==='admin'", self.panel)


if __name__ == "__main__":
    unittest.main()
