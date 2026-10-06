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
function generate_otp(): string { return str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT); }
// One row per phone: issuing a new code replaces the previous one, so only the latest code can verify.
function store_otp(string $phone, string $code, int $now): string {
    $hash=password_hash($code,PASSWORD_DEFAULT);
    $db=db();
    $db->beginTransaction();
    try {
        q('DELETE FROM otp_codes WHERE phone=?',[$phone]);
        q('INSERT INTO otp_codes(phone,otp_hash,expires_at,attempts,created_at) VALUES (?,?,?,?,?)',[$phone,$hash,$now+otp_ttl_seconds(),0,$now]);
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
        $count=(int)($_SESSION['otp_requests'] ?? 0);
        if ($count>=8) { flash('تعداد درخواست‌ها بیش از حد مجاز است. بعداً دوباره تلاش کنید.','error'); redirect('login'); }
        $previous=q('SELECT created_at FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if ($previous && time()-(int)$previous['created_at']<90) { flash('برای درخواست دوباره کمی صبر کنید.','error'); redirect('login'); }
        $code=generate_otp();
        $hash=store_otp($phone,$code,time());
        if (!send_verification_code($phone,$code)) { q('DELETE FROM otp_codes WHERE phone=? AND otp_hash=?',[$phone,$hash]); flash('ارسال پیامک فعلاً امکان‌پذیر نیست. کمی بعد دوباره تلاش کنید.','error'); redirect('login'); }
        $_SESSION['otp_requests']=$count+1;
        $_SESSION['verify_phone']=$phone;
        $_SESSION['verify_name']=$first.' '.$last;
        $_SESSION['verify_password_hash']=password_hash($password,PASSWORD_DEFAULT);
        flash('کد تأیید ارسال شد. کد تا '.fa((int)ceil(otp_ttl_seconds()/60)).' دقیقه معتبر است.'); redirect('verify');
    }
    if ($path==='auth/resend') {
        $phone=(string)($_SESSION['verify_phone'] ?? '');
        if ($phone==='') { flash('درخواست تأییدی در جریان نیست؛ ابتدا ثبت‌نام کنید.','error'); redirect('login'); }
        $count=(int)($_SESSION['otp_requests'] ?? 0);
        if ($count>=8) { flash('تعداد درخواست‌ها بیش از حد مجاز است. بعداً دوباره تلاش کنید.','error'); redirect('verify'); }
        $previous=q('SELECT created_at FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if ($previous && time()-(int)$previous['created_at']<90) { flash('برای درخواست دوباره کمی صبر کنید.','error'); redirect('verify'); }
        $code=generate_otp();
        $hash=store_otp($phone,$code,time());
        if (!send_verification_code($phone,$code)) { q('DELETE FROM otp_codes WHERE phone=? AND otp_hash=?',[$phone,$hash]); flash('ارسال پیامک فعلاً امکان‌پذیر نیست. کمی بعد دوباره تلاش کنید.','error'); redirect('verify'); }
        $_SESSION['otp_requests']=$count+1;
        flash('کد جدید ارسال شد و کد قبلی باطل شد. کد تا '.fa((int)ceil(otp_ttl_seconds()/60)).' دقیقه معتبر است.'); redirect('verify');
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
        $phone=(string)($_SESSION['verify_phone'] ?? '');
        $code=latin_digits((string)($_POST['code'] ?? ''));
        if ($phone==='') { flash('درخواست تأییدی در جریان نیست؛ ابتدا ثبت‌نام کنید.','error'); redirect('login'); }
        $entry=q('SELECT * FROM otp_codes WHERE phone=?',[$phone])->fetch();
        if (!$entry) { flash('کد تأییدی برای این شماره وجود ندارد؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if ($entry['used_at']!==null) { flash('این کد قبلاً استفاده شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if (time()>(int)$entry['expires_at']) { flash('کد منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if ((int)$entry['attempts']>=5) { flash('تعداد تلاش‌های ناموفق بیش از حد مجاز است؛ دوباره درخواست دهید.','error'); redirect('login'); }
        $attempt=q('UPDATE otp_codes SET attempts=attempts+1 WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=? AND attempts<5',[$phone,$entry['otp_hash'],time()]);
        if ($attempt->rowCount()!==1) { flash('این کد دیگر معتبر نیست؛ دوباره درخواست دهید.','error'); redirect('login'); }
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$entry['otp_hash'])) { flash('کد واردشده صحیح نیست.','error'); redirect('verify'); }
        // Consuming the code and creating the account commit together; the guarded UPDATE lets only one concurrent request win.
        $db=db();
        $db->beginTransaction();
        try {
            $now=time();
            $used=q('UPDATE otp_codes SET used_at=? WHERE phone=? AND otp_hash=? AND used_at IS NULL AND expires_at>=? AND attempts<=5', [$now,$phone,$entry['otp_hash'],$now]);
            if ($used->rowCount()!==1) { $db->rollBack(); flash('این کد قبلاً استفاده شده یا منقضی شده است؛ دوباره درخواست دهید.','error'); redirect('login'); }
            q('INSERT INTO users(phone,name,password_hash) VALUES (?,?,?)',[$phone,(string)($_SESSION['verify_name'] ?? ''),(string)($_SESSION['verify_password_hash'] ?? '')]);
            $userId=(int)$db->lastInsertId();
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e->getCode()!=='23000') throw $e;
            unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['verify_password_hash']);
            flash('این شماره موبایل قبلاً ثبت شده است. برای ورود از فرم ورود استفاده کنید.','error');
            redirect('login');
        }
        session_regenerate_id(true); $_SESSION['uid']=$userId; unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['verify_password_hash'],$_SESSION['admin_verified_uid']);
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
