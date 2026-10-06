<?php
declare(strict_types=1);
// Test-only router for `php -S`; never deploy. Stages a pending registration so verification can be tested without SMS.
if (getenv('OTP_TEST_ROUTER')!=='1') { http_response_code(404); exit; }
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/__test/pending' || $path==='/__test/auth-state') {
    session_start();
    require_once dirname(__DIR__).'/src/app.php';
    if ($path==='/__test/auth-state') {
        header('Content-Type: application/json');
        echo json_encode(['uid'=>$_SESSION['uid'] ?? null,'current_user_id'=>user()['id'] ?? null,'has_credentials'=>(bool)array_filter(array_keys($_SESSION),fn($key)=>preg_match('/password|otp|api[_-]?key|secret/i',(string)$key))]);
        exit;
    }
    $phone=(string)($_POST['phone'] ?? '');
    $_SESSION['verify_phone']=$phone;
    $_SESSION['verify_name']='کاربر آزمایشی';
    if (isset($_POST['ttl'])) putenv('OTP_TTL_SECONDS='.(string)$_POST['ttl']);
    if (isset($_POST['code'])) store_otp($phone,(string)$_POST['code'],time(),password_hash('Passw0rd1',PASSWORD_DEFAULT));
    elseif ($phone!=='' && !q('SELECT 1 FROM otp_codes WHERE phone=?',[$phone])->fetchColumn()) store_otp($phone,'000000',time(),password_hash('Passw0rd1',PASSWORD_DEFAULT));
    putenv('OTP_TTL_SECONDS');
    echo csrf();
    exit;
}
if ($path!=='/' && is_file(dirname(__DIR__).'/public'.$path)) return false;
require dirname(__DIR__).'/public/index.php';
