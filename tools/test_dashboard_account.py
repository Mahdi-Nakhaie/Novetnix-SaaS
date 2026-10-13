import hashlib
import json
import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
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
        self.db.executemany("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?)", [(i, 100, '[{"prompt":"Q","options":["A","B"],"correct":0}]') for i in (1, 2, 4)])
        self.version = hashlib.sha256(json.dumps({"pass_percent": 100, "questions": [{"prompt": "Q", "options": ["A", "B"], "correct": 0}]}, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
        loader = body(APP, "dashboard_course_progress")
        helper = body(APP, "dashboard_course_progress_for_course")
        self.course_sql = re.search(r'"(SELECT c\.id FROM courses .*? LIMIT 1)"', loader).group(1)
        self.count_sql = re.search(r'"(SELECT l\.id,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lessons l LEFT JOIN lesson_progress p .*?l\.status=\'published\')"', helper).group(1)

    def tearDown(self):
        self.db.close()

    def totals(self, user=1, slug="main"):
        course = self.db.execute(self.course_sql, (user,)).fetchone() if slug == "main" else self.db.execute("SELECT id FROM courses WHERE slug=?", (slug,)).fetchone()
        rows = self.db.execute(self.count_sql, (user, course[0])).fetchall()
        total = len(rows)
        completed = 0
        for lesson_id, flag, status, timestamp, version in rows:
            quiz = self.db.execute('SELECT pass_percent,questions_json FROM lesson_quizzes WHERE lesson_id=?', (lesson_id,)).fetchone()
            if quiz and flag == 1 and status == 'completed' and timestamp and version == hashlib.sha256(json.dumps({"pass_percent": quiz[0], "questions": json.loads(quiz[1])}, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest():
                completed += 1
        return total, completed, total - completed, round(completed / total * 100, 1) if total else 0

    def test_completion_requires_current_quiz_evidence_and_recalculates(self):
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (1,1,1,'completed','2026-01-01')")
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("UPDATE lesson_progress SET quiz_version=? WHERE user_id=1 AND lesson_id=1", (self.version,))
        self.assertEqual(self.totals(), (2, 1, 1, 50))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at,quiz_version) VALUES (1,2,1,'completed','2026-01-02',?)", (self.version,))
        self.assertEqual(self.totals(), (2, 2, 0, 100))
        self.assertEqual(self.totals(), (2, 2, 0, 100))
        self.db.execute("UPDATE lesson_quizzes SET pass_percent=90 WHERE lesson_id=2")
        self.assertEqual(self.totals(), (2, 1, 1, 50))
        self.db.execute("DELETE FROM lesson_quizzes WHERE lesson_id=1")
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'New','',4,'published')")
        self.assertEqual(self.totals(), (3, 0, 3, 0))

    def test_completion_card_is_guarded_and_offers_existing_next_steps(self):
        view = body(VIEWS, "panel")
        branch = view.split("if ($courseProgress['status']==='completed') {", 1)[1].split("} elseif ($activeLesson['lesson'])", 1)[0]
        self.assertIn("تبریک! این دوره را با موفقیت به پایان رساندی.", branch)
        self.assertIn("دوره تکمیل شده", branch)
        self.assertIn("مرور پروژه‌های موجود", branch)
        self.assertIn("linkto('projects','مرور پروژه‌های موجود ←','btn btn-primary')", branch)
        self.assertIn("linkto('dashboard/course/'.$courseProgress['slug'],'مرور دوباره درس‌ها','btn btn-outline')", branch)
        self.assertEqual(branch.count("'btn btn-primary'"), 1)
        self.assertNotIn("به‌زودی", branch)
        self.assertIn("' is-completed'", view)
        self.assertIn(".course-active-lesson.is-completed{display:block}", (ROOT / "public/assets/style.css").read_text(encoding="utf-8"))
        self.assertIn("if ($path==='projects')", ROUTER)
        self.assertIn("page_projects();", ROUTER)
        self.assertIn("if (preg_match('~^dashboard/course/", ROUTER)
        self.assertIn("$u=require_user()", ROUTER)
        self.assertIn("function page_projects(): void", ROUTER)
        self.assertIn("const PROJECTS = [", (ROOT / "src/data.php").read_text(encoding="utf-8"))
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,completed,status,completed_at) VALUES (1,1,999,1,'completed','2026-01-01')")
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,completed,status,completed_at) VALUES (1,2,999,1,'completed','2026-01-02')")
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("UPDATE lesson_progress SET quiz_version=? WHERE user_id=1", (self.version,))
        self.assertEqual(self.totals(), (2, 2, 0, 100))
        self.assertEqual(self.totals(), (2, 2, 0, 100))
        self.db.execute("UPDATE lesson_quizzes SET pass_percent=90 WHERE lesson_id=1")
        self.assertEqual(self.totals(), (2, 1, 1, 50))

    def test_zero_partial_and_full_completion(self):
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,percentage,completed,status,completed_at,quiz_version) VALUES (1,1,5,50,1,'completed','2026-01-01',?)", (self.version,))
        self.assertEqual(self.totals(), (2, 1, 1, 50))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at,quiz_version) VALUES (1,2,1,'completed','2026-01-01',?)", (self.version,))
        self.assertEqual(self.totals(), (2, 2, 0, 100))

    def test_course_user_draft_and_partial_watch_are_excluded(self):
        self.db.executemany("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds,completed,status,completed_at) VALUES (?,?,?,?,?,?)", [(2, 1, 0, 1, "completed", "2026-01-01"), (1, 2, 200, 0, "started", None), (1, 3, 0, 1, "completed", "2026-01-01"), (1, 4, 0, 1, "completed", "2026-01-01")])
        self.db.execute("UPDATE lesson_progress SET quiz_version=? WHERE completed=1", (self.version,))
        self.assertEqual(self.totals(), (2, 0, 2, 0))
        self.assertEqual(self.totals(2), (2, 1, 1, 50))
        self.assertEqual(self.totals(1, "other"), (1, 1, 0, 100))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,2)")

    def test_empty_unenrolled_and_invalid_progress(self):
        self.db.execute("INSERT INTO enrollments(user_id,course_slug,created_at) VALUES (1,'empty','2026-01-03')")
        self.assertEqual(self.totals(1, "empty"), (0, 0, 0, 0))
        self.assertEqual(self.db.execute(self.course_sql, (1,)).fetchone()[0], 3)
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
        helper = body(APP, "dashboard_course_progress_for_course")
        self.assertIn("return $course ? dashboard_course_progress_for_course($userId,(int)$course['id']) : null", loader)
        self.assertIn("$total-$completed", helper)
        self.assertIn("$total ? round($completed/$total*100,1) : 0", helper)
        self.assertIn('role="progressbar"', view)
        self.assertIn('aria-valuenow="\'.h($courseProgress[\'percentage\'])', view)
        self.assertIn("verified_lesson_completion($lesson,lesson_quiz_for_lesson((int)$lesson['id']))", helper)
        self.assertIn("$courseProgress['status']", view)
        self.assertIn("$courseProgress['status']==='completed'", body(APP, "dashboard_active_lesson"))
        self.assertNotIn("challenge_progress", self.count_sql)


class LeadLifecycleTest(unittest.TestCase):
    def setUp(self):
        DashboardCourseProgressTest.setUp(self)
        self.db.execute("UPDATE users SET password_hash='hash' WHERE id IN (1,2)")
        rules = re.search(r'const LEAD_STAGES = \[(.*?)\];', APP, re.S).group(1)
        self.stages = re.findall(r"\['stage'=>'([^']+)','label'=>'([^']+)','threshold'=>([0-9.]+)\]", rules)

    def tearDown(self):
        self.db.close()

    def stage(self, user=1):
        row = self.db.execute("SELECT phone_verified_at FROM users WHERE id=?", (user,)).fetchone()
        if not row:
            return None
        if row[0] is None:
            return self.stages[0][0]
        best = None
        courses = self.db.execute("SELECT c.id FROM courses c INNER JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE c.status='published' ORDER BY c.id ASC", (user,)).fetchall()
        for (course_id,) in courses:
            rows = self.db.execute(self.count_sql, (user, course_id)).fetchall()
            total = len(rows)
            completed = 0
            for lesson_id, flag, status, timestamp, version in rows:
                quiz = self.db.execute('SELECT pass_percent,questions_json FROM lesson_quizzes WHERE lesson_id=?', (lesson_id,)).fetchone()
                if quiz and flag == 1 and status == 'completed' and timestamp and version == hashlib.sha256(json.dumps({'pass_percent': quiz[0], 'questions': json.loads(quiz[1])}, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest():
                    completed += 1
            percent = completed / total * 100 if total else 0
            if total and (best is None or percent > best):
                best = percent
        index = 1
        if best is not None:
            for i, (_, _, limit) in enumerate(self.stages[2:], 2):
                if best >= float(limit):
                    index = i
        return self.stages[index][0]

    def complete(self, lesson, user=1):
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at,quiz_version) VALUES (?,?,1,'completed','2026-01-01',?)", (user, lesson, self.version))

    def test_registration_verification_and_login_do_not_replace_evidence(self):
        self.db.execute("UPDATE users SET phone_verified_at=NULL WHERE id=1")
        self.assertEqual(self.stage(), 'registered')
        self.complete(1)
        self.assertEqual(self.stage(), 'registered')
        self.db.execute("UPDATE users SET phone_verified_at=100 WHERE id=1")
        self.assertEqual(self.stage(), 'qualified')
        self.db.execute("INSERT INTO activities(user_id,event_name) VALUES (1,'user_logged_in')")
        self.assertEqual(self.stage(), 'qualified')
        self.assertIsNone(self.stage(999))
        self.db.execute("UPDATE users SET password_hash='' WHERE id=1")
        self.assertEqual(self.stage(), 'qualified')

    def test_less_than_boundary_boundary_and_full_completion(self):
        self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'D','',4,'published')")
        self.assertEqual(self.stage(), 'verified')
        self.complete(1)
        self.assertEqual(self.stage(), 'verified')
        self.db.execute("DELETE FROM lessons WHERE id=5")
        self.assertEqual(self.stage(), 'qualified')
        self.complete(2)
        self.assertEqual(self.stage(), 'high_intent')
        self.assertEqual(self.stages[2][2], '50.0')
        self.assertEqual(self.stages[3][2], '100.0')

    def test_missing_and_stale_evidence_cannot_promote(self):
        self.assertEqual(self.stage(), 'verified')
        self.db.execute("INSERT INTO users(phone,name,phone_verified_at) VALUES ('09120000003','C',100)")
        self.assertEqual(self.stage(3), 'verified')
        self.db.execute("INSERT INTO enrollments(user_id,course_slug) VALUES (1,'empty')")
        self.assertEqual(self.stage(), 'verified')
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,percentage,completed,status,completed_at) VALUES (1,1,100,1,'completed','2026-01-01')")
        self.assertEqual(self.stage(), 'verified')
        self.db.execute("UPDATE lesson_progress SET quiz_version=? WHERE lesson_id=1", (self.version,))
        self.assertEqual(self.stage(), 'qualified')
        self.db.execute("UPDATE lesson_quizzes SET pass_percent=90 WHERE lesson_id=1")
        self.assertEqual(self.stage(), 'verified')
        self.db.execute("UPDATE courses SET status='draft' WHERE id=1")
        self.assertEqual(self.stage(), 'verified')

    def test_threshold_is_only_defined_in_central_rules(self):
        self.complete(1)
        self.assertEqual(self.stage(), 'qualified')
        self.stages[2] = ('qualified', 'Qualified', '51.0')
        self.assertEqual(self.stage(), 'verified')
        self.assertIn("$percentage>=$rule['threshold']", body(APP, 'lead_stage_for_user'))
        self.assertIn("lead_progress_percentage($userId)", body(APP, 'lead_stage_for_user'))
        self.assertIn("dashboard_course_progress_for_course($userId,(int)$course['id'])", body(APP, 'lead_progress_summary'))
        self.assertIn("$progress['completed']/$progress['total']*100", body(APP, 'lead_progress_summary'))
        self.assertIn("$progress['total']===0", body(APP, 'lead_progress_summary'))
        self.assertNotIn("user_logged_in", body(APP, 'lead_stage_for_user'))
        self.assertEqual(APP.count("'threshold'=>50.0"), 1)


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
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,last_position,completed,status,completed_at,updated_at,quiz_version) VALUES (?,?,?,?,?,?,?,?)", (user, lesson, position, completed, "completed" if completed else "started", updated if completed else None, updated, self.version if completed else None))

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
        self.assertIn("$courseProgress['status']==='completed'", view)
        self.assertIn("تبریک! این دوره را با موفقیت به پایان رساندی.", view)
        self.assertIn("if ($courseProgress['status']==='completed')", body(APP, "dashboard_active_lesson"))
        self.assertIn("$lesson['position']>$completedPosition", body(APP, "dashboard_active_lesson"))
        self.assertIn("'lesson_status'=>'not_started'", body(APP, "dashboard_active_lesson"))
        self.assertIn("'lesson_status'=>'in_progress'", body(APP, "dashboard_active_lesson"))
        self.assertIn("$next?'not_started':null", body(APP, "dashboard_active_lesson"))
        self.assertIn("'?lesson='.(int)$activeLesson['lesson']['id']", view)
        self.assertLess(view.index("if ($courseProgress['status']==='completed')"), view.index("linkto('dashboard/course/'.$courseProgress['slug'].'?lesson='"))

    def test_invalid_position_activity_and_unavailable_lessons(self):
        self.record(1, 10)
        self.db.execute("UPDATE lessons SET status='draft' WHERE id=1")
        self.assertEqual([lesson[0] for lesson in self.candidates()[0]], [2])
        self.assertEqual(self.candidates()[1], [])
        self.record(2, 0)
        self.db.execute("PRAGMA ignore_check_constraints=ON")
        self.db.execute("UPDATE lesson_progress SET last_position=-4 WHERE lesson_id=2")
        self.db.execute("PRAGMA ignore_check_constraints=OFF")
        self.db.execute("INSERT INTO activities(user_id,event_name) VALUES (1,'lesson')")
        self.assertEqual(self.candidates()[1][0][2], -4)
        self.assertIn("progress_number($row['last_position'])!==null", body(APP, "dashboard_active_lesson"))
        self.assertNotIn("FROM activities", body(APP, "dashboard_active_lesson"))

    def test_dashboard_cta_targets_only_selected_published_lesson(self):
        view = body(VIEWS, "panel")
        self.assertIn("dashboard_active_lesson((int)$me['id'],$courseProgress)", view)
        self.assertEqual(view.count("dashboard_active_lesson((int)$me['id'],$courseProgress)"), 1)
        self.assertIn("هنوز در دوره‌ای ثبت‌نام نکرده‌اید", view)
        self.assertIn("'?lesson='.(int)$activeLesson['lesson']['id']", view)
        self.assertIn("$activeLesson['lesson_status']==='in_progress'?'ادامهٔ یادگیری ←':'شروع یادگیری ←'", view)
        self.assertIn("'btn btn-primary'", view)
        self.assertIn("h($activeLesson['lesson']['title'])", view)
        self.assertIn("$courseProgress['status']==='completed'", view)
        self.assertIn("elseif ($activeLesson['lesson'])", view)
        self.assertIn("هنوز درسی برای این دوره منتشر نشده است", view)
        self.assertIn("$u=require_user()", ROUTER)
        self.assertIn("course_workspace((int)$u['id'],$match[1],$requestedLesson)", ROUTER)
        self.assertIn("course_workspace_selection($course,$requestedLesson)", VIEWS)
        self.assertIn("l.status='published'", body(APP, "dashboard_active_lesson"))
        self.assertIn("course_id=? AND status='published'", body(APP, "course_workspace"))

    def test_dashboard_data_error_has_no_fabricated_destination(self):
        view = body(VIEWS, "panel")
        self.assertLess(view.index("dashboard_active_lesson((int)$me['id'],$courseProgress)"), view.index("catch (Throwable $e)"))
        self.assertIn("$courseError=true", view)
        self.assertIn("دریافت مسیر یادگیری ممکن نشد", view)
        self.assertIn("if ($courseError)", view)
        self.assertLess(view.index("if ($courseError)"), view.index("linkto('dashboard/course/'.$courseProgress['slug'].'?lesson='"))

    def test_saved_position_is_user_and_lesson_scoped_without_unverified_seek(self):
        self.record(1, 42)
        self.record(1, 90, user=2)
        query = re.search(r"SELECT lesson_id,last_position,watched_seconds,percentage,completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=\? AND lesson_id=\?", body(APP, "lesson_progress_for_user")).group(0)
        self.assertEqual(self.db.execute(query, (1, 1)).fetchone()[1], 42)
        self.assertEqual(self.db.execute(query, (2, 1)).fetchone()[1], 90)
        self.assertIsNone(self.db.execute(query, (1, 2)).fetchone())
        self.assertIn("authorized_progress_lesson((int)$u['id'],$lessonId)", body(APP, "lesson_progress_for_user"))
        self.assertIn("progress_number($progress['last_position'])", body(APP, "lesson_progress_for_user"))
        self.assertNotIn("last_position", body(VIEWS, "panel"))
        self.assertNotIn("currentTime", body(VIEWS, "page_course_workspace"))

    def test_duplicate_progress_is_blocked_by_schema_and_guarded_in_code(self):
        self.record(1, 1)
        with self.assertRaises(sqlite3.IntegrityError):
            self.record(1, 2)
        self.assertIn("if (isset($progress[$id])) continue", body(APP, "dashboard_active_lesson"))


if __name__ == "__main__":
    unittest.main()
