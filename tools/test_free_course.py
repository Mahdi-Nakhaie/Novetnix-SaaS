import os
import json
import pathlib
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from html.parser import HTMLParser

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent


class CourseDocument(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.iframes = []
        self.ids = []
        self.links = []
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if tag == 'iframe':
            self.iframes.append(attrs)
        if tag == 'a':
            self.links.append(attrs.get('href', ''))


class FreeCourseHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base
        cls.temp = tempfile.TemporaryDirectory()
        cls.env = {'PATH': os.environ.get('PATH', ''),
                   'DB_DSN': 'sqlite:' + str(test_release.ReleaseHttpTest.database),
                   'NOVENTIX_RELEASE_TEST': '1', 'PUBLIC_SITE_URL': 'https://release.invalid'}
        cls.publish('initial.json')

    @classmethod
    def publish(cls, name):
        return subprocess.check_output([shutil.which('php'), str(ROOT / 'tools/publish_free_course.php'),
                                        str(pathlib.Path(cls.temp.name) / name)], env=cls.env, text=True)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()
        test_release.ReleaseHttpTest.tearDownClass()

    def test_course_page_is_public_but_player_requires_registration(self):
        status, headers, source = test_release.Client(self.base).request('/course/ai-foundations')
        self.assertEqual(status, 200)
        head = test_release.Head(source)
        self.assertEqual(head.h1, 1)
        self.assertEqual(head.canonical, 'https://release.invalid/course/ai-foundations')
        self.assertEqual(head.meta['robots'], 'index,follow')
        self.assertNotIn('noindex', headers.get('X-Robots-Tag', ''))
        self.assertIn('مبانی هوش مصنوعی', source)
        self.assertIn('جلسهٔ دوم', source)
        self.assertIn('به‌زودی', source)
        self.assertNotIn('href="#lesson-2"', source)
        document = CourseDocument(source)
        self.assertEqual(len(document.ids), len(set(document.ids)))
        self.assertEqual(document.iframes, [])
        self.assertNotIn('qbk51d2', source)
        self.assertNotIn('/assets/free-course.js', source)
        self.assertNotIn('fc-switch-player', document.ids)
        self.assertIn('fc-access-gate', source)
        self.assertIn('login?next=course%2Fai-foundations', source)
        self.assertEqual(source.count('nav-free-course'), 2)
        for link in document.links:
            if link.startswith('#'):
                self.assertIn(link[1:], document.ids)
        self.assertIn('/assets/free-course.css', source)
        for asset in ('/assets/free-course.css', '/assets/free-course.js'):
            self.assertEqual(test_release.Client(self.base).request(asset)[0], 200)

    def register_for_course(self, phone):
        client = test_release.Client(self.base)
        client.request('/login?next=course%2Fai-foundations')
        status, headers, _ = client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'آزمایشی', 'phone': phone, 'password': 'FixturePass123'})
        self.assertEqual(status, 303)
        self.assertEqual(headers['Location'], '/course/ai-foundations')
        return client

    def test_verified_registration_login_and_logout_control_access(self):
        client = self.register_for_course('09990000181')
        status, _, source = client.request('/course/ai-foundations')
        self.assertEqual(status, 200)
        document = CourseDocument(source)
        self.assertEqual(len(document.iframes), 1)
        iframe = document.iframes[0]
        self.assertEqual(iframe['src'], 'https://www.aparat.com/video/video/embed/videohash/qbk51d2/vt/frame?titleShow=true')
        self.assertTrue(iframe['title'])
        self.assertIn('allowfullscreen', iframe)
        self.assertIn('fc-switch-player', document.ids)
        self.assertIn('fc-player-action', source)
        self.assertIn('fc-switch-label', document.ids)
        self.assertIn('/assets/free-course.js', source)
        self.assertNotIn('fc-access-gate', source)
        client.post('/logout', {})
        guest = client.request('/course/ai-foundations')[2]
        self.assertNotIn('qbk51d2', guest)
        self.assertEqual(CourseDocument(guest).iframes, [])
        client.request('/login?next=course%2Fai-foundations')
        status, headers, _ = client.post('/auth/login', {'phone': '09990000181', 'password': 'FixturePass123', 'captcha': client.state()['captcha']})
        self.assertEqual(status, 303)
        self.assertEqual(headers['Location'], '/course/ai-foundations')
        client.request('/course/ai-foundations')
        client.post('/logout', {})
        client.request('/login?next=https%3A%2F%2Foutside.invalid')
        status, headers, _ = client.post('/auth/login', {'phone': '09990000181', 'password': 'FixturePass123', 'captcha': client.state()['captcha']})
        self.assertEqual(status, 303)
        self.assertEqual(headers['Location'], '/dashboard')

    def test_registration_without_sms_creates_account_and_signs_in(self):
        client = test_release.Client(self.base)
        client.request('/login?next=course%2Fai-foundations')
        status, headers, _ = client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'بدون‌پیامک', 'phone': '09990000182', 'password': 'FixturePass123'})
        self.assertEqual((status, headers['Location']), (303, '/course/ai-foundations'))
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            row = connection.execute('SELECT name,phone_verified_at FROM users WHERE phone=?', ('09990000182',)).fetchone()
        self.assertEqual(row, ('هنرجو بدون‌پیامک', None))
        source = client.request('/course/ai-foundations')[2]
        self.assertNotIn('fc-access-gate', source)
        self.assertEqual(len(CourseDocument(source).iframes), 1)
        client.post('/logout', {})
        self.assertEqual(CourseDocument(client.request('/course/ai-foundations')[2]).iframes, [])
        client.request('/login')
        wrong = client.post('/auth/login', {'phone': '09990000182', 'password': 'WrongPass123', 'captcha': client.state()['captcha']})
        self.assertEqual((wrong[0], wrong[1]['Location']), (303, '/login'))
        self.assertNotIn('qbk51d2', client.request('/course/ai-foundations')[2])

    def test_highlighted_course_navigation_on_other_public_pages(self):
        client = test_release.Client(self.base)
        for path in ('/', '/articles', '/login'):
            with self.subTest(path=path):
                status, _, source = client.request(path)
                self.assertEqual(status, 200)
                self.assertEqual(source.count('nav-free-course'), 2)
                self.assertIn('href="/course/ai-foundations"', source)
                for opening in ('<nav class="main-nav"', '<nav id="mobile-nav"'):
                    navigation = source.split(opening, 1)[1].split('</nav>', 1)[0]
                    self.assertLess(navigation.index('href="/"'), navigation.index('href="/course/ai-foundations"'))
                    self.assertLess(navigation.index('href="/course/ai-foundations"'), navigation.index('href="/articles"'))
        css = (ROOT / 'public/assets/style.css').read_text()
        self.assertIn('.site-header .nav-free-course{color:var(--brand)}', css)
        self.assertNotIn('.nav-free-course::before', css)

    def test_separate_login_registration_and_private_account(self):
        client = test_release.Client(self.base)
        status, headers, login = client.request('/login')
        self.assertEqual(status, 200)
        self.assertIn('action="/auth/login"', login)
        self.assertNotIn('action="/auth/request"', login)
        self.assertIn('href="/register"', login)
        self.assertIn('noindex', headers.get('X-Robots-Tag', ''))
        status, headers, registration = client.request('/register?next=course%2Fai-foundations')
        self.assertEqual(status, 200)
        self.assertIn('action="/auth/request"', registration)
        self.assertNotIn('action="/auth/login"', registration)
        self.assertNotIn('name="captcha"', registration)
        self.assertIn('href="/login"', registration)
        self.assertIn('قبلاً حساب ساخته‌ای؟', registration)
        self.assertIn('<a href="/login">ورود به حساب</a>', registration)
        self.assertIn('ثبت‌نام در سایت', registration)
        self.assertNotIn('ارسال کد تأیید', registration)
        self.assertNotIn('کد پیامک', registration)
        self.assertIn('ارسال پیامک فعلاً غیرفعال است', registration)
        self.assertIn('هنوز حساب نداری؟', login)
        self.assertIn('<a href="/register">ساخت حساب جدید</a>', login)
        self.assertNotIn('class="btn btn-outline">ساخت حساب جدید', login)
        css = (ROOT / 'public/assets/style.css').read_text()
        self.assertIn('.auth-switch a{', css)
        self.assertIn('min-height:44px', css.split('.auth-switch a{', 1)[1].split('}', 1)[0])
        self.assertIn('.auth-card .stack-form h2{', css)
        self.assertIn('noindex', headers.get('X-Robots-Tag', ''))
        self.assertEqual(test_release.Head(registration).h1, 1)
        status, headers, _ = client.post('/auth/request', {'first_name': 'آزمایش', 'last_name': 'کاربر', 'phone': 'invalid', 'password': 'FixturePass123'})
        self.assertEqual((status, headers['Location']), (303, '/register'))
        registration = client.request('/register')[2]
        self.assertIn('شماره موبایل معتبر وارد کنید.', registration)
        self.assertIn('value="آزمایش"', registration)
        self.assertIn('value="invalid"', registration)
        self.assertNotIn('value="FixturePass123"', registration)
        status, headers, _ = client.request('/dashboard')
        self.assertEqual(status, 303)
        self.assertTrue(headers['Location'].startswith('/login'))

    def test_registration_page_keeps_course_return_destination(self):
        client = test_release.Client(self.base)
        client.request('/register?next=course%2Fai-foundations')
        status, headers, _ = client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'جدید', 'phone': '09990000183', 'password': 'FixturePass123'})
        self.assertEqual((status, headers['Location']), (303, '/course/ai-foundations'))
        for path in ('/login', '/register'):
            source = client.request(path)[2]
            self.assertIn('حساب شما فعال است', source)
            self.assertNotIn('action="/auth/request"', source)
            self.assertNotIn('action="/auth/login"', source)

    def test_player_csp_is_limited_to_course_routes(self):
        client = test_release.Client(self.base)
        _, headers, _ = client.request('/course/ai-foundations')
        policy = headers['Content-Security-Policy']
        self.assertIn("frame-src 'self' https://www.aparat.com", policy)
        self.assertNotIn("'unsafe-inline'", policy)
        _, headers, _ = client.request('/articles')
        self.assertNotIn('https://www.aparat.com', headers['Content-Security-Policy'])

    def lesson_quiz(self):
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            lesson, threshold, raw = connection.execute("SELECT l.id,q.pass_percent,q.questions_json FROM lessons l JOIN courses c ON c.id=l.course_id JOIN lesson_quizzes q ON q.lesson_id=l.id WHERE c.slug='ai-foundations' AND l.position=1").fetchone()
        return lesson, threshold, json.loads(raw)

    def test_workspace_video_and_slide_quiz_use_existing_learning_flow(self):
        client = self.register_for_course('09990000191')
        self.assertEqual(client.post('/courses/enroll', {'slug': 'ai-foundations'})[0], 303)
        status, headers, source = client.request('/dashboard/course/ai-foundations')
        self.assertEqual(status, 200)
        frames = CourseDocument(source).iframes
        self.assertEqual(len(frames), 1)
        self.assertEqual(frames[0]['class'], 'aparat-embed')
        self.assertIn('/videohash/qbk51d2/', frames[0]['src'])
        self.assertIn('allowfullscreen', frames[0])
        self.assertNotIn('data[responsive]', source)
        self.assertNotIn('63043856666', source)
        self.assertIn("https://www.aparat.com", headers['Content-Security-Policy'])
        lesson, threshold, questions = self.lesson_quiz()
        expected = json.loads((ROOT / 'curriculum/ai-foundations-session1-quiz.json').read_text())
        self.assertEqual((threshold, questions), (expected['pass_percent'], expected['questions']))
        self.assertEqual(len(questions), 10)
        for i, question in enumerate(questions):
            self.assertIn(question['prompt'], source)
            self.assertIn(f'name="answers[{i}]"', source)
        self.assertIn('name="quiz_submit" value="1"', source)
        self.assertIn('ثبت پیشرفت و تکمیل درس', source)
        self.assertIn('/assets/style.css?v=8', source)
        css = client.request('/assets/style.css?v=8')[2]
        self.assertIn('.video-placeholder.has-aparat{display:block;position:relative;', css)
        self.assertIn('.aparat-embed{position:absolute;inset:0;display:block;width:100%;height:100%;border:0}', css)
        self.assertIn('aspect-ratio:16/9', css)
        self.assertFalse(json.loads(client.request(f'/progress/lesson/{lesson}')[2])['completed'])

    def test_slide_quiz_threshold_persistence_isolation_and_repeat_submission(self):
        client = self.register_for_course('09990000192')
        client.post('/courses/enroll', {'slug': 'ai-foundations'})
        client.request('/dashboard/course/ai-foundations')
        lesson, threshold, questions = self.lesson_quiz()
        progress = f'/progress/lesson/{lesson}'
        self.assertEqual(client.post('/progress/save', {'lesson_id': lesson, 'completed': 'true'})[0], 422)
        self.assertEqual(client.post('/progress/save', {'lesson_id': lesson, 'watched_seconds': 900, 'last_position': 900, 'percentage': 100, 'completed': 'false'})[0], 200)
        self.assertFalse(json.loads(client.request(progress)[2])['completed'])
        answers = {f'answers[{i}]': str(q['correct']) for i, q in enumerate(questions)}
        wrong = dict(answers)
        for i in range(3):
            wrong[f'answers[{i}]'] = str((questions[i]['correct'] + 1) % len(questions[i]['options']))
        payload = {'lesson_id': lesson, 'quiz_submit': '1'}
        self.assertEqual(client.post('/progress/save', dict(payload, **wrong))[0], 303)
        self.assertFalse(json.loads(client.request(progress)[2])['completed'])
        invalid = dict(answers, **{'answers[0]': '99'})
        self.assertEqual(client.post('/progress/save', dict(payload, **invalid))[0], 303)
        self.assertFalse(json.loads(client.request(progress)[2])['completed'])
        passing = dict(answers)
        for i in range(2):
            passing[f'answers[{i}]'] = wrong[f'answers[{i}]']
        self.assertEqual(threshold, 80)
        self.assertEqual(client.post('/progress/save', dict(payload, **passing))[0], 303)
        first = json.loads(client.request(progress)[2])
        self.assertTrue(first['completed'])
        self.assertIn('تکمیل‌شده', client.request('/dashboard/course/ai-foundations')[2])
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            uid = connection.execute('SELECT id FROM users WHERE phone=?', ('09990000192',)).fetchone()[0]
            before = connection.execute('SELECT completed_at,quiz_version FROM lesson_progress WHERE user_id=? AND lesson_id=?', (uid, lesson)).fetchone()
        self.assertEqual(client.post('/progress/save', dict(payload, **passing))[0], 303)
        client.post('/logout', {})
        client.request('/login')
        self.assertEqual(client.post('/auth/login', {'phone': '09990000192', 'password': 'FixturePass123', 'captcha': client.state()['captcha']})[0], 303)
        self.assertTrue(json.loads(client.request(progress)[2])['completed'])
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            after = connection.execute('SELECT completed_at,quiz_version FROM lesson_progress WHERE user_id=? AND lesson_id=?', (uid, lesson)).fetchone()
            self.assertEqual(after, before)
            self.assertTrue(after[1])
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM activities WHERE user_id=? AND event_name='lesson_completed'", (uid,)).fetchone()[0], 1)
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM activities WHERE user_id=? AND event_name='course_completed'", (uid,)).fetchone()[0], 1)
        other = self.register_for_course('09990000193')
        self.assertEqual(other.post('/progress/save', dict(payload, **answers))[0], 404)
        other.post('/courses/enroll', {'slug': 'ai-foundations'})
        self.assertFalse(json.loads(other.request(progress)[2])['completed'])

    def test_seed_preserves_custom_quiz_and_backfills_existing_lesson(self):
        lesson, threshold, questions = self.lesson_quiz()
        database = test_release.ReleaseHttpTest.database
        custom = [{'prompt': 'آزمون سفارشی مدیر', 'options': ['یک', 'دو'], 'correct': 0}]
        try:
            with sqlite3.connect(database) as connection:
                connection.execute('UPDATE lesson_quizzes SET pass_percent=100,questions_json=? WHERE lesson_id=?', (json.dumps(custom), lesson))
            self.publish('custom-quiz.json')
            self.assertEqual(self.lesson_quiz(), (lesson, 100, custom))
            with sqlite3.connect(database) as connection:
                connection.execute('DELETE FROM lesson_quizzes WHERE lesson_id=?', (lesson,))
            self.assertEqual(test_release.Client(self.base).request('/course/ai-foundations')[0], 200)
            self.assertEqual(self.lesson_quiz(), (lesson, threshold, questions))
        finally:
            with sqlite3.connect(database) as connection:
                connection.execute('UPDATE lesson_quizzes SET pass_percent=?,questions_json=? WHERE lesson_id=?', (threshold, json.dumps(questions), lesson))

    def test_publication_is_repeatable_and_preserves_lesson_identity(self):
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            before = connection.execute('SELECT id,position,content FROM lessons ORDER BY id').fetchall()
        self.publish('repeat.json')
        with sqlite3.connect(test_release.ReleaseHttpTest.database) as connection:
            after = connection.execute('SELECT id,position,content FROM lessons ORDER BY id').fetchall()
            statuses = connection.execute('SELECT position,status FROM lessons ORDER BY position').fetchall()
        self.assertEqual(before, after)
        self.assertEqual(statuses, [(1, 'published'), (2, 'draft')])
        self.assertTrue((pathlib.Path(self.temp.name) / 'repeat.json').is_file())
        status, headers, _ = test_release.Client(self.base).request('/dashboard/course/ai-foundations')
        self.assertEqual(status, 303)
        self.assertTrue(headers['Location'].startswith('/login'))


if __name__ == '__main__':
    unittest.main()
