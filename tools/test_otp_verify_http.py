"""End-to-end OTP verification against the real PHP backend and SQLite database.

Starts `php -S` with tools/otp_test_router.php when PHP is installed, or uses an
already running server via OTP_TEST_BASE_URL + OTP_TEST_DB.
"""
import http.cookiejar
import os
import pathlib
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
    env = dict(os.environ, DB_DSN="sqlite:" + DB, ADMIN_PHONE="09990000000", OTP_TEST_ROUTER="1")
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

    def pending(self, phone, code=None):
        data = {"phone": phone}
        if code is not None:
            data["code"] = code
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


if __name__ == "__main__":
    unittest.main()
