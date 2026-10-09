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
        self.assertIn("if (!$course) { http_response_code(404)", self.detail)
        self.assertNotIn("COURSES", self.detail)
        self.assertIn("course_start_action($course,$me,$enrolled", self.detail)
        self.assertIn("courses/enroll", body(ROUTER, "course_start_action"))
        self.assertIn("dashboard/course/", body(ROUTER, "course_start_action"))
        self.assertIn("page_course(trim(substr($path,7)))", body(ROUTER, "route"))

    def test_lessons_belong_to_course_are_published_and_ordered(self):
        course_id = self.db.execute(self.course_sql, ("published",)).fetchone()[0]
        self.assertEqual(
            [(row[0], row[2]) for row in self.db.execute(self.lesson_sql, (course_id,))],
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


if __name__ == "__main__":
    unittest.main()
