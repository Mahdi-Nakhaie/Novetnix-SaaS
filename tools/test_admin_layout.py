import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from html.parser import HTMLParser

ROOT = Path(os.environ.get('NOVENTIX_TEST_ROOT', Path(__file__).resolve().parent.parent))
PHP = shutil.which('php')
HARNESS = r'''
$root=$argv[1]; $case=$argv[2];
define('COMMERCE_ENABLED', $case==='commerce');
define('FREE_COURSE_SLUG','ai-foundations');
define('PLANS',['free'=>['name'=>'رایگان']]);
$me=['id'=>2,'name'=>'مدیر آزمایشی','phone'=>'09990000000','role'=>$case==='student'?'student':'admin'];
$path=$case==='nested'?'dashboard/glossary/example':'dashboard'; $plan='free';
$_SESSION=['csrf'=>'fixture']; $_GET=[];
function h($s) { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function url($s='') { return '/'.$s; }
function csrf() { return 'fixture'; }
function fa($s) { return (string)$s; }
function seo_head(...$args) {}
function seo_public_path($path) { return true; }
function lead_stage_for_user($id) { return ['label'=>'ثبت‌نام‌شده']; }
function lead_progress_summary($id) { return ['percentage'=>50,'completed'=>2,'status'=>'in_progress']; }
function plan_for($u) { return 'free'; }
$database=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,acquisition_source TEXT,acquisition_campaign TEXT,phone_verified_at TEXT); CREATE TABLE courses(id INTEGER); CREATE TABLE community_posts(id INTEGER); CREATE TABLE contact_messages(id INTEGER);');
$database->exec("INSERT INTO users VALUES(1,'<script>alert(1)</script>','09990000000','','',NULL)");
function q($sql,$args=[]) { global $database; $s=$database->prepare($sql); $s->execute($args); return $s; }
require $root.'/src/views.php';
ob_start();
if ($case==='users' || $case==='empty') {
    if ($case==='empty') $database->exec('DELETE FROM users');
    admin_panel('users');
} elseif ($case==='overview') panel('');
else { head_page('مدیریت','',true); echo '<main class="panel-content"></main>'; foot_page(true); }
$html=ob_get_clean(); echo json_encode(['html'=>$html],JSON_UNESCAPED_UNICODE);
'''


class TagBalance(HTMLParser):
    VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}

    def __init__(self):
        super().__init__()
        self.stack = []

    def handle_starttag(self, tag, attrs):
        if tag not in self.VOID:
            self.stack.append(tag)

    def handle_endtag(self, tag):
        if not self.stack or self.stack.pop() != tag:
            raise AssertionError('Unbalanced HTML: ' + tag)


@unittest.skipUnless(PHP, 'PHP CLI is required')
class AdminLayoutTest(unittest.TestCase):
    def render(self, case):
        result = subprocess.run([PHP, '-r', HARNESS, str(ROOT), case], capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        html = json.loads(result.stdout)['html']
        parser = TagBalance()
        parser.feed(html)
        self.assertEqual(parser.stack, [])
        return html

    def test_admin_shell_and_localized_navigation(self):
        html = self.render('admin')
        self.assertIn('class="is-panel is-admin"', html)
        self.assertIn('/assets/admin.css?v=1', html)
        self.assertEqual(html.count('aria-current="page"'), 1)
        for text in ('آموزش و محتوا', 'ارتباط با کاربران', 'آمار و گزارش‌ها', 'aria-controls="admin-sidebar"'):
            self.assertIn(text, html)
        self.assertNotIn('dashboard/payments', html)

    def test_student_shell_does_not_load_admin_styles(self):
        html = self.render('student')
        self.assertIn('class="is-panel"', html)
        self.assertNotIn('/assets/admin.css', html)
        self.assertNotIn('admin-sidebar', html)

    def test_commerce_navigation_respects_feature_setting(self):
        html = self.render('commerce')
        self.assertIn('dashboard/subscriptions', html)
        self.assertIn('dashboard/payments', html)

    def test_nested_route_keeps_parent_selected(self):
        html = self.render('nested')
        self.assertIn('href="/dashboard/glossary" class="selected" aria-current="page"', html)
        self.assertEqual(html.count('aria-current="page"'), 1)

    def test_dashboard_uses_real_counts_and_management_links(self):
        html = self.render('overview')
        self.assertIn('id="admin-main"', html)
        self.assertEqual(html.count('class="stat-card"'), 4)
        self.assertIn('<strong>1</strong>', html)
        self.assertIn('href="/dashboard/messages"', html)
        self.assertNotIn('دسترسی رایگان محدود', html)
        self.assertNotIn('account-summary', html)

    def test_users_table_localization_and_escaping(self):
        html = self.render('users')
        self.assertIn('class="admin-search"', html)
        self.assertEqual(html.count('<th>'), 9)
        self.assertIn('در حال یادگیری', html)
        self.assertNotIn('<script>', html)
        self.assertIn('&lt;script&gt;', html)

    def test_empty_table_spans_all_columns(self):
        self.assertIn('colspan="9"', self.render('empty'))


if __name__ == '__main__':
    unittest.main()
