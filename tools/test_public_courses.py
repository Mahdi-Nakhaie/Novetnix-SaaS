import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")


def body(source, name):
    start = source.index(f"function {name}(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


def query(source, table):
    return re.search(r'"(SELECT [^"\n]+ FROM ' + table + r' [^"\n]+)"', source).group(1)


class PublicCoursesTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executemany(
            "INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)",
            [("published", "Published", "From database", "published"),
             ("draft", "Draft", "Hidden", "draft"),
             ("empty", "Empty", "No published lessons", "published")],
        )
        self.db.executemany(
            "INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)",
            [(1, "Third", "Third content", 3, "published"),
             (1, "First", "First content", 1, "published"),
             (1, "Second", "Second content", 2, "published"),
             (1, "Draft lesson", "Hidden", 4, "draft"),
             (2, "Other course", "Hidden", 1, "published"),
             (3, "Empty course draft", "Hidden", 1, "draft")],
        )
        self.detail = body(ROUTER, "page_course")
        self.listing = body(VIEWS, "course_cards")
        self.course_sql = query(self.detail, "courses")
        self.lesson_sql = query(self.detail, "lessons")
        self.list_sql = query(self.listing, "courses")

    def tearDown(self):
        self.db.close()

    def test_public_listing_uses_database_and_excludes_draft(self):
        self.assertEqual([row[0] for row in self.db.execute(self.list_sql)], ["published", "empty"])
        self.assertIn("course_cards()", body(ROUTER, "page_courses"))
        self.assertNotIn("COURSES", self.listing)

    def test_course_lookup_rejects_missing_and_draft(self):
        self.assertEqual(self.db.execute(self.course_sql, ("published",)).fetchone()[2], "Published")
        for slug in ("missing", "draft"):
            with self.subTest(slug=slug):
                self.assertIsNone(self.db.execute(self.course_sql, (slug,)).fetchone())
        self.assertIn("if (!$course) { http_response_code(404)", self.detail)
        self.assertNotIn("COURSES", self.detail)
        self.assertNotIn("courses/enroll", self.detail)
        self.assertIn("page_course(trim(substr($path,7)))", body(ROUTER, "route"))

    def test_lessons_belong_to_course_are_published_and_ordered(self):
        course_id = self.db.execute(self.course_sql, ("published",)).fetchone()[0]
        self.assertEqual(
            [(row[0], row[1]) for row in self.db.execute(self.lesson_sql, (course_id,))],
            [("First", 1), ("Second", 2), ("Third", 3)],
        )
        self.assertIn("[$course['id']]", self.detail)
        self.assertIn("foreach ($lessons as $lesson)", self.detail)

    def test_empty_published_lesson_list(self):
        course_id = self.db.execute(self.course_sql, ("empty",)).fetchone()[0]
        self.assertEqual(self.db.execute(self.lesson_sql, (course_id,)).fetchall(), [])
        self.assertIn("if (!$lessons)", self.detail)

    def test_archived_rows_are_excluded_by_public_queries(self):
        db = sqlite3.connect(":memory:")
        try:
            db.executescript("CREATE TABLE courses (id INTEGER,slug TEXT,title TEXT,description TEXT,status TEXT); CREATE TABLE lessons (course_id INTEGER,title TEXT,content TEXT,position INTEGER,status TEXT);")
            db.execute("INSERT INTO courses VALUES (1,'archived','Archived','Hidden','archived')")
            db.execute("INSERT INTO courses VALUES (2,'visible','Visible','Visible','published')")
            db.execute("INSERT INTO lessons VALUES (2,'Archived lesson','Hidden',1,'archived')")
            self.assertEqual(db.execute(self.list_sql).fetchall(), [("visible", "Visible", "Visible")])
            self.assertIsNone(db.execute(self.course_sql, ("archived",)).fetchone())
            self.assertEqual(db.execute(self.lesson_sql, (2,)).fetchall(), [])
        finally:
            db.close()


if __name__ == "__main__":
    unittest.main()
