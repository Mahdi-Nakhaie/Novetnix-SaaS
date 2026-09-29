<?php
declare(strict_types=1);
require_once __DIR__.'/data.php';

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $dsn = getenv('DB_DSN') ?: 'sqlite:'.dirname(__DIR__).'/storage/noventix.sqlite';
    if (str_starts_with($dsn,'sqlite:')) {
        $dir=dirname(substr($dsn,7));
        if (!is_dir($dir)) mkdir($dir,0750,true);
    }
    $db = new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec(file_get_contents(__DIR__.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? '/schema.mysql.sql' : '/schema.sqlite.sql')));
    return $db;
}
function q(string $sql, array $params=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($params); return $s; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fa(int|string $v): string { return strtr((string)$v,'0123456789','۰۱۲۳۴۵۶۷۸۹'); }
function money(int $v): string { return $v ? fa(number_format($v)).' تومان' : 'رایگان'; }
function url(string $path=''): string { return '/'.ltrim($path,'/'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function validate_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('درخواست نامعتبر است. صفحه را دوباره بارگذاری کنید.'); } }
function redirect(string $path): never { header('Location: '.url($path),true,303); exit; }
function flash(string $message, string $kind='success'): void { $_SESSION['flash']=['text'=>$message,'kind'=>$kind]; }
function user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u=q('SELECT id,phone,name,role FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch();
    return $u ?: null;
}
function require_user(): array { $u=user(); if (!$u) redirect('login?next='.rawurlencode(trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/','/'))); return $u; }
function plan_for(array $u): string {
    $row=q("SELECT plan FROM subscriptions WHERE user_id=? AND status='active' AND starts_at<=? AND expires_at>? ORDER BY expires_at DESC LIMIT 1",[$u['id'],gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')])->fetch();
    return $row && isset(PLANS[$row['plan']]) ? $row['plan'] : 'free';
}
function can_course(string $slug, string $plan): bool {
    $need=['python-foundations'=>0,'data-analysis'=>1,'ml-basics'=>1,'nlp-practice'=>2,'deep-learning'=>3,'api-deployment'=>3];
    return array_search($plan,array_keys(PLANS),true) >= ($need[$slug] ?? 99);
}
function normalized_phone(string $raw): ?string {
    $raw=strtr($raw,'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789');
    $raw=preg_replace('/[\s-]+/u','',$raw);
    $raw=preg_replace('/^(\+98|98)/','0',$raw);
    return preg_match('/^09[0-9]{9}$/D',$raw) ? $raw : null;
}
function send_sms(string $phone, string $code): bool {
    $endpoint=getenv('SMS_API_URL');
    if (!$endpoint || !getenv('SMS_API_TOKEN') || !preg_match('~^https://~i',$endpoint)) return false;
    $ch=curl_init($endpoint);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>$code],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.getenv('SMS_API_TOKEN')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false]);
    $result=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $result!==false && $status>=200 && $status<300;
}
function handle_post(string $path): void {
    validate_csrf();
    if ($path==='auth/request') {
        $phone=normalized_phone((string)($_POST['phone'] ?? ''));
        if (!$phone) { flash('شماره موبایل معتبر وارد کنید.','error'); redirect('login'); }
        $count=(int)($_SESSION['otp_requests'] ?? 0);
        if ($count>=8) { flash('تعداد درخواست‌ها بیش از حد مجاز است. بعداً دوباره تلاش کنید.','error'); redirect('login'); }
        $previous=q('SELECT sent_at FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if ($previous && time()-(int)$previous['sent_at']<90) { flash('برای درخواست دوباره کمی صبر کنید.','error'); redirect('login'); }
        $code=(string)random_int(100000,999999);
        if (!send_sms($phone,$code)) { flash('ارسال پیامک فعلاً فعال نیست. تنظیمات سرویس پیامک باید توسط مدیر تکمیل شود.','error'); redirect('login'); }
        q('DELETE FROM otp_codes WHERE phone=?',[$phone]);
        q('INSERT INTO otp_codes(phone,code_hash,expires_at,attempts,sent_at) VALUES (?,?,?,?,?)',[$phone,password_hash($code,PASSWORD_DEFAULT),time()+300,0,time()]);
        $_SESSION['otp_requests']=$count+1;
        $_SESSION['verify_phone']=$phone;
        flash('کد تأیید ارسال شد. کد تا ۵ دقیقه معتبر است.'); redirect('verify');
    }
    if ($path==='auth/verify') {
        $phone=$_SESSION['verify_phone'] ?? '';
        $code=strtr((string)($_POST['code'] ?? ''),'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789');
        $entry=q('SELECT * FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if (!$entry || time()>(int)$entry['expires_at'] || (int)$entry['attempts']>=5) { flash('کد منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        q('UPDATE otp_codes SET attempts=attempts+1 WHERE phone=?',[$phone]);
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$entry['code_hash'])) { flash('کد واردشده صحیح نیست.','error'); redirect('verify'); }
        q('DELETE FROM otp_codes WHERE phone=?',[$phone]);
        $u=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch();
        if (!$u) { q('INSERT INTO users(phone) VALUES (?)',[$phone]); $u=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch(); }
        session_regenerate_id(true); $_SESSION['uid']=$u['id']; unset($_SESSION['verify_phone']);
        flash('خوش آمدید!'); redirect('dashboard');
    }
    if ($path==='logout') { $_SESSION=[]; session_regenerate_id(true); flash('از حساب خود خارج شدید.'); redirect(''); }
    if ($path==='contact/send') {
        $name=trim((string)($_POST['name'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $message=trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($message)<10 || mb_strlen($message)>3000) { flash('نام، ایمیل و پیام معتبر وارد کنید.','error'); redirect('contact'); }
        q('INSERT INTO contact_messages(name,email,message) VALUES (?,?,?)',[$name,$email,$message]); flash('پیام شما ثبت شد.'); redirect('contact');
    }
    $u=require_user(); $plan=plan_for($u);
    if ($path==='profile/save') {
        $name=trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100) { flash('نام باید بین ۲ تا ۱۰۰ نویسه باشد.','error'); redirect('dashboard/profile'); }
        q('UPDATE users SET name=? WHERE id=?',[$name,$u['id']]); flash('پروفایل ذخیره شد.'); redirect('dashboard/profile');
    }
    if ($path==='courses/enroll') {
        $slug=(string)($_POST['slug'] ?? '');
        if (!isset(COURSES[$slug])) { http_response_code(404); exit; }
        if (!can_course($slug,$plan)) { flash('برای این مقاله به اشتراک بالاتر نیاز دارید.','error'); redirect('pricing'); }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$u['id'],$slug]);
        else q('INSERT OR IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$u['id'],$slug]);
        flash('مقاله به مسیر یادگیری شما اضافه شد.'); redirect('dashboard/learning');
    }
    if ($path==='projects/start') {
        $index=filter_var($_POST['index'] ?? '',FILTER_VALIDATE_INT);
        if ($index===false || $index<0 || !isset(PROJECTS[$index])) { http_response_code(404); exit; }
        $limit=PLANS[$plan]['projects'];
        if ($limit===0) { flash('برای شروع پروژه، پلن برنزی یا بالاتر را انتخاب کنید.','error'); redirect('pricing'); }
        $exists=q('SELECT id FROM projects WHERE user_id=? AND catalog_index=?',[$u['id'],$index])->fetch();
        if (!$exists) {
            $count=(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$u['id']])->fetchColumn();
            if ($limit!==-1 && $count >= $limit) { flash('ظرفیت پروژه‌های فعال پلن شما تکمیل شده است.','error'); redirect('pricing'); }
            q('INSERT INTO projects(user_id,catalog_index) VALUES (?,?)',[$u['id'],$index]);
        }
        flash('پروژه به میزکار شما اضافه شد.'); redirect('dashboard/projects');
    }
    if ($path==='community/create' || $path==='community/reply') {
        $body=trim((string)($_POST['body'] ?? ''));
        if (mb_strlen($body)<10 || mb_strlen($body)>5000) { flash('متن باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.','error'); redirect('community'); }
        if ($path==='community/create') {
            $title=trim((string)($_POST['title'] ?? ''));
            if (mb_strlen($title)<5 || mb_strlen($title)>180) { flash('عنوان باید بین ۵ تا ۱۸۰ نویسه باشد.','error'); redirect('community'); }
            q('INSERT INTO community_posts(user_id,title,body) VALUES (?,?,?)',[$u['id'],$title,$body]);
        } else {
            $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT);
            if (!$id || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { http_response_code(404); exit; }
            q('INSERT INTO community_replies(post_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],$body]);
        }
        flash('پیام شما در انجمن ثبت شد.'); redirect('community');
    }
    if ($path==='admin/activate' && $u['role']==='admin') {
        $phone=normalized_phone((string)($_POST['phone'] ?? '')); $selected=(string)($_POST['plan'] ?? '');
        if (!$phone || !isset(PLANS[$selected]) || $selected==='free') { flash('شماره و پلن معتبر وارد کنید.','error'); redirect('dashboard/subscriptions'); }
        $target=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch();
        if (!$target) { flash('کاربر باید ابتدا با شماره خود ثبت‌نام کرده باشد.','error'); redirect('dashboard/subscriptions'); }
        q("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'",[$target['id']]);
        q('INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (?,?,\'active\',?,?)',[$target['id'],$selected,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+30*86400)]);
        flash('اشتراک ۳۰ روزه فعال شد.'); redirect('dashboard/subscriptions');
    }
    http_response_code(404); exit('صفحه پیدا نشد.');
}
