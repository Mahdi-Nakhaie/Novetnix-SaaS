import pathlib
import re
import sqlite3
import unittest

from tools import gen


ROOT = pathlib.Path(__file__).resolve().parent.parent
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
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
            "INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)",
            [("09120000001", "Enrolled", 1), ("09120000002", "Other", 1)],
        )
        self.db.execute("INSERT INTO enrollments(user_id,course_slug) VALUES (?,?)", (1, "published"))
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
        self.workspace = body(APP, "course_workspace")
        self.selection = body(APP, "course_workspace_selection")
        self.workspace_view = body(VIEWS, "page_course_workspace")
        self.course_sql = query(self.detail, "courses")
        self.lesson_sql = query(self.detail, "lessons")
        self.list_sql = query(APP, "courses")

    def tearDown(self):
        self.db.close()

    def test_public_listing_uses_database_and_excludes_draft(self):
        self.assertEqual([row[0] for row in self.db.execute(self.list_sql)], ["published", "empty"])
        self.assertIn("course_cards($courses)", body(ROUTER, "page_courses"))
        self.assertNotIn("COURSES", self.listing)
        self.assertIn("هنوز دوره‌ای منتشر نشده است", self.listing)
        self.assertNotIn("q(", self.listing)

    def test_course_lookup_rejects_missing_and_draft(self):
        self.assertEqual(self.db.execute(self.course_sql, ("published",)).fetchone()[2], "Published")
        for slug in ("missing", "draft"):
            with self.subTest(slug=slug):
                self.assertIsNone(self.db.execute(self.course_sql, (slug,)).fetchone())
        self.assertIn("if (!$course) { page_not_found(); return; }", self.detail)
        self.assertNotIn("COURSES", self.detail)
        self.assertIn("course_start_action($course,$me,$enrolled", self.detail)
        self.assertIn("courses/enroll", body(ROUTER, "course_start_action"))
        self.assertIn("dashboard/course/", body(ROUTER, "course_start_action"))
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
        self.assertIn("estimated_duration_minutes", self.detail)
        self.assertIn("duration_minutes", self.detail)
        self.assertIn("مدت ثبت نشده", self.detail)
        self.assertIn("count($lessons)", self.detail)
        self.assertIn("if (!$lessons)", self.detail)

    def test_embed_is_scoped_to_first_demo_lesson_and_csp_to_workspace(self):
        self.assertIn("$course['slug']==='ai-foundations' && (int)$current['position']===1", self.workspace_view)
        self.assertIn('id="31969839874"', self.workspace_view)
        self.assertIn('https://www.aparat.com/embed/lhu79lu?data[rnddiv]=31969839874&amp;data[responsive]=yes&amp;titleShow=true', self.workspace_view)
        self.assertIn('ویدیوی این جلسه هنوز اضافه نشده است', self.workspace_view)
        self.assertIn('جلسهٔ بعدی', self.workspace_view)
        self.assertIn('aria-current="true"', self.workspace_view)
        entry = (ROOT / "public/index.php").read_text(encoding="utf-8")
        self.assertIn("preg_match('~^dashboard/course/[a-z0-9-]+$~D',$path)", entry)
        self.assertIn("script-src 'self'\".$aparatPolicy", entry)
        self.assertIn("frame-src 'self'\".$aparatPolicy", entry)
        self.assertIn("https://www.aparat.com", entry)

    def test_course_actions_follow_enrollment_and_learning_links_to_workspace(self):
        action = body(ROUTER, "course_start_action")
        self.assertIn("linkto('login'", action)
        self.assertIn("if ($enrolled)", action)
        self.assertIn("form_start('courses/enroll')", action)
        self.assertIn("$course['slug']", action)
        self.assertIn("$me['id'],$course['slug']", self.detail)
        self.assertIn("dashboard/course/", body(VIEWS, "panel"))

    def test_static_demo_exposes_lesson_ui_without_claiming_progress(self):
        paths = {route["path"]: route for route in gen.build_routes()}
        first = gen.render_page(paths["course/ai-foundations"])
        second = gen.render_page(paths["course/ai-foundations/lesson-2"])
        self.assertIn(gen.href("course/ai-foundations"), gen.render_page(paths[""]))
        self.assertIn(gen.href("course/ai-foundations"), gen.render_page(paths["courses"]))
        self.assertNotIn("data-protected=", first)
        self.assertIn("www.aparat.com/embed/lhu79lu", first)
        self.assertNotIn("www.aparat.com/embed/lhu79lu", second)
        self.assertIn("ویدیوی این جلسه هنوز اضافه نشده است", second)
        self.assertIn(gen.href("course/ai-foundations/lesson-2"), first)
        self.assertIn(gen.href("course/ai-foundations"), second)
        for page in (first, second):
            self.assertIn("ذخیرهٔ پیشرفت و ادامهٔ پخش در سایت نمایشی فعال نیست", page)
            self.assertNotIn("/progress/save", page)
        for path in ("", "courses", "course/ai-foundations", "course/ai-foundations/lesson-2"):
            target = ROOT / "site" / path / "index.html"
            self.assertEqual(target.read_text(encoding="utf-8"), gen.render_page(paths[path]))

    def test_workspace_route_uses_auth_and_panel_layout(self):
        route = body(ROUTER, "route")
        self.assertIn("^dashboard/course/([a-z0-9-]+)$", route)
        self.assertIn("$u=require_user()", route)
        self.assertIn("head_page('فضای یادگیری','',true)", route)
        self.assertIn("foot_page(true)", route)
        self.assertNotIn("ob_start", route)
        self.assertNotIn("ob_end_clean", route)

    def test_workspace_loader_and_selection_are_dynamic(self):
        self.assertIn("status='published'", self.workspace)
        self.assertIn("ORDER BY position ASC", self.workspace)
        self.assertIn("[$course['id']]", self.workspace)
        self.assertIn("course_workspace_selection($course,$requestedLesson)", self.workspace_view)
        self.assertIn("$course['lessons']", self.workspace_view)
        self.assertIn("$active", self.workspace_view)
        self.assertIn("is-active", self.workspace_view)
        self.assertIn("previous", self.selection)
        self.assertIn("next", self.selection)
        self.assertIn("video-placeholder", self.workspace_view)
        self.assertIn("aspect-ratio:16/9", (ROOT / "site/assets/style.css").read_text(encoding="utf-8"))

    def test_workspace_authorization_is_backend_and_course_scoped(self):
        route = body(ROUTER, "route")
        self.assertIn("course_workspace((int)$u['id'],$match[1],$requestedLesson)", route)
        self.assertIn("INNER JOIN enrollments e", self.workspace)
        self.assertIn("e.user_id=?", self.workspace)
        self.assertIn("status='published'", self.workspace)
        workspace_course_sql = query(self.workspace, "courses")
        self.assertEqual(self.db.execute(workspace_course_sql, (1, "published")).fetchone()[1], "published")
        self.assertIsNone(self.db.execute(workspace_course_sql, (2, "published")).fetchone())
        self.assertNotIn("content", workspace_course_sql)

    def test_workspace_rejects_missing_or_cross_course_lesson(self):
        lesson_guard = query(self.workspace, "lessons")
        self.assertEqual(self.db.execute(lesson_guard, (1, 1)).fetchone()[0], 1)
        self.assertIsNone(self.db.execute(lesson_guard, (5, 1)).fetchone())
        self.assertIsNone(self.db.execute(lesson_guard, (1, 2)).fetchone())
        self.assertIn("if ($requestedLesson!==null", self.workspace)
        self.assertIn("return null", self.workspace)
        self.assertIn("if ($index<0)", self.selection)

    def test_direct_lesson_parameter_is_validated_before_rendering(self):
        route = body(ROUTER, "route")
        self.assertIn("FILTER_VALIDATE_INT", route)
        self.assertIn("http_response_code(404); exit", route)
        self.assertIn("page_course_workspace($course", route)
        self.assertIn("$requestedLesson=filter_var($requestedLesson", route)
        self.assertIn("course_workspace((int)$u['id'],$match[1],$requestedLesson)", route)

    def test_workspace_lesson_progress_is_scoped_to_user_course_and_published_lessons(self):
        sql = re.search(r'"(SELECT p\.lesson_id,p\.watched_seconds,p\.last_position,p\.percentage,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lesson_progress .*?l\.status=\'published\')"', self.workspace).group(1)
        self.db.executemany(
            "INSERT INTO lesson_progress(user_id,lesson_id,last_position,percentage,completed,status,completed_at) VALUES (?,?,?,?,?,?,?)",
            [(1, 2, 25, 30, 0, "started", None), (1, 1, 0, 100, 1, "completed", "2026-01-01"),
             (2, 3, 30, 40, 0, "started", None), (1, 4, 10, 20, 0, "started", None),
             (1, 5, 10, 20, 0, "started", None)],
        )
        self.assertEqual([row[0] for row in self.db.execute(sql, (1, 1))], [1, 2])
        self.assertEqual([row[0] for row in self.db.execute(sql, (2, 1))], [3])
        self.assertEqual([row[0] for row in self.db.execute(sql, (1, 2))], [5])
        self.assertEqual(self.db.execute(sql, (1, 3)).fetchall(), [])
        self.assertIn("e.user_id=?", self.workspace)
        self.assertIn("lesson_learning_status($progress[(int)$lesson['id']] ?? null,lesson_quiz_for_lesson((int)$lesson['id']))", self.workspace)

    def test_lesson_status_uses_valid_activity_and_existing_completion_flag(self):
        status = body(APP, "lesson_learning_status")
        self.assertIn("if ($progress===null) return 'not_started'", status)
        self.assertIn("progress_number($progress['last_position'])", status)
        self.assertIn("progress_number($progress['watched_seconds'])", status)
        self.assertIn("progress_number($progress['percentage'])", status)
        self.assertIn("$percentage>100) return 'not_started'", status)
        self.assertIn("(int)$progress['completed']===1", status)
        self.assertIn("verified_lesson_completion($progress,$quiz)", status)
        verified = body(APP, "verified_lesson_completion")
        self.assertIn("$progress['status']==='completed'", verified)
        self.assertIn("!empty($progress['completed_at'])", verified)
        self.assertIn("($position>0 || $watched>0 || $percentage>0) ? 'in_progress' : 'not_started'", status)
        self.assertNotIn("$_GET", status)
        self.assertNotIn("INSERT", self.workspace)

    def test_all_three_persian_statuses_appear_in_each_lesson_link(self):
        for label in ("شروع‌نشده", "در حال یادگیری", "تکمیل‌شده"):
            self.assertIn(label, self.workspace_view)
        self.assertIn("$lesson['learning_status']", self.workspace_view)
        self.assertIn("workspace-lesson-status", self.workspace_view)
        self.assertIn("h($lesson['learning_status'])", self.workspace_view)
        self.assertIn("h($lesson['title'])", self.workspace_view)
        css = (ROOT / "public/assets/style.css").read_text(encoding="utf-8")
        self.assertIn(".workspace-lesson-status", css)
        self.assertIn("overflow-wrap:anywhere", css)
        self.assertIn("grid-template-columns:1fr", css)

    def test_empty_and_malformed_progress_remain_distinguishable(self):
        sql = re.search(r'"(SELECT p\.lesson_id,p\.watched_seconds,p\.last_position,p\.percentage,p\.completed,p\.status,p\.completed_at,p\.quiz_version FROM lesson_progress .*?l\.status=\'published\')"', self.workspace).group(1)
        self.assertEqual(self.db.execute(sql, (1, 1)).fetchall(), [])
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (?,?)", (1, 2))
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at) VALUES (?,?,?,?,?)", (1, 1, 1, "completed", "2026-01-01"))
        self.db.execute("PRAGMA ignore_check_constraints=ON")
        self.db.execute("UPDATE lesson_progress SET last_position=-5 WHERE user_id=1 AND lesson_id=1")
        self.db.execute("PRAGMA ignore_check_constraints=OFF")
        rows = {row[0]: row for row in self.db.execute(sql, (1, 1))}
        self.assertEqual(rows[2][1:6], (0, 0, 0, 0, "started"))
        self.assertEqual(rows[1][3:6], (0, 1, "completed"))
        self.assertEqual(rows[1][2], -5)
        status = body(APP, "lesson_learning_status")
        self.assertLess(status.index("$position===null || $watched===null"), status.index("(int)$progress['completed']===1"))
        self.assertIn("verified_lesson_completion($progress,$quiz)", status)

    def test_lesson_quiz_is_only_loaded_for_selected_authorized_lesson_and_has_no_answer_in_html(self):
        self.assertIn("$course['quiz']=$selectedId ? lesson_quiz_for_lesson($selectedId) : null", self.workspace)
        self.assertIn("if ($requestedLesson!==null", self.workspace)
        self.assertLess(self.workspace.index("INNER JOIN enrollments e"), self.workspace.index("lesson_quiz_for_lesson($selectedId)"))
        loader = body(APP, "lesson_quiz_for_lesson")
        self.assertIn("SELECT pass_percent,questions_json FROM lesson_quizzes WHERE lesson_id=?", loader)
        self.assertIn("!is_int($question['correct'] ?? null)", loader)
        self.assertIn("!is_array($questions) || !$questions", loader)
        self.assertNotIn("['correct']", self.workspace_view)
        self.assertIn("form_start('progress/save')", self.workspace_view)
        self.assertIn("name=\"quiz_submit\"", self.workspace_view)
        self.assertIn("name=\"answers[", self.workspace_view)
        self.assertIn("h($question['prompt'])", self.workspace_view)
        self.assertIn("h($option)", self.workspace_view)
        self.assertIn("آزمون معتبری برای این درس منتشر نشده است", self.workspace_view)
        for label in ("آزمون پایان درس", "ثبت پاسخ‌ها", "حداقل"):
            self.assertIn(label, self.workspace_view)
        css = (ROOT / "public/assets/style.css").read_text(encoding="utf-8")
        self.assertIn(".lesson-quiz-question", css)
        self.assertIn("overflow-wrap:anywhere", css)

    def test_archived_rows_are_excluded_by_public_queries(self):
        db = sqlite3.connect(":memory:")
        try:
            db.executescript("CREATE TABLE courses (id INTEGER,slug TEXT,title TEXT,description TEXT,estimated_duration_minutes INTEGER,status TEXT); CREATE TABLE lessons (course_id INTEGER,title TEXT,content TEXT,position INTEGER,duration_minutes INTEGER,status TEXT);")
            db.execute("INSERT INTO courses VALUES (1,'archived','Archived','Hidden',NULL,'archived')")
            db.execute("INSERT INTO courses VALUES (2,'visible','Visible','Visible',NULL,'published')")
            db.execute("INSERT INTO lessons VALUES (2,'Archived lesson','Hidden',1,NULL,'archived')")
            self.assertEqual(db.execute(self.list_sql).fetchall(), [("visible", "Visible", "Visible")])
            self.assertIsNone(db.execute(self.course_sql, ("archived",)).fetchone())
            self.assertEqual(db.execute(self.lesson_sql, (2,)).fetchall(), [])
        finally:
            db.close()
    def test_not_found_and_loading_keep_route_status_and_real_requests(self):
        missing = body(VIEWS, "page_not_found")
        route = body(ROUTER, "route")
        self.assertIn("http_response_code(404)", missing)
        self.assertIn("linkto('','بازگشت به صفحه اصلی'", missing)
        self.assertIn('data-safe-back hidden', missing)
        self.assertIn("page_not_found();", route)
        self.assertIn("require_admin();", route)
        self.assertIn("if (!$member) { page_not_found(); return; }", body(ROUTER, "page_member"))
        self.assertIn("page_not_found(); return;", body(ROUTER, "page_project"))
        self.assertIn('id="noventix-loading"', body(VIEWS, "head_page"))
        js = (ROOT / "public/assets/app.js").read_text(encoding="utf-8")
        css = (ROOT / "public/assets/style.css").read_text(encoding="utf-8")
        for guard in ('event.defaultPrevented', 'event.target.method.toLowerCase() !== "post"', 'window.setTimeout(hideLoading, 10000)', 'window.addEventListener("pageshow", hideLoading)', 'window.addEventListener("pagehide", hideLoading)', 'previous.origin === window.location.origin'):
            self.assertIn(guard, js)
        for rule in ('.noventix-loading:not([hidden])', '.noventix-not-found', 'prefers-reduced-motion:reduce', '.noventix-not-found-actions'):
            self.assertIn(rule, css)
        static = (ROOT / "site/404.html").read_text(encoding="utf-8")
        self.assertEqual(static, gen.render_page({"path": "404", "title": "یافت نشد", "description": "صفحه مورد نظر پیدا نشد.", "view": "notfound", "nav": ""}))
        self.assertIn('data-safe-back hidden', static)
        self.assertIn('previous.origin === window.location.origin', (ROOT / "site/assets/app.js").read_text(encoding="utf-8"))
        self.assertIn('.noventix-not-found', (ROOT / "site/assets/style.css").read_text(encoding="utf-8"))


    def test_public_curriculum_never_exposes_protected_lesson_content(self):
        self.assertNotIn('content', self.lesson_sql)
        self.assertNotIn("$lesson['content']", self.detail)
        self.assertIn('authorized_progress_lesson', APP)
        self.assertIn("e.user_id=?", body(APP, 'authorized_progress_lesson'))
        self.assertIn("l.status='published' AND c.status='published'", body(APP, 'authorized_progress_lesson'))

    def test_free_course_access_is_explicit_and_independent_of_subscriptions(self):
        data = (ROOT / 'src/data.php').read_text(encoding='utf-8')
        self.assertIn('const COMMERCE_ENABLED = true;', data)
        self.assertIn("const FREE_COURSE_SLUG = 'ai-foundations';", data)
        access = body(APP, 'can_course')
        self.assertLess(access.index('$slug===FREE_COURSE_SLUG'), access.index('!COMMERCE_ENABLED'))
        self.assertNotIn('q(', access)
        post = body(APP, 'handle_post')
        enroll = post[post.index("if ($path==='courses/enroll')"):post.index("if ($path==='projects/start')")]
        self.assertIn('if (!can_course($slug,$plan))', enroll)
        self.assertNotIn('isset(COURSES[$slug])', enroll)
        self.assertLess(post.index('$u=require_user()'), post.index("if ($path==='courses/enroll')"))
        self.assertLess(enroll.index("status='published'"), enroll.index('INSERT'))

    def test_free_enrollment_is_idempotent_and_does_not_fabricate_progress(self):
        self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES ('ai-foundations','AI','','published')")
        insert = re.search(r"q\('(INSERT OR IGNORE INTO enrollments[^']+)'", APP).group(1)
        for _ in range(3):
            self.db.execute(insert, (2, 'ai-foundations'))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM enrollments WHERE user_id=2 AND course_slug='ai-foundations'").fetchone()[0], 1)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM subscriptions').fetchone()[0], 0)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM lesson_progress').fetchone()[0], 0)
        self.assertIn('INSERT IGNORE INTO enrollments', APP)
        enroll = APP[APP.index("if ($path==='courses/enroll')"):APP.index("if ($path==='projects/start')")]
        self.assertNotIn('lesson_progress', enroll)
        self.assertNotIn('activities', enroll)

    def test_verified_free_learner_access_uses_own_enrollment_without_payment(self):
        self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES ('ai-foundations','AI','','published')")
        course_id = self.db.execute("SELECT id FROM courses WHERE slug='ai-foundations'").fetchone()[0]
        self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,'Free lesson','protected',1,'published')", (course_id,))
        lesson_id = self.db.execute('SELECT MAX(id) FROM lessons').fetchone()[0]
        self.db.execute("INSERT INTO enrollments(user_id,course_slug) VALUES (2,'ai-foundations')")
        workspace_sql = query(self.workspace, 'courses')
        self.assertIsNotNone(self.db.execute(workspace_sql, (2, 'ai-foundations')).fetchone())
        self.assertIsNone(self.db.execute(workspace_sql, (1, 'ai-foundations')).fetchone())
        access_sql = query(body(APP, 'authorized_progress_lesson'), 'lessons')
        self.assertIsNotNone(self.db.execute(access_sql, (2, lesson_id)).fetchone())
        self.assertIsNone(self.db.execute(access_sql, (1, lesson_id)).fetchone())
        self.db.execute("UPDATE lessons SET status='draft' WHERE id=?", (lesson_id,))
        self.assertIsNone(self.db.execute(access_sql, (2, lesson_id)).fetchone())
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM subscriptions').fetchone()[0], 0)

    def test_anonymous_and_unverified_users_remain_blocked(self):
        auth = (ROOT / 'src/auth.php').read_text(encoding='utf-8')
        self.assertIn("if (empty($_SESSION['uid'])) return null;", body(auth, 'user'))
        self.assertIn("if (!$u || $u['phone_verified_at']===null) return null;", body(auth, 'user'))
        self.assertIn("redirect('login?next='", body(auth, 'require_user'))
        self.assertIn('$u=require_user()', body(ROUTER, 'route'))
        self.assertIn('validate_csrf();', body(APP, 'handle_post'))
        self.assertNotIn("$_POST['user_id']", APP[APP.index("if ($path==='courses/enroll')"):APP.index("if ($path==='projects/start')")])

    def test_commerce_routes_keep_admin_guard_before_paused_message(self):
        route = body(ROUTER, 'route')
        pause = "if (!COMMERCE_ENABLED && in_array($path,['dashboard/subscription','dashboard/subscriptions','dashboard/payments'],true))"
        self.assertIn('if (COMMERCE_ENABLED) page_pricing(); else commerce_paused();', route)
        self.assertLess(route.index('require_admin();'), route.index(pause))
        post = body(APP, 'handle_post')
        activate = post[post.index("if ($path==='admin/activate')"):post.index("if ($path==='challenges/start')")]
        self.assertLess(activate.index('require_admin();'), activate.index('!COMMERCE_ENABLED'))
        self.assertLess(activate.index('!COMMERCE_ENABLED'), activate.index('UPDATE subscriptions'))
        self.assertIn('http_response_code(403)', activate)
        self.assertIn('function page_pricing()', ROUTER)
        self.assertIn('CREATE TABLE IF NOT EXISTS subscriptions', SCHEMA)

    def test_primary_navigation_and_home_have_no_purchase_ctas(self):
        self.assertNotIn("'pricing'=>'", body(VIEWS, 'head_page'))
        self.assertIn("unset($items['dashboard/subscription'],$items['dashboard/subscriptions'],$items['dashboard/payments'])", body(VIEWS, 'panel_nav'))
        for route in gen.build_routes():
            if route['path'] not in ('', 'pricing', 'panel/student/subscription', 'panel/student/payments'):
                continue
            html = gen.render_page(route)
            if route['path'] == '':
                self.assertNotIn('انتخاب پلن', html)
                self.assertNotIn('ارتقای پلن', html)
                self.assertIn('دوره', html)
            else:
                self.assertIn('نسخهٔ سروری PHP', html)
                self.assertNotIn('data-form="subscription"', html)


if __name__ == "__main__":
    unittest.main()
