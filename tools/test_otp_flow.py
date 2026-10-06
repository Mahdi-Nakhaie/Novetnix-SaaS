import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
ENV_EXAMPLE = (ROOT / ".env.example").read_text(encoding="utf-8")
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")


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
        self.assertIn("[$phone,$hash,$now+otp_ttl_seconds(),0,$now]", body)
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
        store = request.index("store_otp($phone,$code,time())")
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


if __name__ == "__main__":
    unittest.main()
