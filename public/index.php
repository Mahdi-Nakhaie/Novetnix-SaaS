<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ini_set('session.cookie_secure','1');
session_start();
$path=trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/','/');
unset($_SESSION['verify_password_hash']);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
$aparatPolicy=preg_match('~^dashboard/course/[a-z0-9-]+$~D',$path) ? ' https://www.aparat.com' : '';
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'".$aparatPolicy."; frame-src 'self'".$aparatPolicy."; font-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
require_once dirname(__DIR__).'/src/app.php';
require_once dirname(__DIR__).'/src/views.php';
require_once dirname(__DIR__).'/src/router.php';
try { db(); } catch (Throwable $e) { http_response_code(503); exit('پایگاه‌داده در دسترس نیست. تنظیمات سرور را بررسی کنید.'); }
$me=user();
$plan=$me ? plan_for($me) : 'free';
if ($_SERVER['REQUEST_METHOD']==='POST') handle_post($path);
route();
