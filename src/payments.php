<?php
declare(strict_types=1);

class PaymentError extends RuntimeException {}

function payment_ready(): bool {
    $base=(string)config_value('PAYMENT_BASE_URL','');
    $url=parse_url($base);
    return (bool)config_value('ZARINPAL_MERCHANT_ID') && is_array($url) && ($url['scheme'] ?? '')==='https' && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment']) && in_array($url['path'] ?? '',['','/'],true) && function_exists('curl_init');
}
function payment_api(string $operation,array $payload): array {
    if (!in_array($operation,['request','verify'],true) || !payment_ready()) throw new PaymentError('درگاه هنوز توسط مدیر تنظیم نشده است.');
    $payload['merchant_id']=(string)config_value('ZARINPAL_MERCHANT_ID');
    $curl=curl_init('https://payment.zarinpal.com/pg/v4/payment/'.$operation.'.json');
    $body='';
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int { if (strlen($body)+strlen($chunk)>65536) return 0; $body.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    if ($ok===false || $status<200 || $status>=300) throw new PaymentError('ارتباط با زرین‌پال ناموفق بود؛ وضعیت پرداخت نامشخص است. از تاریخچه دوباره بررسی کنید.');
    $result=json_decode($body,true);
    if (!is_array($result)) throw new PaymentError('پاسخ درگاه معتبر نیست؛ پرداخت تأیید نشده است.');
    return $result;
}
function payment_authority(string $authority): bool { return (bool)preg_match('/^A[a-zA-Z0-9]{35}$/D',$authority); }
function payment_plan_courses(string $plan): array {
    if (!isset(PLANS[$plan]) || $plan==='free' || $plan==='titanium') return [];
    $courses=q("SELECT c.slug,c.title FROM courses c WHERE c.status='published' AND EXISTS (SELECT 1 FROM lessons l WHERE l.course_id=c.id AND l.status='published') ORDER BY c.id")->fetchAll();
    return array_values(array_filter($courses,fn($course)=>can_course($course['slug'],$plan) && !can_course($course['slug'],'free')));
}
function payment_start(array $u): never {
    $selected=(string)($_POST['plan'] ?? ''); $key=(string)($_POST['request_key'] ?? '');
    if (!COMMERCE_ENABLED || !payment_ready()) { flash('درگاه واقعی هنوز تنظیم نشده است؛ هیچ مبلغی دریافت و اشتراکی فعال نشد.','error'); redirect('dashboard/subscription'); }
    if (!isset(PLANS[$selected]) || PLANS[$selected]['price']<=0 || !preg_match('/^[a-f0-9]{32}$/D',$key)) { http_response_code(422); exit('درخواست خرید معتبر نیست.'); }
    if (!payment_plan_courses($selected)) { flash('این پلن فعلاً محتوای پولی منتشرشده یا مزیت اضافهٔ قابل‌فروش ندارد؛ خرید غیرفعال است.','error'); redirect('dashboard/subscription'); }
    $pdo=db(); $pdo->beginTransaction();
    try {
        q('UPDATE users SET name=name WHERE id=?',[$u['id']]);
        $existing=q('SELECT * FROM payment_orders WHERE user_id=? AND request_key=?',[$u['id'],$key])->fetch();
        if ($existing) { $pdo->commit(); if ($existing['authority'] && $existing['status']!=='paid') { header('Location: https://payment.zarinpal.com/pg/StartPay/'.$existing['authority']); exit; } flash('این درخواست قبلاً ثبت شده است؛ تاریخچه را بررسی کنید.'); redirect('dashboard/payment-history'); }
        if ((int)q('SELECT COUNT(*) FROM payment_orders WHERE user_id=? AND created_at>?',[$u['id'],gmdate('Y-m-d H:i:s',time()-60)])->fetchColumn()>=5) { $pdo->commit(); flash('یک دقیقه بعد دوباره تلاش کنید.','error'); redirect('dashboard/subscription'); }
        $amount=(int)PLANS[$selected]['price']*10;
        q('INSERT INTO payment_orders(user_id,plan,amount_rial,request_key,status) VALUES (?,?,?,?,?)',[$u['id'],$selected,$amount,$key,'requesting']);
        $id=(int)$pdo->lastInsertId(); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    try {
        $result=payment_api('request',['amount'=>$amount,'currency'=>'IRR','description'=>'اشتراک ۳۰ روزه '.PLANS[$selected]['name'].' / '.$id,'callback_url'=>rtrim((string)config_value('PAYMENT_BASE_URL'),'/').'/payment/zarinpal/callback','metadata'=>['mobile'=>$u['phone']],'order_id'=>(string)$id]);
        $data=$result['data'] ?? [];
        if (($data['code'] ?? 0)!==100 || !payment_authority((string)($data['authority'] ?? ''))) throw new PaymentError('ساخت پرداخت در زرین‌پال موفق نبود.');
        q("UPDATE payment_orders SET authority=?,status='pending' WHERE id=?",[$data['authority'],$id]);
        header('Location: https://payment.zarinpal.com/pg/StartPay/'.$data['authority']); exit;
    } catch (PaymentError $e) {
        q("UPDATE payment_orders SET status='request_failed' WHERE id=? AND status='requesting'",[$id]);
        flash($e->getMessage(),'error'); redirect('dashboard/payment-history');
    }
}
function payment_grant(int $id,array $verification): bool {
    $code=$verification['code'] ?? 0;
    $ref=(string)($verification['ref_id'] ?? '');
    if (!in_array($code,[100,101],true) || !preg_match('/^[0-9]{1,64}$/D',$ref)) return false;
    $order=q('SELECT * FROM payment_orders WHERE id=?',[$id])->fetch();
    if (!$order) return false;
    $pdo=db(); $pdo->beginTransaction();
    try {
        q('UPDATE users SET name=name WHERE id=?',[$order['user_id']]);
        $order=q('SELECT * FROM payment_orders WHERE id=?',[$id])->fetch();
        if ($order['status']==='paid') { $pdo->commit(); return true; }
        $now=gmdate('Y-m-d H:i:s');
        $end=q("SELECT MAX(expires_at) FROM subscriptions WHERE user_id=? AND plan=? AND status='active' AND expires_at>?",[$order['user_id'],$order['plan'],$now])->fetchColumn();
        $expires=gmdate('Y-m-d H:i:s',max(time(),$end?strtotime($end.' UTC'):0)+30*86400);
        q("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'",[$order['user_id']]);
        q("INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (?,?,'active',?,?)",[$order['user_id'],$order['plan'],$now,$expires]);
        $subscription=(int)$pdo->lastInsertId();
        q("UPDATE payment_orders SET status='paid',ref_id=?,subscription_id=?,paid_at=?,verify_code=? WHERE id=?",[$ref,$subscription,$now,$code,$id]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function payment_verify_order(array $order): bool {
    if ($order['status']==='paid') return true;
    $result=payment_api('verify',['amount'=>(int)$order['amount_rial'],'authority'=>$order['authority']]);
    $data=$result['data'] ?? [];
    if (payment_grant((int)$order['id'],$data)) return true;
    q("UPDATE payment_orders SET verify_code=? WHERE id=? AND status<>'paid'",[(int)($data['code'] ?? $result['errors']['code'] ?? 0),$order['id']]);
    return false;
}
function payment_callback(): never {
    $authority=$_GET['Authority'] ?? '';
    if (!is_string($authority) || !payment_authority($authority)) { http_response_code(400); exit('شناسه پرداخت معتبر نیست.'); }
    $order=q('SELECT * FROM payment_orders WHERE authority=?',[$authority])->fetch();
    if (!$order) { http_response_code(404); exit('پرداخت پیدا نشد.'); }
    try { $paid=payment_verify_order($order); $message=$paid?'پرداخت تأیید و اشتراک فعال شد.':'پرداخت تأیید نشد. در صورت کسر وجه، از تاریخچه بررسی مجدد کنید.'; }
    catch (PaymentError $e) { $message=$e->getMessage(); }
    flash($message,isset($paid)&&$paid?'success':'error'); redirect('dashboard/payment-history');
}
function payment_checkout(): void {
    echo '<section class="panel-card"><h2>خرید اشتراک با زرین‌پال</h2><p>اشتراک ۳۰ روزه؛ مبلغ به تومان است. Nova AI فعلاً به‌زودی است و فعال نیست. تمدید همان پلن به زمان باقی‌مانده اضافه می‌شود؛ تغییر پلن از امروز است و ماندهٔ پلن قبلی منتقل نمی‌شود.</p>';
    if (!payment_ready()) echo '<p role="status">درگاه واقعی هنوز توسط مدیر تنظیم نشده است. پرداخت آزمایشی یا فعال‌سازی خودکار وجود ندارد.</p>';
    foreach (PLANS as $key=>$p) {
        if (!$p['price']) continue;
        $courses=payment_plan_courses($key);
        echo '<h3>'.h($p['name']).'</h3><p>'.h($p['summary']).'</p>';
        if (!$courses) echo '<p>خرید غیرفعال است: محتوای پولی قابل‌دسترسی یا مزیت اضافهٔ فعال برای فروش موجود نیست.</p>';
        else { echo '<ul>'; foreach ($courses as $course) echo '<li><a href="'.h(url('course/'.$course['slug'])).'">'.h($course['title']).'</a></li>'; echo '</ul>'; }
        form_start('payment/start'); echo '<input type="hidden" name="plan" value="'.h($key).'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(16)).'"><button class="btn btn-primary"'.(!payment_ready() || !$courses?' disabled':'').'>خرید '.h($p['name']).' — '.money($p['price']).'</button></form>';
    }
    echo '<a href="/dashboard/payment-history">تاریخچه و بررسی مجدد پرداخت‌ها</a></section>';
}
function payment_history(array $u,bool $admin=false): void {
    $rows=q('SELECT * FROM payment_orders'.($admin?'':' WHERE user_id=?').' ORDER BY id DESC LIMIT 100',$admin?[]:[$u['id']])->fetchAll();
    echo '<section class="panel-card"><h2>تراکنش‌های واقعی زرین‌پال</h2><p>اشتراک‌های دستی یا قدیمی، رسید پرداخت بانکی محسوب نمی‌شوند.</p>';
    if (!$rows) echo '<p>هنوز تراکنشی ثبت نشده است.</p>';
    foreach ($rows as $r) { echo '<article><h3>'.h(PLANS[$r['plan']]['name']).' — '.money(intdiv((int)$r['amount_rial'],10)).'</h3><p>وضعیت: '.h($r['status']).' · پیگیری: '.h($r['ref_id'] ?? '—').'</p>'; if (!$admin && $r['authority'] && $r['status']!=='paid') { form_start('payment/retry'); echo '<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-outline">بررسی مجدد با زرین‌پال</button></form>'; } echo '</article>'; }
    echo '</section>';
}
