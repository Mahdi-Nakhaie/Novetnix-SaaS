<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ini_set('session.cookie_secure','1');
ini_set('display_errors','0');
ini_set('log_errors','1');
session_start();
if (!empty($_SESSION['uid'])) {
    $now=time();
    if ($now-(int)($_SESSION['last_seen'] ?? $now)>1800 || $now-(int)($_SESSION['authenticated_at'] ?? $now)>43200) {
        $_SESSION=[];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen']=$now;
        $_SESSION['authenticated_at'] ??= $now;
    }
}
header('Cache-Control: private, no-store');
$path=trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/','/');
unset($_SESSION['verify_password_hash']);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
$aparatPolicy=preg_match('~^dashboard/course/[a-z0-9-]+$~D',$path) ? ' https://www.aparat.com' : '';
$seoNonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self' 'nonce-".$seoNonce."'".$aparatPolicy."; frame-src 'self'".$aparatPolicy."; font-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
require_once dirname(__DIR__).'/src/app.php';
require_once dirname(__DIR__).'/src/views.php';
require_once dirname(__DIR__).'/src/router.php';
ob_start();
try {
    db();
    $me=user();
    if ($_SERVER['REQUEST_METHOD']==='GET' && !$me && (array_key_exists('source',$_GET) || array_key_exists('campaign',$_GET))) {
        $_SESSION['registration_source']=acquisition_source($_GET['source'] ?? null);
        $_SESSION['registration_campaign']=acquisition_campaign($_GET['campaign'] ?? null);
    }
    $plan=$me ? plan_for($me) : 'free';
    if ($_SERVER['REQUEST_METHOD']==='POST') handle_post($path);
    route();
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Noventix request failed: '.get_class($e));
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'سرویس موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.';
}
