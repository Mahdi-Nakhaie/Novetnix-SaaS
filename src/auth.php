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
function handle_auth_post(string $path): bool {
    if (!in_array($path,['auth/request','auth/login','auth/verify','auth/admin','logout'],true)) return false;
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
        if ($u) {
            unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['verify_password_hash']);
            flash('این شماره موبایل قبلاً ثبت شده است. برای ورود از فرم ورود استفاده کنید.','error');
            redirect('login');
        }
        $name=(string)($_SESSION['verify_name'] ?? '');
        $passwordHash=(string)($_SESSION['verify_password_hash'] ?? '');
        try {
            q('INSERT INTO users(phone,name,password_hash) VALUES (?,?,?)',[$phone,$name,$passwordHash]);
        } catch (PDOException $e) {
            if ($e->getCode()!=='23000') throw $e;
            unset($_SESSION['verify_phone'],$_SESSION['verify_name'],$_SESSION['verify_password_hash']);
            flash('این شماره موبایل قبلاً ثبت شده است. برای ورود از فرم ورود استفاده کنید.','error');
            redirect('login');
        }
        $u=q('SELECT id FROM users WHERE phone=?',[$phone]);
        $u=$u->fetch();
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
    return true;
}
