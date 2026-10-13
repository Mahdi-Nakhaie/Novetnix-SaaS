import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = (ROOT / "src/auth.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
PUBLIC = (ROOT / "public/index.php").read_text(encoding="utf-8")
ENV = (ROOT / ".env.example").read_text(encoding="utf-8")


def body(source, name):
    start = source.index(f"function {name}(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


class AdminAuthorizationTest(unittest.TestCase):
    def test_authentication_and_authorization_are_separate(self):
        self.assertIn("$_SESSION['uid']", body(AUTH, "user"))
        self.assertIn("function require_admin(): array", AUTH)
        admin = body(AUTH, "require_admin")
        self.assertIn("$u=require_user()", admin)
        self.assertIn("$u['role']!=='admin'", admin)
        self.assertIn("http_response_code(403)", admin)
        self.assertIn("$u['role']==='admin'", body(AUTH, "user"))

    def test_admin_dashboard_routes_are_server_side_protected(self):
        guard = "preg_match('~^dashboard/(users|users-new|courses|catalog|subscriptions|messages|analytics|payments|announcements|content|settings)$~D',$path)"
        self.assertIn(guard, ROUTER)
        self.assertIn("require_admin();", ROUTER)
        self.assertIn("if (str_starts_with($path,'dashboard/')", ROUTER)
        self.assertNotIn("$_GET['role']", ROUTER + APP)
        self.assertNotIn("$_POST['role']", ROUTER + APP)

    def test_sensitive_post_endpoints_require_admin(self):
        for path in ('admin/activate', 'settings/save', 'content/create', 'content/publish', 'announcements/send', 'admin/seed-demo'):
            section = APP[APP.index(f"if ($path==='{path}')"):]
            self.assertIn("require_admin();", section.split("\n", 3)[0] + "\n" + "\n".join(section.splitlines()[:4]))
        self.assertNotIn("if ($path==='admin/activate' && $u['role']==='admin')", APP)
        self.assertNotIn("if ($path==='settings/save' && $u['role']==='admin')", APP)
        self.assertNotIn("if ($path==='content/create' && $u['role']==='admin')", APP)
        self.assertNotIn("if ($path==='announcements/send' && $u['role']==='admin')", APP)

    def test_admin_promotion_requires_configured_identity_and_secret(self):
        admin = APP + AUTH
        auth_admin = body(AUTH, "handle_auth_post")
        segment = auth_admin[auth_admin.index("if ($path==='auth/admin')"):auth_admin.index("if ($path==='logout')")]
        self.assertIn("$u=user()", segment)
        self.assertIn("$u['phone']!==config_value('ADMIN_PHONE')", segment)
        self.assertIn("$hash=config_value('ADMIN_PASSWORD_HASH') ?: ''", segment)
        self.assertIn("password_verify((string)($_POST['password'] ?? ''),$hash)", segment)
        self.assertIn("UPDATE users SET role='admin' WHERE id=?", segment)
        self.assertNotIn("UPDATE users SET role='admin' WHERE id=$_POST", admin)
        self.assertRegex(ENV, r"(?m)^ADMIN_PHONE=")
        self.assertRegex(ENV, r"(?m)^ADMIN_PASSWORD_HASH=")
        self.assertNotIn("ADMIN_PASSWORD=", ENV)

    def test_session_and_csrf_security_remain_enabled(self):
        for setting in ('session.use_strict_mode', 'session.cookie_httponly', 'session.cookie_samesite', 'session.cookie_secure'):
            self.assertIn(setting, PUBLIC)
        self.assertIn("validate_csrf();", APP)
        self.assertIn("form_start('auth/admin'", (ROOT / "src/router.php").read_text(encoding="utf-8"))
        self.assertIn("$_SESSION['admin_verified_uid']", AUTH)

    def test_sensitive_admin_views_only_render_for_effective_admin(self):
        views = (ROOT / "src/views.php").read_text(encoding="utf-8")
        self.assertIn("elseif ($role==='admin') admin_panel($sub);", views)
        self.assertIn("$role==='admin'", views)
        for query in ('SELECT * FROM users', 'SELECT * FROM contact_messages', 'SELECT u.phone,s.plan'):
            self.assertIn(query, views)
        self.assertIn("if ($u['role']==='admin') redirect('dashboard');", (ROOT / "src/router.php").read_text(encoding="utf-8"))


if __name__ == '__main__':
    unittest.main()
