import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")


def body(source, name):
    start = source.index("function " + name + "(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


class ProgressPersistenceTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executemany(
            "INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)",
            [("09120000001", "Enrolled", 1), ("09120000002", "Other", 1)],
        )
        self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)", ("course-a", "A", "A", "published"))
        self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)", ("course-b", "B", "B", "published"))
        self.db.executemany("INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)", [(1, "A lesson", "private", 1, "published"), (2, "B lesson", "private", 1, "published")])
        self.db.execute("INSERT INTO enrollments(user_id,course_slug) VALUES (?,?)", (1, "course-a"))
        self.progress = body(APP, "save_lesson_progress")

    def tearDown(self):
        self.db.close()

    def test_progress_model_has_required_fields_and_unique_key(self):
        columns = {row[1] for row in self.db.execute("PRAGMA table_info(lesson_progress)")}
        self.assertTrue({"user_id", "lesson_id", "watched_seconds", "last_position", "percentage", "completed", "updated_at"} <= columns)
        indexes = self.db.execute("PRAGMA index_list(lesson_progress)").fetchall()
        self.assertTrue(any(row[2] for row in indexes if row[1].startswith("sqlite_autoindex_lesson_progress")))

    def test_progress_upsert_updates_existing_user_lesson(self):
        sql = """INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds,last_position,percentage,completed,status,completed_at)
        VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(user_id,lesson_id) DO UPDATE SET watched_seconds=excluded.watched_seconds,last_position=excluded.last_position,percentage=excluded.percentage,completed=excluded.completed,status=excluded.status,completed_at=excluded.completed_at,updated_at=CURRENT_TIMESTAMP"""
        self.db.execute(sql, (1, 1, 10, 10, 20, 0, "started", None))
        self.db.execute(sql, (1, 1, 25, 25, 50, 0, "started", None))
        self.assertEqual(self.db.execute("SELECT watched_seconds,last_position,percentage FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), (25.0, 25.0, 50.0))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress").fetchone()[0], 1)

    def test_endpoint_uses_auth_user_and_enrollment_lesson_authorization(self):
        self.assertIn("$u['id']", self.progress)
        self.assertIn("INNER JOIN enrollments e", self.progress)
        self.assertIn("e.user_id=?", self.progress)
        self.assertIn("l.id=?", self.progress)
        self.assertIn("ON CONFLICT(user_id,lesson_id)", self.progress)
        self.assertIn("ON DUPLICATE KEY UPDATE", self.progress)
        self.assertIn("updated_at=CURRENT_TIMESTAMP", self.progress)
        self.assertIn("progress_response(500", self.progress)
        self.assertIn("ذخیره پیشرفت انجام نشد", self.progress)
        self.assertNotIn("$_POST['updated_at']", self.progress)
        self.assertNotIn("$_POST['created_at']", self.progress)
        self.assertIn("progress_response(404", self.progress)
        self.assertIn("['error'=>", self.progress)

    def test_authorization_query_rejects_other_users_and_courses(self):
        authorization_sql = re.search(r'SELECT l\.id FROM lessons l INNER JOIN courses c .*?\",\[\$u\[\'id\'\],\$lessonId\]\)', self.progress).group(0)
        sql = authorization_sql.split('\",', 1)[0].strip('"')
        self.assertEqual(self.db.execute(sql, (1, 1)).fetchone(), (1,))
        self.assertIsNone(self.db.execute(sql, (2, 1)).fetchone())
        self.assertIsNone(self.db.execute(sql, (1, 2)).fetchone())

    def test_schema_rejects_invalid_ranges(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,percentage) VALUES (?,?,?)", (1, 1, 101))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds) VALUES (?,?,?)", (1, 1, -1))


if __name__ == "__main__":
    unittest.main()
