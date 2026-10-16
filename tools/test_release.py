"""Release checks use real PHP HTTP/session/SQLite, not a rendering browser."""
import http.cookiejar
import json
import os
import pathlib
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parent.parent


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = ''

    def request(self, path, data=None):
        encoded = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            response = self.opener.open(self.base + path, encoded, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        text = response.read().decode('utf-8')
        token = re.search(r'name="csrf" value="([^"]+)"', text)
        if token:
            self.csrf = token[1]
        return response.status, response.headers, text

    def post(self, path, data):
        return self.request(path, dict(data, csrf=self.csrf))

    def state(self):
        return json.loads(self.request('/__fixture/state')[2])


class Head(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.meta = {}
        self.canonical = None
        self.h1 = 0
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta':
            self.meta[attrs.get('name', attrs.get('property'))] = attrs.get('content')
        elif tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical = attrs['href']
        elif tag == 'h1':
            self.h1 += 1


class ReleaseHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = shutil.which(os.environ.get('PHP_BIN', 'php'))
        if not php:
            raise unittest.SkipTest('PHP unavailable')
        cls.temp = tempfile.TemporaryDirectory()
        cls.database = pathlib.Path(cls.temp.name) / 'release.sqlite'
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        admin_hash = subprocess.check_output([php, '-r', 'echo password_hash("FixturePass123",PASSWORD_DEFAULT);'], text=True)
        env = dict(os.environ, DB_DSN='sqlite:' + str(cls.database), NOVENTIX_RELEASE_TEST='1',
                   ADMIN_PHONE='09990000000', ADMIN_PASSWORD_HASH=admin_hash,
                   SMSIR_API_KEY='synthetic-test-only', SMSIR_TEMPLATE_ID='123',
                   ZARINPAL_MERCHANT_ID='', PAYMENT_BASE_URL='', PUBLIC_SITE_URL='https://release.invalid')
        cls.server = subprocess.Popen([php, '-d', 'disable_functions=curl_init,curl_setopt_array,curl_setopt,curl_exec,curl_getinfo,curl_close',
                                       '-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'public'), str(ROOT / 'tools/release_test_router.php')],
                                      env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(80):
            try:
                if Client(cls.base).request('/login')[0] == 200:
                    return
            except OSError:
                pass
            time.sleep(.05)
        cls.server.terminate()
        cls.server.wait(5)
        cls.temp.cleanup()
        raise RuntimeError('Release test server failed to start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(5)
        cls.temp.cleanup()

    def rows(self, sql, params=()):
        with sqlite3.connect(self.database) as connection:
            return connection.execute(sql, params).fetchall()

    def register(self, client, phone, first='اکبر'):
        self.assertEqual(client.request('/login')[0], 200)
        response = client.post('/auth/request', {'first_name': first, 'last_name': 'آزمایشی', 'phone': phone, 'password': 'FixturePass123'})
        self.assertEqual((response[0], response[1].get('Location')), (303, '/verify'))
        delivery = client.state()['delivery']
        self.assertRegex(delivery, r'^\d{6}$')
        self.assertEqual(client.post('/auth/verify', {'code': delivery})[0], 303)

    def login(self, client, phone, password='FixturePass123'):
        client.request('/login')
        return client.post('/auth/login', {'phone': phone, 'password': password, 'captcha': client.state()['captcha']})

    def admin(self):
        admin = Client(self.base)
        if not self.rows('SELECT id FROM users WHERE phone=?', ('09990000000',)):
            self.register(admin, '09990000000', 'مدیر')
        else:
            self.assertEqual(self.login(admin, '09990000000')[0], 303)
        admin.request('/admin-login')
        self.assertEqual(admin.post('/auth/admin', {'password': 'FixturePass123', 'captcha': admin.state()['captcha']})[0], 303)
        return admin

    def test_akbar_http_journey_and_admin_authoring(self):
        admin = self.admin()
        self.assertEqual(admin.post('/admin/course-save', {'id': '0', 'slug': 'ai-foundations', 'title': 'دوره تست معتبر', 'content': 'محتوای آموزشی مشخص برای آزمون مسیر یادگیری', 'status': 'published'})[0], 303)
        course = self.rows('SELECT id FROM courses WHERE slug=?', ('ai-foundations',))[0][0]
        for position in (1, 2):
            self.assertEqual(admin.post('/admin/lesson-save', {'id': '0', 'course_id': course, 'position': position, 'title': f'جلسه {position}', 'content': 'مفهوم را مطالعه کنید و به سؤال واقعی پاسخ دهید.', 'status': 'published'})[0], 303)
        lessons = [row[0] for row in self.rows('SELECT id FROM lessons WHERE course_id=? ORDER BY position', (course,))]
        questions = json.dumps([{'prompt': 'کدام گزینه پاسخ درست است؟', 'options': ['پاسخ درست', 'پاسخ نادرست'], 'correct': 0}])
        for lesson in lessons:
            self.assertEqual(admin.post('/admin/quiz-save', {'lesson_id': lesson, 'pass_percent': '100', 'questions': questions})[0], 303)
        akbar = Client(self.base)
        status, _, landing = akbar.request('/?source=organic&campaign=release')
        self.assertEqual(status, 200)
        self.assertIn('href="/login"', landing)
        self.register(akbar, '09990000001')
        uid = self.rows('SELECT id FROM users WHERE phone_verified_at IS NOT NULL AND phone=?', ('09990000001',))[0][0]
        self.assertEqual(akbar.post('/logout', {})[0], 303)
        self.assertEqual(akbar.request('/dashboard')[0], 303)
        self.assertEqual(self.login(akbar, '09990000001')[0], 303)
        self.assertEqual(akbar.request('/dashboard')[0], 200)
        self.assertEqual(akbar.post('/courses/enroll', {'slug': 'ai-foundations'})[0], 303)
        self.assertEqual(akbar.request('/dashboard/course/ai-foundations')[0], 200)
        progress = {'lesson_id': lessons[0], 'watched_seconds': 12, 'last_position': 12, 'percentage': 20, 'completed': 'false'}
        self.assertEqual(akbar.post('/progress/save', progress)[0], 200)
        self.assertEqual(json.loads(akbar.request(f'/progress/lesson/{lessons[0]}')[2])['last_position'], 12)
        self.assertEqual(akbar.post('/logout', {})[0], 303)
        self.assertEqual(self.login(akbar, '09990000001')[0], 303)
        self.assertEqual(json.loads(akbar.request(f'/progress/lesson/{lessons[0]}')[2])['last_position'], 12)
        second = Client(self.base)
        self.assertEqual(self.login(second, '09990000001')[0], 303)
        self.assertEqual(second.post('/progress/save', dict(progress, watched_seconds=5, last_position=5, percentage=10))[0], 200)
        merged = json.loads(akbar.request(f'/progress/lesson/{lessons[0]}')[2])
        self.assertEqual((merged['watched_seconds'], merged['last_position'], merged['percentage']), (12, 5, 20))
        outsider = Client(self.base)
        self.register(outsider, '09990000002', 'کاربر')
        self.assertEqual(outsider.request(f'/progress/lesson/{lessons[0]}')[0], 404)
        self.assertEqual(outsider.post('/progress/save', progress)[0], 404)
        self.assertEqual(akbar.post('/progress/save', dict(progress, completed='true'))[0], 422)
        for lesson in lessons:
            for _ in range(2):
                self.assertEqual(akbar.post('/progress/save', {'lesson_id': lesson, 'quiz_submit': '1', 'answers[0]': '0'})[0], 303)
        self.assertEqual(self.rows("SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND completed=1 AND status='completed'", (uid,))[0][0], 2)
        self.assertEqual(self.rows("SELECT COUNT(*) FROM activities WHERE user_id=? AND event_name='course_completed'", (uid,))[0][0], 1)
        self.assertIn('Completed', admin.request('/dashboard/users?q=' + urllib.parse.quote('اکبر'))[2])
        self.assertIn('High Intent', admin.request('/dashboard/users?q=' + urllib.parse.quote('اکبر'))[2])
        self.assertEqual(akbar.request('/dashboard')[0], 200)
        self.assertEqual(akbar.request('/dashboard/users')[0], 403)
        self.assertEqual(akbar.post('/admin/quiz-save', {'lesson_id': lessons[0], 'pass_percent': 100, 'questions': questions})[0], 403)
        self.assertEqual(akbar.request('/progress/save', dict(progress, csrf='invalid'))[0], 419)
        self.assertEqual(akbar.request('/__fixture/expire')[0], 200)
        self.assertEqual(akbar.request('/dashboard')[0], 303)

    def test_expired_subscription_revokes_existing_enrollment_and_template(self):
        php = shutil.which(os.environ.get('PHP_BIN', 'php'))
        database = pathlib.Path(self.temp.name) / 'entitlement.sqlite'
        code = r'''
require '/project/src/app.php';
q("INSERT INTO users(phone,name,phone_verified_at) VALUES ('09990000991','Fixture',1)");
q("INSERT INTO courses(slug,title,description,status) VALUES ('data-analysis','Data','Published learning','published')");
q("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'Lesson','Content',1,'published')");
q("INSERT INTO enrollments(user_id,course_slug) VALUES (1,'data-analysis')");
$template=['status'=>'published','course_id'=>1,'lesson_id'=>1];
function accessState($template) { return [course_workspace(1,'data-analysis')!==null,authorized_progress_lesson(1,1),ws_template_allowed($template,1)]; }
$out=['free'=>accessState($template)];
q("INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (1,'bronze','active',?,?)",[gmdate('Y-m-d H:i:s',time()-60),gmdate('Y-m-d H:i:s',time()+3600)]);
$out['active']=accessState($template);
q('UPDATE subscriptions SET expires_at=?',[gmdate('Y-m-d H:i:s',time()-1)]);
$out['expired']=accessState($template);
q("UPDATE subscriptions SET expires_at=?,status='inactive'",[gmdate('Y-m-d H:i:s',time()+3600)]);
$out['inactive']=accessState($template);
q("UPDATE subscriptions SET status='active',starts_at=?",[gmdate('Y-m-d H:i:s',time()+60)]);
$out['future']=accessState($template);
q('UPDATE subscriptions SET starts_at=?',[gmdate('Y-m-d H:i:s',time()-60)]);
q("INSERT INTO courses(slug,title,description,status) VALUES ('ai-foundations','Free','Learning','published')");
q("INSERT INTO lessons(course_id,title,content,position,status) VALUES (2,'Other','Content',1,'published')");
q("INSERT INTO enrollments(user_id,course_slug) VALUES (1,'ai-foundations')");
$template['lesson_id']=2;
$out['mismatch']=ws_template_allowed($template,1);
q("UPDATE subscriptions SET status='expired'");
$out['free_survives']=course_workspace(1,'ai-foundations')!==null;
$out['matrix']=[];
foreach (array_keys(PLANS) as $plan) $out['matrix'][$plan]=array_map(fn($slug)=>can_course($slug,$plan),['ai-foundations','python-foundations','data-analysis','nlp-practice','deep-learning','unknown']);
echo json_encode($out);
'''
        value = json.loads(subprocess.check_output([php, '-r', code], env=dict(os.environ, DB_DSN='sqlite:' + str(database)), text=True))
        self.assertEqual(value['active'], [True, True, True])
        for state in ('free', 'expired', 'inactive', 'future'):
            self.assertEqual(value[state], [False, False, False], state)
        self.assertFalse(value['mismatch'])
        self.assertTrue(value['free_survives'])
        self.assertEqual(value['matrix'], {
            'free': [True, True, False, False, False, False],
            'bronze': [True, True, True, False, False, False],
            'silver': [True, True, True, True, False, False],
            'gold': [True, True, True, True, True, False],
            'titanium': [True, True, True, True, True, False],
        })

    def test_metadata_public_pages_404_and_private_policy(self):
        client = Client(self.base)
        paths = ['', 'courses', 'projects', 'pricing', 'about', 'contact', 'terms', 'privacy'] + [f'project/{index}' for index in range(9)]
        paths += ['course/' + row[0] for row in self.rows("SELECT slug FROM courses WHERE status='published'")]
        titles, descriptions = set(), set()
        for path in paths:
            with self.subTest(path=path):
                status, _, text = client.request('/' + path)
                self.assertEqual(status, 200)
                head = Head(text)
                self.assertEqual(head.h1, 1)
                self.assertEqual(head.canonical, 'https://release.invalid/' + path)
                self.assertEqual(head.meta['robots'], 'index,follow')
                self.assertEqual(head.meta['og:locale'], 'fa_IR')
                self.assertEqual(head.meta['twitter:card'], 'summary')
                self.assertNotIn(head.meta['description'], descriptions)
                descriptions.add(head.meta['description'])
                title = re.search(r'<title>(.*?)</title>', text)[1]
                self.assertNotIn(title, titles)
                titles.add(title)
                self.assertIn('lang="fa" dir="rtl"', text)
                structured = json.loads(re.search(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', text)[1])
                self.assertEqual(structured['url'], head.canonical)
                self.assertEqual(structured['@type'], 'Course' if path.startswith('course/') else 'WebPage')
        self.assertEqual(Head(client.request('/community')[2]).meta['robots'], 'noindex,follow')
        status, headers, text = client.request('/not-a-real-route')
        self.assertEqual(status, 404)
        self.assertEqual(Head(text).meta['robots'], 'noindex,follow')
        self.assertIn('noindex', headers['X-Robots-Tag'])
        self.assertIsNone(Head(text).canonical)
        self.assertEqual(Head(client.request('/login')[2]).meta['robots'], 'noindex,follow')
        self.assertIn('Sitemap: https://release.invalid/sitemap.xml', client.request('/robots.txt')[2])
        status, _, xml = client.request('/sitemap.xml')
        self.assertEqual(status, 200)
        urls = [node.text for node in ET.fromstring(xml).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
        self.assertIn('https://release.invalid/projects', urls)
        self.assertFalse(any('/dashboard' in url or '/member/' in url for url in urls))

    def test_database_failure_and_missing_public_origin_fail_safely(self):
        php = shutil.which(os.environ.get('PHP_BIN', 'php'))
        for dsn, path in (('sqlite:' + self.temp.name, '/'), ('sqlite:' + str(pathlib.Path(self.temp.name) / 'no-origin.sqlite'), '/sitemap.xml')):
            with self.subTest(path=path):
                with socket.socket() as sock:
                    sock.bind(('127.0.0.1', 0))
                    port = sock.getsockname()[1]
                process = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'public'), str(ROOT / 'public/index.php')],
                                           env=dict(os.environ, DB_DSN=dsn, PUBLIC_SITE_URL=''), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                try:
                    response = None
                    for _ in range(60):
                        try:
                            response = Client(f'http://127.0.0.1:{port}').request(path)
                            break
                        except OSError:
                            time.sleep(.05)
                    self.assertIsNotNone(response)
                    self.assertEqual(response[0], 503)
                    self.assertNotIn(self.temp.name, response[2])
                    self.assertNotIn('PDOException', response[2])
                    self.assertNotIn('<loc>', response[2])
                finally:
                    process.terminate()
                    process.wait(5)

    def test_origin_validation_without_host_fallback(self):
        php = shutil.which(os.environ.get('PHP_BIN', 'php'))
        for origin in ('', 'http://release.invalid', 'https://release.invalid/path', 'https://user:pass@release.invalid', 'https://release.invalid?x=1'):
            with self.subTest(origin=origin):
                value = subprocess.check_output([php, '-r', f'require "{ROOT}/src/seo.php"; echo json_encode(seo_origin());'], env=dict(os.environ, PUBLIC_SITE_URL=origin), text=True)
                self.assertEqual(value, 'null')


if __name__ == '__main__':
    unittest.main()
