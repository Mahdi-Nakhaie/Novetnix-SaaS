"""End-to-end OTP verification against the real PHP backend and SQLite database.

Starts `php -S` with tools/otp_test_router.php when PHP is installed, or uses an
already running server via OTP_TEST_BASE_URL + OTP_TEST_DB.
"""
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
