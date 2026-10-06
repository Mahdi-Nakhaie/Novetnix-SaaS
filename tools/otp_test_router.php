<?php
declare(strict_types=1);
// Test-only router for `php -S`; never deploy. Stages a pending registration so verification can be tested without SMS.
if (getenv('OTP_TEST_ROUTER')!=='1') { http_response_code(404); exit; }
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/__test/pending') {
    session_start();
    require_once dirname(__DIR__).'/src/app.php';
    $phone=(string)($_POST['phone'] ?? '');
    $_SESSION['verify_phone']=$phone;
    $_SESSION['verify_name']='کاربر آزمایشی';
    $_SESSION['verify_password_hash']=password_hash('Passw0rd1',PASSWORD_DEFAULT);
    if (isset($_POST['ttl'])) putenv('OTP_TTL_SECONDS='.(string)$_POST['ttl']);
    if (isset($_POST['code'])) store_otp($phone,(string)$_POST['code'],time());
    putenv('OTP_TTL_SECONDS');
    echo csrf();
    exit;
}
if ($path!=='/' && is_file(dirname(__DIR__).'/public'.$path)) return false;
require dirname(__DIR__).'/public/index.php';
