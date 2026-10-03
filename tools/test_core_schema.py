import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SQLITE_SCHEMA = (ROOT / "src/schema.sqlite.sql").read_text(encoding="utf-8")
MYSQL_SCHEMA = (ROOT / "src/schema.mysql.sql").read_text(encoding="utf-8")


class CoreSchemaTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SQLITE_SCHEMA)

    def tearDown(self):
        self.db.close()

    def test_tables_match_both_engines(self):
        tables = lambda schema: set(re.findall(r"CREATE TABLE IF NOT EXISTS (\w+)", schema))
        self.assertEqual(tables(SQLITE_SCHEMA), tables(MYSQL_SCHEMA))
        self.assertTrue({"users", "courses", "lessons"} <= tables(SQLITE_SCHEMA))
        self.assertEqual(len(tables(SQLITE_SCHEMA)), 20)

    def test_multiple_users_courses_and_lessons(self):
        self.db.executemany("INSERT INTO users(phone) VALUES (?)", [("09123456789",), ("09987654321",)])
        self.db.executemany(
            "INSERT INTO courses(slug,title,description) VALUES (?,?,?)",
            [("python-foundations", "Python", "Foundations"), ("data-analysis", "Data", "Analysis")],
        )
        self.db.executemany(
            "INSERT INTO lessons(course_id,title,content,position) VALUES (?,?,?,?)",
            [(1, "Intro", "Text", 1), (1, "Functions", "Text", 2), (2, "Data", "Text", 1)],
        )
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM users").fetchone()[0], 2)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM courses").fetchone()[0], 2)
        self.assertEqual(self.db.execute("SELECT course_id,position FROM lessons ORDER BY course_id,position").fetchall(), [(1, 1), (1, 2), (2, 1)])

    def test_uniqueness_and_required_fields(self):
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Again','Intro')")
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'First','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Second','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (NULL,'Orphan','Text',2)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Invalid','Text',0)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES ('invalid','Invalid','Text','unknown')")

    def test_foreign_key_prevents_orphans(self):
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (999,'Orphan','Text',1)")
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Intro','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("DELETE FROM courses WHERE id=1")
        self.assertEqual(self.db.execute("PRAGMA foreign_key_check").fetchall(), [])

    def test_existing_database_keeps_users_and_adds_core_tables(self):
        self.db.close()
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.execute("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL DEFAULT '', role TEXT NOT NULL DEFAULT 'student', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)")
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.executescript(SQLITE_SCHEMA)
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        self.db.executescript(SQLITE_SCHEMA)
        self.assertEqual(self.db.execute("SELECT phone FROM users").fetchall(), [("09123456789",)])
        self.assertEqual(self.db.execute("SELECT slug FROM courses").fetchall(), [("python",)])


if __name__ == "__main__":
    unittest.main()
