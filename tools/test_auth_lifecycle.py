import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
ENV = (ROOT / ".env.example").read_text(encoding="utf-8")


class AuthLifecycleTest(unittest.TestCase):
    def test_logout_invalidates_session_and_cookie(self):
        start = AUTH.index("function logout_user")
        end = AUTH.index("function handle_auth_post", start)
        logout = AUTH[start:end]
        for statement in ("$_SESSION=[]", "setcookie(session_name()", "session_destroy()", "session_start()", "session_regenerate_id(true)"):
            self.assertIn(statement, logout)
        self.assertIn("logout_user(); flash", AUTH)

    def test_login_route_exposes_full_logout_for_active_session(self):
        start = ROUTER.index("function page_login")
        end = ROUTER.index("function page_admin_login", start)
        login = ROUTER[start:end]
        self.assertIn("حساب شما فعال است", login)
        self.assertIn("form_start('logout'", login)
        self.assertIn("ثبت‌نام دوباره", login)
        self.assertIn("form_start('auth/request'", login)

    def test_admin_credentials_are_environment_hash_based(self):
        self.assertIn("ADMIN_PHONE=", ENV)
        self.assertIn("ADMIN_PASSWORD_HASH=", ENV)
        self.assertNotIn("ADMIN_PASSWORD=", ENV)
        self.assertIn("password_verify((string)($_POST['password'] ?? ''),$hash)", AUTH)


if __name__ == "__main__":
    unittest.main()
