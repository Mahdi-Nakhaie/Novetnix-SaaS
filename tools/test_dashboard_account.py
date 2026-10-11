import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
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


class DashboardCourseProgressTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executemany("INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)", [("09120000001", "A", 1), ("09120000002", "B", 1)])
        self.db.executemany("INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)", [("main", "Main", "", "published"), ("other", "Other", "", "published"), ("empty", "Empty", "", "published")])
        self.db.executemany("INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)", [(1, "A", "", 1, "published"), (1, "B", "", 2, "published"), (1, "C", "", 3, "draft"), (2, "D", "", 1, "published")])
        self.db.executemany("INSERT INTO enrollments(user_id,course_slug,created_at) VALUES (?,?,?)", [(1, "main", "2026-01-02"), (2, "main", "2026-01-02"), (1, "other", "2026-01-01")])
        loader = body(APP, "dashboard_course_progress")
        self.course_sql = re.search(r'"(SELECT c\.id,c\.slug,c\.title .*? LIMIT 1)"', loader).group(1)
        self.count_sql = re.search(r'"(SELECT COUNT\(\*\) AS total,.*?l\.status=\'published\')"', loader).group(1)

    def tearDown(self):
        self.db.close()

    def totals(self, user=1, slug="main"):
        course = self.db.execute(self.course_sql, (user,)).fetchone() if slug == "main" else self.db.execute("SELECT id FROM courses WHERE slug=?", (slug,)).fetchone()
        total, completed = self.db.execute(self.count_sql, (user, course[0])).fetchone()
        return total, completed, total - completed, round(completed / total * 100, 1) if total else 0

    def test_zero_partial_and_full_completion(self):
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,percentage,completed,status,completed_at) VALUES (1,1,5,50,1,'completed','2026-01-01')")
        self.assertEqual(self.totals(), (2, 1, 1, 50))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (1,2,1,'completed','2026-01-01')")
        self.assertEqual(self.totals(), (2, 2, 0, 100))

    def test_course_user_draft_and_partial_watch_are_excluded(self):
        self.db.executemany("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds,completed,status,completed_at) VALUES (?,?,?,?,?,?)", [(2, 1, 0, 1, "completed", "2026-01-01"), (1, 2, 200, 0, "started", None), (1, 3, 0, 1, "completed", "2026-01-01"), (1, 4, 0, 1, "completed", "2026-01-01")])
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.assertEqual(self.totals(2), (2, 1, 1, 50))
        self.assertEqual(self.totals(1, "other"), (1, 1, 0, 100))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,2)")

    def test_empty_unenrolled_and_invalid_progress(self):
        self.db.execute("INSERT INTO enrollments(user_id,course_slug,created_at) VALUES (1,'empty','2026-01-03')")
        self.assertEqual(self.totals(1, "empty"), (0, 0, 0, 0))
        self.assertEqual(self.db.execute(self.course_sql, (1,)).fetchone()[1], "empty")
        self.assertIsNone(self.db.execute(self.course_sql, (999,)).fetchone())
        self.db.execute("UPDATE enrollments SET created_at='2026-01-01' WHERE course_slug='empty'")
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed) VALUES (1,1,0)")
        self.assertEqual(self.totals(), (2, 0, 2, 0))

    def test_dashboard_uses_exact_backend_counts_and_percentage(self):
        loader = body(APP, "dashboard_course_progress")
        view = body(VIEWS, "panel")
        self.assertIn("dashboard_course_progress((int)$me['id'])", view)
        for field in ("title", "total", "completed", "remaining", "percentage"):
            self.assertIn("$courseProgress['" + field + "']", view)
        self.assertIn("$total-$completed", loader)
        self.assertIn("$total ? round($completed/$total*100,1) : 0", loader)
        self.assertIn('role="progressbar"', view)
        self.assertIn('aria-valuenow="\'.h($courseProgress[\'percentage\'])', view)
        self.assertIn("p.completed=1", self.count_sql)
        self.assertNotIn("challenge_progress", self.count_sql)


class DashboardActiveLessonTest(unittest.TestCase):
    def setUp(self):
        DashboardCourseProgressTest.setUp(self)
        loader = body(APP, "dashboard_active_lesson")
        self.lessons_sql = re.search(r'"(SELECT id,title,position FROM lessons .*?id ASC)"', loader).group(1)
        self.progress_sql = re.search(r'"(SELECT p\.id,p\.lesson_id,.*?p\.id DESC)"', loader).group(1)

    def tearDown(self):
        self.db.close()

    def totals(self, user=1, slug="main"):
        return DashboardCourseProgressTest.totals(self, user, slug)

    def record(self, lesson, position, completed=0, user=1, updated="2026-01-03 00:00:00"):
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,completed,status,completed_at,updated_at) VALUES (?,?,?,?,?,?,?)", (user, lesson, position, completed, "completed" if completed else "started", updated if completed else None, updated))

    def candidates(self, user=1, course=1):
        lessons = self.db.execute(self.lessons_sql, (course,)).fetchall()
        rows = self.db.execute(self.progress_sql, (user, course)).fetchall()
        return lessons, rows

    def test_new_user_starts_at_first_published_lesson(self):
        lessons, rows = self.candidates()
        self.assertEqual([lesson[0] for lesson in lessons], [1, 2])
        self.assertEqual(rows, [])
        self.assertIn("if ($next===null) $next=$lesson", body(APP, "dashboard_active_lesson"))

    def test_active_progress_is_user_and_course_scoped(self):
        self.record(2, 45, updated="2026-01-04 00:00:00")
        self.record(1, 50, user=2, updated="2026-01-05 00:00:00")
        self.record(4, 80, updated="2026-01-06 00:00:00")
        lessons, rows = self.candidates()
        self.assertEqual([row[1] for row in rows], [2])
        self.assertEqual(lessons[1][1], "B")
        self.assertIn("progress_number($row['last_position'])!==null", body(APP, "dashboard_active_lesson"))

    def test_multiple_active_ordered_by_update_and_registration_id(self):
        self.record(1, 10, updated="2026-01-03 00:00:00")
        self.record(2, 20, updated="2026-01-04 00:00:00")
        self.assertEqual([row[1] for row in self.candidates()[1]], [2, 1])
        self.db.execute("UPDATE lesson_progress SET updated_at='2026-01-04 00:00:00' WHERE lesson_id=1")
        self.assertEqual([row[1] for row in self.candidates()[1]], [2, 1])

    def test_completed_lesson_suggests_next_and_full_course_has_no_link(self):
        self.record(1, 20, completed=1)
        lessons, rows = self.candidates()
        self.assertEqual([row[1] for row in rows], [1])
        self.assertEqual(lessons[1][0], 2)
        self.assertEqual(self.totals(), (2, 1, 1, 50))
        self.record(2, 30, completed=1)
        self.assertEqual(self.totals(), (2, 2, 0, 100))
        view = body(VIEWS, "panel")
        self.assertIn("$activeLesson['course_status']==='completed'", view)
        self.assertIn("این دوره را تکمیل کرده‌اید", view)
        self.assertIn("if ($courseProgress['completed']===$courseProgress['total'])", body(APP, "dashboard_active_lesson"))
        self.assertIn("$lesson['position']>$completedPosition", body(APP, "dashboard_active_lesson"))
        self.assertIn("'lesson_status'=>'not_started'", body(APP, "dashboard_active_lesson"))
        self.assertIn("'lesson_status'=>'in_progress'", body(APP, "dashboard_active_lesson"))
        self.assertIn("$next?'not_started':null", body(APP, "dashboard_active_lesson"))
        self.assertIn("'?lesson='.(int)$activeLesson['lesson']['id']", view)
        self.assertLess(view.index("if ($activeLesson['course_status']==='completed')"), view.index("linkto('dashboard/course/'.$courseProgress['slug'].'?lesson='"))

    def test_invalid_position_activity_and_unavailable_lessons(self):
        self.record(1, 10)
        self.db.execute("UPDATE lessons SET status='draft' WHERE id=1")
        self.assertEqual([lesson[0] for lesson in self.candidates()[0]], [2])
        self.assertEqual(self.candidates()[1], [])
        self.record(2, 0)
        self.db.execute("PRAGMA ignore_check_constraints=ON")
        self.db.execute("UPDATE lesson_progress SET last_position=-4 WHERE lesson_id=2")
        self.db.execute("PRAGMA ignore_check_constraints=OFF")
        self.db.execute("INSERT INTO activities(user_id,kind) VALUES (1,'lesson')")
        self.assertEqual(self.candidates()[1][0][2], -4)
        self.assertIn("progress_number($row['last_position'])!==null", body(APP, "dashboard_active_lesson"))
        self.assertNotIn("FROM activities", body(APP, "dashboard_active_lesson"))

    def test_duplicate_progress_is_blocked_by_schema_and_guarded_in_code(self):
        self.record(1, 1)
        with self.assertRaises(sqlite3.IntegrityError):
            self.record(1, 2)
        self.assertIn("if (isset($progress[$id])) continue", body(APP, "dashboard_active_lesson"))


if __name__ == "__main__":
    unittest.main()
