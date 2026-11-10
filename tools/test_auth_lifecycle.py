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

    def test_login_route_sends_authenticated_users_to_dashboard(self):
        start = ROUTER.index("function page_login")
        end = ROUTER.index("function page_admin_login", start)
        login = ROUTER[start:end]
        self.assertIn("if ($existing && trim((string)$existing['name'])!=='') redirect('dashboard');", login)
        self.assertIn("form_start('auth/request'", login)
        self.assertIn("form_start('auth/login'", login)

    def test_persistent_login_stores_only_hashed_validator(self):
        start = AUTH.index("function remember_cookie_options")
        end = AUTH.index("function logout_user", start)
        remember = AUTH[start:end]
        self.assertIn("random_bytes(32)", remember)
        self.assertIn("hash('sha256',$validator)", remember)
        self.assertIn("hash_equals((string)$row['validator_hash'],hash('sha256',$parts[2]))", remember)
        self.assertIn("remember_ttl_seconds()", remember)
        self.assertIn("'httponly'=>true", remember)
        self.assertIn("'samesite'=>'Lax'", remember)

    def test_logout_revokes_persistent_tokens(self):
        start = AUTH.index("function logout_user")
        end = AUTH.index("function sms_free_registration", start)
        logout = AUTH[start:end]
        self.assertIn("remember_revoke_all((int)$_SESSION['uid'])", logout)
        self.assertIn("remember_clear_cookie()", logout)

    def test_remember_cookie_restores_session_before_routing(self):
        index = (ROOT / "public/index.php").read_text(encoding="utf-8")
        self.assertIn("remember_restore()", index)
        self.assertLess(index.index("remember_restore()"), index.index("$me=user();"))

    def test_admin_credentials_are_environment_hash_based(self):
        self.assertIn("ADMIN_PHONE=", ENV)
        self.assertIn("ADMIN_PASSWORD_HASH=", ENV)
        self.assertNotIn("ADMIN_PASSWORD=", ENV)
        self.assertIn("password_verify((string)($_POST['password'] ?? ''),$hash)", AUTH)


    def test_admin_phone_override_loads_before_base_pool(self):
        override = ROOT / 'outputs/deploy/00-noventix-admin-phone.conf'
        self.assertTrue(override.is_file())
        self.assertLess(override.name, 'noventix.conf')
        deployment = (ROOT / 'outputs/deploy/assign-noventix-admin.py').read_text(encoding='utf-8')
        self.assertIn('path.name > TARGET.name', deployment)
        self.assertIn('LEGACY.read_text() == EXPECTED', deployment)

    def test_admin_assignment_checks_effective_worker_configuration(self):
        deployment = (ROOT / 'outputs/deploy/assign-noventix-admin.py').read_text(encoding='utf-8')
        self.assertIn('def verify_runtime_admin_phone():', deployment)
        self.assertIn("client.connect('/run/php/php8.2-fpm-noventix.sock')", deployment)
        self.assertIn('Effective administrator phone does not match', deployment)
        self.assertIn('os.unlink(filename)', deployment)
        reload = deployment.index("run(['systemctl', 'reload', 'php8.2-fpm.service'])")
        verify = deployment.index('            verify_runtime_admin_phone()', reload)
        success = deployment.index("'admin_phone_configured': True", verify)
        self.assertLess(reload, verify)
        self.assertLess(verify, success)


if __name__ == "__main__":
    unittest.main()
