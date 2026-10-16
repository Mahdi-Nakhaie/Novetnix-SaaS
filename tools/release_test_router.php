<?php
declare(strict_types=1);
if (getenv('NOVENTIX_RELEASE_TEST')!=='1' || PHP_SAPI!=='cli-server') { http_response_code(404); exit; }
if (!in_array('curl_exec',explode(',',(string)ini_get('disable_functions')),true)) { http_response_code(503); exit('Test transport must replace cURL.'); }
if (!function_exists('curl_exec')) {
function curl_init(?string $url=null): object { return (object)['url'=>$url,'options'=>[]]; }
function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
function curl_setopt(object $handle,int $option,mixed $value): bool { $handle->options[$option]=$value; return true; }
function curl_exec(object $handle): string|false {
    if ($handle->url!=='https://api.sms.ir/v1/send/verify') return false;
    $payload=json_decode($handle->options[CURLOPT_POSTFIELDS] ?? '',true);
    if (!is_array($payload) || !preg_match('/^09990000[0-9]{3}$/D',$payload['mobile'] ?? '')) return false;
    $_SESSION['test_delivery']=$payload['parameters'][0]['value'];
    return '{"status":1}';
}
function curl_getinfo(object $handle,int $option=0): mixed { return 200; }
function curl_close(object $handle): void {}
}
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/__fixture/state' || $path==='/__fixture/expire') {
    session_start();
    if ($path==='/__fixture/expire') { $_SESSION['last_seen']=time()-1801; echo 'expired'; exit; }
    header('Content-Type: application/json');
    echo json_encode(['delivery'=>$_SESSION['test_delivery'] ?? null,'captcha'=>$_SESSION['captcha_answer'] ?? $_SESSION['captcha'] ?? null,'uid'=>$_SESSION['uid'] ?? null]);
    exit;
}
if ($path!=='/' && is_file(dirname(__DIR__).'/public'.$path)) return false;
require dirname(__DIR__).'/public/index.php';
