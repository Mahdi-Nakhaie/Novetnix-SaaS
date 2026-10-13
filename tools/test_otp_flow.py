import pathlib
import re
import sqlite3
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
ENV_EXAMPLE = (ROOT / ".env.example").read_text(encoding="utf-8")
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
CONNECTION = (ROOT / "database/connection.php").read_text(encoding="utf-8")


def function_body(name):
    match = re.search(r"function " + name + r"\(.*?\n}\n", AUTH, re.S)
    if not match:
        match = re.search(r"function " + name + r"\(.*?}\n", AUTH)
    return match.group(0)


class OtpFlowTest(unittest.TestCase):
    def test_generation_uses_csprng_with_six_digits(self):
        body = function_body("generate_otp")
        self.assertIn("random_int(0,999999)", body)
        self.assertIn("str_pad", body)
        self.assertNotRegex(AUTH, r"(?<![_\w])(rand|mt_rand|uniqid|microtime)\(")
        self.assertIn("preg_match('/^[0-9]{6}$/D',$code)", AUTH)

    def test_only_hash_is_stored_and_previous_code_is_replaced(self):
        body = function_body("store_otp")
        self.assertIn("password_hash($code,PASSWORD_DEFAULT)", body)
        self.assertIn("beginTransaction", body)
        self.assertIn("DELETE FROM otp_codes WHERE phone=?", body)
        self.assertIn("[$phone,$hash,$passwordHash,$now+otp_ttl_seconds(),0,$now]", body)
        self.assertNotIn("$code", body.split("INSERT INTO otp_codes")[1])

    def test_ttl_is_configurable(self):
        body = function_body("otp_ttl_seconds")
        self.assertIn("config_value('OTP_TTL_SECONDS')", body)
        self.assertNotIn("time()+300", AUTH)
        self.assertRegex(ENV_EXAMPLE, r"(?m)^OTP_TTL_SECONDS=300$")

    def test_smsir_verify_contract(self):
        body = function_body("send_verification_code")
        self.assertIn("https://api.sms.ir/v1/send/verify", body)
        self.assertIn("'x-api-key: '.$apiKey", body)
        self.assertIn("'mobile'=>$phone", body)
        self.assertIn("'templateId'=>$templateId", body)
        self.assertIn("'parameters'=>[['name'=>$parameter,'value'=>$code]]", body)
        self.assertIn("$status===200", body)
        self.assertIn("['status'] ?? 0)===1", body)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>false", body)

    def test_code_is_not_exposed_or_logged(self):
        for line in AUTH.splitlines():
            if re.search(r"error_log|flash\(|redirect\(|header\(|echo |print", line):
                self.assertNotRegex(line, r"error_log\([^;]*\$(code|apiKey)")
                self.assertNotRegex(line, r"(flash|redirect|header)\([^;]*\$code")
        self.assertNotIn("$_SESSION['otp", AUTH.replace("$_SESSION['otp_requests']", ""))
        self.assertNotIn("otp_hash", ROUTER)

    def test_no_real_secret_in_env_example(self):
        self.assertRegex(ENV_EXAMPLE, r"(?m)^SMSIR_API_KEY=$")
        self.assertRegex(ENV_EXAMPLE, r"(?m)^SMSIR_TEMPLATE_ID=$")
        self.assertNotIn("SMS_API_TOKEN", ENV_EXAMPLE)

    def test_registration_order_and_duplicate_mobile_are_preserved(self):
        request = AUTH[AUTH.index("if ($path==='auth/request')"):AUTH.index("if ($path==='auth/login')")]
        duplicate = request.index("SELECT 1 FROM users WHERE phone=?")
        store = request.index("store_otp($phone,$code,time(),password_hash($password,PASSWORD_DEFAULT))")
        send = request.index("send_verification_code($phone,$code)")
        self.assertLess(duplicate, store)
        self.assertLess(store, send)
        self.assertIn("DELETE FROM otp_codes WHERE phone=? AND otp_hash=?", request)
        self.assertNotIn("INSERT INTO users", request)
        verify = AUTH[AUTH.index("if ($path==='auth/verify')"):AUTH.index("if ($path==='auth/admin')")]
        self.assertIn("time()>(int)$entry['expires_at']", verify)
        self.assertIn("AND otp_hash=? AND used_at IS NULL AND expires_at>=?", verify)
        self.assertIn("23000", verify)

    def test_database_rules_for_latest_and_expired_codes(self):
        db = sqlite3.connect(":memory:")
        try:
            db.executescript(SCHEMA)
            db.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09123456789','old-hash',400,100)")
            db.execute("DELETE FROM otp_codes WHERE phone='09123456789'")
            db.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09123456789','new-hash',500,200)")
            self.assertEqual(db.execute("SELECT otp_hash,created_at,expires_at FROM otp_codes").fetchall(), [("new-hash", 200, 500)])
            stale = db.execute("UPDATE otp_codes SET used_at=250 WHERE phone='09123456789' AND otp_hash='old-hash' AND used_at IS NULL AND expires_at>=250")
            self.assertEqual(stale.rowcount, 0)
            expired = db.execute("UPDATE otp_codes SET used_at=501 WHERE phone='09123456789' AND otp_hash='new-hash' AND used_at IS NULL AND expires_at>=501")
            self.assertEqual(expired.rowcount, 0)
            valid = db.execute("UPDATE otp_codes SET used_at=300 WHERE phone='09123456789' AND otp_hash='new-hash' AND used_at IS NULL AND expires_at>=300")
            self.assertEqual(valid.rowcount, 1)
        finally:
            db.close()

    def test_verify_checks_code_server_side_for_session_phone(self):
        verify = AUTH[AUTH.index("if ($path==='auth/verify')"):AUTH.index("if ($path==='auth/admin')")]
        self.assertIn("$phone=(string)($_SESSION['verify_phone'] ?? '')", verify)
        self.assertIn("$_POST['code']", verify)
        self.assertNotIn("$_GET", verify)
        order = [verify.index(s) for s in ("if (!$entry)", "$entry['used_at']!==null", "time()>(int)$entry['expires_at']",
                                           "password_verify($code,$entry['otp_hash'])", "SET used_at=?", "INSERT INTO users", "$db->commit()")]
        self.assertEqual(order, sorted(order))
        self.assertLess(verify.index("beginTransaction"), verify.index("SET used_at=?"))
        self.assertIn("'کد واردشده صحیح نیست. '.fa($left).' تلاش دیگر باقی مانده است.'", verify)
        self.assertIn("attempts<?',[$phone,$entry['otp_hash'],time(),$maxAttempts]", verify)
        self.assertIn("session_regenerate_id(true); $_SESSION['uid']=$userId;", verify)
        self.assertIn("redirect('dashboard')", verify)

    def test_session_contains_no_credentials_or_otp(self):
        public = (ROOT / "public/index.php").read_text(encoding="utf-8")
        self.assertIn("session.use_strict_mode", public)
        self.assertIn("session.cookie_httponly", public)
        self.assertIn("session.cookie_samesite", public)
        self.assertIn("session.cookie_secure", public)
        self.assertIn("unset($_SESSION['verify_password_hash'])", public)
        self.assertNotIn("$_SESSION['verify_password_hash']", AUTH)
        self.assertNotRegex(AUTH, r"\$_SESSION\['[^']*(password|otp|api_key)[^']*'\]")
        self.assertIn("$_SESSION['uid']=(int)$u['id']", AUTH)
        self.assertIn("$_SESSION=[]; session_regenerate_id(true)", AUTH)
        self.assertIn("pending_password_hash", SCHEMA)
        self.assertIn("pending_password_hash", (ROOT / "database/schema.mysql.sql").read_text(encoding="utf-8"))
        self.assertIn("ADD COLUMN pending_password_hash", CONNECTION)

    def test_login_requires_verified_phone_and_checks_hash(self):
        login = AUTH[AUTH.index("if ($path==='auth/login')"):AUTH.index("if ($path==='auth/verify')")]
        self.assertIn("normalized_phone($phoneRaw)", login)
        self.assertIn("password_verify($password,$hash)", login)
        self.assertIn("$u['phone_verified_at']===null", login)
        self.assertLess(login.index('session_regenerate_id(true)'), login.index("$_SESSION['uid']=(int)$u['id']"))
        self.assertIn("شماره موبایل یا رمز عبور صحیح نیست.", login)
        self.assertIn("$u['phone_verified_at']===null", function_body("user"))
        verify = AUTH[AUTH.index("if ($path==='auth/verify')"):AUTH.index("if ($path==='auth/admin')")]
        self.assertIn("INSERT INTO users(phone,name,password_hash,phone_verified_at)", verify)

    def test_funnel_events_are_scoped_to_successful_auth_operations(self):
        request = AUTH[AUTH.index("if ($path==='auth/request')"):AUTH.index("if ($path==='auth/login')")]
        login = AUTH[AUTH.index("if ($path==='auth/login')"):AUTH.index("if ($path==='auth/verify')")]
        verify = AUTH[AUTH.index("if ($path==='auth/verify')"):AUTH.index("if ($path==='auth/admin')")]
        admin = AUTH[AUTH.index("if ($path==='auth/admin')"):AUTH.index("if ($path==='logout')")]
        self.assertNotIn('record_activity_event(', request)
        self.assertNotIn('record_activity_event(', admin)
        self.assertIn("record_activity_event($userId,'user_registered')", verify)
        self.assertIn("record_activity_event($userId,'user_verified')", verify)
        self.assertEqual(verify.count('record_activity_event('), 2)
        for step in ("if ($used->rowCount()!==1)", "INSERT INTO users(phone,name,password_hash,phone_verified_at)", "$userId=(int)$db->lastInsertId()", "record_activity_event($userId,'user_registered')", "record_activity_event($userId,'user_verified')", '$db->commit()'):
            self.assertIn(step, verify)
        self.assertLess(verify.index('password_verify($code,'), verify.index('beginTransaction()'))
        self.assertLess(verify.index("INSERT INTO users(phone,name,password_hash,phone_verified_at)"), verify.index("record_activity_event($userId,'user_registered')"))
        self.assertLess(verify.index("record_activity_event($userId,'user_verified')"), verify.index('$db->commit()'))
        self.assertIn("record_activity_event((int)$u['id'],'user_logged_in')", login)
        self.assertEqual(login.count('record_activity_event('), 1)
        self.assertLess(login.index('if (!$u || $u[\'phone_verified_at\']===null || !$passwordValid)'), login.index('session_regenerate_id(true)'))
        self.assertLess(login.index("$_SESSION['uid']=(int)$u['id']"), login.index("record_activity_event((int)$u['id'],'user_logged_in')"))
        self.assertNotIn('record_activity_event(', AUTH[AUTH.index("if ($path==='logout')"):])
        recorder = CONNECTION[CONNECTION.index('function record_activity_event('):]
        self.assertIn("INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)", recorder)
        self.assertNotRegex(login + verify, r"record_activity_event\([^;]*\[(?:[^]]*\$(?:password|code|hash|token|session))")
        self.assertNotIn('INSERT INTO activities', login + verify)

    def test_funnel_success_failure_and_retry_preserve_distinct_events(self):
        db = sqlite3.connect(':memory:')
        db.execute('PRAGMA foreign_keys=ON')
        try:
            db.executescript(SCHEMA)
            db.execute("INSERT INTO otp_codes(phone,otp_hash,pending_password_hash,expires_at,created_at) VALUES ('09120000101','otp-hash','password-hash',200,100)")
            self.assertEqual(db.execute('SELECT COUNT(*) FROM activities').fetchone()[0], 0)
            db.commit()
            db.execute('BEGIN')
            used = db.execute('UPDATE otp_codes SET used_at=?,pending_password_hash=NULL WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=?', (150, '09120000101', 'otp-hash', 150))
            self.assertEqual(used.rowcount, 1)
            db.execute("INSERT INTO users(phone,phone_verified_at) VALUES ('09120000101',150)")
            user_id = db.execute('SELECT id FROM users WHERE phone=?', ('09120000101',)).fetchone()[0]
            for event in ('user_registered', 'user_verified'):
                db.execute('INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)', (user_id, event, None))
            db.commit()
            self.assertEqual(db.execute('UPDATE otp_codes SET used_at=160 WHERE phone=? AND used_at IS NULL', ('09120000101',)).rowcount, 0)
            db.execute('INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)', (user_id, 'user_logged_in', None))
            db.commit()
            rows = db.execute('SELECT user_id,event_name,created_at,metadata FROM activities ORDER BY id').fetchall()
            self.assertEqual([row[1] for row in rows], ['user_registered', 'user_verified', 'user_logged_in'])
            self.assertTrue(all(row[0] == user_id and row[2] and row[3] is None for row in rows))
            db.execute('BEGIN')
            db.execute("INSERT INTO users(phone,phone_verified_at) VALUES ('09120000102',160)")
            other_id = db.execute("SELECT id FROM users WHERE phone='09120000102'").fetchone()[0]
            db.execute('INSERT INTO activities(user_id,event_name) VALUES (?,?)', (other_id, 'user_registered'))
            db.rollback()
            self.assertEqual(db.execute('SELECT COUNT(*) FROM activities').fetchone()[0], 3)
            self.assertIsNone(db.execute("SELECT id FROM users WHERE phone='09120000102'").fetchone())
        finally:
            db.close()

    def test_legacy_verified_users_keep_progress_when_migrated(self):
        db = sqlite3.connect(":memory:")
        try:
            db.execute("PRAGMA foreign_keys=ON")
            db.executescript(SCHEMA.replace(", phone_verified_at INTEGER", ""))
            db.execute("INSERT INTO users(phone,name,password_hash) VALUES ('09120000131','User','hash')")
            db.execute("INSERT INTO users(phone,name,password_hash) VALUES ('09120000132','User','hash')")
            db.execute("INSERT INTO courses(slug,title,description) VALUES ('saved','Saved','Saved')")
            db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Saved','Saved',1)")
            db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,1)")
            db.execute("INSERT INTO otp_codes(phone,otp_hash,created_at,expires_at,used_at) VALUES ('09120000131','hash',100,200,150)")
            db.execute("ALTER TABLE users ADD COLUMN phone_verified_at INTEGER")
            migration = re.search(r"\$db->exec\('(UPDATE users SET phone_verified_at=.*?)'\);", CONNECTION).group(1)
            db.execute(migration)
            self.assertEqual(db.execute("SELECT phone_verified_at FROM users ORDER BY id").fetchall(), [(150,), (None,)])
            self.assertEqual(db.execute("SELECT user_id FROM lesson_progress").fetchall(), [(1,)])
        finally:
            db.close()

    def test_otp_limits_are_centralized_in_configuration(self):
        for key, default in (("OTP_MAX_ATTEMPTS", 5), ("OTP_RESEND_COOLDOWN_SECONDS", 90), ("OTP_RESEND_LIMIT", 5), ("OTP_RESEND_WINDOW_SECONDS", 3600)):
            self.assertIn(f"otp_setting('{key}',{default},", AUTH)
            self.assertRegex(ENV_EXAMPLE, rf"(?m)^{key}={default}$")
        self.assertEqual(AUTH.count("reserve_otp_send($phone,time())"), 2)
        for literal in ("<90", ">=8", "attempts<5", "attempts<=5", ">=5) { flash", "$_SESSION['otp_requests']"):
            self.assertNotIn(literal, AUTH)

    def test_concurrent_consume_succeeds_only_once(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = pathlib.Path(tmp) / "race.sqlite"
            setup = sqlite3.connect(path)
            setup.executescript(SCHEMA)
            setup.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09120000001','h',500,100)")
            setup.commit()
            setup.close()
            a = sqlite3.connect(path, timeout=0, isolation_level=None)
            b = sqlite3.connect(path, timeout=0, isolation_level=None)
            consume = "UPDATE otp_codes SET used_at=? WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=?"
            try:
                a.execute("BEGIN")
                b.execute("BEGIN")
                self.assertEqual(a.execute(consume, (200, "09120000001", "h", 200)).rowcount, 1)
                with self.assertRaises(sqlite3.OperationalError):
                    b.execute(consume, (200, "09120000001", "h", 200))
                a.execute("COMMIT")
                self.assertEqual(b.execute(consume, (201, "09120000001", "h", 201)).rowcount, 0)
                b.execute("COMMIT")
                self.assertEqual(a.execute("SELECT used_at FROM otp_codes").fetchall(), [(200,)])
            finally:
                a.close()
                b.close()

    def test_used_code_and_other_phone_are_rejected(self):
        db = sqlite3.connect(":memory:")
        try:
            db.executescript(SCHEMA)
            db.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09120000001','hash-a',500,100)")
            consume = "UPDATE otp_codes SET used_at=? WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=?"
            self.assertEqual(db.execute(consume, (200, "09120000002", "hash-a", 200)).rowcount, 0)
            self.assertEqual(db.execute(consume, (200, "09120000001", "hash-a", 200)).rowcount, 1)
            self.assertEqual(db.execute(consume, (210, "09120000001", "hash-a", 210)).rowcount, 0)
        finally:
            db.close()

    def test_otp_is_not_displayed_in_ui(self):
        page = ROUTER[ROUTER.index("function page_verify"):]
        page = page[:page.index("\n}\n")]
        self.assertNotIn("otp", page.lower())
        for path in ("tools/demo.js", "site/assets/demo.js"):
            demo = (ROOT / path).read_text(encoding="utf-8")
            self.assertNotRegex(demo, r"textContent\s*=[^;]*lastCode")
            self.assertNotIn("کد تأیید: ", demo)


if __name__ == "__main__":
    unittest.main()
