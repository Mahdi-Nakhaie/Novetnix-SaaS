<?php
declare(strict_types=1);

function money(int $amount): string { return fa(number_format($amount,0,'.','٬')).' تومان'; }
function linkto(string $path,string $label,string $class=''): void { echo '<a class="'.($class?h($class):'').'" href="'.h(url($path)).'">'.h($label).'</a>'; }
function form_start(string $action,string $class='',bool $customValidation=false,bool $multipart=false): void { echo '<form action="'.h(url($action)).'" method="post" class="'.($class?h($class):'').($customValidation?' custom-validation':'').($multipart?' enctype="multipart/form-data"':'').'"'.($customValidation?' novalidate':'').'><input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
function head_page(string $title,string $description='',bool $panel=false,bool $indexable=false): void {
    global $me,$path;
    if ($panel || !($indexable && seo_public_path($path)) || http_response_code()===404) header('X-Robots-Tag: noindex, follow');
    if ($panel) header('Cache-Control: private, no-store');
    $full=($title==='خانه' ? 'Noventix | از یادگیری تا ساختن' : h($title).' | Noventix');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#201a35"><title>'.$full.'</title><meta name="description" content="'.h($description ?: 'Noventix؛ آموزش پروژه‌محور پایتون، علم داده و هوش مصنوعی از مطالعه تا ساختن.').'">';
    echo '<meta name="google-site-verification" content="ac80Ad6zRSLvvfcYFAMfV2JVVj8rUKlz49RpTpzwv70"><link rel="icon" href="/assets/favicon.webp?v=2" type="image/webp">';
    seo_head($title,$description ?: 'Noventix؛ آموزش پروژه‌محور پایتون، علم داده و هوش مصنوعی از مطالعه تا ساختن.',$panel,$indexable);
    if ($path==='course/'.FREE_COURSE_SLUG) {
        echo '<link rel="stylesheet" href="/assets/free-course.css?v=3">';
        if ($me && trim($me['name'])!=='') echo '<script src="/assets/free-course.js?v=2" defer></script>';
    }
    if ($path==='' || $path==='articles' || str_starts_with($path,'articles/')) echo '<link rel="stylesheet" href="/assets/articles.css?v=3">';
    $darychehAssets=($path==='dashboard/darycheh' || str_starts_with($path,'dashboard/darycheh/')) ? '<link rel="stylesheet" href="/assets/darycheh.css?v=1"><script src="/assets/darycheh.js?v=1" defer></script>' : '';
    if (!$panel) echo '<link rel="stylesheet" href="/assets/header.css?v=5">';
    $adminPanel=$panel && ($me['role'] ?? '')==='admin';
    echo '<link rel="stylesheet" href="/assets/style.css?v=38">'.($adminPanel?'<link rel="stylesheet" href="/assets/admin.css?v=1">':'').'<script src="/assets/app.js?v=5" defer></script><script src="/assets/playback-events.js" defer></script>'.$darychehAssets.'</head><body class="'.($panel?'is-panel':'').($adminPanel?' is-admin':'').'">';
    echo '<div class="noventix-loading" id="noventix-loading" role="status" aria-live="polite" hidden><div class="noventix-loading-card"><span class="noventix-loading-mark" aria-hidden="true">N</span><strong>Noventix</strong><span class="noventix-loading-track" aria-hidden="true"><span></span></span><p>در حال آماده‌سازی فضای یادگیری...</p></div></div>';
    if ($panel) { panel_nav(); return; }
    echo '<header class="site-header"><div class="container header-inner"><a href="/" class="brand" aria-label="Noventix، صفحه اصلی"><img src="/assets/noventix-logo.webp?v=2" alt="Noventix" width="72" height="72"><span class="brand-name" lang="en" dir="ltr">Noventix</span></a><nav class="main-nav" aria-label="ناوبری اصلی">';
    foreach ([''=>'خانه','course/'.FREE_COURSE_SLUG=>'دورهٔ رایگان','articles'=>'مقاله‌ها','projects'=>'پروژه‌ها','pricing'=>'قیمت‌گذاری','learners'=>'جامعه','community'=>'انجمن','glossary'=>'لغت‌نامه','about'=>'درباره ما','contact'=>'تماس با ما'] as $href=>$label) linkto($href,$label,trim(($path===$href?'active ':'').($href==='course/'.FREE_COURSE_SLUG?'nav-free-course':'')));
    echo '</nav><div class="header-actions">';
    echo '<a class="btn btn-small btn-outline cart-button" href="'.h(url('cart')).'" aria-label="سبد خرید"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.1 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4L20 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/></svg><span>سبد خرید</span></a>';
    if ($me) linkto('dashboard','پنل من','btn btn-small btn-outline'); else linkto('login','ورود','btn btn-small btn-outline');
    linkto($me?'dashboard':'register','ثبت‌نام رایگان','btn btn-small btn-primary');
    echo '</div><button class="menu-toggle" type="button" aria-label="باز کردن فهرست" aria-expanded="false" aria-controls="mobile-nav">☰</button></div><nav id="mobile-nav" class="mobile-nav" aria-label="ناوبری موبایل">';
    foreach ([''=>'خانه','course/'.FREE_COURSE_SLUG=>'دورهٔ رایگان','articles'=>'مقاله‌ها','projects'=>'پروژه‌ها','pricing'=>'قیمت‌گذاری','learners'=>'جامعه','community'=>'انجمن','glossary'=>'لغت‌نامه','about'=>'درباره ما','contact'=>'تماس با ما','dashboard'=>'پنل من'] as $href=>$label) linkto($href,$label,trim(($path===$href?'active ':'').($href==='course/'.FREE_COURSE_SLUG?'nav-free-course':'')));
    if (!$me) { linkto('login','ورود به حساب'); linkto('register','ثبت‌نام'); }
    echo '<a class="btn btn-small btn-outline cart-button mobile-cart-link" href="'.h(url('cart')).'" aria-label="سبد خرید"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.1 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4L20 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/></svg><span>سبد خرید</span></a>';
    echo '</nav></header>';
}
function commerce_paused(): void {
    echo '<main class="container section"><section class="panel-card"><h1>یادگیری رایگان در Noventix</h1><p>پرداخت و خرید اشتراک فعلاً در دسترس نیست؛ اما می‌توانی همین حالا یادگیری را رایگان شروع کنی.</p>';
    linkto('course/'.FREE_COURSE_SLUG,'مشاهده دوره رایگان ←','btn btn-primary');
    echo '</section></main>';
}
function page_not_found(): void {
    http_response_code(404);
    head_page('یافت نشد','صفحه مورد نظر پیدا نشد.');
    echo '<main class="noventix-not-found"><section class="noventix-not-found-content" aria-labelledby="not-found-title"><span class="noventix-not-found-label">NOVENTIX / LOST PATH</span><div class="noventix-not-found-art" aria-hidden="true"><span class="noventix-not-found-orbit"></span><span class="noventix-not-found-code">404</span><span class="noventix-not-found-dot"></span></div><h1 id="not-found-title">این صفحه پیدا نشد.</h1><p>ممکن است نشانی صفحه تغییر کرده باشد یا لینک اشتباه باشد. از یکی از مسیرهای زیر ادامه بده.</p><div class="noventix-not-found-actions">';
    linkto('','بازگشت به صفحه اصلی','btn btn-primary');
    echo '<a class="btn btn-outline" href="/" data-safe-back hidden>بازگشت به صفحه قبل</a></div></section></main>';
    foot_page();
}
function panel_nav(): void {
    global $me,$path;
    $role=$me['role'];
    if ($role==='admin') { admin_nav(); return; }
    $items=$role==='admin'
        ? ['dashboard'=>'Overview','dashboard/users'=>'کاربران','dashboard/users-new'=>'کاربران جدید','dashboard/courses'=>'Courses & Lessons','dashboard/catalog'=>'پروژه‌ها','dashboard/challenges'=>'چالش‌ها','dashboard/subscriptions'=>'اشتراک‌ها','dashboard/payments'=>'پرداخت‌ها','dashboard/community'=>'Community','dashboard/support'=>'پشتیبانی','dashboard/announcements'=>'اعلان‌ها','dashboard/gamification'=>'Gamification','dashboard/analytics'=>'Analytics','dashboard/content'=>'Content','dashboard/glossary'=>'واژه‌نامه','dashboard/profile'=>'پروفایل','dashboard/settings'=>'System Settings']
        : ['dashboard'=>'نمای کلی','dashboard/learning'=>'مسیر یادگیری','dashboard/projects'=>'پروژه‌ها','dashboard/challenges'=>'چالش‌ها','dashboard/community'=>'Community','messages'=>'پیام‌ها','dashboard/nova'=>'Nova AI','dashboard/subscription'=>'اشتراک','dashboard/support'=>'پشتیبانی','dashboard/gamification'=>'Gamification','dashboard/activity'=>'فعالیت و Streak','dashboard/profile'=>'پروفایل','dashboard/settings'=>'تنظیمات'];
    $items['dashboard/workspace']='میزکار پایتون';
    $items['dashboard/darycheh']='دریچه';
    if ($role!=='admin') $items['member/'.$me['id']]='پروفایل مهارتی';
    if ($role==='admin') {
        $items['dashboard/workspace-templates']='تمرین‌های پایتون';
        $items['dashboard/darycheh-manage']='مدیریت سناریوهای دریچه';
    }
    if (!COMMERCE_ENABLED) unset($items['dashboard/subscription'],$items['dashboard/subscriptions'],$items['dashboard/payments']);
    echo '<aside class="sidebar"><a class="panel-brand" href="/"><img src="/assets/favicon.webp" alt="" width="42" height="42"><span>Noventix</span></a><div class="sidebar-caption">'.($role==='admin'?'مدیریت پلتفرم':($role==='owner'?'پنل مالک':'فضای یادگیری')).'</div><nav aria-label="ناوبری پنل">';
    foreach ($items as $href=>$label) linkto($href,$label,$path===$href?'selected':'');
    echo '</nav><div class="sidebar-bottom"><a href="/">بازگشت به سایت ↗</a>';
    form_start('logout'); echo '<button type="submit" class="text-button">خروج از حساب</button></form></div></aside><div class="panel-body"><div class="panel-top"><button type="button" class="panel-menu" aria-label="فهرست پنل">☰</button><span>Noventix <span class="muted">/ '.($role==='admin'?'مدیریت':($role==='owner'?'مالک':'دانش‌آموز')).'</span></span><span class="panel-avatar">'.h(mb_substr($me['name'] ?: $me['phone'],0,1)).'</span></div>';
}
function admin_nav(): void {
    global $me,$path;
    $groups=[
        'مدیریت'=>['dashboard'=>'نمای کلی','dashboard/users'=>'کاربران','dashboard/users-new'=>'ثبت‌نام‌های جدید','dashboard/analytics'=>'آمار و گزارش‌ها'],
        'آموزش و محتوا'=>['dashboard/courses'=>'دوره‌ها و درس‌ها','dashboard/catalog'=>'پروژه‌ها','dashboard/challenges'=>'چالش‌ها','dashboard/workspace-templates'=>'تمرین‌های پایتون','dashboard/darycheh-manage'=>'سناریوهای دریچه','dashboard/content'=>'محتوا','dashboard/glossary'=>'واژه‌نامه'],
        'ارتباط با کاربران'=>['dashboard/community'=>'انجمن','dashboard/support'=>'پشتیبانی','dashboard/messages'=>'پیام‌های تماس','dashboard/announcements'=>'اعلان‌ها'],
        'حساب و ابزارها'=>['dashboard/workspace'=>'میزکار پایتون','dashboard/darycheh'=>'دریچه','dashboard/gamification'=>'پیشرفت و امتیازها','dashboard/profile'=>'پروفایل من','dashboard/settings'=>'تنظیمات سامانه'],
    ];
    if (COMMERCE_ENABLED) $groups['امور مالی']=['dashboard/subscriptions'=>'اشتراک‌ها','dashboard/payments'=>'پرداخت‌ها'];
    $current='مدیریت';
    foreach ($groups as $items) foreach ($items as $href=>$label) if ($path===$href || ($href!=='dashboard' && str_starts_with($path,$href.'/'))) $current=$label;
    echo '<a class="admin-skip" href="#admin-main">رفتن به محتوای اصلی</a><aside class="sidebar" id="admin-sidebar"><a class="panel-brand" href="/"><img src="/assets/favicon.webp" alt="" width="42" height="42"><span lang="en" dir="ltr">Noventix</span></a><div class="sidebar-caption">پنل مدیریت</div><nav aria-label="ناوبری مدیریت">';
    foreach ($groups as $title=>$items) {
        echo '<div class="admin-nav-group"><span class="admin-nav-label">'.h($title).'</span>';
        foreach ($items as $href=>$label) {
            $active=$path===$href || ($href!=='dashboard' && str_starts_with($path,$href.'/'));
            echo '<a href="'.h(url($href)).'"'.($active?' class="selected" aria-current="page"':'').'><span class="admin-nav-mark" aria-hidden="true"></span>'.h($label).'</a>';
        }
        echo '</div>';
    }
    echo '</nav><div class="sidebar-bottom"><a href="/">مشاهدهٔ سایت ↗</a>';
    form_start('logout');
    echo '<button type="submit" class="text-button">خروج از حساب</button></form></div></aside><div class="panel-body"><header class="panel-top"><button type="button" class="panel-menu" aria-label="باز و بسته کردن منوی مدیریت" aria-expanded="false" aria-controls="admin-sidebar">☰</button><div class="admin-breadcrumb"><span>مدیریت پلتفرم</span><strong>'.h($current).'</strong></div><div class="admin-account"><div><strong>'.h($me['name'] ?: 'مدیر').'</strong><span>مدیر پلتفرم</span></div><span class="panel-avatar">'.h(mb_substr($me['name'] ?: 'م',0,1)).'</span></div></header>';
}
function admin_overview(): void {
    global $me;
    echo '<section class="admin-welcome"><div><span class="eyebrow">مرکز مدیریت نوونتیکس</span><h1>سلام، '.h($me['name'] ?: 'مدیر').' </h1><p>کاربران، محتوای آموزشی و ارتباطات پلتفرم را از اینجا مدیریت کنید.</p></div>';
    linkto('dashboard/users','مدیریت کاربران','btn btn-primary');
    echo '</section><div class="stat-grid">';
    $stats=[['کل کاربران','SELECT COUNT(*) FROM users','dashboard/users','حساب ثبت‌شده'],['دوره‌های آموزشی','SELECT COUNT(*) FROM courses','dashboard/courses','دوره در سامانه'],['گفت‌وگوهای انجمن','SELECT COUNT(*) FROM community_posts','dashboard/community','گفت‌وگوی ثبت‌شده'],['پیام‌های تماس','SELECT COUNT(*) FROM contact_messages','dashboard/messages','پیام دریافتی']];
    foreach ($stats as [$label,$sql,$href,$hint]) echo '<a class="stat-card" href="'.h(url($href)).'"><span>'.h($label).'</span><strong>'.fa((int)q($sql)->fetchColumn()).'</strong><small>'.h($hint).' <span aria-hidden="true">←</span></small></a>';
    echo '</div><section class="panel-card"><div class="admin-section-title"><div><span class="eyebrow">دسترسی سریع</span><h2>کارهای روزمرهٔ مدیریت</h2></div><span class="pill">مدیریت پلتفرم</span></div><div class="admin-shortcuts">';
    foreach ([['dashboard/courses','دوره‌ها و درس‌ها','بررسی و مدیریت محتوای آموزشی'],['dashboard/users-new','ثبت‌نام‌های جدید','مشاهدهٔ تازه‌ترین اعضای پلتفرم'],['dashboard/support','پشتیبانی کاربران','بررسی درخواست‌ها و تیکت‌ها'],['dashboard/announcements','اعلان‌ها','اطلاع‌رسانی به یادگیرندگان'],['dashboard/analytics','آمار و گزارش‌ها','مرور داده‌های فعالیت پلتفرم'],['dashboard/glossary','واژه‌نامه','مدیریت واژه‌ها و مفاهیم آموزشی']] as [$href,$label,$hint]) echo '<a href="'.h(url($href)).'"><strong>'.h($label).'<span aria-hidden="true">←</span></strong><span>'.h($hint).'</span></a>';
    echo '</div></section>';
}
function clamp(int $min,int $cur,int $max=99): int { return max($min,min($cur,$max)); }
function pager(int $total,int $perPage,string $base,string $query=''): void {
    $pages=max(1,(int)ceil($total/$perPage)); if ($pages<2) return;
    $page=clamp(1,(int)($_GET['page'] ?? 1),$pages);
    echo '<nav class="pager" aria-label="صفحه‌بندی">';
    if ($page>1) echo '<a href="'.h(url($base.'?page='.($page-1).$query)).'">صفحه قبل</a>'; else echo '<span class="is-disabled">صفحه قبل</span>';
    echo '<span>صفحه '.fa($page).' از '.fa($pages).'</span>';
    if ($page<$pages) echo '<a href="'.h(url($base.'?page='.($page+1).$query)).'">صفحه بعد</a>'; else echo '<span class="is-disabled">صفحه بعد</span>';
    echo '</nav>';
}
function foot_page(bool $panel=false): void {
    if ($panel) { echo '</div></body></html>'; return; }
    echo '<footer class="footer" dir="rtl"><div class="container footer-shell"><div class="footer-main"><div class="footer-intro"><a href="'.h(url('')).'" class="footer-brand" aria-label="Noventix، صفحه اصلی"><img class="footer-mark" src="/assets/noventix-logo.webp?v=2" alt="Noventix" width="64" height="64"><span class="footer-brand-name" lang="en" dir="ltr">Noventix</span></a><p>یادگیری پروژه‌محور پایتون و هوش مصنوعی به زبان فارسی؛ از فهم مفهوم تا ساختن پروژه.</p><p class="footer-message">هر مفهوم، یک قدم برای ساختن بهتر</p></div><nav class="footer-links" aria-label="راهنمای پایین صفحه"><section class="footer-column"><h2>یادگیری و منابع</h2>';
    linkto('articles','مقاله‌های آموزشی'); if (in_array(FREE_COURSE_SLUG,array_column(published_courses(),'slug'),true)) linkto('course/'.FREE_COURSE_SLUG,'دورهٔ رایگان'); linkto('cart','سبد خرید'); linkto('projects','پروژه‌های عملی'); linkto('glossary','واژه‌نامهٔ هوش مصنوعی'); linkto('community','انجمن یادگیری');
    echo '</section><section class="footer-column"><h2>دربارهٔ نوونتیکس</h2>';
    linkto('about','دربارهٔ ما'); linkto('learners','جامعهٔ یادگیرندگان'); linkto('pricing','پلن‌ها و امکانات');
    echo '</section><section class="footer-column"><h2>ارتباط و راهنما</h2>';
    linkto('contact','تماس با ما'); linkto('help','مرکز راهنما'); linkto('dashboard/support','پشتیبانی حساب');
    echo '<div class="footer-socials"><a href="https://instagram.com/noventix.ir" lang="en" dir="ltr">Instagram</a><a href="https://t.me/noventix_ir" lang="en" dir="ltr">Telegram</a><a href="https://www.aparat.com/noventix.ir">آپارات</a></div></section></nav></div><div class="footer-bottom"><div class="footer-trust"><a referrerpolicy="origin" target="_blank" rel="noopener noreferrer" href="https://trustseal.enamad.ir/?id=8641030&amp;Code=P20i20MkqQZQ188j2OBeA3QHoZHurpL1" aria-label="نماد اعتماد الکترونیکی"><img referrerpolicy="origin" src="https://trustseal.enamad.ir/logo.aspx?id=8641030&amp;Code=P20i20MkqQZQ188j2OBeA3QHoZHurpL1" alt="نماد اعتماد الکترونیکی" width="100" height="100" loading="lazy" decoding="async" code="P20i20MkqQZQ188j2OBeA3QHoZHurpL1"></a></div><small>© '.fa(date('Y')).' نوونتیکس؛ تمامی حقوق محفوظ است.</small><nav class="footer-bottom-links" aria-label="قوانین استفاده">';
    linkto('terms','قوانین و مقررات'); linkto('privacy','حریم خصوصی');
    echo '</nav></div></div></footer></body></html>';
}
function notice(): void { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="container"><div role="status" class="notice '.h($f['kind']).'">'.h($f['text']).'</div></div>'; } }
function section_header(string $eyebrow,string $title,string $body=''): void { echo '<div class="section-header"><span class="eyebrow">'.h($eyebrow).'</span><h2>'.h($title).'</h2>'; if ($body) echo '<p>'.h($body).'</p>'; echo '</div>'; }
function plan_is_available(string $id): bool {
    if ($id==='free') return true;
    return COMMERCE_ENABLED && payment_ready() && payment_plan_courses($id)!==[];
}
function available_plan_ids(): array {
    return array_values(array_filter(array_keys(PLANS),static fn(string $id): bool=>plan_is_available($id)));
}
function upcoming_plan_cards(): void {
    $plans=[
        'bronze'=>['برنزی','شروعی محکم و هدفمند','برای ساختن پایه‌های درست و برداشتن اولین قدم‌های مطمئن در دنیای برنامه‌نویسی.',['تمرکز بر مفاهیم پایه','عادت به تمرین مستمر','حرکت از مفهوم به کد']],
        'silver'=>['نقره‌ای','از یادگیری به تجربه','برای عبور از مثال‌های ساده و پرورش نگاه مسئله‌محور در مسیر یادگیری.',['تقویت مهارت حل مسئله','تمرین با سناریوهای کاربردی','تبدیل دانش به تجربه']],
        'gold'=>['طلایی','نگاهی حرفه‌ای به ساختن','برای کسانی که به کیفیت خروجی، تحلیل دقیق‌تر و ساختن پروژه‌های جدی فکر می‌کنند.',['تفکر تحلیلی و دقیق','توجه به کیفیت پروژه','ساخت خروجی قابل ارائه']],
        'titanium'=>['تیتانیومی','فراتر از مسیر معمول','برای ذهن‌های بلندپرواز؛ با تمرکز بر چالش‌های عمیق‌تر و رشد مستقل مهارت‌ها.',['نگاه جامع به مسئله','تمرکز بر چالش‌های پیچیده','رشد مستمر و مستقل']]
    ];
    echo '<div class="upcoming-plans">';
    foreach ($plans as $id=>[$name,$tagline,$description,$focus]) {
        $available=plan_is_available($id);
        echo '<article class="upcoming-plan plan-'.h($id).'" aria-label="پلن '.h($name).($available?'':'؛ به‌زودی').'">';
        echo '<span class="eyebrow">اشتراک Noventix</span><h2>پلن '.h($name).'</h2><h3>'.h($tagline).'</h3><p class="plan-description">'.h($description).'</p><ul class="plan-focus">';
        foreach ($focus as $item) echo '<li>'.h($item).'</li>';
        echo '</ul>';
        if ($available) {
            echo '<div class="plan-price">'.money((int)PLANS[$id]['price']).'<small>/ ماه</small></div>';
            plan_purchase_button($id,'btn btn-primary full');
        } else {
            echo '<div class="plan-availability"><div class="plan-chain" aria-hidden="true">';
            for ($i=0;$i<9;$i++) echo '<span></span>';
            echo '</div><strong class="plan-coming-soon">به‌زودی</strong></div>';
        }
        echo '</article>';
    }
    echo '</div>';
}
function plan_cards(bool $short=false): void {
    $available=available_plan_ids();
    echo '<div class="pricing-grid pricing-grid-active">';
    foreach ($available as $id) {
        $p=PLANS[$id];
        echo '<article class="price-card '.($id==='gold'?'featured':'').'" data-plan="'.h($id).'"'.($id==='gold'?' aria-label="پیشنهاد ویژه"':'').'><div class="card-top"><span class="pill">'.h($p['tag']).'</span>'.($id==='gold'?'<span class="popular">پیشنهاد ما</span>':'').'</div><h3>'.h($p['name']).'</h3><p class="muted">'.h($p['summary']).'</p><div class="price">'.($p['price']?money($p['price']):'رایگان').'<small>'.($p['price']?'/ ماه':'برای همیشه').'</small></div><div class="card-divider"></div><ul class="check-list">';
        foreach (array_slice($p['features'],0,$short?3:count($p['features'])) as $feature) echo '<li>'.h($feature).'</li>';
        echo '</ul>';
        if (!$short && !empty($p['limits'])) { echo '<div class="plan-limits"><span class="eyebrow">سقف دقیق این پلن</span><table><tbody>'; foreach ($p['limits'] as [$label,$value]) echo '<tr><th scope="row">'.h($label).'</th><td>'.h($value).'</td></tr>'; echo '</tbody></table></div>'; }
        if ($id==='free') linkto('course/'.FREE_COURSE_SLUG,'شروع دورهٔ رایگان','btn btn-outline full');
        else plan_purchase_button($id,'btn '.($id==='gold'?'btn-gold':'btn-outline').' full');
        echo '</article>';
    }
    echo '</div>';
}
function community_post_controls(array $post,?array $viewer,string $returnTo): void {
    $id=(int)$post['id'];
    $likes=(int)q("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='like'",[$id])->fetchColumn();
    $liked=$viewer && (bool)q("SELECT 1 FROM post_reactions WHERE post_id=? AND user_id=? AND kind='like'",[$id,$viewer['id']])->fetchColumn();
    $icon='<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>';
    echo '<div class="post-actions">';
    if ($viewer) {
        form_start('community/react','react-form');
        echo '<input type="hidden" name="post_id" value="'.$id.'"><input type="hidden" name="kind" value="like"><input type="hidden" name="active" value="'.($liked?'0':'1').'"><input type="hidden" name="return_to" value="'.h($returnTo).'"><button class="heart-button'.($liked?' is-liked':'').'" aria-pressed="'.($liked?'true':'false').'" aria-label="'.($liked?'برداشتن پسند از پست':'پسندیدن پست').'">'.$icon.'<span>'.fa($likes).'</span></button></form>';
    } else echo '<a class="heart-button" href="'.h(url('login')).'" aria-label="برای پسندیدن پست وارد شوید">'.$icon.'<span>'.fa($likes).'</span></a>';
    if ($viewer && ((int)$viewer['id']===(int)$post['user_id'] || $viewer['role']==='admin')) {
        echo '<details class="post-delete"><summary>حذف پست</summary><p>این پست و همهٔ پاسخ‌ها و پسندهای آن حذف می‌شوند. این کار برگشت‌پذیر نیست.</p>';
        form_start('community/delete','stack-form');
        echo '<input type="hidden" name="post_id" value="'.$id.'"><input type="hidden" name="return_to" value="'.h($returnTo).'"><label class="delete-confirm"><input type="checkbox" name="confirm" value="delete" required> حذف پست و پاسخ‌ها را تأیید می‌کنم</label><button class="btn btn-danger">حذف نهایی پست</button></form></details>';
    }
    echo '</div>';
}

function project_cards(int $limit=99): void {
    $covers=[0=>['house-price-regression','House price regression prediction project'],1=>['multiclass-flower-classification','Multiclass flower classification project'],2=>['diabetes-risk-classification','Diabetes risk classification project'],3=>['sentiment-analysis','Sentiment analysis project'],4=>['flight-delay-prediction','Flight delay prediction project'],5=>['weather-temperature-forecast','Weather temperature forecasting project'],6=>['housing-price-prediction','Housing price prediction project'],7=>['iris-flower-classification','Iris flower classification project'],8=>['project-nine-neural-network','Flight delay analysis project'],9=>['flight-delay-analysis','Flight delay analysis project'],10=>['sentiment-classification','Sentiment classification project'],11=>['machine-learning-pipeline','Machine learning pipeline project'],12=>['python-welcome-card','کارت خوشامدگویی شخصی در پایتون'],13=>['python-shopping-receipt','محاسبهٔ مبلغ خرید در پایتون'],14=>['python-temperature-converter','تبدیل دمای سانتی‌گراد و فارنهایت'],15=>['python-grade-conditions','بررسی شرط قبولی نمره'],16=>['python-even-numbers','جداسازی و جمع اعداد زوج'],17=>['python-measurement-summary','خلاصه‌سازی فهرست اندازه‌گیری‌ها'],18=>['python-word-count','شمارش فراوانی واژه‌های متن'],19=>['python-book-search','جست‌وجو در عنوان کتاب‌ها'],20=>['python-inventory','تجمیع موجودی کالاها در پایتون'],21=>['python-cart-discount','سبد خرید با تخفیف'],22=>['python-boolean-rules','بررسی قواعد رشتهٔ آزمایشی'],23=>['python-unique-participants','فهرست یکتای شرکت‌کنندگان'],24=>['python-class-report','کارنامهٔ خلاصهٔ کلاس'],25=>['python-unicode-id','پاک‌ساز شناسهٔ عددی ساختگی'],26=>['python-csv-sales','گزارش فروش از متن CSV'],27=>['python-json-settings','بارگذاری تنظیمات برنامه'],28=>['python-file-extensions','فهرست‌ساز پسوند فایل‌ها'],29=>['python-safe-number','مبدل امن متن به عدد صحیح'],30=>['python-deadline-days','شمارندهٔ روز تا موعد'],31=>['python-study-statistics','گزارش مقاوم زمان مطالعه'],32=>['python-decimal-invoice','فاکتور دقیق با اعشار ده‌دهی'],33=>['python-prime-testing','تشخیص عدد اول با آزمون مرزی'],34=>['python-numpy-report','گزارش عددی با آرایهٔ NumPy'],35=>['python-pandas-expenses','گزارش دسته‌های هزینه با Pandas']];
    echo '<div class="project-grid">'; foreach (array_slice(PROJECTS,0,$limit,true) as $i=>$p) { $image=$covers[$i] ?? null; echo '<article class="project-card"><div class="project-visual visual-'.($i%4).($i===8?' project-nine':'').($image ? ' has-project-image' : '').'">'.($image ? '<picture>'.($i<12 ? '<source srcset="/assets/'.h($image[0]).'.webp" type="image/webp">' : '').'<img src="/assets/'.h($image[0]).'.jpg" alt="'.h($image[1]).'" width="1536" height="1024" loading="lazy" decoding="async"></picture>' : '').'<span class="visual-number">'.fa(str_pad((string)($i+1),2,'0',STR_PAD_LEFT)).'</span>'.($image ? '' : '<span class="visual-glyph">'.(['{ }','◈','◇','⌘'][$i%4]).'</span>').'<span class="visual-label">'.h(strtoupper($p['topic'])).'</span></div><div class="project-info"><div class="meta-row"><span class="pill">'.h($p['topic']).'</span><span>'.h($p['level']).'</span></div><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p><div class="project-bottom"><span dir="ltr">'.h($p['skills']).'</span><a href="'.h(url('project/'.$i)).'" aria-label="مشاهده پروژه '.h($p['title']).'">مشاهده پروژه ←</a></div></div></article>'; } echo '</div>';
}
function course_cards(array $courses): void {
    if (!$courses) { echo '<div class="empty-state"><h2>هنوز دوره‌ای منتشر نشده است.</h2><p>به‌زودی مسیرهای یادگیری جدید در این بخش قرار می‌گیرند.</p></div>'; return; }
    echo '<div class="course-grid">'; $i=0; $catalog=cart_catalog(); foreach ($courses as $c) { $cartKey='course:'.$c['slug']; echo '<article class="course-card"><div class="course-icon icon-'.($i++%4).'">'.h(mb_substr($c['title'],0,1)).'</div><div class="meta-row"><span class="pill">دوره</span><span>منتشرشده</span></div><h3>'.h($c['title']).'</h3><p>'.h($c['description']).'</p><a class="inline-link" href="'.h(url('course/'.$c['slug'])).'">مشاهدهٔ دوره ←</a>'.(isset($catalog[$cartKey])?'<form method="post" action="'.h(url('cart/add')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="key" value="'.h($cartKey).'"><button class="btn btn-outline">افزودن به سبد · '.money((int)$catalog[$cartKey]['price']).'</button></form>':'').'</article>'; } echo '</div>';
}
function home(array $courses): void {
    echo '<main><section class="hero container"><div class="hero-copy"><span class="eyebrow"><span class="dot"></span> مسیر روشن یادگیری هوش مصنوعی</span><h1>یاد بگیر،<br><em>بساز و پیشرفت کن.</em></h1><p>پایتون، علم داده و هوش مصنوعی را با توضیح‌های روشن یاد بگیر و با پروژه‌های واقعی به مهارت قابل استفاده تبدیل کن.</p><div class="hero-buttons">'; linkto('course/'.FREE_COURSE_SLUG,'شروع دوره رایگان ←','btn btn-primary'); linkto('projects','دیدن پروژه‌ها','btn btn-outline'); echo '</div><div class="hero-proof"><span>✦ مسیر پروژه‌محور</span><span>✦ یادگیری به زبان فارسی</span><span>✦ شروع رایگان</span></div></div><div class="hero-display hero-code-display"><div class="hero-window hero-code-window" aria-label="نمونه کد پایتون و خروجی آن"><div class="window-top"><span class="window-dots" aria-hidden="true"><i></i><i></i><i></i></span><span dir="ltr">noventix / first_project.py</span><span class="code-label">PYTHON</span></div><div class="hero-code-caption"><span class="hero-code-kicker" dir="ltr">IDEA → CODE → IMPACT</span><h2>ایده‌ات را به کد تبدیل کن.</h2><p>از چند خط ساده تا اولین پروژهٔ واقعی تو.</p></div><div class="hero-code-tab" dir="ltr"><span>first_project.py</span><small>Python 3</small></div><pre dir="ltr" tabindex="0" aria-label="نمونه کد محاسبه میانگین امتیازها"><code><span class="hero-code-line"><span class="code-purple">def</span> <span class="code-yellow">analyze</span>(scores):</span>
<span class="hero-code-line">    total = <span class="code-yellow">sum</span>(scores)</span>
<span class="hero-code-line">    <span class="code-purple">return</span> total / <span class="code-yellow">len</span>(scores)</span>
<span class="hero-code-line"> </span>
<span class="hero-code-line">scores = [<span class="code-blue">12</span>, <span class="code-blue">16</span>, <span class="code-blue">20</span>]</span>
<span class="hero-code-line">average = <span class="code-yellow">analyze</span>(scores)</span>
<span class="hero-code-line"><span class="code-yellow">print</span>(<span class="code-green">f"Average: {average:.1f}"</span>)</span></code></pre><div class="hero-code-terminal"><div><span class="status-dot" aria-hidden="true"></span><span>خروجی نمونه</span><span class="hero-code-terminal-tag" dir="ltr">TERMINAL</span></div><samp dir="ltr">Average: 16.0</samp></div><div class="window-footer"><span>این باکس نمایشی است؛ در میزکار کد بنویس و اجرا کن.</span><a href="/projects">شروع ساخت ←</a></div></div></div></section>';
    echo '<section class="metrics"><div class="container metrics-inner"><div><strong>۰۱</strong><span>مقاله‌های روشن و کاربردی</span></div><div><strong>۰۲</strong><span>تمرین روی پروژه واقعی</span></div><div><strong>۰۳</strong><span>بازخورد و رشد مستمر</span></div><div><strong>∞</strong><span>مسیرهای تازه برای ساختن</span></div></div></section>';
    echo '<section class="section container">'; section_header('روش یادگیری ما','از فهمیدن تا ساختن، یک مسیر روشن','هر مفهوم را با توضیح ساده شروع کن، در یک پروژه به کار ببر و نتیجه‌اش را ببین.'); echo '<div class="features-grid"><article class="feature"><div class="feature-icon">◇</div><span class="feature-n">01 / DISCOVER</span><h3>بخوان و بفهم</h3><p>مفهوم‌های پایتون، ماشین لرنینگ و علم داده را در مقاله‌های کوتاه و هدفمند یاد بگیر.</p></article><article class="feature"><div class="feature-icon">⌘</div><span class="feature-n">02 / BUILD</span><h3>یادگیری با ساختن</h3><p>از تحلیل داده تا مدل‌های یادگیری عمیق، مهارت‌هایت را روی مسئله‌های واقعی آزمایش کن.</p></article><article class="feature"><div class="feature-icon">✳</div><span class="feature-n">03 / GROW</span><h3>پیشرفت قابل مشاهده</h3><p>پروژه‌هایت را در میزکار دنبال کن و با گفت‌وگو در انجمن، راه‌حل‌های بهتری پیدا کن.</p></article></div></section>';
    echo '<section class="section alt-section"><div class="container"><div class="section-row home-section-row">'; section_header('مقاله‌های آموزشی','از کجا شروع می‌کنی؟','راهنماهای مفصل و کاربردی برای هر مرحله از یادگیری.'); linkto('articles','همه مقاله‌ها ←','btn btn-outline home-section-link'); echo '</div>'; article_cards(6); echo '</div></section>';
    echo '<section class="section container"><div class="section-row home-section-row">'; section_header('یادگیری در عمل','پروژه‌هایی برای دنیای واقعی','از مسئله‌های کوچک شروع کن و به ساختن راه‌حل‌های قابل ارائه برس.'); linkto('projects','همه پروژه‌ها ←','btn btn-outline home-section-link'); echo '</div>'; project_cards(3); echo '</section>';
    echo '<section class="section dark-band"><div class="container band-inner"><div><span class="eyebrow">یادگیری که به عمل می‌رسد</span><h2>هر مفهوم،<br>یک قدم برای ساختن.</h2><p>مسئله را بفهم، راه‌حل را امتحان کن و مهارتی بساز که در موقعیت‌های تازه هم به کارت بیاید.</p>'; linkto('about','درباره رویکرد ما ←','btn btn-light'); echo '</div><div class="band-steps"><div><span>01</span><strong>درک مفهوم</strong><small>با سؤال درست شروع کن</small></div><div><span>02</span><strong>ساخت پروژه</strong><small>راه‌حل را امتحان کن</small></div><div><span>03</span><strong>انتقال مهارت</strong><small>در مسئله تازه مستقل باش</small></div></div></div></section>';
    echo '<section class="section container"><div class="section-header"><span class="eyebrow">برای شروع، نه وعدهٔ استخدام</span><h2>می‌دانی، اما هنوز نمی‌سازی؟</h2><p>Noventix برای مبتدی‌هایی است که می‌خواهند برنامه‌نویسی و هوش مصنوعی را با حل مسئله و تمرین واقعی یاد بگیرند.</p></div><div class="features-grid"><article class="feature"><h3>۱. حساب بساز</h3><p>نام، شماره و رمز را ثبت کن و همان‌جا وارد سایت شو.</p></article><article class="feature"><h3>۲. دورهٔ رایگان را شروع کن</h3><p>از صفحهٔ دوره، ثبت‌نام کن؛ جلسه‌ها و آزمون‌های موجود در پنل در دسترس‌اند. تکمیل درس به پاسخ آزمون وابسته است، نه صرفاً بازکردن ویدئو.</p></article><article class="feature"><h3>۳. تمرین کن و برگرد</h3><p>فایل‌های تمرینت را در میزکار خصوصی ذخیره کن. اجرای کد به اتصال سرویس ایزوله وابسته است؛ پخش آپارات به‌تنهایی پیشرفت خودکار ثبت نمی‌کند.</p></article></div></section>';
    echo '<section class="section container"><div class="section-header"><span class="eyebrow">پلن‌های Noventix · به‌زودی</span><h2>پلن مناسب مسیرت</h2><p>برنزی، نقره‌ای، طلایی و تیتانیومی؛ چهار مسیر برای شروع هدفمند، تمرین کاربردی و رشد حرفه‌ای در دنیای برنامه‌نویسی.</p></div>'; upcoming_plan_cards(); echo '<div class="home-plans-link">'; linkto('pricing#plan-features','مشاهدهٔ امکانات پلن‌ها','btn btn-outline'); echo '</div></section>';
    echo '<section class="section container"><div class="cta-panel"><span class="eyebrow">آینده از همین قدم شروع می‌شود</span><h2>پروژه بعدی‌ات منتظر توست.</h2><p>مسیرت را انتخاب کن، یاد بگیر و چیزی بساز که بتوانی به آن افتخار کنی.</p>'; linkto('login','رایگان شروع کن ←','btn btn-light'); echo '</div></section></main>';
}
function panel_head(string $title,string $sub): void { echo '<div class="panel-heading"><h1>'.h($title).'</h1><p>'.h($sub).'</p></div>'; }
function course_workspace_duration(?int $minutes): string { return $minutes===null ? 'مدت ثبت نشده' : fa($minutes).' دقیقه'; }
function course_workspace_lesson_link(string $slug,array $lesson): string { return h(url('dashboard/course/'.$slug.'?lesson='.(int)$lesson['id'])); }
function free_course_player(bool $canWatch): void {
    if (!$canWatch) {
        echo '<div class="fc-access-gate"><span class="fc-lock-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg></span><span class="fc-kicker">یک قدم تا شروع یادگیری</span><h3>برای تماشای دوره، ثبت‌نام کن</h3><p>با شمارهٔ موبایل ثبت‌نام کن؛ حساب ساخته می‌شود و جلسهٔ اول همین‌جا برایت باز می‌شود.</p>';
        linkto('register?next='.rawurlencode('course/'.FREE_COURSE_SLUG),'ثبت‌نام با شماره موبایل','btn btn-primary');
        linkto('login?next='.rawurlencode('course/'.FREE_COURSE_SLUG),'حساب دارم؛ ورود به حساب','fc-text-link');
        echo '<small>کاملاً رایگان · بدون نیاز به پرداخت</small></div>';
        return;
    }
    echo '<div id="fc-video" class="fc-player"><iframe src="https://www.aparat.com/video/video/embed/videohash/qbk51d2/vt/frame?titleShow=true" title="جلسهٔ اول دورهٔ رایگان: مبانی هوش مصنوعی" allow="fullscreen; picture-in-picture" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div><div class="fc-video-help"><div class="fc-playback-heading"><span class="fc-playback-dot" aria-hidden="true"></span><strong>راهنمای پخش ویدیو</strong><span>پخش از آپارات</span></div><p id="fc-player-status" role="status" aria-live="polite">اگر ویدیو پخش نمی‌شود، روش جایگزین را امتحان کنید یا آن را در آپارات ببینید.</p><div class="fc-player-tools"><button id="fc-switch-player" class="fc-player-action" type="button" aria-controls="fc-video" hidden><span class="fc-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1"/></svg></span><span class="fc-action-copy"><strong id="fc-switch-label">پخش‌کنندهٔ جایگزین</strong><small id="fc-switch-description">تغییر روش پخش در همین صفحه</small></span></button><a class="fc-player-action fc-aparat-action" href="https://www.aparat.com/v/qbk51d2" target="_blank" rel="noopener noreferrer"><span class="fc-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 4h6v6M20 4l-9 9M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg></span><span class="fc-action-copy"><strong>تماشا در آپارات</strong><small>باز شدن ویدیو در تب جدید</small></span><span class="fc-action-arrow" aria-hidden="true">↗</span></a></div></div>';
}
function page_free_course(array $course): void {
    global $me;
    $canWatch=$me && trim($me['name'])!=='';
    $enrolled=$canWatch && (bool)q('SELECT 1 FROM enrollments WHERE user_id=? AND course_slug=?',[$me['id'],$course['slug']])->fetchColumn();
    $lessons=q("SELECT id,title,content,position,duration_minutes FROM lessons WHERE course_id=? AND status='published' ORDER BY position ASC",[(int)$course['id']])->fetchAll();
    $first=$lessons[0] ?? null;
    $total=count($lessons);
    $duration=$course['estimated_duration_minutes'];
    if ($duration===null && $lessons) {
        $durations=array_column($lessons,'duration_minutes');
        $duration=!in_array(null,$durations,true) ? array_sum($durations) : null;
    }
    $durationLabel=$duration===null ? 'مدت ثبت نشده' : fa((int)$duration).' دقیقه';
    head_page($course['title'],$course['description'],false,true);
    notice();
    echo '<main class="free-course"><section class="fc-hero"><div class="container fc-hero-grid"><div class="fc-intro"><nav class="fc-breadcrumb" aria-label="مسیر صفحه">';
    linkto('','خانه'); echo '<span aria-hidden="true">/</span><span aria-current="page">'.h($course['title']).'</span></nav><span class="fc-eyebrow"><span aria-hidden="true"></span>مسیر یادگیری · دوره</span><h1>'.h($course['title']).'</h1><p class="fc-lead">'.h($course['description']).'</p><div class="fc-actions"><a href="#lesson-1" class="btn btn-primary">'.($canWatch?'شروع تماشا':'ثبت‌نام و شروع رایگان').' <span aria-hidden="true">←</span></a><a href="#course-sessions" class="fc-text-link">دیدن سرفصل‌ها</a></div><ul class="fc-facts"><li>'.($course['slug']===FREE_COURSE_SLUG?'کاملاً رایگان':'دسترسی مطابق پلن').'</li><li>'.fa($total).' جلسه</li><li>'.h($durationLabel).'</li></ul></div><aside class="fc-start-card" aria-label="معرفی دوره"><div class="fc-card-top"><span>مسیر شروع یادگیری</span><span class="fc-free-tag">'.($course['slug']===FREE_COURSE_SLUG?'رایگان':'دوره').'</span></div><div class="fc-concept" aria-hidden="true"><span>AI</span><i></i><b>'.fa(str_pad((string)$total,2,'0',STR_PAD_LEFT)).'</b></div><h2>'.h($first['title'] ?? 'به‌زودی').'</h2><p>'.h($first ? mb_substr(trim(strip_tags((string)$first['content'])),0,120).'…' : 'جلسه‌های این دوره به‌زودی منتشر می‌شوند.').'</p><div class="fc-card-bottom"><span>'.fa($total).' جلسه در دسترس</span><span>'.h($durationLabel).'</span></div></aside></div></section><section class="container fc-course-layout" aria-label="محتوای دوره"><div class="fc-main">';
    if (!$first) {
        echo '<section class="fc-lesson"><div class="empty-state"><h2>هنوز جلسه‌ای منتشر نشده است</h2><p>به‌محض انتشار اولین جلسه، همین‌جا نمایش داده می‌شود.</p></div></section>';
    } else {
        echo '<section id="lesson-1" class="fc-lesson" aria-labelledby="fc-lesson-title"><div class="fc-section-heading"><div><span class="fc-kicker">جلسهٔ اول · آمادهٔ تماشا</span><h2 id="fc-lesson-title">'.h($first['title']).'</h2></div><span class="fc-available">منتشرشده</span></div>';
        free_course_player($canWatch);
        echo '<div class="fc-lesson-description"><h3>دربارهٔ این جلسه</h3><p>'.nl2br(h((string)$first['content'])).'</p><p class="fc-note">تماشای جلسه‌ها فقط پس از ثبت‌نام با شمارهٔ موبایل امکان‌پذیر است. پخش ویدیو به‌تنهایی پیشرفت آموزشی را ثبت نمی‌کند.</p></div></section>';
    }
    echo '<section class="fc-about" aria-labelledby="fc-about-title"><span class="fc-kicker">برای شروع، نه پیچیده‌تر کردن</span><h2 id="fc-about-title">این دوره برای توست اگر…</h2><div class="fc-audience"><article><span aria-hidden="true">۰۱</span><h3>تازه شروع کرده‌ای</h3><p>می‌خواهی مفاهیم پایه را بشناسی و برای اولین قدم، نقطهٔ شروع روشنی داشته باشی.</p></article><article><span aria-hidden="true">۰۲</span><h3>دنبال توضیح ساده‌ای</h3><p>ترجیح می‌دهی پیش از رفتن سراغ ابزارها و اصطلاحات تخصصی، اصل موضوع را بفهمی.</p></article></div></section></div><aside class="fc-sidebar"><section id="course-sessions" class="fc-sessions" aria-labelledby="fc-sessions-title"><div class="fc-sessions-header"><span class="fc-kicker">قدم‌به‌قدم جلو برو</span><h2 id="fc-sessions-title">جلسه‌های دوره</h2><p>'.fa($total).' جلسه منتشرشده</p></div><ol>';
    foreach ($lessons as $index=>$lesson) {
        $current=$index===0;
        echo '<li class="'.($current?'fc-session-current':'fc-session-soon').'">'.($current?'<a href="#lesson-1">':'<span>').'<span class="fc-session-number">'.fa(str_pad((string)((int)$lesson['position']),2,'0',STR_PAD_LEFT)).'</span><span><strong>'.h($lesson['title']).'</strong><small>'.($lesson['duration_minutes']===null?'مدت ثبت نشده':fa((int)$lesson['duration_minutes']).' دقیقه').'</small></span>'.($current?'<span class="fc-play-icon" aria-hidden="true">▶</span></a>':'<span class="fc-soon-tag">در پنل</span></span>').'</li>';
    }
    if (!$lessons) echo '<li class="fc-session-soon"><span class="fc-session-number">—</span><span><strong>جلسه‌ای منتشر نشده</strong><small>به‌زودی</small></span></li>';
    echo '</ol><p class="fc-session-note">جلسه‌های بعدی پس از انتشار، در همین فهرست و در فضای یادگیری پنل در دسترس قرار می‌گیرند.</p></section><section class="fc-account"><span class="fc-kicker">ادامه در فضای یادگیری</span><h2>مسیرت را دنبال کن</h2><p>برای تماشای جلسه‌ها و دسترسی به فضای یادگیری دوره، باید با شمارهٔ موبایل ثبت‌نام کنی؛ ثبت‌نام همین حالا حساب تو را می‌سازد.</p>';
    if ($canWatch) course_start_action($course,$me,$enrolled,'btn btn-outline');
    else linkto('register?next='.rawurlencode('course/'.$course['slug']),'ثبت‌نام با شماره موبایل','btn btn-outline');
    echo '</section></aside></section><section class="container fc-bottom-note"><p>لازم نیست از همه جلوتر باشی؛ کافی‌ست اولین قدم را برداری.</p><a href="#lesson-1">شروع از جلسهٔ اول <span aria-hidden="true">↑</span></a></section></main>';
    foot_page();
}
function page_course_workspace(?array $course,string $requestedLesson): void {
    if (!$course) { http_response_code(404); echo '<main class="panel-content workspace-error"><div class="empty-state"><span class="eyebrow">404</span><h1>Course پیدا نشد.</h1><p>این Course منتشر نشده یا دیگر در دسترس نیست.</p>'; linkto('dashboard','بازگشت به پنل ←','btn btn-primary'); echo '</div></main>'; return; }
    $selection=course_workspace_selection($course,$requestedLesson); $current=$selection['current']; $duration=course_duration_minutes($course);
    echo '<main class="panel-content course-workspace"><nav class="workspace-breadcrumb" aria-label="مسیر صفحه">'; linkto('dashboard/learning','مسیر یادگیری',''); echo '<span aria-hidden="true">/</span><span aria-current="page">'.h($course['title']).'</span></nav><header class="workspace-header"><div><span class="eyebrow">فضای یادگیری · دوره</span><h1>'.h($course['title']).'</h1><p>'.h($course['description']).'</p></div><div class="workspace-meta"><span>'.fa(count($course['lessons'])).' جلسه</span><span>'.h(course_workspace_duration($duration)).'</span></div></header>';
    if (!$current) { echo '<section class="workspace-empty empty-state"><h2>هنوز Lesson منتشرشده‌ای وجود ندارد.</h2><p>به‌محض انتشار Lesson، مسیر یادگیری از همین‌جا نمایش داده می‌شود.</p></section></main>'; return; }
    $hasVideo=$course['slug']==='ai-foundations' && (int)$current['position']===1;
    echo '<div class="workspace-layout"><section class="workspace-main"><div class="video-placeholder'.($hasVideo?' has-aparat':'').'" aria-label="ویدیوی جلسه">';
    if ($hasVideo) {
        echo '<iframe class="aparat-embed" src="https://www.aparat.com/video/video/embed/videohash/qbk51d2/vt/frame?titleShow=true" title="جلسهٔ اول: مبانی هوش مصنوعی" allow="fullscreen; picture-in-picture" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    } else {
        echo '<div class="video-placeholder-inner"><strong>ویدیوی این جلسه هنوز اضافه نشده است</strong><span>تا زمان انتشار ویدیو، متن درس را در همین صفحه مطالعه کنید.</span></div>';
    }
    echo '</div>'; if ($hasVideo) echo '<p class="workspace-video-note">این ویدیو از آپارات پخش می‌شود؛ برای ثبت پیشرفت و تکمیل درس، آزمون پایان درس را با موفقیت بگذرانید.</p>';
    echo '<section class="current-lesson"><div class="workspace-kicker">جلسه '.fa((int)$current['position']).' از '.fa(count($course['lessons'])).'</div><h2>'.h($current['title']).'</h2><div class="current-lesson-meta"><span>'.h(course_workspace_duration($current['duration_minutes']===null?null:(int)$current['duration_minutes'])).'</span><span>متن درس</span></div><p>'.nl2br(h($current['content'])).'</p></section>';
    ws_lesson_exercises((int)$GLOBALS['me']['id'],(int)$current['id']);
    echo '<section class="current-lesson lesson-quiz"><h2>آزمون پایان درس</h2>';
    if ($course['quiz']) {
        echo '<p>برای تکمیل درس باید به حداقل '.fa($course['quiz']['pass_percent']).'٪ پرسش‌ها پاسخ درست بدهید.</p>';
        form_start('progress/save');
        echo '<input type="hidden" name="quiz_submit" value="1"><input type="hidden" name="lesson_id" value="'.(int)$current['id'].'">';
        foreach ($course['quiz']['questions'] as $i=>$question) {
            echo '<fieldset class="lesson-quiz-question"><legend>'.fa($i+1).'. '.h($question['prompt']).'</legend>';
            foreach ($question['options'] as $j=>$option) echo '<label><input type="radio" name="answers['.$i.']" value="'.$j.'" required><span>'.h($option).'</span></label>';
            echo '</fieldset>';
        }
        echo '<button type="submit" class="btn btn-primary">ثبت پاسخ‌ها</button></form>';
    } else echo '<p>آزمون معتبری برای این درس منتشر نشده است؛ بازکردن درس یا تماشای ویدئو آن را تکمیل نمی‌کند.</p>';
    echo '</section><nav class="lesson-navigation" aria-label="ناوبری جلسه‌ها">';
    if ($selection['previous']) echo '<a class="lesson-nav-link" href="'.course_workspace_lesson_link($course['slug'],$selection['previous']).'"><span>← جلسهٔ قبلی</span><strong>'.h($selection['previous']['title']).'</strong></a>'; else echo '<span class="lesson-nav-link is-disabled"><span>← جلسهٔ قبلی</span><strong>ابتدای مسیر</strong></span>';
    if ($selection['next']) echo '<a class="lesson-nav-link is-next" href="'.course_workspace_lesson_link($course['slug'],$selection['next']).'"><span>جلسهٔ بعدی →</span><strong>'.h($selection['next']['title']).'</strong></a>'; else echo '<span class="lesson-nav-link is-disabled is-next"><span>جلسهٔ بعدی →</span><strong>پایان مسیر</strong></span>';
    echo '</nav></section><aside class="workspace-sidebar" aria-label="فهرست جلسه‌ها"><div class="workspace-sidebar-head"><span class="eyebrow">سرفصل‌ها</span><strong>'.fa(count($course['lessons'])).' جلسه</strong></div><div class="workspace-lesson-list">'; foreach ($course['lessons'] as $lesson) { $active=(int)$lesson['id']===(int)$current['id']; echo '<a class="workspace-lesson'.($active?' is-active':''). '" href="'.course_workspace_lesson_link($course['slug'],$lesson).'"'.($active?' aria-current="true"':'').'><span class="workspace-lesson-number">'.fa((int)$lesson['position']).'</span><span class="workspace-lesson-copy"><strong>'.h($lesson['title']).'</strong><small>'.h(course_workspace_duration($lesson['duration_minutes']===null?null:(int)$lesson['duration_minutes'])).'</small><span class="workspace-lesson-status is-'.h($lesson['learning_status']).'">'.h(['not_started'=>'شروع‌نشده','in_progress'=>'در حال یادگیری','completed'=>'تکمیل‌شده'][$lesson['learning_status']]).'</span></span><span class="workspace-lesson-indicator" aria-hidden="true">'.($active?'●':'').'</span></a>'; } echo '</div></aside></div></main>';
}
function jdate(string $utc): string {
    $ts=strtotime($utc.' UTC'); if (!$ts) return '—';
    $gy=(int)date('Y',$ts); $gm=(int)date('n',$ts); $gd=(int)date('j',$ts);
    $jdn=gregorian_day_number($gy,$gm,$gd);
    $jy=$gy-621;
    while (gregorian_day_number($jy+621,3,jalali_march($jy+621))>$jdn) $jy--;
    while (gregorian_day_number($jy+622,3,jalali_march($jy+622))<=$jdn) $jy++;
    $k=$jdn-gregorian_day_number($jy+621,3,jalali_march($jy+621));
    if ($k<=185) { $jm=1+intdiv($k,31); $jd=($k%31)+1; }
    else { $k-=186; $jm=7+intdiv($k,30); $jd=($k%30)+1; }
    return fa($jy).'/'.fa(str_pad((string)$jm,2,'0',STR_PAD_LEFT)).'/'.fa(str_pad((string)$jd,2,'0',STR_PAD_LEFT));
}
function gregorian_day_number(int $gy,int $gm,int $gd): int {
    $d=intdiv(($gy+intdiv($gm-8,6)+100100)*1461,4)+intdiv(153*(($gm+9)%12)+2,5)+$gd-34840408;
    return $d-intdiv(intdiv($gy+100100+intdiv($gm-8,6),100)*3,4)+752;
}
function jalali_march(int $gy): int {
    $breaks=[-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    $jy=$gy-621; $leapJ=-14; $jp=$breaks[0]; $jump=0;
    for ($i=1;$i<count($breaks);$i++) {
        $jm=$breaks[$i]; $jump=$jm-$jp;
        if ($jy<$jm) break;
        $leapJ+=intdiv($jump,33)*8+intdiv($jump%33,4);
        $jp=$jm;
    }
    $n=$jy-$jp;
    $leapJ+=intdiv($n,33)*8+intdiv(($n%33)+3,4);
    if ($jump%33===4 && $jump-$n===4) $leapJ++;
    $leapG=intdiv($gy,4)-intdiv((intdiv($gy,100)+1)*3,4)-150;
    return 20+$leapJ-$leapG;
}
function admin_cohort_data(): array {
    $months=6;
    $current=new DateTimeImmutable('first day of this month',new DateTimeZone('UTC'));
    $start=$current->modify('-'.($months-1).' months');
    $labels=[];
    for ($i=0;$i<$months;$i++) $labels[]=$start->modify('+'.$i.' months')->format('Y-m');
    $cohorts=[]; $userCohorts=[];
    foreach (q('SELECT id,created_at FROM users WHERE created_at>=? ORDER BY created_at ASC',[$start->format('Y-m-d H:i:s')])->fetchAll() as $user) {
        $cohort=substr((string)$user['created_at'],0,7);
        if (!in_array($cohort,$labels,true)) continue;
        $id=(int)$user['id'];
        $userCohorts[$id]=$cohort;
        $cohorts[$cohort]??=['size'=>0,'active'=>array_fill(0,$months,[])];
        $cohorts[$cohort]['size']++;
    }
    foreach (q('SELECT user_id,created_at FROM activities WHERE created_at>=?',[$start->format('Y-m-d H:i:s')])->fetchAll() as $activity) {
        $userId=(int)$activity['user_id'];
        if (!isset($userCohorts[$userId])) continue;
        $cohort=$userCohorts[$userId];
        $activityMonth=substr((string)$activity['created_at'],0,7);
        $cohortDate=DateTimeImmutable::createFromFormat('!Y-m-d',$cohort.'-01',new DateTimeZone('UTC'));
        $activityDate=DateTimeImmutable::createFromFormat('!Y-m-d',$activityMonth.'-01',new DateTimeZone('UTC'));
        if (!$cohortDate || !$activityDate) continue;
        $offset=((int)$activityDate->format('Y')-(int)$cohortDate->format('Y'))*12+(int)$activityDate->format('n')-(int)$cohortDate->format('n');
        if ($offset>=0 && $offset<$months) $cohorts[$cohort]['active'][$offset][$userId]=true;
    }
    ksort($cohorts);
    return ['labels'=>$labels,'rows'=>$cohorts];
}
function bars(array $values,bool $gold=false): void {
    $max=max(1,max($values)); echo '<div class="chart-bars'.($gold?' is-gold':'').'">';
    foreach ($values as $i=>$v) echo '<span style="height:'.round($v/$max*100).'%" title="'.h(fa($v)).'"></span>';
    echo '</div>';
}
function panel(string $sub): void {
    global $me,$plan;
    $role=$me['role']; echo '<main class="panel-content"'.($role==='admin'?' id="admin-main" tabindex="-1"':'').'>';
    if ($sub==='' && $role==='admin') { admin_overview(); echo '</main>'; return; }
    if ($sub==='') {
        echo '<div class="panel-welcome"><div><span class="eyebrow">'.($role==='admin'?'مدیریت Noventix':($role==='owner'?'فضای مالک':'فضای یادگیری شما')).'</span><h1>سلام'.($me['name']?' '.h($me['name']):'').'، خوش آمدی 👋</h1><p>'.($role==='admin'?'وضعیت پلتفرم را در یک نگاه ببین و کاربران را مدیریت کن.':'امروز هم یک قدم برای ساختن مهارت تازه بردار.').'</p></div><span class="pill">'.(COMMERCE_ENABLED?'پلن '.h(PLANS[$plan]['name']):'دسترسی رایگان محدود').'</span></div>';
        if ($role==='admin') { $stats=[['کاربران',(int)q('SELECT COUNT(*) FROM users')->fetchColumn()],['اشتراک فعال',(int)q("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at>?",[gmdate('Y-m-d H:i:s')])->fetchColumn()],['گفت‌وگوها',(int)q('SELECT COUNT(*) FROM community_posts')->fetchColumn()],['پیام‌ها',(int)q('SELECT COUNT(*) FROM contact_messages')->fetchColumn()]]; }
        else { $stats=[['مقاله‌های من',(int)q('SELECT COUNT(*) FROM enrollments WHERE user_id=?',[$me['id']])->fetchColumn()],['پروژه‌های من',(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$me['id']])->fetchColumn()],['گفت‌وگوها',(int)q('SELECT COUNT(*) FROM community_posts WHERE user_id=?',[$me['id']])->fetchColumn()],[COMMERCE_ENABLED?'پلن فعال':'دسترسی',COMMERCE_ENABLED?PLANS[$plan]['name']:'رایگان محدود']]; }
        echo '<div class="stat-grid">'; foreach ($stats as [$label,$value]) echo '<div class="stat-card"><span>'.h($label).'</span><strong>'.(is_int($value)?fa($value):h($value)).'</strong></div>'; echo '</div><section class="panel-card account-summary"><div><span class="eyebrow">حساب من</span><h2>اطلاعات حساب</h2></div><dl><div><dt>نام و نام خانوادگی</dt><dd>'.h(trim((string)$me['name'])!==''?$me['name']:'ثبت نشده').'</dd></div><div><dt>شماره تلفن</dt><dd dir="ltr">'.h(trim((string)$me['phone'])!==''?$me['phone']:'ثبت نشده').'</dd></div></dl></section><div class="panel-columns"><section class="panel-card"><span class="eyebrow">گام بعدی</span><h2>'.($role==='admin'?'مدیریت کاربران':'مسیرت را ادامه بده').'</h2><p>'.($role==='admin'?'ثبت‌نام و پیشرفت واقعی یادگیرندگان را بررسی کنید.':'دوره مقدماتی هوش مصنوعی را بدون پرداخت شروع کن و پیشرفتت را دنبال کن.').'</p>'; linkto($role==='admin'?'dashboard/users':'course/'.FREE_COURSE_SLUG,$role==='admin'?'مدیریت کاربران ←':'شروع دوره رایگان ←','btn btn-primary'); echo '</section><section class="panel-card soft"><span class="eyebrow">'.($role==='admin'?'جامعه':'کنار هم یاد می‌گیریم').'</span><h2>انجمن Noventix</h2><p>سؤال‌ها، تجربه‌ها و ایده‌ها را با جامعه یادگیری در میان بگذار.</p>'; linkto('community','ورود به انجمن ←','btn btn-outline'); echo '</section></div>';
        if ($role!=='admin') {
            $courseError=false;
            try {
                $courseProgress=dashboard_course_progress((int)$me['id']);
                $activeLesson=$courseProgress ? dashboard_active_lesson((int)$me['id'],$courseProgress) : null;
            } catch (Throwable $e) {
                $courseError=true;
                $courseProgress=null;
            }
            if ($courseError) echo '<section class="panel-card course-progress-card"><span class="eyebrow">وضعیت دوره</span><p>دریافت مسیر یادگیری ممکن نشد. لطفاً دوباره تلاش کنید.</p></section>';
            elseif ($courseProgress) {
                echo '<section class="panel-card course-progress-card"><span class="eyebrow">وضعیت دوره</span><h2>'.h($courseProgress['title']).'</h2><p>'.(['completed'=>'تکمیل‌شده','in_progress'=>'در حال یادگیری','not_started'=>'شروع‌نشده'][$courseProgress['status']]).'</p><div class="course-progress-details"><div><span>پیشرفت دوره</span><strong>'.fa((string)$courseProgress['percentage']).'٪</strong></div><div><span>درس‌های تکمیل‌شده</span><strong>'.fa($courseProgress['completed']).' از '.fa($courseProgress['total']).'</strong></div><div><span>درس‌های باقی‌مانده</span><strong>'.fa($courseProgress['remaining']).'</strong></div></div><div class="progress" role="progressbar" aria-label="پیشرفت دوره" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.h($courseProgress['percentage']).'"><span style="width:'.h($courseProgress['percentage']).'%"></span></div>';
                echo '<div class="course-active-lesson'.($courseProgress['status']==='completed'?' is-completed':'').'">';
                if ($courseProgress['status']==='completed') {
                    echo '<strong>تبریک! این دوره را با موفقیت به پایان رساندی.</strong><span>دوره تکمیل شده</span><p>حالا وقت آن است که آموخته‌هایت را در یک مسیر عملی ادامه بدهی.</p>';
                    linkto('projects','مرور پروژه‌های موجود ←','btn btn-primary');
                    linkto('dashboard/course/'.$courseProgress['slug'],'مرور دوباره درس‌ها','btn btn-outline');
                } elseif ($activeLesson['lesson']) {
                    echo '<p>'.fa($courseProgress['remaining']).' درس الزامی باقی مانده است.</p>';
                    echo '<strong>'.h($activeLesson['lesson']['title']).'</strong><span>'.($activeLesson['lesson_status']==='in_progress'?'در حال یادگیری':'شروع‌نشده').'</span>';
                    linkto('dashboard/course/'.$courseProgress['slug'].'?lesson='.(int)$activeLesson['lesson']['id'],$activeLesson['lesson_status']==='in_progress'?'ادامهٔ یادگیری ←':'شروع یادگیری ←','btn btn-primary');
                } else echo '<span>هنوز درسی برای این دوره منتشر نشده است.</span>';
                echo '</div></section>';
            } else echo '<section class="panel-card course-progress-card"><span class="eyebrow">وضعیت دوره</span><p>هنوز در دوره‌ای ثبت‌نام نکرده‌اید.</p></section>';
        }
    } elseif ($sub==='profile') {
        panel_head('پروفایل من','اطلاعات حساب و خلاصهٔ مهارت شما.');
        $summary=skill_profile_for((int)$me['id']);
        echo '<div class="profile-head"><div><span class="profile-avatar">'.h(mb_substr($me['name'] ?: 'ن',0,1)).'</span><div><h2>'.h($me['name'] ?: 'کاربر Noventix').'</h2><p class="muted">'.h($me['phone']).' · سطح '.fa($summary['level']['level']).' · '.fa($summary['level']['xp']).' XP</p><p class="muted">'.h($role==='admin'?'مدیر پلتفرم':($role==='owner'?'مالک پلتفرم':'سازنده‌ای در مسیر یادگیری هوش مصنوعی')).'</p></div></div>';
        linkto('member/'.$me['id'],'دیدن پروفایل مهارتی','btn btn-primary');
        echo '</div><div class="panel-card"><span class="eyebrow">خلاصه مهارت</span><h2>پیشرفت ثبت‌شده</h2><div class="profile-summary-stats">';
        foreach ([['XP',fa($summary['level']['xp'])],['پروژه تأییدشده',fa(count($summary['completions']))],['درس تکمیل‌شده',fa($summary['lessons'])],['پیام خوانده‌نشده',fa(messages_unread((int)$me['id']))]] as [$label,$value]) echo '<div class="skill-stat"><span>'.h($label).'</span><strong>'.h($value).'</strong></div>';
        echo '</div><div class="profile-summary-links">'; linkto('messages','پیام‌های من','btn btn-outline'); linkto('learners','جامعه یادگیرندگان','btn btn-outline'); echo '</div></div>';
        echo '<div class="panel-card narrow">'; form_start('profile/account','stack-form'); echo '<label>نام نمایشی<input name="name" maxlength="100" minlength="2" required value="'.h($me['name']).'" placeholder="نام شما"></label><label>دربارهٔ من<textarea name="bio" maxlength="600" rows="5" placeholder="دربارهٔ مسیر یادگیری، علاقه‌مندی‌ها و مهارت‌هایت بنویس...">'.h((string)($me['bio'] ?? '')).'</textarea><span class="fine-print">حداکثر ۶۰۰ نویسه؛ این متن در پروفایل مهارتی شما نمایش داده می‌شود.</span></label><label>ایمیل (اختیاری)<input type="email" dir="ltr" name="email" maxlength="255" placeholder="you@example.com"></label><label>شماره موبایل<input dir="ltr" disabled value="'.h($me['phone']).'"></label><button class="btn btn-primary">ذخیره تغییرات</button></form><p class="fine-print">شماره موبایل شناسه ورود شماست و از این صفحه قابل تغییر نیست.</p></div>';
        if ($role==='student') {
            echo '<section class="panel-card account-danger"><h2>حذف کامل حساب</h2><p>حساب، پروفایل مهارتی، پروژه‌ها، پیشرفت آموزشی، پست‌ها، پاسخ‌ها، پیام‌ها و اطلاعات وابستهٔ شما برای همیشه حذف می‌شوند. ابتدا اجراها و دانلودهای فعال میزکار را متوقف کنید. این کار برگشت‌پذیر نیست.</p><details><summary>می‌خواهم حسابم را حذف کنم</summary>';
            form_start('account/delete','stack-form');
            echo '<label>رمز عبور فعلی<input type="password" name="password" dir="ltr" autocomplete="current-password" required></label><label>برای تأیید، عبارت «حذف حساب» را بنویسید<input name="confirmation" required autocomplete="off" placeholder="حذف حساب"></label><button class="btn btn-danger">حذف دائمی حساب و اطلاعات من</button></form></details></section>';
        }
    } elseif ($sub==='nova' && $role!=='admin') {
        panel_head('Nova AI','به‌زودی');
        echo '<div class="panel-card"><h2>به‌زودی</h2><p>Nova AI فعلاً غیرفعال است.</p></div>';
    } elseif ($sub==='subscription' && $role!=='admin') {
        panel_head('اشتراک من','پلن فعلی: '.PLANS[$plan]['name']);
        payment_checkout();
    } elseif ($sub==='learning' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>مسیر یادگیری من</h1><p>دوره‌هایی که برای یادگیری انتخاب کرده‌اید.</p></div><div class="panel-list">';
        $rows=q('SELECT course_slug FROM enrollments WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll();
        if (!$rows) echo '<div class="empty-state">هنوز دوره‌ای اضافه نکرده‌اید. <a href="/courses">دوره‌ها را ببینید ←</a></div>';
        foreach ($rows as $row) {
            $course=q("SELECT slug,title,description FROM courses WHERE slug=? AND status='published'",[$row['course_slug']])->fetch();
            if ($course) {
                echo '<div class="list-item"><div><span class="pill">دوره</span><h3>'.h($course['title']).'</h3><p>'.h($course['description']).'</p></div><a href="'.h(url('dashboard/course/'.$course['slug'])).'">ادامه یادگیری ←</a></div>';
            } elseif (isset(COURSES[$row['course_slug']])) {
                echo '<div class="list-item"><div><span class="pill">'.h(COURSES[$row['course_slug']]['topic']).'</span><h3>'.h(COURSES[$row['course_slug']]['title']).'</h3></div><a href="'.h(url('course/'.$row['course_slug'])).'">ادامه مطالعه ←</a></div>';
            }
        }
        echo '</div>';
    } elseif ($sub==='learning' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>مسیر یادگیری من</h1><p>دوره‌هایی که برای یادگیری انتخاب کرده‌اید.</p></div><div class="panel-list">';
        $rows=q('SELECT course_slug FROM enrollments WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll();
        if (!$rows) echo '<div class="empty-state">هنوز دوره‌ای اضافه نکرده‌اید. <a href="/courses">دوره‌ها را ببینید ←</a></div>';
        foreach ($rows as $row) {
            $course=q("SELECT slug,title,description FROM courses WHERE slug=? AND status='published'",[$row['course_slug']])->fetch();
            if ($course) {
                echo '<div class="list-item"><div><span class="pill">دوره</span><h3>'.h($course['title']).'</h3><p>'.h($course['description']).'</p></div><a href="'.h(url('dashboard/course/'.$course['slug'])).'">ادامه یادگیری ←</a></div>';
            } elseif (isset(COURSES[$row['course_slug']])) {
                echo '<div class="list-item"><div><span class="pill">'.h(COURSES[$row['course_slug']]['topic']).'</span><h3>'.h(COURSES[$row['course_slug']]['title']).'</h3></div><a href="'.h(url('course/'.$row['course_slug'])).'">ادامه مطالعه ←</a></div>';
            }
        }
        echo '</div>';
    } elseif ($sub==='projects' && $role!=='admin') {
        ws_index($me);
        echo '<div class="panel-heading"><h1>پروژه‌های من</h1><p>مسئله‌هایی که برای ساختن انتخاب کرده‌ای.</p></div><div class="panel-list">'; $rows=q('SELECT catalog_index FROM projects WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز پروژه‌ای شروع نکردی. <a href="/projects">پروژه‌ها را ببین ←</a></div>'; foreach ($rows as $r) { $p=PROJECTS[$r['catalog_index']] ?? null; if($p) echo '<div class="list-item"><div><span class="pill">'.h($p['topic']).'</span><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p></div><a href="'.h(url('project/'.$r['catalog_index'])).'">جزئیات ←</a></div>'; } echo '</div>';
    } elseif ($sub==='community') {
        panel_head('Community','از سؤال کوچک تا نمایش بزرگ‌ترین پروژه‌ها.');
        echo '<nav><a href="/dashboard/community">همهٔ پست‌ها</a> · <a href="/dashboard/community?saved=1">ذخیره‌های من</a></nav>';
        $savedOnly=($_GET['saved'] ?? '')==='1';
        $page=max(1,(int)($_GET['page'] ?? 1));
        $offset=min($page-1,1000000)*50;
        $posts=q('SELECT p.id,p.user_id,p.title,p.body,p.created_at,u.name,u.phone FROM community_posts p JOIN users u ON u.id=p.user_id'.($savedOnly ? " WHERE EXISTS (SELECT 1 FROM post_reactions r WHERE r.post_id=p.id AND r.user_id=? AND r.kind='save')" : '').' ORDER BY p.id DESC LIMIT 50 OFFSET '.$offset,$savedOnly?[$me['id']]:[])->fetchAll();
        echo '<div class="panel-card narrow-left"><h2>Create Post</h2>'; form_start('community/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label><label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="4"></textarea></label><button class="btn btn-primary">Create Post</button></form></div>';
        echo '<div class="panel-list">'; if (!$posts) echo '<div class="empty-state">هنوز پستی ثبت نشده است.</div>';
        foreach ($posts as $p) {
            $saves=(int)q("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='save'",[$p['id']])->fetchColumn();
            $replies=(int)q('SELECT COUNT(*) FROM community_replies WHERE post_id=?',[$p['id']])->fetchColumn();
            echo '<article class="post-card"><div class="meta-row"><a class="profile-link" href="'.h(url('member/'.$p['user_id'])).'">'.h($p['name'] ?: mb_substr($p['phone'],0,4).'****').'</a><span>'.h(jdate($p['created_at'])).'</span>'; if ((int)$p['user_id']!==$me['id']) { $following=q('SELECT 1 FROM user_follows WHERE follower_id=? AND followed_id=?',[$me['id'],$p['user_id']])->fetchColumn(); if ($following) echo '<span class="pill">دنبال می‌کنید</span>'; else { form_start('community/follow','react-form'); echo '<input type="hidden" name="user_id" value="'.(int)$p['user_id'].'"><button class="text-button">دنبال کردن</button></form>'; } echo '<a class="text-button" href="'.h(url('member/'.$p['user_id'])).'">پروفایل مهارتی</a>'; } echo '</div><h2>'.h($p['title']).'</h2><p>'.nl2br(h($p['body'])).'</p><div class="react-row">';
            community_post_controls($p,$me,'dashboard/community');
            foreach (['save'=>'ذخیره'] as $kind=>$label) { $reacted=(bool)q('SELECT 1 FROM post_reactions WHERE post_id=? AND user_id=? AND kind=?',[$p['id'],$me['id'],$kind])->fetchColumn(); if ($reacted) $label='ذخیره‌شده'; form_start('community/react','react-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><input type="hidden" name="kind" value="'.h($kind).'"><button class="text-button"'.($reacted?' disabled':'').'>'.h($label).' '.fa($saves).'</button></form>'; }
            echo '<a class="text-button" href="'.h(url('community')).'">Comment '.fa($replies).'</a></div>'; echo '<div class="comment-list">'; foreach (q('SELECT r.user_id,r.body,r.created_at,u.name FROM community_replies r JOIN users u ON u.id=r.user_id WHERE r.post_id=? ORDER BY r.id',[$p['id']])->fetchAll() as $reply) echo '<div class="comment"><a class="profile-link" href="'.h(url('member/'.$reply['user_id'])).'">'.h($reply['name'] ?: 'کاربر').'</a><p>'.nl2br(h($reply['body'])).'</p></div>'; echo '</div>'; form_start('community/comment','comment-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><textarea name="body" required minlength="2" maxlength="2000" rows="2" placeholder="نظر خود را بنویسید…"></textarea><button class="btn btn-small btn-outline">ارسال نظر</button></form></article>';
        }
        echo '</div><nav>';
        $filter=$savedOnly?'&saved=1':'';
        if ($page>1) linkto('dashboard/community?page='.($page-1).$filter,'صفحهٔ قبل','btn btn-outline');
        if (count($posts)===50) linkto('dashboard/community?page='.($page+1).$filter,'صفحهٔ بعد','btn btn-outline');
        echo '</nav>';
    } elseif ($sub==='support') {
        panel_head('پشتیبانی','تیکت‌های باز و اولویت‌بندی آن‌ها.');
        $open=(int)q("SELECT COUNT(*) FROM tickets WHERE user_id=? AND status='open'",[$me['id']])->fetchColumn();
        echo '<div class="panel-card narrow-left"><span class="pill">صف درخواست‌های پشتیبانی</span><h2>'.fa($open).' تیکت باز</h2><p class="muted">تیکت‌های اولویت‌دار را بررسی یا تیکت جدیدی ایجاد کن.</p><button type="button" class="btn btn-primary" data-toggle="#ticket-form">ایجاد تیکت</button></div>';
        echo '<div id="ticket-form" class="panel-card narrow" hidden><h2>تیکت جدید</h2>'; form_start('tickets/create','stack-form'); echo '<label>موضوع<input name="subject" required minlength="5" maxlength="180"></label><label>اولویت<select name="priority">'; foreach (TICKET_PRIORITIES as $pr) echo '<option value="'.h($pr).'">'.h($pr).'</option>'; echo '</select></label><label>شرح مشکل<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت تیکت</button></form></div>';
        echo '<div class="panel-list">'; $rows=q('SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT 30',[$me['id']])->fetchAll();
        if (!$rows) echo '<div class="empty-state">هنوز تیکتی ثبت نکرده‌ای.</div>';
        foreach ($rows as $t) { echo '<div class="ticket-card"><div class="meta-row"><span class="pill">'.h($t['priority']).'</span><span>'.h($t['status']==='open'?'باز':'بسته').'</span><span>'.h(jdate($t['created_at'])).'</span></div><h3>'.h($t['subject']).'</h3><div class="ticket-thread">'; foreach (q('SELECT r.body,r.created_at,r.user_id,u.name FROM ticket_replies r JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.id',[$t['id']])->fetchAll() as $reply) echo '<div class="ticket-message'.((int)$reply['user_id']===(int)$me['id'] ? ' is-user' : '').'"><strong>'.((int)$reply['user_id']===(int)$me['id'] ? 'شما' : h($reply['name'] ?: 'پشتیبانی')).'</strong><p>'.nl2br(h($reply['body'])).'</p></div>'; echo '</div>'; form_start('tickets/reply','ticket-reply-form'); echo '<input type="hidden" name="ticket_id" value="'.(int)$t['id'].'"><textarea name="body" required minlength="2" maxlength="5000" rows="3" placeholder="پاسخ خود را بنویسید…"></textarea><button class="btn btn-small btn-outline">ارسال پاسخ</button></form></div>'; }
        echo '</div>';
    } elseif ($sub==='payments') {
        panel_head('پرداخت‌ها','تاریخچه تراکنش‌های تأییدشده');
        payment_history($me,$role==='admin');
    } elseif ($sub==='gamification') {
        lp_progress_view((int)$me['id']);
    } elseif ($sub==='activity' && $role!=='admin') {
        panel_head('فعالیت و Streak','هر روزی که واقعاً کار کرده باشی، در این تقویم ثبت می‌شود.');
        $kinds=[['projects','پروژه فعال','پروژه‌هایی که به فهرست خود اضافه کرده‌ای'],['challenge_progress','چالش شروع‌شده','چالش‌هایی که به فهرست خود اضافه کرده‌ای'],['enrollments','مقاله ذخیره‌شده','مقاله‌هایی که در مسیر یادگیری ذخیره کرده‌ای'],['community_posts','گفت‌وگوی انجمن','پست‌هایی که در انجمن منتشر کرده‌ای']];
        $counts=[]; foreach ($kinds as [$table]) $counts[$table]=(int)q("SELECT COUNT(*) FROM $table WHERE user_id=?",[$me['id']])->fetchColumn();
        // A day is active when at least one timestamped action happened on it.
        $days=q('SELECT day,SUM(hits) AS hits FROM ('
            .'SELECT substr(created_at,1,10) AS day,1 AS hits FROM projects WHERE user_id=? '
            .'UNION ALL SELECT substr(created_at,1,10),1 FROM enrollments WHERE user_id=? '
            .'UNION ALL SELECT substr(created_at,1,10),1 FROM challenge_progress WHERE user_id=? '
            .'UNION ALL SELECT substr(created_at,1,10),1 FROM community_posts WHERE user_id=? '
            .') GROUP BY day ORDER BY day DESC LIMIT 60',[$me['id'],$me['id'],$me['id'],$me['id']])->fetchAll();
        $active=array_column($days,'day');
        $run=0; $cursor=new DateTimeImmutable('today',new DateTimeZone('UTC'));
        // Today without activity yet should not break yesterday's streak.
        if (!in_array($cursor->format('Y-m-d'),$active,true)) $cursor=$cursor->modify('-1 day');
        while (in_array($cursor->format('Y-m-d'),$active,true)) { $run++; $cursor=$cursor->modify('-1 day'); }
        $activeMonth=count(array_filter($active,fn($d)=>str_starts_with($d,gmdate('Y-m'))));
        $week=array_fill(0,7,0);
        foreach ($days as $row) { $delta=(int)floor((time()-strtotime($row['day'].' UTC'))/86400); if ($delta>=0 && $delta<7) $week[6-$delta]+=(int)$row['hits']; }
        echo '<div class="stat-grid"><div class="stat-card"><span>Streak فعلی</span><strong>'.fa($run).' روز</strong></div><div class="stat-card"><span>روزهای فعال این ماه</span><strong>'.fa($activeMonth).'</strong></div><div class="stat-card"><span>روزهای ثبت‌شده</span><strong>'.fa(count($days)).'</strong></div><div class="stat-card"><span>کارهای این هفته</span><strong>'.fa(array_sum($week)).'</strong></div></div>';
        echo '<div class="panel-card"><h2>فعالیت هفته</h2><p class="muted">اندازه هر ستون نشان می‌دهد آن روز چقدر درگیر تمرین بوده‌ای.</p><div class="activity-strip">';
        $labels=[]; for ($i=6;$i>=0;$i--) $labels[]=ACTIVITY_LABELS[((int)gmdate('w',strtotime('-'.$i.' days'))+1)%7]; $top=max(1,max($week));
        foreach ($week as $i=>$value) echo '<div class="activity-day"><span class="activity-bar" style="height:'.round($value/$top*100).'%" title="'.h($labels[$i]).' · '.fa($value).' فعالیت"></span><small>'.h($labels[$i]).'</small></div>';
        echo '</div><p class="muted">یک روز فقط وقتی فعال شمرده می‌شود که دست‌کم یک کار واقعی در آن ثبت شده باشد؛ بازدید صفحه حساب نمی‌شود.</p></div>';
        echo '<div class="panel-columns"><section class="panel-card"><h2>شکست فعالیت</h2><div class="panel-list">';
        foreach ($kinds as [$table,$label,$hint]) echo '<div class="usage-row"><span>'.h($label).'<small class="muted"> · '.h($hint).'</small></span><strong>'.fa($counts[$table]).'</strong></div>';
        echo '</div></section><section class="panel-card"><h2>Streak چطور محاسبه می‌شود؟</h2><ul class="check-list"><li>هر روزی که دست‌کم یک کار واقعی ثبت کنی، برای آن روز شمرده می‌شود.</li><li>اگر یک روز کامل بی‌فعالیت بماند، شمارنده به صفر برمی‌گردد؛ روزهای قبلی حفظ می‌شوند.</li><li>فعالیت امروز که هنوز ثبت نشده، Streak دیروز را از بین نمی‌برد.</li></ul></section></div>';
    } elseif ($sub==='challenges') {
        ws_challenges($me);
    } elseif ($sub==='settings') {
        panel_head('تنظیمات','مدیریت اطلاعات حساب و فضای یادگیری.');
        echo '<section class="panel-card narrow"><h2>اطلاعات حساب</h2>';
        form_start('profile/account','stack-form');
        echo '<label>نام نمایشی<input name="name" minlength="2" maxlength="100" required value="'.h($me['name']).'"></label><label>شماره موبایل<input dir="ltr" disabled value="'.h($me['phone']).'"></label><p class="fine-print">شماره موبایل شناسهٔ ورود است و از این صفحه تغییر نمی‌کند.</p><button class="btn btn-primary">ذخیره نام</button></form></section><section class="panel-card"><h2>فضای یادگیری</h2><p>خرید اشتراک فعلاً متوقف است؛ میزکار با سهمیهٔ محدود و دورهٔ رایگان در دسترس‌اند. ارسال اعلان پیامکی و ایمیلی فعال نیست.</p>';
        linkto('dashboard/profile','پروفایل و مهارت‌ها','btn btn-outline');
        echo '</section>';
    } elseif ($role==='admin') admin_panel($sub);
    else { http_response_code(404); echo '<div class="empty-state">این بخش در دسترس نیست.</div>'; }
    echo '</main>';
}
function admin_panel(string $sub): void {
    if ($sub==='subscriptions') {
        echo '<div class="panel-heading"><h1>اشتراک‌ها</h1><p>فعال‌سازی دستی فقط بعد از تأیید پرداخت از مسیر رسمی انجام شود.</p></div><div class="panel-card narrow"><h2>فعال‌سازی اشتراک ۳۰ روزه</h2>'; form_start('admin/activate','stack-form'); echo '<label>شماره موبایل کاربر<input name="phone" type="tel" dir="ltr" placeholder="09123456789" required></label><label>پلن<select name="plan">'; foreach (PLANS as $id=>$p) if($id!=='free') echo '<option value="'.h($id).'">'.h($p['name']).'</option>'; echo '</select></label><button class="btn btn-primary">فعال‌سازی پس از تأیید پرداخت</button></form></div><div class="panel-card"><h2>اشتراک‌های اخیر</h2><div class="table-wrap"><table><thead><tr><th>شماره</th><th>پلن</th><th>وضعیت</th><th>پایان (UTC)</th></tr></thead><tbody>'; foreach (q('SELECT u.phone,s.plan,s.status,s.expires_at FROM subscriptions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 50')->fetchAll() as $r) echo '<tr><td dir="ltr">'.h($r['phone']).'</td><td>'.h(PLANS[$r['plan']]['name'] ?? $r['plan']).'</td><td>'.h($r['status']).'</td><td dir="ltr">'.h($r['expires_at']).'</td></tr>'; echo '</tbody></table></div></div>';
    } elseif ($sub==='users') {
        panel_head('کاربران','فهرست حساب‌های ثبت‌شده و وضعیت Lead و یادگیری آن‌ها.');
        try {
            $search=is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
            echo '<form method="get" class="admin-search"><label>جست‌وجوی نام<input name="q" value="'.h($search).'" maxlength="100" placeholder="نام کاربر را وارد کنید"></label><button class="btn btn-outline">جست‌وجو</button></form>';
            $rows=$search==='' ? q('SELECT id,name,phone,acquisition_source,acquisition_campaign,phone_verified_at FROM users ORDER BY id DESC LIMIT 100')->fetchAll() : q('SELECT id,name,phone,acquisition_source,acquisition_campaign,phone_verified_at FROM users WHERE name LIKE ? ORDER BY id DESC LIMIT 100',['%'.mb_substr($search,0,100).'%'])->fetchAll();
            echo '<div class="panel-card table-wrap"><table><thead><tr><th>نام و نام خانوادگی</th><th>شماره موبایل</th><th>پلن فعال</th><th>وضعیت کاربر</th><th>پیشرفت دوره</th><th>درس‌های تکمیل‌شده</th><th>وضعیت دوره</th><th>منبع جذب</th><th>کمپین</th></tr></thead><tbody>';
            if (!$rows) echo '<tr><td colspan="9" class="muted">کاربری مطابق جست‌وجو یافت نشد یا هنوز ثبت‌نامی انجام نشده است.</td></tr>';
            foreach ($rows as $r) {
                $stage=lead_stage_for_user((int)$r['id']);
                $learning=lead_progress_summary((int)$r['id']);
                $progress=$learning ? fa((string)round((float)$learning['percentage'],1)).'٪' : '—';
                $completed=$learning ? fa((int)$learning['completed']) : '—';
                $completion=match ($learning['status'] ?? null) {
                    'completed'=>'تکمیل‌شده',
                    'in_progress'=>'در حال یادگیری',
                    'not_started'=>'شروع‌نشده',
                    default=>'—'
                };
                $source=trim((string)($r['acquisition_source'] ?? '')) ?: 'Unknown';
                $campaign=trim((string)($r['acquisition_campaign'] ?? '')) ?: '—';
                echo '<tr><td>'.h(trim((string)$r['name']) ?: '—').'</td><td dir="ltr">'.h($r['phone']).'</td><td>'.h(PLANS[plan_for($r)]['name']).'</td><td>'.h($stage['label'] ?? '—').'</td><td dir="ltr">'.h($progress).'</td><td>'.h($completed).'</td><td>'.h($completion).'</td><td>'.h($source).'</td><td dir="ltr">'.h($campaign).'</td></tr>';
            }
            echo '</tbody></table></div>';
        } catch (Throwable $e) {
            echo '<div class="panel-card"><p class="muted">دریافت فهرست کاربران ممکن نشد. لطفاً دوباره تلاش کنید.</p></div>';
        }
    } elseif ($sub==='messages') {
        echo '<div class="panel-heading"><h1>پیام‌های تماس</h1><p>پیام‌های ثبت‌شده از فرم تماس.</p></div><div class="panel-list">'; foreach (q('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 50')->fetchAll() as $r) echo '<div class="list-item"><div><h3>'.h($r['name']).' · '.h($r['email']).'</h3><p>'.nl2br(h($r['message'])).'</p></div><small>'.h($r['created_at']).'</small></div>'; echo '</div>';
    } elseif ($sub==='courses') {
        panel_head('دوره‌ها و درس‌ها','مدیریت ساختار دوره‌ها، انتشار محتوا و ترتیب درس‌ها.');
        course_admin_forms();
        try {
            $courses=q('SELECT id,title,status FROM courses ORDER BY id ASC')->fetchAll();
            $lessons=q('SELECT id,course_id,title,position,status FROM lessons ORDER BY course_id ASC,position ASC,id ASC')->fetchAll();
            $byCourse=[];
            foreach ($lessons as $lesson) $byCourse[(int)$lesson['course_id']][]=$lesson;
            if (!$courses) echo '<div class="panel-card"><p class="muted">هنوز دوره‌ای ثبت نشده است.</p></div>';
            foreach ($courses as $course) {
                echo '<section class="panel-card"><h2>'.h($course['title']).'</h2><p>شناسه دوره: '.fa((int)$course['id']).' · Status: '.h($course['status'] ?? '—').'</p>';
                $items=$byCourse[(int)$course['id']] ?? [];
                if (!$items) echo '<p class="muted">این دوره هنوز درسی ندارد.</p>';
                else {
                    echo '<div class="table-wrap"><table><thead><tr><th>عنوان درس</th><th>ترتیب</th><th>وضعیت</th><th>ویدئو</th></tr></thead><tbody>';
                    foreach ($items as $lesson) echo '<tr><td>'.h($lesson['title']).' (ID: '.fa((int)$lesson['id']).')</td><td>'.fa((int)$lesson['position']).'</td><td>'.h($lesson['status'] ?? '—').'</td><td>ثبت نشده (فیلد ویدئو موجود نیست)</td></tr>';
                    echo '</tbody></table></div>';
                }
                echo '</section>';
            }
        } catch (Throwable $e) {
            echo '<div class="panel-card"><p class="muted">دریافت فهرست دوره‌ها و درس‌ها ممکن نشد. لطفاً دوباره تلاش کنید.</p></div>';
        }
    } elseif ($sub==='projects') {
        echo '<div class="panel-heading"><h1>کاتالوگ پروژه‌ها</h1><p>'.fa(count(PROJECTS)).' پروژه فعال در کاتالوگ.</p></div><div class="panel-card"><p class="muted">کاتالوگ از فایل محتوای برنامه بارگذاری می‌شود؛ ویرایش از پنل هنوز فعال نیست.</p>'; linkto('projects','مشاهده کاتالوگ ←','btn btn-outline'); echo '</div>';
    } elseif ($sub==='users-new') {
        panel_head('کاربران جدید','حساب‌هایی که تازه ثبت‌نام کرده‌اند.');
        echo '<div class="panel-card table-wrap"><table><thead><tr><th>نام</th><th>شماره</th><th>تاریخ عضویت</th></tr></thead><tbody>';
        foreach (q('SELECT name,phone,created_at FROM users ORDER BY id DESC LIMIT 30')->fetchAll() as $r) echo '<tr><td>'.h($r['name'] ?: '—').'</td><td dir="ltr">'.h($r['phone']).'</td><td>'.h(jdate($r['created_at'])).'</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($sub==='catalog') {
        panel_head('پروژه‌ها','کاتالوگ پروژه‌های فعال پلتفرم.');
        echo '<div class="panel-card"><p class="muted">'.fa(count(PROJECTS)).' پروژه فعال در کاتالوگ برنامه.</p>'; linkto('projects','مشاهده کاتالوگ ←','btn btn-outline'); echo '</div>';
        project_cards();
    } elseif ($sub==='payments') {
        require_admin();
        panel_head('مدیریت پرداخت‌ها','تراکنش‌های واقعی زرین‌پال');
        payment_history(require_user(),true);
    } elseif ($sub==='analytics') {
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);
        $rows=[['کاربران ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM users WHERE created_at>=?',[$since])->fetchColumn()],['گفت‌وگوهای ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM community_posts WHERE created_at>=?',[$since])->fetchColumn()],['پروژه‌های شروع‌شده',(int)q('SELECT COUNT(*) FROM projects')->fetchColumn()],['مقاله‌های ذخیره‌شده',(int)q('SELECT COUNT(*) FROM enrollments')->fetchColumn()]];
        panel_head('آمار و گزارش‌ها','روند فعالیت و درآمد پلتفرم.');
        echo '<div class="stat-grid">'; foreach ($rows as [$label,$value]) echo '<div class="stat-card"><span>'.h($label).'</span><strong>'.fa($value).'</strong></div>'; echo '</div>';
        $users=[]; for ($i=0;$i<8;$i++) $users[]=(int)q('SELECT COUNT(*) FROM users WHERE created_at>=? AND created_at<?',[gmdate('Y-m-d H:i:s',time()-($i+1)*7*86400),gmdate('Y-m-d H:i:s',time()-$i*7*86400)])->fetchColumn();
        $revenue=[]; foreach (PLANS as $id=>$p) if ($p['price']) $revenue[]=(int)q("SELECT COUNT(*) FROM subscriptions WHERE plan=?",[$id])->fetchColumn()*(int)$p['price'];
        while (count($revenue)<8) $revenue[]=0;
        echo '<div class="chart-grid"><section class="panel-card"><h2>ثبت‌نام کاربران</h2><p class="muted">کاربران جدید در ۸ هفته گذشته</p>'; bars(array_reverse($users),true); echo '</section><section class="panel-card"><h2>درآمد پلن‌ها</h2><p class="muted">درآمد ماهانه به تفکیک پلن</p>'; bars(array_slice($revenue,0,8)); echo '</section></div>';
        try {
            $cohort=admin_cohort_data();
            echo '<section class="panel-card cohort-card"><h2>ماندگاری کاربران</h2><p class="muted">مبنای cohort ماه ثبت‌نام است؛ فعالیت بر اساس رویدادهای واقعی جدول activities محاسبه شده است.</p>';
            if (!$cohort['rows']) echo '<div class="empty-state">برای شش ماه اخیر دادهٔ کافی برای cohort وجود ندارد.</div>';
            else {
                echo '<div class="table-wrap"><table class="cohort-table"><thead><tr><th>ماه ثبت‌نام</th><th>کاربران</th>'; foreach ($cohort['labels'] as $label) echo '<th>'.h($label).'</th>'; echo '</tr></thead><tbody>';
                foreach ($cohort['rows'] as $label=>$row) {
                    $cohortIndex=array_search($label,$cohort['labels'],true);
                    echo '<tr><th scope="row" dir="ltr">'.h($label).'</th><td>'.fa($row['size']).'</td>';
                    foreach ($cohort['labels'] as $index=>$month) {
                        $observed=count($cohort['labels'])-1-(int)$cohortIndex;
                        if ($index>$observed) { echo '<td dir="ltr">—</td>'; continue; }
                        $count=count($row['active'][$index] ?? []);
                        $rate=$row['size'] ? round($count/$row['size']*100) : 0;
                        echo '<td dir="ltr"><strong>'.fa($rate).'%</strong><small>'.fa($count).' نفر</small></td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';
        } catch (Throwable $e) {
            echo '<section class="panel-card"><h2>ماندگاری کاربران</h2><p class="muted">دریافت دادهٔ cohort ممکن نشد. لطفاً دوباره تلاش کنید.</p></section>';
        }
        echo '<div class="panel-card"><h2>توزیع پلن‌ها</h2><div class="table-wrap"><table><thead><tr><th>پلن</th><th>اشتراک فعال</th></tr></thead><tbody>'; foreach (PLANS as $id=>$p) echo '<tr><td>'.h($p['name']).'</td><td>'.fa((int)q("SELECT COUNT(*) FROM subscriptions WHERE plan=? AND status='active' AND expires_at>?",[$id,gmdate('Y-m-d H:i:s')])->fetchColumn()).'</td></tr>'; echo '</tbody></table></div></div>';
    } elseif ($sub==='subscriptions') {
        $active=(int)q("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at>?",[gmdate('Y-m-d H:i:s')])->fetchColumn();
        $limit=PLANS['titanium']['nova']; $used=min($limit,(int)$active);
        panel_head('مدیریت اشتراک‌ها','وضعیت پلن‌ها و مصرف ظرفیت.');
        echo '<div class="sub-layout"><section class="panel-card"><h2>Usage</h2><div class="usage-row"><span>AI Credits</span><strong>'.fa($used).' از '.fa($limit).'∞</strong></div><div class="progress"><span style="width:'.max(2,round($used/$limit*100)).'%"></span></div><div class="usage-row"><span>Projects</span><strong>نامحدود</strong></div></section>';
        echo '<section class="plan-tile"><span class="eyebrow">Current Plan</span><h2>'.h(PLANS[$plan]['name']).'</h2><p>تمدید: '.jdate(gmdate('Y-m-d H:i:s',time()+30*86400)).'</p>'; linkto('pricing','ارتقای پلن','btn btn-gold'); echo '</section></div>';
        echo '<div class="panel-card narrow"><h2>فعال‌سازی اشتراک ۳۰ روزه</h2>'; form_start('admin/activate','stack-form'); echo '<label>شماره موبایل کاربر<input name="phone" type="tel" dir="ltr" placeholder="09123456789" required></label><label>پلن<select name="plan">'; foreach (PLANS as $id=>$p) if($id!=='free') echo '<option value="'.h($id).'">'.h($p['name']).'</option>'; echo '</select></label><button class="btn btn-primary">فعال‌سازی پس از تأیید پرداخت</button></form></div>';
        echo '<div class="panel-card"><h2>اشتراک‌های اخیر</h2><div class="table-wrap"><table><thead><tr><th>شماره</th><th>پلن</th><th>وضعیت</th><th>پایان</th></tr></thead><tbody>'; foreach (q('SELECT u.phone,s.plan,s.status,s.expires_at FROM subscriptions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 50')->fetchAll() as $r) echo '<tr><td dir="ltr">'.h($r['phone']).'</td><td>'.h(PLANS[$r['plan']]['name'] ?? $r['plan']).'</td><td>'.h($r['status']).'</td><td>'.h(jdate($r['expires_at'])).'</td></tr>'; echo '</tbody></table></div></div>';
    } elseif ($sub==='support') {
        panel_head('پشتیبانی','تیکت‌های باز و اولویت‌بندی آن‌ها.');
        $open=(int)q("SELECT COUNT(*) FROM tickets WHERE status='open'")->fetchColumn();
        echo '<div class="panel-card narrow-left"><span class="pill">صف درخواست‌های پشتیبانی</span><h2>'.fa($open).' تیکت باز</h2><p class="muted">تیکت‌های اولویت‌دار را بررسی یا تیکت جدیدی ایجاد کن.</p><button type="button" class="btn btn-primary" data-toggle="#ticket-form">ایجاد تیکت</button></div>';
        echo '<div id="ticket-form" class="panel-card narrow" hidden><h2>تیکت جدید</h2>'; form_start('tickets/create','stack-form'); echo '<label>موضوع<input name="subject" required minlength="5" maxlength="180"></label><label>اولویت<select name="priority">'; foreach (TICKET_PRIORITIES as $pr) echo '<option value="'.h($pr).'">'.h($pr).'</option>'; echo '</select></label><label>شرح مشکل<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت تیکت</button></form></div>';
        echo '<div class="panel-list">'; $tickets=q('SELECT t.id,t.subject,t.priority,t.status,t.created_at,u.name,u.phone FROM tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 40')->fetchAll();
        if (!$tickets) echo '<div class="empty-state">هنوز تیکتی ثبت نشده است.</div>';
        foreach ($tickets as $t) echo '<div class="list-item"><div><div class="meta-row"><span class="pill">'.h($t['priority']).'</span><span>'.h($t['status']==='open'?'باز':'بسته').'</span><span>'.h(jdate($t['created_at'])).'</span></div><h3>'.h($t['subject']).'</h3><p>'.h($t['name'] ?: mb_substr($t['phone'],0,4).'****').'</p></div></div>';
        echo '</div>';
    } elseif ($sub==='announcements') {
        panel_head('اعلان‌ها','آخرین رخدادهای مسیر یادگیری.');
        echo '<div class="panel-card narrow-left"><h2>ارسال اعلان</h2>'; form_start('announcements/send','stack-form'); echo '<label>عنوان<input name="title" required minlength="3" maxlength="255"></label><label>متن<textarea name="body" required minlength="5" maxlength="2000" rows="4"></textarea></label><button class="btn btn-primary">ارسال اعلان</button></form></div>';
        echo '<div class="panel-list">'; $rows=q('SELECT * FROM announcements ORDER BY id DESC LIMIT 30')->fetchAll();
        if (!$rows) { echo '<div class="empty-state">هنوز اعلانی ثبت نشده است.</div>'; foreach (ANNOUNCEMENTS as $a) echo '<div class="announce-card"><div><h2>'.h($a['title']).'</h2><p>'.h($a['body']).'</p></div><small>'.h($a['ago']).'</small></div>'; }
        else foreach ($rows as $a) echo '<div class="announce-card"><div><h2>'.h($a['title']).'</h2><p>'.h($a['body']).'</p></div><small>'.h(jdate($a['created_at'])).'</small></div>';
        echo '</div>';
    } elseif ($sub==='gamification') {
        lp_progress_view((int)$me['id']);
    } elseif ($sub==='content') {
        panel_head('محتوا','مدیریت محتوای آموزشی و صفحات عمومی.');
        echo '<div class="panel-card narrow-left"><button type="button" class="btn btn-primary" data-toggle="#content-form">ایجاد محتوا</button></div>';
        echo '<div id="content-form" class="panel-card narrow" hidden><h2>محتوای جدید</h2>'; form_start('content/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="3" maxlength="180"></label><label>نوع<select name="kind"><option value="مقاله">مقاله</option><option value="صفحه">صفحه</option></select></label><button class="btn btn-primary">ذخیره و انتشار</button></form></div>';
        echo '<div class="panel-card"><h2>آخرین آیتم‌ها</h2><div class="content-list">'; $rows=q('SELECT * FROM content_items ORDER BY id DESC LIMIT 30')->fetchAll();
        if (!$rows) $rows=array_map(fn($c)=>['id'=>null,'title'=>$c['title'],'kind'=>$c['kind'],'status'=>$c['status']],CONTENT_ITEMS);
        foreach ($rows as $c) { echo '<div class="content-row"><div><h3>'.h($c['title']).'</h3><span class="muted">'.h($c['kind']).' · '.h($c['status']).'</span></div><div class="content-actions">'; if ($c['id'] && $c['status']!=='منتشرشده') { form_start('content/publish'); echo '<input type="hidden" name="id" value="'.(int)$c['id'].'"><button class="btn btn-small btn-outline">انتشار</button></form>'; } echo '<span class="icon-btn" aria-hidden="true">✎</span></div></div>'; }
        echo '</div></div>';
    } elseif ($sub==='glossary' || str_starts_with($sub,'glossary/')) {
        admin_glossary_panel($sub);
    } elseif ($sub==='settings') {
        panel_head('تنظیمات سامانه','مدیریت تنظیمات مرتبط با هر بخش.');
        echo '<div class="settings-grid">'; foreach (SETTINGS_GROUPS as $g) { $cur=(string)q('SELECT svalue FROM settings WHERE skey=?',[$g['key']])->fetchColumn(); echo '<article class="setting-card"><div><h3>'.h($g['title']).'</h3><span class="muted">'.h($g['hint']).'</span></div>'; form_start('settings/save','setting-form'); echo '<input type="hidden" name="key" value="'.h($g['key']).'"><input name="value" value="'.h($cur).'" placeholder="مقدار تنظیم"><button class="btn btn-small btn-outline">ذخیره</button></form></article>'; }
        echo '</div>';
    } elseif ($sub==='messages') {
        panel_head('پیام‌های تماس','پیام‌های ثبت‌شده از فرم تماس.');
        echo '<div class="panel-list">'; foreach (q('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 50')->fetchAll() as $r) echo '<div class="list-item"><div><h3>'.h($r['name']).' · '.h($r['email']).'</h3><p>'.nl2br(h($r['message'])).'</p></div><small>'.h(jdate($r['created_at'])).'</small></div>'; echo '</div>';
    } else { http_response_code(404); echo '<div class="empty-state">این بخش در دسترس نیست.</div>'; }
}
