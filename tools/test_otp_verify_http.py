"""End-to-end OTP verification against the real PHP backend and SQLite database.

Starts `php -S` with tools/otp_test_router.php when PHP is installed, or uses an
already running server via OTP_TEST_BASE_URL + OTP_TEST_DB.
"""
import base64
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
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parent.parent
SERVER = None
BASE = os.environ.get("OTP_TEST_BASE_URL", "").rstrip("/")
DB = os.environ.get("OTP_TEST_DB", "")
TMP = None


def setUpModule():
    global SERVER, BASE, DB, TMP
    if BASE:
        if not DB:
            raise unittest.SkipTest("OTP_TEST_DB is required with OTP_TEST_BASE_URL")
        return
    php = shutil.which(os.environ.get("PHP_BIN", "php"))
    if not php:
        raise unittest.SkipTest("PHP is not installed; set OTP_TEST_BASE_URL/OTP_TEST_DB to run")
    TMP = tempfile.mkdtemp()
    DB = os.path.join(TMP, "otp.sqlite")
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    env = dict(os.environ, DB_DSN="sqlite:" + DB, ADMIN_PHONE="09990000000", OTP_TEST_ROUTER="1", ZARINPAL_MERCHANT_ID="", PAYMENT_BASE_URL="")
    SERVER = subprocess.Popen([php, "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public"), str(ROOT / "tools/otp_test_router.php")],
                              env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    BASE = f"http://127.0.0.1:{port}"
    for _ in range(50):
        try:
            urllib.request.urlopen(BASE + "/login", timeout=1)
            return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError("PHP test server did not start")


def tearDownModule():
    if SERVER:
        SERVER.terminate()
        SERVER.wait(5)
    if TMP:
        shutil.rmtree(TMP, ignore_errors=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
        self.csrf = ""

    def request(self, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = self.opener.open(BASE + path, body, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.headers.get("Location", ""), response.read().decode("utf-8", "replace")

    def pending(self, phone, code=None, ttl=None):
        data = {"phone": phone}
        if code is not None:
            data["code"] = code
        if ttl is not None:
            data["ttl"] = ttl
        self.csrf = self.request("/__test/pending", data)[2].strip()

    def verify(self, code):
        return self.request("/auth/verify", {"csrf": self.csrf, "code": code})


def db(sql, params=()):
    con = sqlite3.connect(DB, timeout=30)
    try:
        result = con.execute(sql, params).fetchall()
        con.commit()
        return result
    finally:
        con.close()


def user_count(phone):
    return db("SELECT COUNT(*) FROM users WHERE phone=?", (phone,))[0][0]


def otp(phone):
    return db("SELECT used_at, attempts FROM otp_codes WHERE phone=?", (phone,))[0]


class CommerceCommunityHttpTest(unittest.TestCase):
    def verified(self, phone):
        client = Browser()
        client.pending(phone, '636363')
        self.assertEqual(client.verify('636363')[0], 303)
        return client

    def test_nova_is_paused_without_usage_or_messages(self):
        owner = self.verified('09120000701')
        uid = db('SELECT id FROM users WHERE phone=?', ('09120000701',))[0][0]
        status, _, text = owner.request('/dashboard/nova')
        self.assertEqual(status, 200)
        self.assertIn('به‌زودی', text)
        self.assertNotIn('action="/nova/ask"', text)
        self.assertEqual(owner.request('/nova/ask', {'csrf': owner.csrf, 'question': 'A question long enough'})[0], 303)
        self.assertEqual(db('SELECT COUNT(*) FROM nova_messages WHERE user_id=?', (uid,))[0][0], 0)
        self.assertEqual(db('SELECT COUNT(*) FROM nova_usage WHERE user_id=?', (uid,))[0][0], 0)

    def test_repeated_saves_are_unique_and_collection_is_private(self):
        owner = self.verified('09120000702')
        other = self.verified('09120000703')
        uid = db('SELECT id FROM users WHERE phone=?', ('09120000702',))[0][0]
        db('INSERT INTO community_posts(user_id,title,body) VALUES (?,?,?)', (uid, 'Unique bookmarked post 702', 'A community post for save testing'))
        post = db('SELECT MAX(id) FROM community_posts')[0][0]
        for _ in range(10):
            self.assertEqual(owner.request('/community/react', {'csrf': owner.csrf, 'post_id': post, 'kind': 'save'})[0], 303)
        self.assertEqual(db("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='save'", (post,))[0][0], 1)
        status, _, saved = owner.request('/dashboard/community?saved=1')
        self.assertEqual(status, 200)
        self.assertIn('Unique bookmarked post 702', saved)
        self.assertIn('ذخیره‌شده', saved)
        self.assertNotIn('Unique bookmarked post 702', other.request('/dashboard/community?saved=1')[2])
        self.assertEqual(other.request('/community/react', {'csrf': 'bad', 'post_id': post, 'kind': 'save'})[0], 419)
        self.assertEqual(Browser().request('/dashboard/community?saved=1')[0], 303)

    def test_payment_history_is_owner_scoped_and_not_admin_route(self):
        owner = self.verified('09120000704')
        other = self.verified('09120000705')
        uid = db('SELECT id FROM users WHERE phone=?', ('09120000704',))[0][0]
        db("INSERT INTO payment_orders(user_id,plan,amount_rial,request_key,status,ref_id) VALUES (?,'bronze',1900000,?,'pending',?)", (uid, 'c' * 32, '9876543210704'))
        status, _, text = owner.request('/dashboard/payment-history')
        self.assertEqual(status, 200)
        self.assertIn('9876543210704', text)
        self.assertNotIn('9876543210704', other.request('/dashboard/payment-history')[2])
        self.assertEqual(other.request('/dashboard/payments')[0], 403)
        status, _, checkout = owner.request('/dashboard/subscription')
        self.assertEqual(status, 200)
        self.assertIn('خرید اشتراک با زرین‌پال', checkout)
        self.assertIn('درگاه واقعی هنوز', checkout)
        self.assertIn('disabled', checkout)
        self.assertIn('</html>', checkout)
        status, _, pricing = owner.request('/pricing')
        self.assertEqual(status, 200)
        self.assertIn('برنزی', pricing)
        self.assertIn('</html>', pricing)
        before = db('SELECT COUNT(*) FROM subscriptions')[0][0]
        self.assertEqual(owner.request('/payment/start', {'csrf': owner.csrf, 'plan': 'gold', 'request_key': 'a' * 32})[0], 303)
        self.assertEqual(db('SELECT COUNT(*) FROM subscriptions')[0][0], before)
        self.assertEqual(owner.request('/payment/start', {'csrf': 'bad', 'plan': 'gold', 'request_key': 'a' * 32})[0], 419)
        self.assertEqual(other.request('/payment/retry', {'csrf': other.csrf, 'id': db('SELECT MAX(id) FROM payment_orders')[0][0]})[0], 404)
        self.assertEqual(Browser().request('/payment/start', {'plan': 'gold'})[0], 419)
        self.assertEqual(owner.request('/payment/zarinpal/callback?Authority=invalid&Status=OK')[0], 400)


class WorkspaceHttpTest(unittest.TestCase):
    def verified(self, phone):
        client = Browser()
        client.pending(phone, '636363')
        self.assertEqual(client.verify('636363')[0], 303)
        return client

    def post(self, client, path, **values):
        status, _, text = client.request('/workspace/' + path, {'csrf': client.csrf, **values})
        return status, json.loads(text)

    def test_dataset_routes_require_owner_and_csrf(self):
        owner = self.verified('09120000321')
        other = self.verified('09120000322')
        _, created = self.post(owner, 'create', title='Hub permissions')
        project = created['id']
        self.assertEqual(other.request('/workspace/datasets-state?id=' + str(project))[0], 404)
        self.assertEqual(other.request('/workspace/datasets-search?id=' + str(project) + '&q=iris')[0], 404)
        self.assertEqual(Browser().request('/workspace/datasets-state?id=' + str(project))[0], 303)
        self.assertEqual(owner.request('/workspace/datasets-begin', {'csrf': 'bad', 'id': project})[0], 419)
        self.assertEqual(self.post(owner, 'datasets-begin', id=project, dataset='../bad', selected_files='[]')[0], 422)
        self.assertEqual(self.post(other, 'datasets-cancel', id=project, import_id='a' * 32)[0], 404)

    def test_dataset_expiry_releases_reserved_space(self):
        owner = self.verified('09120000325')
        _, created = self.post(owner, 'create', title='Expired import')
        project = created['id']
        identifier = 'e' * 32
        owner_id = db('SELECT user_id FROM projects WHERE id=?', (project,))[0][0]
        metadata = json.dumps({'source': 'https://huggingface.co/datasets/scikit-learn/iris', 'license': None})
        files = json.dumps([{'path': 'small.csv', 'size': 32, 'hash': 'a' * 40, 'algorithm': 'git-sha1'}])
        db("INSERT INTO workspace_dataset_imports(id,project_id,owner,dataset_id,revision,fingerprint,state,files_json,metadata_json,bytes,completed_files,created) VALUES (?,?,?,?,?,?,'downloading',?,?,32,0,?)", (identifier, project, owner_id, 'scikit-learn/iris', 'a' * 40, 'b' * 64, files, metadata, int(time.time()) - 121))
        status, _, text = owner.request('/workspace/datasets-state?id=' + str(project))
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(text)['imports'][0]['state'], 'failed')
        self.assertEqual(db('SELECT bytes FROM project_workspaces WHERE project_id=?', (project,))[0][0], len("print('Hello, Noventix!')\n"))

    @unittest.skipUnless(os.environ.get('NOVENTIX_HF_LIVE_TESTS') == '1', 'Set NOVENTIX_HF_LIVE_TESTS=1 for real public Hub integration')
    def test_live_hub_download_filesystem_quota_dedup_and_python_manifest(self):
        owner = self.verified('09120000323')
        other = self.verified('09120000324')
        _, created = self.post(owner, 'create', title='Real Iris import')
        project = created['id']
        jobs = []
        try:
            status, _, text = owner.request('/workspace/datasets-search?id=' + str(project) + '&q=scikit-learn%2Firis')
            self.assertEqual(status, 200, text)
            self.assertTrue(any(d['id'] == 'scikit-learn/iris' for d in json.loads(text)['datasets']))
            status, _, text = owner.request('/workspace/datasets-detail?id=' + str(project) + '&dataset=scikit-learn/iris')
            self.assertEqual(status, 200, text)
            detail = json.loads(text)
            self.assertEqual(detail['access'], 'public')
            self.assertTrue(any(f['path'] == 'Iris.csv' for f in detail['files']))
            args = {'id': project, 'dataset': detail['id'], 'dataset_revision': detail['revision'], 'selected_files': '["Iris.csv"]'}
            status, job = self.post(owner, 'datasets-begin', **args)
            self.assertEqual(status, 202, job)
            jobs.append(job['id'])
            self.assertEqual(self.post(other, 'datasets-step', id=project, import_id=job['id'])[0], 404)
            self.assertEqual(self.post(owner, 'datasets-begin', **args)[1]['id'], job['id'])
            status, cancelled = self.post(owner, 'datasets-cancel', id=project, import_id=job['id'])
            self.assertEqual((status, cancelled['state']), (200, 'cancelled'))
            self.assertEqual(self.post(owner, 'datasets-step', id=project, import_id=job['id'])[1]['state'], 'cancelled')
            status, job = self.post(owner, 'datasets-begin', **args)
            self.assertEqual(status, 202, job)
            jobs.append(job['id'])
            status, complete = self.post(owner, 'datasets-step', id=project, import_id=job['id'])
            self.assertEqual((status, complete['state']), (200, 'succeeded'), complete)
            self.assertEqual(self.post(owner, 'datasets-begin', **args)[1]['id'], job['id'])
            state = json.loads(owner.request('/workspace/data?id=' + str(project))[2])
            path = complete['directory'] + '/Iris.csv'
            self.assertIn(path, state['files'])
            raw = base64.b64decode(state['files'][path]['data'])
            self.assertTrue(raw.startswith(b'Id,SepalLengthCm'))
            self.assertTrue((ROOT / 'storage/datasets' / job['id'] / '0.data').is_file())
            self.assertEqual(other.request('/workspace/download?id=' + str(project) + '&file=' + urllib.parse.quote(path))[0], 404)
            self.assertEqual(owner.request('/workspace/download?id=' + str(project) + '&file=' + urllib.parse.quote(path))[2].encode(), raw)
            self.assertEqual(self.post(owner, 'save', id=project, revision=state['revision'], files=json.dumps(state['files']))[0], 200)
            persisted = db('SELECT files_json,bytes FROM project_workspaces WHERE project_id=?', (project,))[0]
            self.assertNotIn(path, json.loads(persisted[0]))
            self.assertGreaterEqual(persisted[1], len(raw))
            metadata = db('SELECT files_json FROM workspace_dataset_imports WHERE id=?', (job['id'],))[0][0]
            self.assertNotIn('data', json.loads(metadata)[0])
            sys_path = str(ROOT / 'services/python-runner')
            import sys
            sys.path.insert(0, sys_path)
            import policy
            self.assertEqual(policy.manifest(state['files'])[path]['data'], state['files'][path]['data'])
            import csv
            import io
            self.assertEqual(len(list(csv.DictReader(io.StringIO(raw.decode())))), 150)
            changed = dict(state['files'])
            changed[path] = {'kind': 'file', 'data': base64.b64encode(b'changed').decode()}
            self.assertEqual(self.post(owner, 'save', id=project, revision=state['revision'] + 1, files=json.dumps(changed))[0], 422)
            _, bad_project = self.post(owner, 'create', title='Checksum failure')
            status, bad_job = self.post(owner, 'datasets-begin', **{**args, 'id': bad_project['id']})
            self.assertEqual(status, 202, bad_job)
            jobs.append(bad_job['id'])
            metadata = json.loads(db('SELECT files_json FROM workspace_dataset_imports WHERE id=?', (bad_job['id'],))[0][0])
            metadata[0]['hash'] = '0' * 40
            db('UPDATE workspace_dataset_imports SET files_json=? WHERE id=?', (json.dumps(metadata), bad_job['id']))
            status, rejected = self.post(owner, 'datasets-step', id=bad_project['id'], import_id=bad_job['id'])
            self.assertEqual((status, rejected['state']), (200, 'failed'), rejected)
            self.assertFalse((ROOT / 'storage/datasets' / bad_job['id'] / '0.data').exists())
            _, full_project = self.post(owner, 'create', title='Quota full')
            db('UPDATE project_workspaces SET bytes=5242880 WHERE project_id=?', (full_project['id'],))
            status, error = self.post(owner, 'datasets-begin', **{**args, 'id': full_project['id']})
            self.assertEqual(status, 422, error)
            self.assertEqual(db("SELECT COUNT(*) FROM workspace_dataset_imports WHERE project_id=?", (full_project['id'],))[0][0], 0)
        finally:
            for identifier in jobs:
                directory = ROOT / 'storage/datasets' / identifier
                if directory.exists():
                    shutil.rmtree(directory)

    def test_workspace_create_save_conflict_isolation_and_restore(self):
        owner = self.verified('09120000301')
        other = self.verified('09120000302')
        status, created = self.post(owner, 'create', title='Persistent Python')
        self.assertEqual(status, 201)
        project = created['id']
        self.assertEqual(owner.request('/dashboard/workspace/' + str(project))[0], 200)
        self.assertEqual(other.request('/workspace/data?id=' + str(project))[0], 404)
        state = json.loads(owner.request('/workspace/data?id=' + str(project))[2])
        files = {'main.py': {'kind': 'file', 'data': base64.b64encode(b'print(42)\n').decode()}}
        self.assertEqual(self.post(owner, 'save', id=project, revision=state['revision'], files=json.dumps(files))[0], 200)
        self.assertEqual(self.post(owner, 'save', id=project, revision=state['revision'], files='{}')[0], 409)
        self.assertEqual(self.post(other, 'save', id=project, revision=2, files='{}')[0], 404)
        self.assertEqual(owner.request('/workspace/download?id=' + str(project) + '&file=main.py')[2], 'print(42)\n')
        self.assertEqual(other.request('/workspace/download?id=' + str(project) + '&file=main.py')[0], 404)
        self.assertEqual(self.post(owner, 'delete', id=project, revision=2)[0], 200)
        self.assertEqual(owner.request('/workspace/data?id=' + str(project))[0], 404)
        self.assertEqual(self.post(owner, 'restore', id=project, revision=3)[0], 200)
        self.assertEqual(json.loads(owner.request('/workspace/data?id=' + str(project))[2])['files'], files)
        self.assertEqual(owner.request('/workspace/save', {'csrf': 'invalid', 'id': project, 'revision': 4, 'files': '{}'})[0], 419)
        self.assertEqual(owner.request('/workspace/admin-template-save', {'csrf': owner.csrf, 'title': 'Not admin'})[0], 403)

    def test_template_snapshot_and_disabled_runner(self):
        client = self.verified('09120000303')
        files = {'main.py': {'kind': 'file', 'data': base64.b64encode(b'print(1)\n').decode()}}
        db("INSERT INTO workspace_templates(title,instructions,status,files_json,bytes) VALUES ('HTTP fixture','','published',?,9)", (json.dumps(files),))
        template = db("SELECT id FROM workspace_templates WHERE title='HTTP fixture'")[0][0]
        status, created = self.post(client, 'create', template_id=template)
        self.assertEqual(status, 201)
        self.assertEqual(self.post(client, 'create', template_id=template)[1]['id'], created['id'])
        db("UPDATE workspace_templates SET files_json='{}',revision=revision+1 WHERE id=?", (template,))
        state = json.loads(client.request('/workspace/data?id=' + str(created['id']))[2])
        self.assertEqual(state['files'], files)
        if not state['runner_ready']:
            self.assertEqual(self.post(client, 'run', id=created['id'], revision=state['revision'], request_key='00000000-0000-0000-0000-000000000000')[0], 503)
        anonymous = Browser()
        self.assertEqual(anonymous.request('/workspace/data?id=' + str(created['id']))[0], 303)


class OtpVerifyHttpTest(unittest.TestCase):
    def test_1_correct_code_verifies_user_consumes_otp_and_opens_dashboard(self):
        b = Browser()
        b.pending("09120000101", "123456")
        self.assertEqual(user_count("09120000101"), 0)
        status, location, _ = b.verify("123456")
        self.assertEqual((status, location), (303, "/dashboard"))
        self.assertEqual(user_count("09120000101"), 1)
        self.assertIsNotNone(otp("09120000101")[0])
        status, location, html = b.request("/dashboard")
        self.assertEqual(status, 200, location)
        self.assertIn("خوش آمدید", html)

    def test_2_wrong_code_is_rejected_and_user_stays_unverified(self):
        b = Browser()
        b.pending("09120000102", "111111")
        status, location, _ = b.verify("222222")
        self.assertEqual((status, location), (303, "/verify"))
        self.assertEqual(user_count("09120000102"), 0)
        self.assertEqual(otp("09120000102"), (None, 1))
        self.assertIn("کد واردشده صحیح نیست", b.request("/verify")[2])

    def test_3_used_otp_is_rejected(self):
        first = Browser()
        first.pending("09120000103", "333333")
        self.assertEqual(first.verify("333333")[1], "/dashboard")
        second = Browser()
        second.pending("09120000103")
        status, location, _ = second.verify("333333")
        self.assertEqual((status, location), (303, "/login"))
        self.assertIn("قبلاً استفاده شده", second.request("/login")[2])
        self.assertEqual(user_count("09120000103"), 1)

    def test_4_expired_otp_is_rejected(self):
        b = Browser()
        b.pending("09120000104", "444444")
        now = int(time.time())
        db("UPDATE otp_codes SET created_at=?, expires_at=? WHERE phone=?", (now - 1000, now - 1, "09120000104"))
        status, location, _ = b.verify("444444")
        self.assertEqual((status, location), (303, "/login"))
        self.assertIn("کد منقضی شده است", b.request("/login")[2])
        self.assertEqual(user_count("09120000104"), 0)
        self.assertIsNone(otp("09120000104")[0])

    def test_5_code_of_another_user_is_rejected(self):
        owner, other = Browser(), Browser()
        owner.pending("09120000105", "555555")
        other.pending("09120000106", "666666")
        status, location, _ = other.verify("555555")
        self.assertEqual((status, location), (303, "/verify"))
        self.assertEqual(user_count("09120000106"), 0)
        self.assertEqual(user_count("09120000105"), 0)
        self.assertEqual(otp("09120000105"), (None, 0))
        self.assertEqual(owner.verify("555555")[1], "/dashboard")

    def test_6_reusing_correct_code_in_same_session_is_rejected(self):
        b = Browser()
        b.pending("09120000107", "777777")
        self.assertEqual(b.verify("777777")[1], "/dashboard")
        status, location, _ = b.verify("777777")
        self.assertEqual((status, location), (303, "/login"))
        self.assertEqual(user_count("09120000107"), 1)

    def test_7_concurrent_verifications_consume_otp_once(self):
        a, b = Browser(), Browser()
        a.pending("09120000108", "888888")
        b.pending("09120000108")
        results = []
        threads = [threading.Thread(target=lambda br=br: results.append(br.verify("888888"))) for br in (a, b)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(sorted(r[1] for r in results), ["/dashboard", "/login"])
        self.assertEqual(user_count("09120000108"), 1)
        self.assertIsNotNone(otp("09120000108")[0])

    def test_9_verify_page_does_not_display_the_otp(self):
        b = Browser()
        b.pending("09120000109", "909090")
        status, _, html = b.request("/verify")
        self.assertEqual(status, 200)
        self.assertNotIn("909090", html)
        self.assertNotIn("۹۰۹۰۹۰", html)
        self.assertNotIn("otp_hash", html)
        self.assertNotIn("$2y$", html)


    def test_10_ttl_comes_from_configuration(self):
        b = Browser()
        for ttl, expected in (("120", 120), ("600", 600), ("5", 300)):
            b.pending("09120000110", "101010", ttl)
            created, expires = db("SELECT created_at, expires_at FROM otp_codes WHERE phone=?", ("09120000110",))[0]
            self.assertEqual(expires - created, expected, ttl)
        b.pending("09120000110", "101010")
        created, expires = db("SELECT created_at, expires_at FROM otp_codes WHERE phone=?", ("09120000110",))[0]
        self.assertEqual(expires - created, 300)

    def test_11_new_code_replaces_old_without_creating_user(self):
        b = Browser()
        b.pending("09120000111", "111000")
        b.pending("09120000111", "222000")
        self.assertEqual(db("SELECT COUNT(*) FROM otp_codes WHERE phone=?", ("09120000111",))[0][0], 1)
        self.assertEqual(user_count("09120000111"), 0)
        self.assertEqual(b.verify("111000")[1], "/verify")
        self.assertEqual(user_count("09120000111"), 0)
        self.assertEqual(b.verify("222000")[1], "/dashboard")
        self.assertEqual(user_count("09120000111"), 1)

    def test_12_multiple_resends_keep_only_latest_code(self):
        b = Browser()
        codes = ["300001", "300002", "300003", "300004"]
        for code in codes:
            b.pending("09120000112", code)
        rows = db("SELECT otp_hash, used_at, attempts FROM otp_codes WHERE phone=?", ("09120000112",))
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0][1:], (None, 0))
        self.assertTrue(rows[0][0].startswith("$2y$"))
        self.assertNotIn(codes[-1], rows[0][0])
        for old in codes[:-1]:
            self.assertEqual(b.verify(old)[1], "/verify")
        self.assertEqual(user_count("09120000112"), 0)
        self.assertEqual(b.verify(codes[-1])[1], "/dashboard")
        self.assertEqual(user_count("09120000112"), 1)

    def test_13_resend_endpoint_respects_cooldown_and_never_creates_user(self):
        b = Browser()
        b.pending("09120000113", "131313")
        self.assertEqual(resend(b), "/verify")
        html = b.request("/verify")[2]
        self.assertIn("ارسال پیامک فعلاً امکان‌پذیر نیست", html)
        self.assertIn("ارسال دوباره کد", html)
        self.assertNotIn("131313", html)
        self.assertEqual(b.verify("131313")[1], "/verify")
        self.assertEqual(otp("09120000113"), (None, 5))
        self.assertEqual(resend(b), "/verify")
        self.assertIn("ثانیه دیگر صبر کنید", b.request("/verify")[2])
        self.assertEqual(requests_row("09120000113")[1], 1)
        self.assertEqual(user_count("09120000113"), 0)

    def test_14_resend_without_pending_registration_is_rejected(self):
        b = Browser()
        csrf = b.request("/__test/pending", {"phone": ""})[2].strip()
        status, location, _ = b.request("/auth/resend", {"csrf": csrf})
        self.assertEqual((status, location), (303, "/login"))

    def test_15_valid_code_within_attempt_limit_is_accepted(self):
        b = Browser()
        b.pending("09120000115", "151515")
        for _ in range(4):
            self.assertEqual(b.verify("000000")[1], "/verify")
        self.assertEqual(otp("09120000115"), (None, 4))
        self.assertEqual(b.verify("151515")[1], "/dashboard")
        self.assertEqual(user_count("09120000115"), 1)

    def test_16_wrong_codes_count_down_then_block_even_correct_code(self):
        b = Browser()
        b.pending("09120000116", "161616")
        for left in (4, 3, 2, 1):
            self.assertEqual(b.verify("000000")[1], "/verify")
            self.assertIn(f"کد واردشده صحیح نیست. {fa(left)} تلاش دیگر باقی مانده است.", b.request("/verify")[2])
        self.assertEqual(b.verify("000000")[1], "/verify")
        self.assertIn("این کد غیرفعال شد", b.request("/verify")[2])
        self.assertEqual(otp("09120000116"), (None, 5))
        status, location, _ = b.verify("161616")
        self.assertEqual((status, location), (303, "/verify"))
        self.assertIn("این کد غیرفعال شد", b.request("/verify")[2])
        self.assertEqual(otp("09120000116"), (None, 5))
        self.assertEqual(user_count("09120000116"), 0)

    def test_17_client_state_cannot_reset_attempts(self):
        b = Browser()
        b.pending("09120000117", "171717")
        for _ in range(5):
            b.request("/auth/verify", {"csrf": b.csrf, "code": "000000", "attempts": "0", "expires_at": "9999999999"})
        fresh = Browser()
        fresh.pending("09120000117")
        status, location, _ = fresh.request("/auth/verify", {"csrf": fresh.csrf, "code": "171717", "attempts": "0"})
        self.assertEqual((status, location), (303, "/verify"))
        self.assertEqual(otp("09120000117"), (None, 5))
        self.assertEqual(user_count("09120000117"), 0)

    def test_18_registration_cooldown_applies_per_phone_across_sessions(self):
        first = Browser()
        self.assertEqual(register(first, "09120000118"), "/login")
        self.assertIn("ارسال پیامک فعلاً امکان‌پذیر نیست", first.request("/login")[2])
        second = Browser()
        self.assertEqual(register(second, "09120000118", ignored_counter="0"), "/login")
        html = second.request("/login")[2]
        self.assertIn("ثانیه دیگر صبر کنید", html)
        self.assertEqual(requests_row("09120000118")[1], 1)
        self.assertEqual(db("SELECT COUNT(*) FROM otp_codes WHERE phone=?", ("09120000118",))[0][0], 0)
        self.assertEqual(user_count("09120000118"), 0)

    def test_19_resend_limit_per_window_then_allowed_after_window(self):
        phone = "09120000119"
        for _ in range(5):
            b = Browser()
            b.pending(phone)
            age_requests(phone, last=100)
            self.assertEqual(resend(b), "/verify")
            self.assertIn("ارسال پیامک فعلاً امکان‌پذیر نیست", b.request("/verify")[2])
        self.assertEqual(requests_row(phone)[1], 5)
        age_requests(phone, last=100)
        b = Browser()
        b.pending(phone)
        self.assertEqual(resend(b), "/verify")
        self.assertIn("به سقف مجاز رسیده است", b.request("/verify")[2])
        self.assertEqual(requests_row(phone)[1], 5)
        age_requests(phone, last=100, window=3600)
        self.assertEqual(resend(b), "/verify")
        self.assertIn("ارسال پیامک فعلاً امکان‌پذیر نیست", b.request("/verify")[2])
        self.assertEqual(requests_row(phone)[1], 1)
        self.assertEqual(user_count(phone), 0)

    def test_20_persian_and_arabic_digit_codes_are_accepted(self):
        for phone, code, typed in (("09120000120", "120120", "۱۲۰۱۲۰"), ("09120000121", "121121", "١٢١١٢١")):
            b = Browser()
            b.pending(phone, code)
            self.assertEqual(b.verify(typed)[1], "/dashboard")
            self.assertEqual(user_count(phone), 1)

    def test_21_login_preserves_learning_data_and_rotates_session(self):
        phone = "09120000122"
        b = Browser()
        b.pending(phone, "122122")
        self.assertEqual(b.verify("122122")[1], "/dashboard")
        user_id = db("SELECT id FROM users WHERE phone=?", (phone,))[0][0]
        self.assertIsNotNone(db("SELECT phone_verified_at FROM users WHERE id=?", (user_id,))[0][0])
        db("INSERT INTO enrollments(user_id,course_slug) VALUES (?,?)", (user_id, "python-foundations"))
        db("INSERT INTO courses(slug,title,description) VALUES ('saved-progress','Saved','Saved')")
        course_id = db("SELECT id FROM courses WHERE slug='saved-progress'")[0][0]
        db("INSERT INTO lessons(course_id,title,content,position) VALUES (?,?,?,1)", (course_id, "Saved", "Saved"))
        lesson_id = db("SELECT id FROM lessons WHERE course_id=?", (course_id,))[0][0]
        db("INSERT INTO lesson_progress(user_id,lesson_id,status,completed_at) VALUES (?,?,'completed','2026-10-06 10:00:00')", (user_id, lesson_id))
        self.assertEqual(b.request("/logout", {"csrf": b.csrf})[1], "/")
        old_sid = session_id(b)
        self.assertEqual(login(b, "+98 9120000122", "Passw0rd1")[1], "/dashboard")
        self.assertNotEqual(session_id(b), old_sid)
        self.assertEqual(b.request("/dashboard")[0], 200)
        self.assertIn("مقاله‌های من", b.request("/dashboard")[2])
        self.assertEqual(b.request("/dashboard/learning")[0], 200)
        self.assertEqual(db("SELECT course_slug FROM enrollments WHERE user_id=?", (user_id,)), [("python-foundations",)])
        self.assertEqual(db("SELECT status FROM lesson_progress WHERE user_id=?", (user_id,)), [("completed",)])

    def test_22_unverified_and_unknown_users_do_not_gain_access(self):
        owner = Browser()
        owner.pending("09120000123", "123123")
        self.assertEqual(owner.verify("123123")[1], "/dashboard")
        hash_value = db("SELECT password_hash FROM users WHERE phone='09120000123'")[0][0]
        db("INSERT INTO users(phone,name,password_hash) VALUES (?,?,?)", ("09120000124", "کاربر", hash_value))
        for phone, password in (("09120000124", "Passw0rd1"), ("09120000125", "Passw0rd1"), ("09120000123", "wrong-password")):
            b = Browser()
            status, location, _ = login(b, phone, password)
            self.assertEqual((status, location), (303, "/login"))
            self.assertIn("شماره موبایل یا رمز عبور صحیح نیست.", b.request("/login")[2])
            self.assertTrue(b.request("/dashboard")[1].startswith("/login"))

    def test_23_unverified_session_cannot_open_dashboard(self):
        b = Browser()
        b.pending("09120000126", "126126")
        self.assertEqual(b.verify("126126")[1], "/dashboard")
        db("UPDATE users SET phone_verified_at=NULL WHERE phone='09120000126'")
        self.assertTrue(b.request("/dashboard")[1].startswith("/login"))

    def test_24_login_identity_session_and_logout(self):
        owner = Browser()
        owner.pending("09120000127", "127127")
        self.assertFalse(auth_state(owner)["has_credentials"])
        self.assertEqual(owner.verify("127127")[1], "/dashboard")
        owner_id = db("SELECT id FROM users WHERE phone='09120000127'")[0][0]
        self.assertIsNone(db("SELECT pending_password_hash FROM otp_codes WHERE phone='09120000127'")[0][0])
        self.assertEqual(owner.request("/logout", {"csrf": owner.csrf})[1], "/")
        b = Browser()
        login(b, "09120000127", "wrong-password")
        self.assertIsNone(auth_state(b)["uid"])
        before = session_id(b)
        self.assertEqual(login(b, "09120000127", "Passw0rd1")[1], "/dashboard")
        self.assertNotEqual(before, session_id(b))
        state = auth_state(b)
        self.assertEqual((state["uid"], state["current_user_id"], state["has_credentials"]), (owner_id, owner_id, False))
        self.assertEqual(b.request("/dashboard?user_id=9999")[0], 200)
        self.assertEqual(auth_state(b)["current_user_id"], owner_id)
        self.assertEqual(b.request("/logout", {"csrf": b.csrf})[1], "/")
        self.assertIsNone(auth_state(b)["uid"])
        self.assertTrue(b.request("/dashboard")[1].startswith("/login"))


def auth_state(browser):
    return json.loads(browser.request("/__test/auth-state")[2])


def session_id(browser):
    return next((cookie.value for cookie in browser.opener.handlers if isinstance(cookie, urllib.request.HTTPCookieProcessor) for cookie in cookie.cookiejar if cookie.name == "PHPSESSID"), None)


def login(browser, phone, password):
    html = browser.request("/login")[2]
    captcha = re.search(r'<div class="captcha-box"><strong dir="ltr">([^<]+)</strong>', html).group(1)
    csrf = re.search(r'name="csrf" value="([^"]+)"', html).group(1)
    browser.csrf = csrf
    return browser.request("/auth/login", {"csrf": csrf, "phone": phone, "password": password, "captcha": captcha})


def fa(n):
    return str(n).translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


def resend(browser):
    return browser.request("/auth/resend", {"csrf": browser.csrf})[1]


def register(browser, phone, **extra):
    browser.csrf = browser.request("/__test/pending", {"phone": ""})[2].strip()
    data = {"csrf": browser.csrf, "first_name": "علی", "last_name": "رضایی", "phone": phone, "password": "Passw0rd1", **extra}
    return browser.request("/auth/request", data)[1]


def requests_row(phone):
    return db("SELECT window_start, request_count, last_requested FROM otp_requests WHERE phone=?", (phone,))[0]


def age_requests(phone, last=0, window=0):
    db("UPDATE otp_requests SET last_requested=last_requested-?, window_start=window_start-? WHERE phone=?", (last, window, phone))


if __name__ == "__main__":
    unittest.main()
