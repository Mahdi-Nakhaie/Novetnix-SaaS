<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ini_set('session.cookie_secure','1');
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
require_once dirname(__DIR__).'/src/app.php';
$path=trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/','/');
try { db(); } catch (Throwable $e) { http_response_code(503); exit('پایگاه‌داده در دسترس نیست. تنظیمات سرور را بررسی کنید.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') handle_post($path);
$me=user(); $plan=$me ? plan_for($me) : 'free';
function linkto(string $path,string $label,string $class=''): void { echo '<a class="'.($class?h($class):'').'" href="'.h(url($path)).'">'.h($label).'</a>'; }
function form_start(string $action,string $class=''): void { echo '<form action="'.h(url($action)).'" method="post" class="'.($class?h($class):'').'"><input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
function head_page(string $title,string $description='',bool $panel=false): void {
    global $me,$path;
    $full=($title==='خانه' ? 'Noventix | از یادگیری تا ساختن' : h($title).' | Noventix');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#201a35"><title>'.$full.'</title><meta name="description" content="'.h($description ?: 'Noventix؛ آموزش پروژه‌محور پایتون، علم داده و هوش مصنوعی از مطالعه تا ساختن.').'"><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head><body class="'.($panel?'is-panel':'').'">';
    if ($panel) { panel_nav(); return; }
    echo '<header class="site-header"><div class="container header-inner"><a href="/" class="brand" aria-label="Noventix، صفحه اصلی"><img src="/assets/logo.png" alt="Noventix" width="171" height="55"></a><nav class="main-nav" aria-label="ناوبری اصلی">';
    foreach ([''=>'خانه','courses'=>'مسیر یادگیری','projects'=>'پروژه‌ها','community'=>'انجمن','pricing'=>'قیمت‌گذاری','about'=>'درباره ما','contact'=>'تماس با ما'] as $href=>$label) linkto($href,$label, $path===$href?'active':'');
    echo '</nav><div class="header-actions">';
    if ($me) linkto('dashboard','پنل من','btn btn-small btn-outline'); else linkto('login','ورود','btn btn-small btn-outline');
    linkto($me?'dashboard':'login','شروع رایگان','btn btn-small btn-primary');
    echo '</div><button class="menu-toggle" type="button" aria-label="باز کردن فهرست" aria-expanded="false" aria-controls="mobile-nav">☰</button></div><nav id="mobile-nav" class="mobile-nav" aria-label="ناوبری موبایل">';
    foreach ([''=>'خانه','courses'=>'مسیر یادگیری','projects'=>'پروژه‌ها','community'=>'انجمن','pricing'=>'قیمت‌گذاری','about'=>'درباره ما','contact'=>'تماس با ما','dashboard'=>'پنل من'] as $href=>$label) linkto($href,$label);
    echo '</nav></header>';
}
function panel_nav(): void {
    global $me,$path;
    $role=$me['role'];
    $items=$role==='admin' ? ['dashboard'=>'نمای کلی','dashboard/users'=>'کاربران','dashboard/courses'=>'دوره‌ها','dashboard/projects'=>'پروژه‌ها','dashboard/subscriptions'=>'اشتراک‌ها','dashboard/analytics'=>'تحلیل‌ها','dashboard/community'=>'انجمن','dashboard/messages'=>'پیام‌ها','dashboard/profile'=>'پروفایل'] : ($role==='owner' ? ['dashboard'=>'نمای کلی','dashboard/learning'=>'مسیر یادگیری','dashboard/projects'=>'پروژه‌ها','dashboard/community'=>'انجمن','dashboard/subscription'=>'اشتراک','dashboard/profile'=>'پروفایل'] : ['dashboard'=>'نمای کلی','dashboard/learning'=>'مسیر یادگیری','dashboard/projects'=>'پروژه‌ها','dashboard/community'=>'انجمن','dashboard/subscription'=>'اشتراک','dashboard/profile'=>'پروفایل']);
    echo '<aside class="sidebar"><a class="panel-brand" href="/">Noventix <span>N</span></a><div class="sidebar-caption">'.($role==='admin'?'مدیریت پلتفرم':($role==='owner'?'پنل مالک':'فضای یادگیری')).'</div><nav aria-label="ناوبری پنل">';
    foreach ($items as $href=>$label) linkto($href,$label,$path===$href?'selected':'');
    echo '</nav><div class="sidebar-bottom"><a href="/">بازگشت به سایت ↗</a>';
    form_start('logout'); echo '<button type="submit" class="text-button">خروج از حساب</button></form></div></aside><div class="panel-body"><div class="panel-top"><button type="button" class="panel-menu" aria-label="فهرست پنل">☰</button><span>Noventix <span class="muted">/ '.($role==='admin'?'مدیریت':($role==='owner'?'مالک':'دانش‌آموز')).'</span></span><span class="panel-avatar">'.h(mb_substr($me['name'] ?: $me['phone'],0,1)).'</span></div>';
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
    echo '<footer class="footer"><div class="container"><div class="footer-grid"><div><a href="/" class="footer-brand">Noventix <span>N</span></a><p>از یاد گرفتن تا توانایی ساختن؛ یک قدم واقعی در هر پروژه.</p></div><div><h3>یادگیری</h3>'; linkto('courses','مقاله‌ها'); linkto('projects','پروژه‌ها'); linkto('community','انجمن'); echo '</div><div><h3>Noventix</h3>'; linkto('pricing','پلن‌ها'); linkto('about','درباره ما'); linkto('contact','تماس با ما'); echo '</div><div><h3>همراه ما باشید</h3><a href="https://instagram.com/noventix.ir" rel="noopener noreferrer" target="_blank">Instagram ↗</a><a href="https://t.me/noventix_ir" rel="noopener noreferrer" target="_blank">Telegram ↗</a><a href="https://rubika.ir/noventix_ir" rel="noopener noreferrer" target="_blank">Rubika ↗</a><a href="https://ble.ir/noventix_ir" rel="noopener noreferrer" target="_blank">Bale ↗</a><a href="https://www.aparat.com/noventix.ir" rel="noopener noreferrer" target="_blank">Aparat ↗</a></div></div><div class="footer-end"><span>© '.fa(date('Y')).' Noventix. همه حقوق محفوظ است.</span><span>یادگیری عمیق، ساختن واقعی.</span></div></div></footer></body></html>';
}
function notice(): void { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="container"><div role="status" class="notice '.h($f['kind']).'">'.h($f['text']).'</div></div>'; } }
function section_header(string $eyebrow,string $title,string $body=''): void { echo '<div class="section-header"><span class="eyebrow">'.h($eyebrow).'</span><h2>'.h($title).'</h2>'; if ($body) echo '<p>'.h($body).'</p>'; echo '</div>'; }
function plan_cards(bool $short=false): void {
    echo '<div class="pricing-grid">';
    foreach (PLANS as $id=>$p) {
        echo '<article class="price-card '.($id==='gold'?'featured':'').'"><div class="card-top"><span class="pill">'.h($p['tag']).'</span>'.($id==='gold'?'<span class="popular">پیشنهاد ما</span>':'').'</div><h3>'.h($p['name']).'</h3><p class="muted">'.h($p['summary']).'</p><div class="price">'.($p['price']?money($p['price']):'رایگان').'<small>'.($p['price']?'/ ماه':'برای همیشه').'</small></div><div class="card-divider"></div><ul class="check-list">';
        foreach (array_slice($p['features'],0,$short?3:5) as $feature) echo '<li>'.h($feature).'</li>';
        echo '</ul><a class="btn '.($id==='gold'?'btn-gold':'btn-outline').' full" href="'.h(url($id==='free'?'login':'dashboard/subscription')).'">'.($id==='free'?'شروع رایگان':'انتخاب پلن').'</a></article>';
    }
    echo '</div>';
}
function project_cards(int $limit=99): void {
    echo '<div class="project-grid">'; foreach (array_slice(PROJECTS,0,$limit) as $i=>$p) { echo '<article class="project-card"><div class="project-visual visual-'.($i%4).'"><span class="visual-number">'.fa(str_pad((string)($i+1),2,'0',STR_PAD_LEFT)).'</span><span class="visual-glyph">'.(['{ }','◈','◇','⌘'][$i%4]).'</span><span class="visual-label">'.h(strtoupper($p['topic'])).'</span></div><div class="project-info"><div class="meta-row"><span class="pill">'.h($p['topic']).'</span><span>'.h($p['level']).'</span></div><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p><div class="project-bottom"><span dir="ltr">'.h($p['skills']).'</span><a href="'.h(url('project/'.$i)).'" aria-label="مشاهده پروژه '.h($p['title']).'">مشاهده پروژه ←</a></div></div></article>'; } echo '</div>';
}
function course_cards(): void {
    echo '<div class="course-grid">'; $i=0; foreach (COURSES as $slug=>$c) { echo '<article class="course-card"><div class="course-icon icon-'.($i++%4).'">'.h(mb_substr($c['topic'],0,1)).'</div><div class="meta-row"><span class="pill">'.h($c['topic']).'</span><span>'.h($c['level']).' · '.fa($c['minutes']).' دقیقه مطالعه</span></div><h3>'.h($c['title']).'</h3><p>'.h($c['intro']).'</p><a class="inline-link" href="'.h(url('course/'.$slug)).'">مطالعه مقاله ←</a></article>'; } echo '</div>';
}
function home(): void {
    echo '<main><section class="hero container"><div class="hero-copy"><span class="eyebrow"><span class="dot"></span> مسیر تازه یادگیری هوش مصنوعی</span><h1>فقط یاد نگیر؛<br><em>واقعاً بساز.</em></h1><p>پایتون، علم داده و هوش مصنوعی را با مقاله‌های کاربردی یاد بگیر؛ بعد با پروژه‌های واقعی، آموخته‌هایت را به مهارت تبدیل کن.</p><div class="hero-buttons">'; linkto('courses','شروع مسیر یادگیری ←','btn btn-primary'); linkto('projects','دیدن پروژه‌ها','btn btn-outline'); echo '</div><div class="hero-proof"><span>✦ مسیر پروژه‌محور</span><span>✦ یادگیری به زبان فارسی</span><span>✦ شروع رایگان</span></div></div><div class="hero-display"><div class="hero-window"><div class="window-top"><span class="window-dots">● ● ●</span><span dir="ltr">noventix / your-next-project</span><span class="code-label">PYTHON</span></div><div class="nova-bubble"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><span><strong>سلام، من Nova هستم!</strong><small>بیا مسئله را قدم‌به‌قدم حل کنیم.</small></span></div><pre dir="ltr"><span class="code-blue">from</span> sklearn.model_selection <span class="code-blue">import</span> train_test_split

<span class="code-purple">def</span> <span class="code-yellow">build_your_future</span>(data):
    insights = <span class="code-yellow">learn</span>(data)
    <span class="code-purple">return</span> <span class="code-green">"something remarkable"</span></pre><div class="window-footer"><span><span class="status-dot"></span> اولین پروژه‌ات از همین‌جا شروع می‌شود</span><a href="/projects">شروع ساخت ←</a></div></div><div class="floating-note"><span>↗</span><strong>از ایده تا اجرا</strong><small>یک پروژه در هر قدم</small></div></div></section>';
    echo '<section class="metrics"><div class="container metrics-inner"><div><strong>۰۱</strong><span>مقاله‌های روشن و کاربردی</span></div><div><strong>۰۲</strong><span>تمرین روی پروژه واقعی</span></div><div><strong>۰۳</strong><span>بازخورد و رشد مستمر</span></div><div><strong>∞</strong><span>مسیرهای تازه برای ساختن</span></div></div></section>';
    echo '<section class="section container">'; section_header('روش یادگیری ما','فاصله دانستن تا توانستن را کم کن','به جای جمع‌کردن آموزش‌های ناتمام، با یک مسیر روشن مهارتت را در عمل بساز.'); echo '<div class="features-grid"><article class="feature"><div class="feature-icon">◇</div><span class="feature-n">01 / DISCOVER</span><h3>بخوان و بفهم</h3><p>مفهوم‌های پایتون، ماشین لرنینگ و علم داده را در مقاله‌های کوتاه و هدفمند یاد بگیر.</p></article><article class="feature"><div class="feature-icon">⌘</div><span class="feature-n">02 / BUILD</span><h3>یادگیری با ساختن</h3><p>از تحلیل داده تا مدل‌های یادگیری عمیق، مهارت‌هایت را روی مسئله‌های واقعی آزمایش کن.</p></article><article class="feature"><div class="feature-icon">✳</div><span class="feature-n">03 / GROW</span><h3>پیشرفت قابل مشاهده</h3><p>پروژه‌هایت را در میزکار دنبال کن و با گفت‌وگو در انجمن، راه‌حل‌های بهتری پیدا کن.</p></article></div></section>';
    echo '<section class="section alt-section"><div class="container"><div class="section-row">'; section_header('مسیرهای یادگیری','از کجا شروع می‌کنی؟','مقاله‌های کاربردی برای هر مرحله از سفر یادگیری.'); linkto('courses','همه مقاله‌ها ←','inline-link'); echo '</div>'; course_cards(); echo '</div></section>';
    echo '<section class="section container"><div class="section-row">'; section_header('یادگیری در عمل','پروژه‌هایی برای دنیای واقعی','از مسئله‌های کوچک شروع کن و به ساختن راه‌حل‌های قابل ارائه برس.'); linkto('projects','همه پروژه‌ها ←','inline-link'); echo '</div>'; project_cards(3); echo '</section>';
    echo '<section class="section dark-band"><div class="container band-inner"><div><span class="eyebrow">متد یادگیری CRAFT</span><h2>هر قدم یادگیری،<br>یک قدم نزدیک‌تر به ساختن.</h2><p>از درک مسئله و تمرین تا کاربرد آموخته‌ها در موقعیت‌های تازه؛ مسیری که با عمل معنا پیدا می‌کند.</p>'; linkto('about','درباره رویکرد ما ←','btn btn-light'); echo '</div><div class="band-steps"><div><span>01</span><strong>درک مفهوم</strong><small>با سؤال درست شروع کن</small></div><div><span>02</span><strong>ساخت پروژه</strong><small>راه‌حل را امتحان کن</small></div><div><span>03</span><strong>انتقال مهارت</strong><small>در مسئله تازه مستقل باش</small></div></div></div></section>';
    echo '<section class="section container">'; section_header('پلن‌های یادگیری','برای هر مرحله، یک انتخاب','از رایگان شروع کن و هر وقت آماده بودی، امکانات بیشتر را فعال کن.'); plan_cards(true); echo '<div class="center-link">'; linkto('pricing','مقایسه کامل امکانات پلن‌ها ←','inline-link'); echo '</div></section>';
    echo '<section class="section container"><div class="cta-panel"><span class="eyebrow">آینده از همین قدم شروع می‌شود</span><h2>پروژه بعدی‌ات منتظر توست.</h2><p>مسیرت را انتخاب کن، یاد بگیر و چیزی بساز که بتوانی به آن افتخار کنی.</p>'; linkto('login','رایگان شروع کن ←','btn btn-light'); echo '</div></section></main>';
}
function panel(string $sub): void {
    global $me,$plan;
    $role=$me['role']; echo '<main class="panel-content">';
    if ($sub==='') {
        echo '<div class="panel-welcome"><div><span class="eyebrow">'.($role==='admin'?'مدیریت Noventix':($role==='owner'?'فضای مالک':'فضای یادگیری شما')).'</span><h1>سلام'.($me['name']?' '.h($me['name']):'').'، خوش آمدی 👋</h1><p>'.($role==='admin'?'وضعیت پلتفرم را در یک نگاه ببین و کاربران را مدیریت کن.':'امروز هم یک قدم برای ساختن مهارت تازه بردار.').'</p></div><span class="pill">پلن '.h(PLANS[$plan]['name']).'</span></div>';
        if ($role==='admin') { $stats=[['کاربران',(int)q('SELECT COUNT(*) FROM users')->fetchColumn()],['اشتراک فعال',(int)q("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at>?",[gmdate('Y-m-d H:i:s')])->fetchColumn()],['گفت‌وگوها',(int)q('SELECT COUNT(*) FROM community_posts')->fetchColumn()],['پیام‌ها',(int)q('SELECT COUNT(*) FROM contact_messages')->fetchColumn()]]; }
        else { $stats=[['مقاله‌های من',(int)q('SELECT COUNT(*) FROM enrollments WHERE user_id=?',[$me['id']])->fetchColumn()],['پروژه‌های من',(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$me['id']])->fetchColumn()],['گفت‌وگوها',(int)q('SELECT COUNT(*) FROM community_posts WHERE user_id=?',[$me['id']])->fetchColumn()],['پلن فعال',PLANS[$plan]['name']]]; }
        echo '<div class="stat-grid">'; foreach ($stats as [$label,$value]) echo '<div class="stat-card"><span>'.h($label).'</span><strong>'.(is_int($value)?fa($value):h($value)).'</strong></div>'; echo '</div><div class="panel-columns"><section class="panel-card"><span class="eyebrow">گام بعدی</span><h2>'.($role==='admin'?'مدیریت اشتراک‌ها':'مسیرت را ادامه بده').'</h2><p>'.($role==='admin'?'فعال‌سازی اشتراک پس از تأیید پرداخت خارج از سامانه و بررسی دستی امکان‌پذیر است.':'یک مقاله را بخوان و سپس دانسته‌هایت را در یک پروژه تازه به کار بگیر.').'</p>'; linkto($role==='admin'?'dashboard/subscriptions':'courses',$role==='admin'?'مدیریت اشتراک‌ها ←':'مشاهده مقاله‌ها ←','btn btn-primary'); echo '</section><section class="panel-card soft"><span class="eyebrow">'.($role==='admin'?'جامعه':'کنار هم یاد می‌گیریم').'</span><h2>انجمن Noventix</h2><p>سؤال‌ها، تجربه‌ها و ایده‌ها را با جامعه یادگیری در میان بگذار.</p>'; linkto('community','ورود به انجمن ←','btn btn-outline'); echo '</section></div>';
    } elseif ($sub==='profile') {
        echo '<div class="panel-heading"><h1>پروفایل من</h1><p>اطلاعات نمایش داده‌شده در حساب کاربری.</p></div><div class="panel-card narrow">'; form_start('profile/save','stack-form'); echo '<label>نام نمایشی<input name="name" maxlength="100" minlength="2" required value="'.h($me['name']).'" placeholder="نام شما"></label><label>شماره موبایل<input dir="ltr" disabled value="'.h($me['phone']).'"></label><button class="btn btn-primary">ذخیره تغییرات</button></form></div>';
    } elseif ($sub==='subscription' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>اشتراک من</h1><p>امکانات شما بر اساس وضعیت اشتراک فعال تنظیم می‌شود.</p></div><div class="panel-card narrow"><span class="pill">پلن فعلی</span><h2>'.h(PLANS[$plan]['name']).'</h2><ul class="check-list">'; foreach (PLANS[$plan]['features'] as $f) echo '<li>'.h($f).'</li>'; echo '</ul><p class="muted">پرداخت آنلاین هنوز متصل نشده است. برای فعال‌سازی پلن پولی با پشتیبانی تماس بگیرید.</p>'; linkto('pricing','مقایسه پلن‌ها ←','btn btn-outline'); echo '</div>';
    } elseif ($sub==='learning' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>مسیر یادگیری من</h1><p>مقاله‌های ذخیره‌شده برای ادامه مطالعه.</p></div><div class="panel-list">'; $rows=q('SELECT course_slug FROM enrollments WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز مقاله‌ای اضافه نکردی. <a href="/courses">مقاله‌ها را ببین ←</a></div>'; foreach ($rows as $r) if(isset(COURSES[$r['course_slug']])) echo '<div class="list-item"><div><span class="pill">'.h(COURSES[$r['course_slug']]['topic']).'</span><h3>'.h(COURSES[$r['course_slug']]['title']).'</h3></div><a href="'.h(url('course/'.$r['course_slug'])).'">ادامه مطالعه ←</a></div>'; echo '</div>';
    } elseif ($sub==='projects' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>پروژه‌های من</h1><p>مسئله‌هایی که برای ساختن انتخاب کرده‌ای.</p></div><div class="panel-list">'; $rows=q('SELECT catalog_index FROM projects WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز پروژه‌ای شروع نکردی. <a href="/projects">پروژه‌ها را ببین ←</a></div>'; foreach ($rows as $r) { $p=PROJECTS[$r['catalog_index']] ?? null; if($p) echo '<div class="list-item"><div><span class="pill">'.h($p['topic']).'</span><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p></div><a href="'.h(url('project/'.$r['catalog_index'])).'">جزئیات ←</a></div>'; } echo '</div>';
    } elseif ($sub==='community') { echo '<div class="panel-heading"><h1>انجمن</h1><p>یادگیری با گفت‌وگو بهتر می‌شود.</p></div><div class="panel-card">'; linkto('community','مشاهده و مشارکت در گفت‌وگوها ←','btn btn-primary'); echo '</div>';
    } elseif ($role==='admin') admin_panel($sub);
    else { http_response_code(404); echo '<div class="empty-state">این بخش در دسترس نیست.</div>'; }
    echo '</main>';
}
function admin_panel(string $sub): void {
    if ($sub==='subscriptions') {
        echo '<div class="panel-heading"><h1>اشتراک‌ها</h1><p>فعال‌سازی دستی فقط بعد از تأیید پرداخت از مسیر رسمی انجام شود.</p></div><div class="panel-card narrow"><h2>فعال‌سازی اشتراک ۳۰ روزه</h2>'; form_start('admin/activate','stack-form'); echo '<label>شماره موبایل کاربر<input name="phone" type="tel" dir="ltr" placeholder="09123456789" required></label><label>پلن<select name="plan">'; foreach (PLANS as $id=>$p) if($id!=='free') echo '<option value="'.h($id).'">'.h($p['name']).'</option>'; echo '</select></label><button class="btn btn-primary">فعال‌سازی پس از تأیید پرداخت</button></form></div><div class="panel-card"><h2>اشتراک‌های اخیر</h2><div class="table-wrap"><table><thead><tr><th>شماره</th><th>پلن</th><th>وضعیت</th><th>پایان (UTC)</th></tr></thead><tbody>'; foreach (q('SELECT u.phone,s.plan,s.status,s.expires_at FROM subscriptions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 50')->fetchAll() as $r) echo '<tr><td dir="ltr">'.h($r['phone']).'</td><td>'.h(PLANS[$r['plan']]['name'] ?? $r['plan']).'</td><td>'.h($r['status']).'</td><td dir="ltr">'.h($r['expires_at']).'</td></tr>'; echo '</tbody></table></div></div>';
    } elseif ($sub==='users') {
        echo '<div class="panel-heading"><h1>کاربران</h1><p>فهرست حساب‌های ثبت‌شده.</p></div><div class="panel-card table-wrap"><table><thead><tr><th>نام</th><th>شماره</th><th>نقش</th><th>تاریخ عضویت (UTC)</th></tr></thead><tbody>'; foreach (q('SELECT * FROM users ORDER BY id DESC LIMIT 100')->fetchAll() as $r) echo '<tr><td>'.h($r['name'] ?: '—').'</td><td dir="ltr">'.h($r['phone']).'</td><td>'.h($r['role']).'</td><td dir="ltr">'.h($r['created_at']).'</td></tr>'; echo '</tbody></table></div>';
    } elseif ($sub==='messages') {
        echo '<div class="panel-heading"><h1>پیام‌های تماس</h1><p>پیام‌های ثبت‌شده از فرم تماس.</p></div><div class="panel-list">'; foreach (q('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 50')->fetchAll() as $r) echo '<div class="list-item"><div><h3>'.h($r['name']).' · '.h($r['email']).'</h3><p>'.nl2br(h($r['message'])).'</p></div><small>'.h($r['created_at']).'</small></div>'; echo '</div>';
    } elseif ($sub==='courses' || $sub==='projects') {
        echo '<div class="panel-heading"><h1>'.($sub==='courses'?'محتوای آموزشی':'کاتالوگ پروژه‌ها').'</h1><p>'.($sub==='courses'?fa(count(COURSES)).' مقاله فعال در کاتالوگ.':fa(count(PROJECTS)).' پروژه فعال در کاتالوگ.').'</p></div><div class="panel-card"><p class="muted">کاتالوگ از فایل محتوای برنامه بارگذاری می‌شود؛ ویرایش از پنل هنوز فعال نیست.</p>'; linkto($sub==='courses'?'courses':'projects','مشاهده کاتالوگ ←','btn btn-outline'); echo '</div>';
    } elseif ($sub==='analytics') {
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);
        $rows=[['کاربران ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM users WHERE created_at>=?',[$since])->fetchColumn()],['گفت‌وگوهای ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM community_posts WHERE created_at>=?',[$since])->fetchColumn()],['پروژه‌های شروع‌شده',(int)q('SELECT COUNT(*) FROM projects')->fetchColumn()],['مقاله‌های ذخیره‌شده',(int)q('SELECT COUNT(*) FROM enrollments')->fetchColumn()]];
        echo '<div class="panel-heading"><h1>تحلیل‌ها</h1><p>شاخص‌های پایه فعالیت پلتفرم.</p></div><div class="stat-grid">'; foreach ($rows as [$label,$value]) echo '<div class="stat-card"><span>'.h($label).'</span><strong>'.fa($value).'</strong></div>'; echo '</div><div class="panel-card"><h2>توزیع پلن‌ها</h2><div class="table-wrap"><table><thead><tr><th>پلن</th><th>اشتراک فعال</th></tr></thead><tbody>'; foreach (PLANS as $id=>$p) echo '<tr><td>'.h($p['name']).'</td><td>'.fa((int)q("SELECT COUNT(*) FROM subscriptions WHERE plan=? AND status='active' AND expires_at>?",[$id,gmdate('Y-m-d H:i:s')])->fetchColumn()).'</td></tr>'; echo '</tbody></table></div></div>';
    } else { http_response_code(404); echo '<div class="empty-state">این بخش در دسترس نیست.</div>'; }
}
