import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(os.environ.get('NOVENTIX_TEST_ROOT', Path(__file__).resolve().parent.parent))
PHP = shutil.which('php')

HARNESS = r'''
$root=$argv[1]; $case=$argv[2];
ini_set('session.save_path',$argv[3]); session_start();
define('SMS_VERIFICATION_ENABLED',false);
$database=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database->exec(file_get_contents($root.'/database/schema.sqlite.sql'));
function q($sql,$args=[]) { global $database; $statement=$database->prepare($sql); $statement->execute($args); return $statement; }
function config_value($key,$default=null) { return $key==='ADMIN_PHONE' ? '09990000000' : $default; }
function url($path='') { return '/'.$path; }
function head_page(...$args) {}
function notice() {}
function foot_page() {}
function h($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function form_start($action,...$args) { echo '<form action="/'.h($action).'">'; }
require $root.'/src/auth.php';
$router=file_get_contents($root.'/src/router.php');
$start=strpos($router,'function page_admin_login(');
$end=strpos($router,"\nfunction ",$start+1);
eval(substr($router,$start,$end-$start));
q("INSERT INTO users(id,phone,name,role,password_hash) VALUES(2,?,'Test Owner','admin',?)",['09990000000',password_hash('AccountFixture123',PASSWORD_DEFAULT)]);
q("INSERT INTO users(id,phone,name,role,password_hash) VALUES(3,?,'Other','student',?)",['09990000001',password_hash('OtherFixture123',PASSWORD_DEFAULT)]);
q('INSERT INTO admin_credentials(user_id,reset_authorized_until) VALUES(2,?)',[time()+3600]);
$_SESSION=['uid'=>2,'csrf'=>'fixture-csrf','captcha'=>'ABCDE'];
$_POST=['csrf'=>'fixture-csrf','captcha'=>'ABCDE','account_password'=>'AccountFixture123','new_password'=>'NewAdminFixture123','password_confirmation'=>'NewAdminFixture123'];
$path='auth/admin-password';
if ($case==='wrong_account_password') $_POST['account_password']='WrongFixture123';
if ($case==='wrong_confirmation') $_POST['password_confirmation']='DifferentFixture123';
if ($case==='weak_password') $_POST['new_password']=$_POST['password_confirmation']='short';
if ($case==='short_unicode_password') $_POST['new_password']=$_POST['password_confirmation']='نمونهa1';
if ($case==='expired_grant') q('UPDATE admin_credentials SET reset_authorized_until=?',[time()-1]);
if ($case==='used_grant') q('UPDATE admin_credentials SET reset_authorized_until=0');
if ($case==='non_admin_account') q("UPDATE users SET role='student' WHERE id=2");
if ($case==='other_account') $_SESSION['uid']=3;
if ($case==='bad_csrf') $_POST['csrf']='wrong';
if ($case==='bad_captcha') $_POST['captcha']='WRONG';
if ($case==='attempts_exhausted') q('UPDATE admin_credentials SET reset_attempts=5');
if ($case==='relogin' || $case==='wrong_admin_password') {
    q('UPDATE admin_credentials SET password_hash=?,password_version=1,reset_authorized_until=0',[password_hash('NewAdminFixture123',PASSWORD_DEFAULT)]);
    $path='auth/admin'; $_POST['password']=$case==='relogin' ? 'NewAdminFixture123' : 'WrongFixture123';
}
if ($case==='old_session' || $case==='current_session') {
    q('UPDATE admin_credentials SET password_version=1');
    $_SESSION['admin_verified_uid']=2;
    $_SESSION['admin_password_version']=$case==='old_session' ? 0 : 1;
    echo json_encode(['role'=>user()['role']]); exit;
}
if ($case==='setup_view' || $case==='normal_view') {
    if ($case==='normal_view') q('UPDATE admin_credentials SET reset_authorized_until=0');
    ob_start(); page_admin_login(); $html=ob_get_clean();
    echo json_encode(['setup'=>str_contains($html,'name="new_password"'),'confirmation'=>str_contains($html,'name="password_confirmation"'),'account_password'=>str_contains($html,'name="account_password"'),'normal'=>str_contains($html,'action="/auth/admin"')]); exit;
}
register_shutdown_function(function() {
    $credential=q('SELECT password_hash,password_version,reset_authorized_until,reset_attempts FROM admin_credentials WHERE user_id=2')->fetch();
    echo "\nRESULT:".json_encode([
        'status'=>http_response_code() ?: 200,
        'password_saved'=>$credential['password_hash']!==null && password_verify('NewAdminFixture123',$credential['password_hash']),
        'account_password_unchanged'=>password_verify('AccountFixture123',(string)q('SELECT password_hash FROM users WHERE id=2')->fetchColumn()),
        'grant_consumed'=>(int)$credential['reset_authorized_until']===0,
        'attempts'=>(int)$credential['reset_attempts'],
        'version'=>(int)$credential['password_version'],
        'verified'=>($_SESSION['admin_verified_uid'] ?? null)===2,
        'session_version'=>(int)($_SESSION['admin_password_version'] ?? 0),
        'other_role'=>q('SELECT role FROM users WHERE id=3')->fetchColumn(),
    ]);
});
handle_auth_post($path);
'''


@unittest.skipUnless(PHP, 'PHP CLI is required')
class AdminPasswordTest(unittest.TestCase):
    def run_case(self, case):
        with tempfile.TemporaryDirectory(dir=ROOT) as directory:
            result = subprocess.run([PHP, '-r', HARNESS, str(ROOT), case, directory], capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('Warning', result.stderr)
        return json.loads(result.stdout.rsplit('RESULT:', 1)[-1])

    def test_authorized_setup_consumes_grant_and_enters_admin(self):
        result = self.run_case('success')
        self.assertEqual(result['status'], 303)
        self.assertTrue(result['password_saved'])
        self.assertTrue(result['account_password_unchanged'])
        self.assertTrue(result['grant_consumed'])
        self.assertTrue(result['verified'])
        self.assertEqual(result['version'], 1)
        self.assertEqual(result['session_version'], 1)
        self.assertEqual(result['other_role'], 'student')

    def test_invalid_submissions_do_not_set_a_password(self):
        for case, status in [('wrong_account_password',303),('wrong_confirmation',303),('weak_password',303),('short_unicode_password',303),('expired_grant',403),('used_grant',403),('non_admin_account',403),('other_account',403),('bad_csrf',419),('bad_captcha',303),('attempts_exhausted',403)]:
            with self.subTest(case=case):
                result = self.run_case(case)
                self.assertEqual(result['status'], status)
                self.assertFalse(result['password_saved'])
                self.assertFalse(result['verified'])
                self.assertTrue(result['account_password_unchanged'])

    def test_incorrect_account_password_uses_persistent_attempt_limit(self):
        self.assertEqual(self.run_case('wrong_account_password')['attempts'], 1)

    def test_new_password_is_used_on_subsequent_admin_login(self):
        result = self.run_case('relogin')
        self.assertTrue(result['verified'])
        self.assertEqual(result['session_version'], 1)
        self.assertFalse(self.run_case('wrong_admin_password')['verified'])

    def test_previous_admin_session_is_invalidated(self):
        self.assertEqual(self.run_case('old_session')['role'], 'student')
        self.assertEqual(self.run_case('current_session')['role'], 'admin')

    def test_setup_fields_are_only_rendered_with_recovery_authorization(self):
        setup = self.run_case('setup_view')
        self.assertTrue(setup['setup'] and setup['confirmation'] and setup['account_password'])
        normal = self.run_case('normal_view')
        self.assertFalse(normal['setup'])
        self.assertTrue(normal['normal'])


if __name__ == '__main__':
    unittest.main()
