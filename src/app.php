<?php
declare(strict_types=1);
require_once __DIR__.'/data.php';
require_once dirname(__DIR__).'/database/connection.php';
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fa(int|string $v): string { return strtr((string)$v,'0123456789','۰۱۲۳۴۵۶۷۸۹'); }
function money(int $v): string { return $v ? fa(number_format($v)).' تومان' : 'رایگان'; }
function url(string $path=''): string { return '/'.ltrim($path,'/'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function captcha_code(): string {
    if (empty($_SESSION['captcha'])) $_SESSION['captcha']=strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,5));
    return $_SESSION['captcha'];
}
function validate_captcha(): bool {
    $given=strtoupper(trim((string)($_POST['captcha'] ?? '')));
    $expected=(string)($_SESSION['captcha'] ?? '');
    unset($_SESSION['captcha']);
    return $expected!=='' && hash_equals($expected,$given);
}
function validate_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('درخواست نامعتبر است. صفحه را دوباره بارگذاری کنید.'); } }
function redirect(string $path): never { header('Location: '.url($path),true,303); exit; }
function flash(string $message, string $kind='success'): void { $_SESSION['flash']=['text'=>$message,'kind'=>$kind]; }
function user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u=q('SELECT id,phone,name,role FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch();
    if ($u && $u['role']==='admin' && (!hash_equals((string)config_value('ADMIN_PHONE'), $u['phone']) || ($_SESSION['admin_verified_uid'] ?? null)!==(int)$u['id'])) $u['role']='student';
    return $u ?: null;
}
function require_user(): array {
    $u=user();
    if (!$u || trim($u['name'])==='') {
        unset($_SESSION['uid'],$_SESSION['admin_verified_uid']);
        redirect('login?next='.rawurlencode(trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/','/')));
    }
    return $u;
}
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
    $endpoint=config_value('SMS_API_URL');
    if (!$endpoint || !config_value('SMS_API_TOKEN') || !preg_match('~^https://~i',$endpoint)) return false;
    $ch=curl_init($endpoint);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>$code],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.config_value('SMS_API_TOKEN')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false]);
    $result=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $result!==false && $status>=200 && $status<300;
}
function nova_answer(array $messages): ?string {
    $key=config_value('AVALAI_API_KEY');
    if (!$key || !function_exists('curl_init')) return null;
    $ch=curl_init('https://api.avalai.ir/v1/chat/completions');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>35,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>config_value('AVALAI_MODEL') ?: 'gpt-6-astra','messages'=>$messages,'max_tokens'=>700],JSON_THROW_ON_ERROR),
    ]);
    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body===false || $status<200 || $status>=300 || strlen($body)>1048576) return null;
    $data=json_decode($body,true);
    $answer=$data['choices'][0]['message']['content'] ?? null;
    return is_string($answer) && trim($answer)!=='' ? mb_substr(trim($answer),0,6000) : null;
}
function handle_post(string $path): void {
    validate_csrf();
    if ($path==='auth/request') {
        $phone=normalized_phone((string)($_POST['phone'] ?? ''));
        $first=trim((string)($_POST['first_name'] ?? ''));
        $last=trim((string)($_POST['last_name'] ?? ''));
        $password=(string)($_POST['password'] ?? '');
        if (mb_strlen($first)<2 || mb_strlen($first)>50 || mb_strlen($last)<2 || mb_strlen($last)>50) { flash('نام و نام خانوادگی معتبر وارد کنید.','error'); redirect('login'); }
        if (!$phone) { flash('شماره موبایل معتبر وارد کنید.','error'); redirect('login'); }
        if (strlen($password)<8 || strlen($password)>72) { flash('رمز عبور باید بین ۸ تا ۷۲ نویسه باشد.','error'); redirect('login'); }
        if (!preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password)) { flash('رمز عبور باید دست‌کم یک حرف انگلیسی و یک عدد داشته باشد.','error'); redirect('login'); }
        $count=(int)($_SESSION['otp_requests'] ?? 0);
        if ($count>=8) { flash('تعداد درخواست‌ها بیش از حد مجاز است. بعداً دوباره تلاش کنید.','error'); redirect('login'); }
        $previous=q('SELECT created_at FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if ($previous && time()-(int)$previous['created_at']<90) { flash('برای درخواست دوباره کمی صبر کنید.','error'); redirect('login'); }
        $code=(string)random_int(100000,999999);
        if (!send_sms($phone,$code)) { flash('ارسال پیامک فعلاً فعال نیست. تنظیمات سرویس پیامک باید توسط مدیر تکمیل شود.','error'); redirect('login'); }
        q('DELETE FROM otp_codes WHERE phone=?',[$phone]);
        q('INSERT INTO otp_codes(phone,otp_hash,expires_at,attempts,created_at) VALUES (?,?,?,?,?)',[$phone,password_hash($code,PASSWORD_DEFAULT),time()+300,0,time()]);
        $_SESSION['otp_requests']=$count+1;
        $_SESSION['verify_phone']=$phone;
        $_SESSION['verify_name']=$first.' '.$last;
        $_SESSION['verify_password_hash']=password_hash($password,PASSWORD_DEFAULT);
        flash('کد تأیید ارسال شد. کد تا ۵ دقیقه معتبر است.'); redirect('verify');
    }
    if ($path==='auth/login') {
        if (!validate_captcha()) { flash('کد کپچا صحیح نیست.','error'); redirect('login'); }
        $phone=normalized_phone((string)($_POST['phone'] ?? ''));
        $password=(string)($_POST['password'] ?? '');
        $u=$phone ? q('SELECT id,password_hash FROM users WHERE phone=?',[$phone])->fetch() : false;
        if (!$u || !password_verify($password,(string)$u['password_hash'])) { flash('شماره موبایل یا رمز عبور صحیح نیست.','error'); redirect('login'); }
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; unset($_SESSION['admin_verified_uid']);
        if ($phone===(config_value('ADMIN_PHONE') ?: '')) redirect('admin-login');
        flash('خوش آمدید!'); redirect('dashboard');
    }
    if ($path==='auth/verify') {
        $phone=$_SESSION['verify_phone'] ?? '';
        $code=strtr((string)($_POST['code'] ?? ''),'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789');
        $entry=q('SELECT * FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if (!$entry || $entry['used_at']!==null || time()>(int)$entry['expires_at'] || (int)$entry['attempts']>=5) { flash('کد منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        q('UPDATE otp_codes SET attempts=attempts+1 WHERE phone=?',[$phone]);
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$entry['otp_hash'])) { flash('کد واردشده صحیح نیست.','error'); redirect('verify'); }
        $used=q('UPDATE otp_codes SET used_at=? WHERE phone=? AND used_at IS NULL AND expires_at>=? AND attempts<=5', [time(),$phone,time()]);
        if ($used->rowCount()!==1) { flash('کد منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        $u=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch();
        $name=(string)($_SESSION['verify_name'] ?? '');
        $passwordHash=(string)($_SESSION['verify_password_hash'] ?? '');
        if (!$u) { q('INSERT INTO users(phone,name,password_hash) VALUES (?,?,?)',[$phone,$name,$passwordHash]); $u=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch(); }
        else q('UPDATE users SET name=?, password_hash=CASE WHEN password_hash=\'\' THEN ? ELSE password_hash END WHERE id=?',[$name,$passwordHash,$u['id']]);
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['verify_password_hash'],$_SESSION['admin_verified_uid']);
        if ($phone!=='' && $phone===(config_value('ADMIN_PHONE') ?: '')) redirect('admin-login');
        flash('خوش آمدید!'); redirect('dashboard');
    }
    if ($path==='auth/admin') {
        $u=user();
        if (!$u || $u['phone']!==config_value('ADMIN_PHONE')) { http_response_code(403); exit('دسترسی مجاز نیست.'); }
        if (!validate_captcha()) { flash('کد کپچا صحیح نیست.','error'); redirect('admin-login'); }
        $attempts=(int)($_SESSION['admin_attempts'] ?? 0);
        $hash=config_value('ADMIN_PASSWORD_HASH') ?: '';
        if ($attempts>=5) { http_response_code(429); exit('تعداد تلاش‌ها بیش از حد مجاز است. دوباره وارد شوید.'); }
        $_SESSION['admin_attempts']=$attempts+1;
        if ($hash==='' || !password_verify((string)($_POST['password'] ?? ''),$hash)) { flash('رمز مدیریت صحیح نیست.','error'); redirect('admin-login'); }
        q("UPDATE users SET role='admin' WHERE id=?",[$u['id']]);
        session_regenerate_id(true); $_SESSION['admin_verified_uid']=(int)$u['id']; unset($_SESSION['admin_attempts']);
        redirect('dashboard');
    }
    if ($path==='logout') { $_SESSION=[]; session_regenerate_id(true); flash('از حساب خود خارج شدید.'); redirect(''); }
    if ($path==='contact/send') {
        $name=trim((string)($_POST['name'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $message=trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($message)<10 || mb_strlen($message)>3000) { flash('نام، ایمیل و پیام معتبر وارد کنید.','error'); redirect('contact'); }
        q('INSERT INTO contact_messages(name,email,message) VALUES (?,?,?)',[$name,$email,$message]); flash('پیام شما ثبت شد.'); redirect('contact');
    }
    $u=require_user(); $plan=plan_for($u);
    if ($path==='nova/ask') {
        $question=trim((string)($_POST['question'] ?? ''));
        if (mb_strlen($question)<10 || mb_strlen($question)>2000) { flash('سؤال باید بین ۱۰ تا ۲۰۰۰ نویسه باشد.','error'); redirect('dashboard/nova'); }
        $limit=PLANS[$plan]['nova'];
        $month=gmdate('Y-m');
        $used=(int)q('SELECT used FROM nova_usage WHERE user_id=? AND month=?',[$u['id'],$month])->fetchColumn();
        if ($used >= $limit) { flash('اعتبار Nova در این ماه تمام شده است.','error'); redirect('dashboard/nova'); }
        if (time()-(int)($_SESSION['nova_last'] ?? 0)<5) { flash('چند ثانیه دیگر دوباره تلاش کنید.','error'); redirect('dashboard/nova'); }
        $_SESSION['nova_last']=time();
        $history=array_reverse(q('SELECT question,answer FROM nova_messages WHERE user_id=? ORDER BY id DESC LIMIT 5',[$u['id']])->fetchAll());
        $messages=[['role'=>'system','content'=>'تو Nova، دستیار آموزشی فارسی Noventix هستی. پاسخ دقیق و روشن بده، اگر مطمئن نیستی صادقانه بگو و از ادعای اجرای کد یا دسترسی به حساب کاربر خودداری کن.']];
        foreach ($history as $turn) { $messages[]=['role'=>'user','content'=>$turn['question']]; $messages[]=['role'=>'assistant','content'=>$turn['answer']]; }
        $messages[]=['role'=>'user','content'=>$question];
        $answer=nova_answer($messages);
        if ($answer===null) { flash('ارتباط با Nova برقرار نشد. کمی بعد دوباره تلاش کنید؛ اعتباری کم نشد.','error'); redirect('dashboard/nova'); }
        q('INSERT INTO nova_messages(user_id,question,answer) VALUES (?,?,?)',[$u['id'],$question,$answer]);
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT INTO nova_usage(user_id,month,used) VALUES (?,?,1) ON DUPLICATE KEY UPDATE used=used+1',[$u['id'],$month]);
        else q('INSERT INTO nova_usage(user_id,month,used) VALUES (?,?,1) ON CONFLICT(user_id,month) DO UPDATE SET used=used+1',[$u['id'],$month]);
        redirect('dashboard/nova');
    }
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
    if ($path==='challenges/start') {
        $index=filter_var($_POST['index'] ?? '',FILTER_VALIDATE_INT);
        if ($index===false || !isset(CHALLENGES[$index])) { http_response_code(404); exit; }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q("INSERT INTO challenge_progress(user_id,challenge_index,status) VALUES (?,?,'started') ON DUPLICATE KEY UPDATE status='started'",[$u['id'],$index]);
        else q("INSERT INTO challenge_progress(user_id,challenge_index,status) VALUES (?,?,'started') ON CONFLICT(user_id,challenge_index) DO UPDATE SET status='started'",[$u['id'],$index]);
        flash('چالش به فهرست شما اضافه شد.'); redirect('dashboard/challenges');
    }
    if ($path==='community/comment') {
        $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT); $body=trim((string)($_POST['body'] ?? ''));
        if (!$id || mb_strlen($body)<2 || mb_strlen($body)>2000 || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { flash('نظر معتبر وارد کنید.','error'); redirect('dashboard/community'); }
        q('INSERT INTO community_replies(post_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],$body]); flash('نظر شما ثبت شد.'); redirect('dashboard/community');
    }
    if ($path==='community/follow') {
        $target=filter_var($_POST['user_id'] ?? '',FILTER_VALIDATE_INT);
        if (!$target || $target===$u['id'] || !q('SELECT id FROM users WHERE id=?',[$target])->fetch()) { http_response_code(404); exit; }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$u['id'],$target]);
        else q('INSERT OR IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$u['id'],$target]);
        flash('این کاربر به فهرست دنبال‌شده‌ها اضافه شد.'); redirect('dashboard/community');
    }
    if ($path==='tickets/reply') {
        $ticket=filter_var($_POST['ticket_id'] ?? '',FILTER_VALIDATE_INT); $body=trim((string)($_POST['body'] ?? ''));
        $owned=$ticket ? q('SELECT id FROM tickets WHERE id=? AND user_id=?',[$ticket,$u['id']])->fetch() : false;
        if (!$owned || mb_strlen($body)<2 || mb_strlen($body)>5000) { flash('پاسخ معتبر وارد کنید.','error'); redirect('dashboard/support'); }
        q('INSERT INTO ticket_replies(ticket_id,user_id,body) VALUES (?,?,?)',[$ticket,$u['id'],$body]); q("UPDATE tickets SET status='open' WHERE id=?",[$ticket]); flash('پاسخ شما به تیکت اضافه شد.'); redirect('dashboard/support');
    }
    if ($path==='community/react') {
        $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT); $kind=(string)($_POST['kind'] ?? '');
        if (!$id || !in_array($kind,['like','save'],true) || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { http_response_code(404); exit; }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO post_reactions(post_id,user_id,kind) VALUES (?,?,?)',[$id,$u['id'],$kind]);
        else q('INSERT OR IGNORE INTO post_reactions(post_id,user_id,kind) VALUES (?,?,?)',[$id,$u['id'],$kind]);
        redirect('dashboard/community');
    }
    if ($path==='tickets/create') {
        $subject=trim((string)($_POST['subject'] ?? '')); $body=trim((string)($_POST['body'] ?? '')); $priority=(string)($_POST['priority'] ?? 'متوسط');
        if (mb_strlen($subject)<5 || mb_strlen($subject)>180 || mb_strlen($body)<10 || mb_strlen($body)>5000 || !in_array($priority,TICKET_PRIORITIES,true)) { flash('موضوع، متن و اولویت معتبر وارد کنید.','error'); redirect('dashboard/support'); }
        $pdo=db(); $pdo->beginTransaction();
        try {
            q('INSERT INTO tickets(user_id,subject,priority) VALUES (?,?,?)',[$u['id'],$subject,$priority]);
            q('INSERT INTO ticket_replies(ticket_id,user_id,body) VALUES (?,?,?)',[$pdo->lastInsertId(),$u['id'],$body]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('تیکت شما ثبت شد.'); redirect('dashboard/support');
    }
    if ($path==='profile/account') {
        $name=trim((string)($_POST['name'] ?? '')); $email=trim((string)($_POST['email'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) { flash('نام و ایمیل معتبر وارد کنید.','error'); redirect('dashboard/profile'); }
        q('UPDATE users SET name=? WHERE id=?',[$name,$u['id']]);
        flash('پروفایل به‌روزرسانی شد.'); redirect('dashboard/profile');
    }
    if ($path==='settings/save' && $u['role']==='admin') {
        $key=(string)($_POST['key'] ?? '');
        if (!in_array($key,array_column(SETTINGS_GROUPS,'key'),true)) { flash('تنظیم نامعتبر است.','error'); redirect('dashboard/settings'); }
        $value=trim((string)($_POST['value'] ?? ''));
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT INTO settings(skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=?',[$key,$value,$value]);
        else q('INSERT INTO settings(skey,svalue) VALUES (?,?) ON CONFLICT(skey) DO UPDATE SET svalue=?',[$key,$value,$value]);
        flash('تنظیم ذخیره شد.'); redirect('dashboard/settings');
    }
    if ($path==='content/create' && $u['role']==='admin') {
        $title=trim((string)($_POST['title'] ?? '')); $kind=trim((string)($_POST['kind'] ?? 'مقاله'));
        if (mb_strlen($title)<3 || mb_strlen($title)>180) { flash('عنوان باید بین ۳ تا ۱۸۰ نویسه باشد.','error'); redirect('dashboard/content'); }
        if (!in_array($kind,['مقاله','صفحه'],true)) $kind='مقاله';
        q('INSERT INTO content_items(title,kind,status) VALUES (?,?,?)',[$title,$kind,'پیش‌نویس']);
        flash('محتوای جدید ذخیره و منتشر شد.'); redirect('dashboard/content');
    }
    if ($path==='content/publish' && $u['role']==='admin') {
        $id=filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(404); exit; }
        q("UPDATE content_items SET status='منتشرشده' WHERE id=?",[$id]);
        flash('محتوا منتشر شد.'); redirect('dashboard/content');
    }
    if ($path==='announcements/send' && $u['role']==='admin') {
        $title=trim((string)($_POST['title'] ?? '')); $body=trim((string)($_POST['body'] ?? ''));
        if (mb_strlen($title)<3 || mb_strlen($title)>255 || mb_strlen($body)<5 || mb_strlen($body)>2000) { flash('عنوان و متن اعلان معتبر وارد کنید.','error'); redirect('dashboard/announcements'); }
        q('INSERT INTO announcements(title,body) VALUES (?,?)',[$title,$body]);
        flash('اعلان برای کاربران ارسال شد.'); redirect('dashboard/announcements');
    }
    if ($path==='admin/seed-demo' && $u['role']==='admin') {
        foreach (ANNOUNCEMENTS as $a) q('INSERT INTO announcements(title,body) VALUES (?,?)',[$a['title'],$a['body']]);
        foreach (CONTENT_ITEMS as $c) q('INSERT INTO content_items(title,kind,status) VALUES (?,?,?)',[$c['title'],$c['kind'],$c['status']]);
        flash('داده‌های نمونه ایجاد شد.'); redirect('dashboard');
    }
    http_response_code(404); exit('صفحه پیدا نشد.');
}
