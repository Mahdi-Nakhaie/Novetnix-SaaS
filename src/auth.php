<?php
declare(strict_types=1);

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
    $u=q('SELECT id,phone,name,role,phone_verified_at FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch();
    if (!$u || $u['phone_verified_at']===null) return null;
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
function registration_input(string $key): ?string {
    $value=$_POST[$key] ?? null;
    return is_string($value) ? $value : null;
}
function registration_error(string $message, string $first, string $last, string $phone): never {
    $_SESSION['registration_form']=['first_name'=>$first,'last_name'=>$last,'phone'=>$phone];
    flash($message,'error');
    redirect('login');
}
function normalized_phone(string $raw): ?string {
    $raw=latin_digits($raw);
    $raw=preg_replace('/[\s-]+/u','',$raw);
    $raw=preg_replace('/^(\+98|98)/','0',$raw);
    return preg_match('/^09[0-9]{9}$/D',$raw) ? $raw : null;
}
function otp_ttl_seconds(): int {
    $ttl=filter_var(config_value('OTP_TTL_SECONDS'),FILTER_VALIDATE_INT,['options'=>['min_range'=>60,'max_range'=>900]]);
    return $ttl===false ? 300 : $ttl;
}
function otp_setting(string $key, int $default, int $min, int $max): int {
    $value=filter_var(config_value($key),FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    return $value===false ? $default : $value;
}
// Capped at 5 by the otp_codes.attempts CHECK constraint.
function otp_max_attempts(): int { return otp_setting('OTP_MAX_ATTEMPTS',5,1,5); }
function otp_resend_cooldown_seconds(): int { return otp_setting('OTP_RESEND_COOLDOWN_SECONDS',90,30,3600); }
function otp_resend_limit(): int { return otp_setting('OTP_RESEND_LIMIT',5,1,20); }
function otp_resend_window_seconds(): int { return otp_setting('OTP_RESEND_WINDOW_SECONDS',3600,300,86400); }
// Records an issuance for $phone and returns null, or returns a wait message when the per-phone cooldown/limit applies.
function reserve_otp_send(string $phone, int $now): ?string {
    $cooldown=otp_resend_cooldown_seconds();
    $limit=otp_resend_limit();
    $window=otp_resend_window_seconds();
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO otp_requests(phone,window_start,request_count,last_requested) VALUES (?,?,0,0)',[$phone,$now]);
    else q('INSERT OR IGNORE INTO otp_requests(phone,window_start,request_count,last_requested) VALUES (?,?,0,0)',[$phone,$now]);
    // The conditional row update serializes reservations for the same phone on both SQLite and MySQL.
    $reserved=q('UPDATE otp_requests SET request_count=CASE WHEN window_start<=? THEN 1 ELSE request_count+1 END, window_start=CASE WHEN window_start<=? THEN ? ELSE window_start END, last_requested=? WHERE phone=? AND last_requested<=? AND (window_start<=? OR request_count<?)',[$now-$window,$now-$window,$now,$now,$phone,$now-$cooldown,$now-$window,$limit]);
    if ($reserved->rowCount()===1) return null;
    $row=q('SELECT window_start,request_count,last_requested FROM otp_requests WHERE phone=?',[$phone])->fetch();
    if ((int)$row['window_start']>$now-$window && (int)$row['request_count']>=$limit) return 'تعداد درخواست کد برای این شماره به سقف مجاز رسیده است. حدود '.fa(max(1,(int)ceil(((int)$row['window_start']+$window-$now)/60))).' دقیقه دیگر دوباره تلاش کنید.';
    return 'برای دریافت کد جدید لطفاً '.fa(max(1,(int)$row['last_requested']+$cooldown-$now)).' ثانیه دیگر صبر کنید.';
}
function generate_otp(): string { return str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT); }
// One row per phone: issuing a new code replaces the previous one, so only the latest code can verify.
function store_otp(string $phone, string $code, int $now, ?string $passwordHash=null): string {
    $hash=password_hash($code,PASSWORD_DEFAULT);
    $db=db();
    $db->beginTransaction();
    try {
        q('DELETE FROM otp_codes WHERE phone=?',[$phone]);
        q('INSERT INTO otp_codes(phone,otp_hash,pending_password_hash,expires_at,attempts,created_at) VALUES (?,?,?,?,?,?)',[$phone,$hash,$passwordHash,$now+otp_ttl_seconds(),0,$now]);
        $db->commit();
        return $hash;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function send_verification_code(string $phone, string $code): bool {
    $apiKey=(string)config_value('SMSIR_API_KEY','');
    $templateId=filter_var(config_value('SMSIR_TEMPLATE_ID'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $parameter=(string)(config_value('SMSIR_CODE_PARAMETER') ?: 'Code');
    if ($apiKey==='' || $templateId===false || !function_exists('curl_init')) return false;
    $ch=curl_init('https://api.sms.ir/v1/send/verify');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['mobile'=>$phone,'templateId'=>$templateId,'parameters'=>[['name'=>$parameter,'value'=>$code]]],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','x-api-key: '.$apiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false]);
    $result=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body=is_string($result) ? json_decode($result,true) : null;
    if ($status===200 && is_array($body) && (int)($body['status'] ?? 0)===1) return true;
    error_log('SMS.ir verify send failed: HTTP '.$status.', status '.(is_array($body) ? (string)($body['status'] ?? 'unknown') : 'invalid-response'));
    return false;
}
function handle_auth_post(string $path): bool {
    if (!in_array($path,['auth/request','auth/resend','auth/login','auth/verify','auth/admin','logout'],true)) return false;
    validate_csrf();
    if ($path==='auth/request') {
        $firstRaw=registration_input('first_name');
        $lastRaw=registration_input('last_name');
        $phoneRaw=registration_input('phone');
        $password=registration_input('password');
        $first=$firstRaw===null ? '' : trim($firstRaw);
        $last=$lastRaw===null ? '' : trim($lastRaw);
        $phone=$phoneRaw===null ? null : normalized_phone($phoneRaw);
        $preservedPhone=$phone ?? ($phoneRaw===null ? '' : trim($phoneRaw));
        if ($firstRaw===null || $lastRaw===null || mb_strlen($first)<2 || mb_strlen($first)>50 || mb_strlen($last)<2 || mb_strlen($last)>50) registration_error('نام و نام خانوادگی معتبر وارد کنید.',$first,$last,$preservedPhone);
        if ($phoneRaw===null || !$phone) registration_error('شماره موبایل معتبر وارد کنید.',$first,$last,$preservedPhone);
        if ($password===null || strlen($password)<8 || strlen($password)>72) registration_error('رمز عبور باید بین ۸ تا ۷۲ نویسه باشد.',$first,$last,$phone);
        if (!preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password)) registration_error('رمز عبور باید دست‌کم یک حرف انگلیسی و یک عدد داشته باشد.',$first,$last,$phone);
        if (q('SELECT 1 FROM users WHERE phone=?',[$phone])->fetchColumn()) registration_error('این شماره موبایل قبلاً ثبت شده است. برای ورود از فرم ورود استفاده کنید.',$first,$last,$phone);
        $wait=reserve_otp_send($phone,time());
        if ($wait!==null) registration_error($wait,$first,$last,$phone);
        $code=generate_otp();
        $hash=store_otp($phone,$code,time(),password_hash($password,PASSWORD_DEFAULT));
        if (!send_verification_code($phone,$code)) { q('DELETE FROM otp_codes WHERE phone=? AND otp_hash=?',[$phone,$hash]); flash('ارسال پیامک فعلاً امکان‌پذیر نیست. کمی بعد دوباره تلاش کنید.','error'); redirect('login'); }
        $_SESSION['verify_phone']=$phone;
        $_SESSION['verify_name']=$first.' '.$last;
        flash('کد تأیید ارسال شد. کد تا '.fa((int)ceil(otp_ttl_seconds()/60)).' دقیقه معتبر است.'); redirect('verify');
    }
    if ($path==='auth/resend') {
        $phone=(string)($_SESSION['verify_phone'] ?? '');
        if ($phone==='') { flash('درخواست تأییدی در جریان نیست؛ ابتدا ثبت‌نام کنید.','error'); redirect('login'); }
        $passwordHash=q('SELECT pending_password_hash FROM otp_codes WHERE phone=?',[$phone])->fetchColumn();
        if (!$passwordHash) { flash('درخواست تأییدی در جریان نیست؛ ابتدا ثبت‌نام کنید.','error'); redirect('login'); }
        $wait=reserve_otp_send($phone,time());
        if ($wait!==null) { flash($wait,'error'); redirect('verify'); }
        $code=generate_otp();
        $hash=store_otp($phone,$code,time(),(string)$passwordHash);
        if (!send_verification_code($phone,$code)) { q('UPDATE otp_codes SET attempts=5 WHERE phone=? AND otp_hash=? AND used_at IS NULL', [$phone,$hash]); flash('ارسال پیامک فعلاً امکان‌پذیر نیست. کمی بعد دوباره تلاش کنید.','error'); redirect('verify'); }
        flash('کد جدید ارسال شد و کد قبلی باطل شد. کد تا '.fa((int)ceil(otp_ttl_seconds()/60)).' دقیقه معتبر است.'); redirect('verify');
    }
    if ($path==='auth/login') {
        if (!validate_captcha()) { flash('کد کپچا صحیح نیست.','error'); redirect('login'); }
        $phoneRaw=registration_input('phone');
        $phone=$phoneRaw===null ? null : normalized_phone($phoneRaw);
        $password=registration_input('password') ?? '';
        $u=$phone ? q('SELECT id,password_hash,phone_verified_at FROM users WHERE phone=?',[$phone])->fetch() : false;
        $dummyHash=password_hash('invalid-account',PASSWORD_DEFAULT);
        $hash=$u && $u['password_hash']!=='' ? (string)$u['password_hash'] : $dummyHash;
        $passwordValid=password_verify($password,$hash);
        if (!$u || $u['phone_verified_at']===null || !$passwordValid) { flash('شماره موبایل یا رمز عبور صحیح نیست.','error'); redirect('login'); }
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; unset($_SESSION['admin_verified_uid']);
        if ($phone===(config_value('ADMIN_PHONE') ?: '')) redirect('admin-login');
        flash('خوش آمدید!'); redirect('dashboard');
    }
    if ($path==='auth/verify') {
        $phone=(string)($_SESSION['verify_phone'] ?? '');
        $code=latin_digits((string)($_POST['code'] ?? ''));
        if ($phone==='') { flash('درخواست تأییدی در جریان نیست؛ ابتدا ثبت‌نام کنید.','error'); redirect('login'); }
        $entry=q('SELECT * FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if (!$entry) { flash('کد تأییدی برای این شماره وجود ندارد؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if ($entry['used_at']!==null) { flash('این کد قبلاً استفاده شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if (time()>(int)$entry['expires_at']) { flash('کد منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if (empty($entry['pending_password_hash'])) { flash('درخواست ثبت‌نام معتبر نیست؛ دوباره درخواست دهید.','error'); redirect('login'); }
        $maxAttempts=otp_max_attempts();
        $blocked='تعداد تلاش‌های ناموفق بیش از حد مجاز است و این کد غیرفعال شد. با «ارسال دوباره کد» کد جدید دریافت کنید.';
        if ((int)$entry['attempts']>=$maxAttempts) { flash($blocked,'error'); redirect('verify'); }
        $attempt=q('UPDATE otp_codes SET attempts=attempts+1 WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=? AND attempts<?',[$phone,$entry['otp_hash'],time(),$maxAttempts]);
        if ($attempt->rowCount()!==1) { flash('این کد دیگر معتبر نیست؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$entry['otp_hash'])) {
            $left=$maxAttempts-(int)q('SELECT attempts FROM otp_codes WHERE phone=? AND otp_hash=?',[$phone,$entry['otp_hash']])->fetchColumn();
            flash($left>0 ? 'کد واردشده صحیح نیست. '.fa($left).' تلاش دیگر باقی مانده است.' : $blocked,'error');
            redirect('verify');
        }
        // Consuming the code and creating the account commit together; the guarded UPDATE lets only one concurrent request win.
        $db=db();
        $db->beginTransaction();
        try {
            $now=time();
            $used=q('UPDATE otp_codes SET used_at=?, pending_password_hash=NULL WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=? AND attempts<=?', [$now,$phone,$entry['otp_hash'],$now,$maxAttempts]);
            if ($used->rowCount()!==1) { $db->rollBack(); flash('این کد قبلاً استفاده شده یا منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
            q('INSERT INTO users(phone,name,password_hash,phone_verified_at) VALUES (?,?,?,?)',[$phone,(string)($_SESSION['verify_name'] ?? ''),(string)$entry['pending_password_hash'],$now]);
            $userId=(int)$db->lastInsertId();
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e->getCode()!=='23000') throw $e;
            unset($_SESSION['verify_phone'],$_SESSION['verify_name']);
            flash('این شماره موبایل قبلاً ثبت شده است. برای ورود از فرم ورود استفاده کنید.','error');
            redirect('login');
        }
        session_regenerate_id(true); $_SESSION['uid']=$userId; unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['admin_verified_uid']);
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
    return true;
}
