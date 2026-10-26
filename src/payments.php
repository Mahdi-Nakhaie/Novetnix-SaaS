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
    if (($order['plan'] ?? '')==='cart') return payment_grant_cart($order,$data);
    if (payment_grant((int)$order['id'],$data)) return true;
    q("UPDATE payment_orders SET verify_code=? WHERE id=? AND status<>'paid'",[(int)($data['code'] ?? $result['errors']['code'] ?? 0),$order['id']]);
    return false;
}
function payment_callback(): never {
    $authority=$_GET['Authority'] ?? '';
    if (!is_string($authority) || !payment_authority($authority)) { http_response_code(400); exit('شناسه پرداخت معتبر نیست.'); }
    $order=q('SELECT * FROM payment_orders WHERE authority=?',[$authority])->fetch();
    if (!$order) { http_response_code(404); exit('پرداخت پیدا نشد.'); }
    if (strtoupper((string)($_GET['Status'] ?? ''))!=='OK') { q("UPDATE payment_orders SET status='cancelled' WHERE id=? AND status<>'paid'",[$order['id']]); flash('پرداخت لغو شد و مبلغی به حساب شما اضافه نشد.','error'); redirect('cart'); }
    try { $paid=payment_verify_order($order); $message=$paid?'پرداخت تأیید شد و دسترسی خریداری‌شده فعال شد.':'پرداخت تأیید نشد. اگر مبلغی کسر شده، تاریخچه پرداخت را بررسی کنید.'; }
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
    foreach ($rows as $r) { $label=($r['plan'] ?? '')==='cart' ? 'سبد خرید' : (PLANS[$r['plan']]['name'] ?? 'پرداخت'); echo '<article><h3>'.h($label).' — '.money(intdiv((int)$r['amount_rial'],10)).'</h3><p>وضعیت: '.h($r['status']).' · پیگیری: '.h($r['ref_id'] ?? '—').'</p>'; if (!$admin && $r['authority'] && $r['status']!=='paid') { form_start('payment/retry'); echo '<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-outline">بررسی مجدد با زرین‌پال</button></form>'; } echo '</article>'; }
    echo '</section>';
}

function course_product_price(array $course): int {
    return match ($course['level'] ?? '') {
        'مقدماتی' => 490000,
        'متوسط' => 990000,
        default => 990000,
    };
}
function cart_catalog(): array {
    $items=[];
    foreach (q("SELECT slug,title,description FROM courses WHERE status='published' AND slug<>? ORDER BY id",[FREE_COURSE_SLUG])->fetchAll() as $course) {
        $items['course:'.$course['slug']]=['key'=>'course:'.$course['slug'],'type'=>'course','slug'=>$course['slug'],'title'=>$course['title'],'description'=>$course['description'],'price'=>course_product_price($course)];
    }
    foreach (PLANS as $key=>$plan) if ($key!=='free' && $plan['price']>0 && payment_plan_courses($key)!==[]) $items['plan:'.$key]=['key'=>'plan:'.$key,'type'=>'plan','slug'=>$key,'title'=>'پلن '.$plan['name'],'description'=>$plan['summary'],'price'=>(int)$plan['price']];
    return $items;
}
function cart_items(): array {
    $catalog=cart_catalog(); $items=[];
    foreach ((array)($_SESSION['cart'] ?? []) as $key=>$quantity) if (isset($catalog[$key]) && (int)$quantity===1) $items[$key]=$catalog[$key];
    return $items;
}
function cart_total(array $items): int { return array_sum(array_column($items,'price')); }
function cart_add(string $key): never {
    $catalog=cart_catalog();
    if (!isset($catalog[$key])) { flash('این محصول دیگر در دسترس نیست.','error'); redirect('cart'); }
    $_SESSION['cart']=$_SESSION['cart'] ?? [];
    if (count($_SESSION['cart'])>=20 && !isset($_SESSION['cart'][$key])) { flash('سبد خرید به سقف مجاز رسیده است.','error'); redirect('cart'); }
    $_SESSION['cart'][$key]=1; flash('محصول به سبد خرید اضافه شد.'); redirect('cart');
}
function cart_remove(string $key): never { unset($_SESSION['cart'][$key]); flash('محصول از سبد خرید حذف شد.'); redirect('cart'); }
function cart_clear(): never { unset($_SESSION['cart']); flash('سبد خرید خالی شد.'); redirect('cart'); }
function payment_cart_start(array $u): never {
    if (!COMMERCE_ENABLED || !payment_ready()) { flash('درگاه پرداخت هنوز تنظیم نشده است؛ مبلغی دریافت نشد.','error'); redirect('cart'); }
    $items=cart_items();
    if (!$items) { flash('سبد خرید شما خالی است.','error'); redirect('cart'); }
    $planItems=array_filter($items,fn(array $item): bool=>$item['type']==='plan');
    if (count($planItems)>1) { flash('در هر سفارش فقط یک پلن می‌تواند انتخاب شود.','error'); redirect('cart'); }
    $key=bin2hex(random_bytes(16)); $amount=cart_total($items); $json=json_encode(array_values($items),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $pdo=db(); $pdo->beginTransaction();
    try {
        q('INSERT INTO payment_orders(user_id,plan,amount_rial,amount_toman,request_key,status,cart_json) VALUES (?,?,?,?,?,?,?)',[$u['id'],'cart',$amount*10,$amount,$key,'requesting',$json]);
        $id=(int)$pdo->lastInsertId(); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    try {
        $result=payment_api('request',['amount'=>$amount*10,'currency'=>'IRR','description'=>'خرید سبد Noventix / '.$id,'callback_url'=>rtrim((string)config_value('PAYMENT_BASE_URL'),'/').'/payment/zarinpal/callback','metadata'=>['mobile'=>$u['phone']],'order_id'=>(string)$id]);
        $data=$result['data'] ?? [];
        if (($data['code'] ?? 0)!==100 || !payment_authority((string)($data['authority'] ?? ''))) throw new PaymentError('ساخت پرداخت در زرین‌پال موفق نبود.');
        q("UPDATE payment_orders SET authority=?,status='pending' WHERE id=?",[$data['authority'],$id]);
        header('Location: https://payment.zarinpal.com/pg/StartPay/'.$data['authority']); exit;
    } catch (PaymentError $e) { q("UPDATE payment_orders SET status='request_failed' WHERE id=? AND status='requesting'",[$id]); flash($e->getMessage(),'error'); redirect('cart'); }
}
function payment_grant_cart(array $order,array $verification): bool {
    if (!in_array($verification['code'] ?? 0,[100,101],true) || !preg_match('/^[0-9]{1,64}$/D',(string)($verification['ref_id'] ?? ''))) return false;
    $items=json_decode((string)($order['cart_json'] ?? ''),true);
    if (!is_array($items) || !$items) return false;
    $pdo=db(); $pdo->beginTransaction();
    try {
        $existing=q('SELECT status FROM payment_orders WHERE id=?',[$order['id']])->fetchColumn();
        if ($existing==='paid') { $pdo->commit(); return true; }
        $plan=null;
        foreach ($items as $item) {
            if (($item['type'] ?? '')==='course') {
                if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$order['user_id'],$item['slug']]);
                else q('INSERT OR IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$order['user_id'],$item['slug']]);
            }
            if (($item['type'] ?? '')==='plan') $plan=$item['slug'];
        }
        $subscriptionId=null;
        if ($plan && isset(PLANS[$plan])) {
            $now=gmdate('Y-m-d H:i:s'); $expires=gmdate('Y-m-d H:i:s',time()+30*86400);
            q("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'",[$order['user_id']]);
            q("INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (?,?,'active',?,?)",[$order['user_id'],$plan,$now,$expires]);
            $subscriptionId=(int)$pdo->lastInsertId();
        }
        q("UPDATE payment_orders SET status='paid',ref_id=?,subscription_id=?,paid_at=?,verify_code=? WHERE id=?",[(string)$verification['ref_id'],$subscriptionId,gmdate('Y-m-d H:i:s'),(int)$verification['code'],$order['id']]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function cart_page(): void {
    $items=cart_items(); $catalog=cart_catalog(); $total=cart_total($items); $available=array_values(array_filter($catalog,fn(array $item): bool=>!isset($items[$item['key']])));
    echo '<main class="cart-page container section"><div class="cart-heading"><div><span class="eyebrow">سبد انتخاب‌های تو</span><h1>قبل از شروع، انتخابت را مرور کن.</h1><p>دوره‌ها و پلن‌های انتخابی‌ات را یک‌جا ببین. مبلغ نهایی پیش از انتقال به زرین‌پال در سمت سرور محاسبه می‌شود.</p></div><div class="cart-heading-mark" aria-hidden="true"><span>۰۱</span><strong>مرور<br>و پرداخت</strong></div></div>';
    if (!$items) {
        echo '<section class="cart-empty" aria-labelledby="cart-empty-title"><div class="cart-empty-mark" aria-hidden="true">＋</div><span class="eyebrow">هنوز چیزی انتخاب نشده</span><h2 id="cart-empty-title">سبدت آمادهٔ اولین انتخاب توست.</h2><p>یک دوره یا پلن مناسب مسیرت را انتخاب کن و یادگیری را هدفمندتر ادامه بده.</p><div class="cart-empty-actions">'; linkto('course/'.FREE_COURSE_SLUG,'شروع با دورهٔ رایگان','btn btn-primary'); linkto('articles','دیدن مسیرهای یادگیری','btn btn-outline'); echo '</div></section>';
    } else {
        echo '<div class="cart-layout"><section class="cart-items-card" aria-labelledby="cart-items-title"><div class="cart-card-heading"><div><span class="eyebrow">انتخاب‌های تو</span><h2 id="cart-items-title">اقلام سبد <span>('.fa(count($items)).')</span></h2></div>'; linkto('cart/clear','خالی‌کردن سبد','cart-clear-link'); echo '</div><div class="cart-items">';
        foreach ($items as $item) {
            $kind=$item['type']==='plan'?'پلن یادگیری':'دورهٔ آموزشی';
            echo '<article class="cart-product"><div class="cart-product-art cart-product-art-'.h($item['type']).'" aria-hidden="true"><span>'.($item['type']==='plan'?'✦':'⌘').'</span><small>'.($item['type']==='plan'?'PLAN':'COURSE').'</small></div><div class="cart-product-main"><div class="cart-product-meta"><span class="pill">'.h($kind).'</span><span class="cart-available">دسترسی فوری</span></div><h3>'.h($item['title']).'</h3><p>'.h($item['description']).'</p><div class="cart-product-foot"><strong>'.money((int)$item['price']).'</strong><form method="post" action="'.h(url('cart/remove')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="key" value="'.h($item['key']).'"><button class="cart-remove" type="submit">حذف از سبد</button></form></div></div></article>';
        }
        echo '</div><div class="cart-benefits"><div><span aria-hidden="true">✓</span><p><strong>پرداخت امن</strong><small>پرداخت از مسیر زرین‌پال</small></p></div><div><span aria-hidden="true">↗</span><p><strong>شروع سریع</strong><small>فعال‌سازی پس از پرداخت</small></p></div><div><span aria-hidden="true">∞</span><p><strong>بدون هزینهٔ ارسال</strong><small>تمام محصولات دیجیتال‌اند</small></p></div></div></section><aside class="cart-summary" aria-labelledby="cart-summary-title"><div class="summary-kicker"><span class="eyebrow">خلاصهٔ سفارش</span><span class="summary-secure">قفل امن</span></div><h2 id="cart-summary-title">پرداخت تو</h2><div class="summary-lines"><div><span>جمع اقلام</span><strong>'.money($total).'</strong></div><div><span>تخفیف</span><strong class="summary-muted">فعلاً ندارد</strong></div><div><span>هزینهٔ ارسال</span><strong class="summary-free">رایگان</strong></div></div><div class="summary-total"><span>مبلغ نهایی</span><strong>'.money($total).'</strong></div>';
        if (count(array_filter($items,fn(array $item): bool=>$item['type']==='plan'))<2) { form_start('cart/checkout','cart-checkout-form'); echo '<button class="btn btn-primary cart-checkout" type="submit">ادامه و پرداخت با زرین‌پال <span aria-hidden="true">←</span></button></form>'; }
        echo '<p class="summary-note">با ادامه، به درگاه امن زرین‌پال منتقل می‌شوی.</p></aside></div>';
    }
    if ($available) { echo '<section class="cart-recommendations" aria-labelledby="cart-recommendations-title"><div class="cart-card-heading"><div><span class="eyebrow">برای مسیر بعدی</span><h2 id="cart-recommendations-title">شاید این‌ها هم به کارت بیاید</h2></div><span class="cart-scroll-hint">برای دیدن بیشتر بکشید ←</span></div><div class="cart-recommendation-track">'; foreach (array_slice($available,0,4) as $item) { echo '<article class="cart-recommendation"><div class="cart-recommendation-art" aria-hidden="true">'.($item['type']==='plan'?'✦':'⌘').'</div><div><span class="pill">'.h($item['type']==='plan'?'پلن':'دوره').'</span><h3>'.h($item['title']).'</h3><strong>'.money((int)$item['price']).'</strong><form method="post" action="'.h(url('cart/add')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="key" value="'.h($item['key']).'"><button class="btn btn-outline" type="submit">افزودن به سبد</button></form></div></article>'; } echo '</div></section>'; }
    echo '</main>';
}
