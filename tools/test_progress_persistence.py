import hashlib
import json
import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")


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

    def save_sql(self):
        return re.search(r'"(INSERT INTO lesson_progress[^"\n]+ON CONFLICT\(user_id,lesson_id\) DO UPDATE[^"\n]+)"', self.progress).group(1)

    def test_progress_upsert_updates_existing_user_lesson(self):
        sql = self.save_sql()
        self.db.execute(sql, (1, 1, 10, 10, 20))
        self.db.execute(sql, (1, 1, 25, 25, 50))
        self.assertEqual(self.db.execute("SELECT watched_seconds,last_position,percentage FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), (25.0, 25.0, 50.0))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress").fetchone()[0], 1)

    def test_repeated_saves_preserve_existing_completion_and_first_timestamp(self):
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (1,1,1,'completed','2026-01-01 10:00:00')")
        sql = self.save_sql()
        for position in (30, 60, 60):
            self.db.execute(sql, (1, 1, position, position, 50))
        row = self.db.execute("SELECT completed,status,completed_at,last_position FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone()
        self.assertEqual(row, (1, "completed", "2026-01-01 10:00:00", 60.0))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress").fetchone()[0], 1)
        self.db.execute(sql, (2, 1, 20, 20, 20))
        self.assertEqual(self.db.execute("SELECT completed,completed_at FROM lesson_progress WHERE user_id=2 AND lesson_id=1").fetchone(), (0, None))
        self.assertEqual(self.db.execute("SELECT completed,completed_at FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), (1, "2026-01-01 10:00:00"))

    def test_frontend_completion_cannot_create_or_replace_completed_row(self):
        self.assertIn("if ($completed) {", self.progress)
        self.assertLess(self.progress.index("authorized_progress_lesson((int)$u['id'],$lessonId)"), self.progress.index("if ($completed) {"))
        self.assertLess(self.progress.index("progress_response(422,['error'=>'تکمیل درس"), self.progress.index("INSERT INTO lesson_progress"))
        lookup = re.search(r"SELECT completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=\? AND lesson_id=\?", self.progress).group(0)
        self.assertIsNone(self.db.execute(lookup, (1, 1)).fetchone())
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (1,1,1,'completed','2026-01-01 10:00:00')")
        self.assertEqual(self.db.execute(lookup, (1, 1)).fetchone(), (1, "completed", "2026-01-01 10:00:00", None))
        self.assertIsNone(self.db.execute(lookup, (2, 1)).fetchone())
        self.assertIn("verified_lesson_completion($existing ?: null,lesson_quiz_for_lesson($lessonId))", self.progress)
        self.assertIn("progress_response(200,['saved'=>true])", self.progress)
        self.assertLess(self.progress.index("progress_response(200,['saved'=>true])"), self.progress.index("progress_response(422,['error'=>'تکمیل درس"))
        self.assertNotIn("completed=excluded.completed", self.progress)
        self.assertNotIn("completed=VALUES(completed)", self.progress)
        self.assertNotIn("completed_at=excluded.completed_at", self.progress)
        self.assertNotIn("completed_at=VALUES(completed_at)", self.progress)
        self.assertIn('percentage=MAX(lesson_progress.percentage,excluded.percentage)', self.progress)
        self.assertIn('percentage=GREATEST(percentage,VALUES(percentage))', self.progress)
        self.assertIn("validate_csrf()", body(APP, "handle_post"))
        self.assertIn("$u=require_user()", body(APP, "handle_post"))

    def test_dashboard_counts_and_lesson_rows_are_stable_after_repeated_saves(self):
        sql = self.save_sql()
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (1,1,1,'completed','2026-01-01 10:00:00')")
        for _ in range(3):
            self.db.execute(sql, (1, 1, 100, 100, 80))
        rows = re.search(r'"(SELECT l\.id,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lessons l LEFT JOIN lesson_progress p .*?l\.status=\'published\')"', body(APP, "dashboard_course_progress_for_course")).group(1)
        self.assertEqual(self.db.execute(rows, (1, 1)).fetchall(), [(1, 1, 'completed', '2026-01-01 10:00:00', None)])
        self.assertEqual(self.db.execute(rows, (2, 1)).fetchone()[1], None)
        self.assertEqual(self.db.execute(rows, (1, 2)).fetchone()[1], None)
        lessons = re.search(r'"(SELECT p\.lesson_id,p\.watched_seconds,.*?l\.status=\'published\')"', body(APP, "course_workspace")).group(1)
        self.assertEqual(self.db.execute(lessons, (1, 1)).fetchone()[4:7], (1, "completed", "2026-01-01 10:00:00"))
        self.assertEqual(self.db.execute(lessons, (2, 1)).fetchall(), [])
        self.assertEqual(self.db.execute(lessons, (1, 2)).fetchall(), [])

    def test_quiz_completion_is_atomic_scoped_and_preserves_first_timestamp(self):
        sql = re.search(r'"(INSERT INTO lesson_progress[^"\n]+ON CONFLICT\(user_id,lesson_id\) DO UPDATE[^"\n]+)"', body(APP, "submit_lesson_quiz")).group(1)
        self.db.execute(sql, (1, 1, "2026-01-01 10:00:00", "v1"))
        self.db.execute(sql, (1, 1, "2026-02-02 11:00:00", "v1"))
        self.assertEqual(self.db.execute("SELECT completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), (1, "completed", "2026-01-01 10:00:00", "v1"))
        self.db.execute(sql, (1, 1, "2026-04-01 00:00:00", "v2"))
        self.assertEqual(self.db.execute("SELECT completed_at,quiz_version FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), ("2026-01-01 10:00:00", "v2"))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone()[0], 1)
        self.db.execute(self.save_sql(), (1, 2, 30, 30, 40))
        self.db.execute(sql, (2, 2, "2026-03-03 12:00:00", "v1"))
        self.assertEqual(self.db.execute("SELECT completed,completed_at FROM lesson_progress WHERE user_id=1 AND lesson_id=2").fetchone(), (0, None))
        self.assertEqual(self.db.execute("SELECT completed,completed_at FROM lesson_progress WHERE user_id=2 AND lesson_id=2").fetchone(), (1, "2026-03-03 12:00:00"))
        rows = re.search(r'"(SELECT l\.id,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lessons l LEFT JOIN lesson_progress p .*?l\.status=\'published\')"', body(APP, "dashboard_course_progress_for_course")).group(1)
        self.assertEqual(self.db.execute(rows, (1, 1)).fetchone()[1], 1)
        self.assertIsNone(self.db.execute(rows, (2, 1)).fetchone()[1])
        self.assertEqual(self.db.execute(rows, (2, 2)).fetchone()[1], 1)
        self.db.execute(self.save_sql(), (1, 1, 25, 25, 25))
        self.assertEqual(self.db.execute("SELECT completed,completed_at FROM lesson_progress WHERE user_id=1 AND lesson_id=1").fetchone(), (1, "2026-01-01 10:00:00"))

    def test_learning_events_follow_verified_quiz_and_are_idempotent(self):
        submit = body(APP, "submit_lesson_quiz")
        once = body(APP, "record_learning_event_once")
        complete = body(APP, "course_lessons_complete")
        recorder = (ROOT / "database/connection.php").read_text(encoding="utf-8")
        self.assertIn("record_activity_event($userId,$eventName,$metadata)", once)
        self.assertIn("SELECT metadata FROM activities WHERE user_id=? AND event_name=?", once)
        self.assertIn("($stored[$key] ?? null)===$id", once)
        self.assertIn("record_learning_event_once($userId,'course_started'", submit)
        self.assertIn("record_learning_event_once($userId,'lesson_started'", submit)
        self.assertIn("record_learning_event_once($userId,'lesson_completed'", submit)
        self.assertIn("record_learning_event_once($userId,'course_completed'", submit)
        self.assertLess(submit.index('authorized_progress_lesson('), submit.index('record_learning_event_once('))
        self.assertLess(submit.index('if ($passed===null)'), submit.index('record_learning_event_once('))
        self.assertLess(submit.index('record_learning_event_once($userId,\'course_started\''), submit.index('if ($passed) {'))
        self.assertLess(submit.index('verified_lesson_completion($progress'), submit.index('record_learning_event_once($userId,\'lesson_completed\''))
        self.assertLess(submit.index('course_lessons_complete($userId,$courseId)'), submit.index('record_learning_event_once($userId,\'course_completed\''))
        self.assertLess(submit.index('beginTransaction()'), submit.index('record_learning_event_once('))
        self.assertLess(submit.index('record_learning_event_once($userId,\'course_completed\''), submit.index('$pdo->commit()'))
        self.assertIn('SELECT user_id FROM enrollments WHERE user_id=? AND course_slug=? FOR UPDATE', submit)
        self.assertIn('UPDATE enrollments SET created_at=created_at WHERE user_id=? AND course_slug=?', submit)
        self.assertIn('if (!$lessons) return false', complete)
        self.assertIn('verified_lesson_completion($lesson,lesson_quiz_for_lesson((int)$lesson[\'id\']))', complete)
        self.assertNotIn('record_activity_event(', self.progress)
        self.assertIn('INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)', recorder)

        quiz = {'pass_percent': 100, 'questions': [{'prompt': 'Q', 'options': ['A', 'B'], 'correct': 0}]}
        version = hashlib.sha256(json.dumps(quiz, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
        self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'Second','',2,'published')")
        self.db.executemany("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?)", [(lesson, 100, json.dumps(quiz['questions'])) for lesson in (1, 3)])
        lookup = re.search(r"'(SELECT metadata FROM activities WHERE user_id=\? AND event_name=\?)'", once).group(1)
        insert = re.search(r"'(INSERT INTO activities\(user_id,event_name,metadata\) VALUES \(\?,\?,\?\))'", recorder).group(1)
        upsert = re.search(r'"(INSERT INTO lesson_progress[^"\n]+ON CONFLICT\(user_id,lesson_id\) DO UPDATE[^"\n]+)"', submit).group(1)
        counts = re.search(r'"(SELECT l\.id,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lessons l LEFT JOIN lesson_progress p .*?l\.status=\'published\')"', complete).group(1)

        def event(user, name, key, value, metadata):
            for (raw,) in self.db.execute(lookup, (user, name)):
                if json.loads(raw)[key] == value:
                    return
            self.db.execute(insert, (user, name, json.dumps(metadata)))

        def full(user, course):
            rows = self.db.execute(counts, (user, course)).fetchall()
            if not rows:
                return False
            for lesson, completed, status, timestamp, saved_version in rows:
                quiz_row = self.db.execute('SELECT pass_percent,questions_json FROM lesson_quizzes WHERE lesson_id=?', (lesson,)).fetchone()
                if not quiz_row or completed != 1 or status != 'completed' or not timestamp:
                    return False
                current = {'pass_percent': quiz_row[0], 'questions': json.loads(quiz_row[1])}
                if saved_version != hashlib.sha256(json.dumps(current, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest():
                    return False
            return True

        def attempt(user, lesson, passed):
            access = re.search(r'"(SELECT l\.id FROM lessons l INNER JOIN courses c .*?c\.status=\'published\')"', body(APP, 'authorized_progress_lesson')).group(1)
            if not self.db.execute(access, (user, lesson)).fetchone():
                return False
            course = self.db.execute('SELECT course_id FROM lessons WHERE id=?', (lesson,)).fetchone()[0]
            self.db.execute('SAVEPOINT attempt')
            event(user, 'course_started', 'course_id', course, {'course_id': course})
            event(user, 'lesson_started', 'lesson_id', lesson, {'course_id': course, 'lesson_id': lesson})
            if passed:
                self.db.execute(upsert, (user, lesson, '2026-01-01 12:00:00', version))
                event(user, 'lesson_completed', 'lesson_id', lesson, {'course_id': course, 'lesson_id': lesson})
                if full(user, course):
                    event(user, 'course_completed', 'course_id', course, {'course_id': course})
            self.db.execute('RELEASE SAVEPOINT attempt')
            return True

        self.assertFalse(attempt(2, 1, True))
        self.assertFalse(attempt(1, 2, True))
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM activities').fetchone()[0], 0)
        self.assertTrue(attempt(1, 1, False))
        self.assertTrue(attempt(1, 1, False))
        self.assertEqual(self.db.execute('SELECT event_name FROM activities ORDER BY id').fetchall(), [('course_started',), ('lesson_started',)])
        self.assertTrue(attempt(1, 1, True))
        self.assertTrue(attempt(1, 1, True))
        self.assertEqual(self.db.execute('SELECT event_name FROM activities ORDER BY id').fetchall(), [('course_started',), ('lesson_started',), ('lesson_completed',)])
        self.assertTrue(attempt(1, 3, True))
        self.assertTrue(attempt(1, 3, True))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM activities WHERE event_name='course_completed'").fetchone()[0], 1)
        self.assertTrue(full(1, 1))
        self.db.execute('UPDATE lesson_quizzes SET pass_percent=80 WHERE lesson_id=3')
        self.assertFalse(full(1, 1))
        rows = self.db.execute('SELECT user_id,event_name,metadata,created_at FROM activities ORDER BY id').fetchall()
        self.assertEqual(len(rows), 6)
        self.assertTrue(all(row[0] == 1 and row[3] and set(json.loads(row[2])) <= {'course_id', 'lesson_id'} for row in rows))

    def test_quiz_submission_reuses_authenticated_csrf_protected_progress_route(self):
        save = body(APP, "save_lesson_progress")
        submit = body(APP, "submit_lesson_quiz")
        grade = body(APP, "lesson_quiz_passed")
        self.assertIn("submit_lesson_quiz($u)", save)
        self.assertIn("if ($path==='progress/save') { save_lesson_progress($u); }", body(APP, "handle_post"))
        self.assertIn("validate_csrf()", body(APP, "handle_post"))
        self.assertIn("$u=require_user()", body(APP, "handle_post"))
        self.assertIn("authorized_progress_lesson((int)$u['id'],$lessonId)", submit)
        self.assertIn("lesson_quiz_for_lesson($lessonId)", submit)
        self.assertLess(submit.index("if ($passed===null)"), submit.index("INSERT INTO lesson_progress"))
        self.assertIn("if ($passed) {", submit)
        self.assertIn("$correct*100 >= $quiz['pass_percent']*count($quiz['questions'])", grade)
        self.assertIn("count($answers)!==count($quiz['questions'])", grade)
        self.assertIn("(int)$answer===$question['correct']", grade)
        self.assertIn("if ($passed===null)", submit)
        self.assertNotIn("$_POST['passed']", submit)
        self.assertNotIn("$_POST['user_id']", submit)
        self.assertNotIn("$_POST['pass_percent']", submit)
        mysql = re.search(r'"(INSERT INTO lesson_progress[^"\n]+ON DUPLICATE KEY UPDATE[^"\n]+)"', submit).group(1)
        self.assertLess(mysql.index("completed_at=IF("), mysql.index("completed=1,status='completed'"))

    def test_quiz_threshold_boundary_for_variable_question_counts(self):
        grade = body(APP, "lesson_quiz_passed")
        self.assertIn("$correct*100 >= $quiz['pass_percent']*count($quiz['questions'])", grade)
        for total, threshold, correct, expected in [(1, 100, 0, False), (1, 100, 1, True), (2, 50, 1, True), (2, 75, 1, False), (2, 75, 2, True), (5, 80, 3, False), (5, 80, 4, True), (5, 100, 5, True)]:
            with self.subTest(total=total, threshold=threshold, correct=correct):
                self.assertEqual(correct * 100 >= threshold * total, expected)

    def test_endpoint_uses_auth_user_and_enrollment_lesson_authorization(self):
        self.assertIn("$u['id']", self.progress)
        self.assertIn("authorized_progress_lesson((int)$u['id'],$lessonId)", self.progress)
        authorization = body(APP, "authorized_progress_lesson")
        self.assertIn("INNER JOIN enrollments e", authorization)
        self.assertIn("e.user_id=?", authorization)
        self.assertIn("l.id=?", authorization)
        self.assertIn("ON CONFLICT(user_id,lesson_id)", self.progress)
        self.assertIn("ON DUPLICATE KEY UPDATE", self.progress)
        self.assertIn("updated_at=CURRENT_TIMESTAMP", self.progress)
        self.assertIn("progress_response(500", self.progress)
        self.assertIn("ذخیره پیشرفت انجام نشد", self.progress)
        self.assertNotIn("$_POST['updated_at']", self.progress)
        self.assertNotIn("$_POST['created_at']", self.progress)
        self.assertIn("progress_response(404", self.progress)
        self.assertIn("function progress_number(mixed $value): ?float", APP)
        self.assertIn("is_finite($number) && $number>=0", APP)
        self.assertIn("$watched===null || $position===null || $percentage===null", self.progress)
        self.assertIn("'position_valid'=>$position!==null", body(APP, "lesson_progress_for_user"))
        self.assertIn("'progress_status'=>$position===null?'invalid':'valid'", body(APP, "lesson_progress_for_user"))
        self.assertIn("'progress_status'=>'missing'", body(APP, "lesson_progress_for_user"))

    def test_authorization_query_rejects_other_users_and_courses(self):
        authorization_sql = re.search(r'SELECT l\.id FROM lessons l INNER JOIN courses c .*?\",\[\$userId,\$lessonId\]\)', body(APP, "authorized_progress_lesson")).group(0)
        sql = authorization_sql.split('\",', 1)[0].strip('"')
        self.assertEqual(self.db.execute(sql, (1, 1)).fetchone(), (1,))
        self.assertIsNone(self.db.execute(sql, (2, 1)).fetchone())
        self.assertIsNone(self.db.execute(sql, (1, 2)).fetchone())

    def test_retrieval_returns_only_own_lesson_without_writing(self):
        loader = body(APP, "lesson_progress_for_user")
        query = re.search(r"SELECT lesson_id,last_position,watched_seconds,percentage,completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=\? AND lesson_id=\?", loader).group(0)
        self.db.execute("INSERT INTO enrollments(user_id,course_slug) VALUES (?,?)", (2, "course-a"))
        self.db.executemany(
            "INSERT INTO lesson_progress(user_id,lesson_id,last_position,watched_seconds,percentage,completed) VALUES (?,?,?,?,?,?)",
            [(1, 1, 120.375, 130.5, 16.67, 0), (2, 1, 540.125, 550, 75, 0), (1, 2, 33, 40, 10, 0)],
        )
        before = self.db.total_changes
        self.assertEqual(self.db.execute(query, (1, 1)).fetchone()[:5], (1, 120.375, 130.5, 16.67, 0))
        self.assertEqual(self.db.execute(query, (2, 1)).fetchone()[1], 540.125)
        self.assertIsNone(self.db.execute(query, (1, 999)).fetchone())
        self.assertEqual(self.db.total_changes, before)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress").fetchone()[0], 3)
        self.assertIn("'last_position'=>0", loader)
        self.assertIn("'watched_seconds'=>0", loader)
        self.assertIn("'percentage'=>0", loader)
        self.assertIn("'completed'=>false", loader)
        self.assertIn("authorized_progress_lesson((int)$u['id'],$lessonId)", loader)
        self.assertNotIn("INSERT INTO lesson_progress", loader)

    def test_retrieval_route_checks_session_before_fetch(self):
        route = body(ROUTER, "route")
        self.assertIn("^progress/lesson/([1-9][0-9]*)$", route)
        self.assertIn("$u=user()", route)
        self.assertIn("progress_response(401", route)
        self.assertLess(route.index("progress_response(401"), route.index("lesson_progress_for_user($u"))
        self.assertNotIn("$_GET['user_id']", body(APP, "lesson_progress_for_user"))

    def test_schema_rejects_invalid_ranges(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,percentage) VALUES (?,?,?)", (1, 1, 101))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds) VALUES (?,?,?)", (1, 1, -1))


if __name__ == "__main__":
    unittest.main()
