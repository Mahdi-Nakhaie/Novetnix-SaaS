<?php
declare(strict_types=1);

function linkto(string $path,string $label,string $class=''): void { echo '<a class="'.($class?h($class):'').'" href="'.h(url($path)).'">'.h($label).'</a>'; }
function form_start(string $action,string $class='',bool $customValidation=false): void { echo '<form action="'.h(url($action)).'" method="post" class="'.($class?h($class):'').($customValidation?' custom-validation':'').($customValidation?'" novalidate':'"').'><input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
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
    $items=$role==='admin'
        ? ['dashboard'=>'Overview','dashboard/users'=>'کاربران','dashboard/users-new'=>'کاربران جدید','dashboard/courses'=>'دوره‌ها','dashboard/catalog'=>'پروژه‌ها','dashboard/challenges'=>'چالش‌ها','dashboard/subscriptions'=>'اشتراک‌ها','dashboard/payments'=>'پرداخت‌ها','dashboard/community'=>'Community','dashboard/support'=>'پشتیبانی','dashboard/announcements'=>'اعلان‌ها','dashboard/gamification'=>'Gamification','dashboard/analytics'=>'Analytics','dashboard/content'=>'Content','dashboard/profile'=>'پروفایل','dashboard/settings'=>'System Settings']
        : ['dashboard'=>'نمای کلی','dashboard/learning'=>'مسیر یادگیری','dashboard/projects'=>'پروژه‌ها','dashboard/challenges'=>'چالش‌ها','dashboard/community'=>'Community','dashboard/nova'=>'Nova AI','dashboard/subscription'=>'اشتراک','dashboard/support'=>'پشتیبانی','dashboard/gamification'=>'Gamification','dashboard/activity'=>'فعالیت و Streak','dashboard/profile'=>'پروفایل','dashboard/settings'=>'تنظیمات'];
    echo '<aside class="sidebar"><a class="panel-brand" href="/"><img src="/assets/favicon.png" alt="" width="42" height="42"><span>Noventix</span></a><div class="sidebar-caption">'.($role==='admin'?'مدیریت پلتفرم':($role==='owner'?'پنل مالک':'فضای یادگیری')).'</div><nav aria-label="ناوبری پنل">';
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
        echo '</ul>';
        if (!$short && !empty($p['limits'])) { echo '<div class="plan-limits"><span class="eyebrow">سقف دقیق این پلن</span><table><tbody>'; foreach ($p['limits'] as [$label,$value]) echo '<tr><th scope="row">'.h($label).'</th><td>'.h($value).'</td></tr>'; echo '</tbody></table></div>'; }
        echo '<a class="btn '.($id==='gold'?'btn-gold':'btn-outline').' full" href="'.h(url($id==='free'?'login':'dashboard/subscription')).'">'.($id==='free'?'شروع رایگان':'انتخاب پلن').'</a></article>';
    }
    echo '</div>';
}
function project_cards(int $limit=99): void {
    echo '<div class="project-grid">'; foreach (array_slice(PROJECTS,0,$limit) as $i=>$p) { echo '<article class="project-card"><div class="project-visual visual-'.($i%4).'"><span class="visual-number">'.fa(str_pad((string)($i+1),2,'0',STR_PAD_LEFT)).'</span><span class="visual-glyph">'.(['{ }','◈','◇','⌘'][$i%4]).'</span><span class="visual-label">'.h(strtoupper($p['topic'])).'</span></div><div class="project-info"><div class="meta-row"><span class="pill">'.h($p['topic']).'</span><span>'.h($p['level']).'</span></div><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p><div class="project-bottom"><span dir="ltr">'.h($p['skills']).'</span><a href="'.h(url('project/'.$i)).'" aria-label="مشاهده پروژه '.h($p['title']).'">مشاهده پروژه ←</a></div></div></article>'; } echo '</div>';
}
function course_cards(array $courses): void {
    echo '<div class="course-grid">'; $i=0; foreach ($courses as $c) { echo '<article class="course-card"><div class="course-icon icon-'.($i++%4).'">'.h(mb_substr($c['title'],0,1)).'</div><div class="meta-row"><span class="pill">Course</span><span>منتشرشده</span></div><h3>'.h($c['title']).'</h3><p>'.h($c['description']).'</p><a class="inline-link" href="'.h(url('course/'.$c['slug'])).'">مشاهده Course ←</a></article>'; } echo '</div>';
}
function home(array $courses): void {
    echo '<main><section class="hero container"><div class="hero-copy"><span class="eyebrow"><span class="dot"></span> مسیر تازه یادگیری هوش مصنوعی</span><h1>فقط یاد نگیر؛<br><em>واقعاً بساز.</em></h1><p>پایتون، علم داده و هوش مصنوعی را با مقاله‌های کاربردی یاد بگیر؛ بعد با پروژه‌های واقعی، آموخته‌هایت را به مهارت تبدیل کن.</p><div class="hero-buttons">'; linkto('courses','شروع مسیر یادگیری ←','btn btn-primary'); linkto('projects','دیدن پروژه‌ها','btn btn-outline'); echo '</div><div class="hero-proof"><span>✦ مسیر پروژه‌محور</span><span>✦ یادگیری به زبان فارسی</span><span>✦ شروع رایگان</span></div></div><div class="hero-display"><div class="hero-window"><div class="window-top"><span class="window-dots">● ● ●</span><span dir="ltr">noventix / your-next-project</span><span class="code-label">PYTHON</span></div><div class="nova-bubble"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><span><strong>سلام، من Nova هستم!</strong><small>بیا مسئله را قدم‌به‌قدم حل کنیم.</small></span></div><pre dir="ltr"><span class="code-blue">from</span> sklearn.model_selection <span class="code-blue">import</span> train_test_split

<span class="code-purple">def</span> <span class="code-yellow">build_your_future</span>(data):
    insights = <span class="code-yellow">learn</span>(data)
    <span class="code-purple">return</span> <span class="code-green">"something remarkable"</span></pre><div class="window-footer"><span><span class="status-dot"></span> اولین پروژه‌ات از همین‌جا شروع می‌شود</span><a href="/projects">شروع ساخت ←</a></div></div><div class="floating-note"><span>↗</span><strong>از ایده تا اجرا</strong><small>یک پروژه در هر قدم</small></div></div></section>';
    echo '<section class="metrics"><div class="container metrics-inner"><div><strong>۰۱</strong><span>مقاله‌های روشن و کاربردی</span></div><div><strong>۰۲</strong><span>تمرین روی پروژه واقعی</span></div><div><strong>۰۳</strong><span>بازخورد و رشد مستمر</span></div><div><strong>∞</strong><span>مسیرهای تازه برای ساختن</span></div></div></section>';
    echo '<section class="section container">'; section_header('روش یادگیری ما','فاصله دانستن تا توانستن را کم کن','به جای جمع‌کردن آموزش‌های ناتمام، با یک مسیر روشن مهارتت را در عمل بساز.'); echo '<div class="features-grid"><article class="feature"><div class="feature-icon">◇</div><span class="feature-n">01 / DISCOVER</span><h3>بخوان و بفهم</h3><p>مفهوم‌های پایتون، ماشین لرنینگ و علم داده را در مقاله‌های کوتاه و هدفمند یاد بگیر.</p></article><article class="feature"><div class="feature-icon">⌘</div><span class="feature-n">02 / BUILD</span><h3>یادگیری با ساختن</h3><p>از تحلیل داده تا مدل‌های یادگیری عمیق، مهارت‌هایت را روی مسئله‌های واقعی آزمایش کن.</p></article><article class="feature"><div class="feature-icon">✳</div><span class="feature-n">03 / GROW</span><h3>پیشرفت قابل مشاهده</h3><p>پروژه‌هایت را در میزکار دنبال کن و با گفت‌وگو در انجمن، راه‌حل‌های بهتری پیدا کن.</p></article></div></section>';
    echo '<section class="section alt-section"><div class="container"><div class="section-row">'; section_header('مسیرهای یادگیری','از کجا شروع می‌کنی؟','مقاله‌های کاربردی برای هر مرحله از سفر یادگیری.'); linkto('courses','همه مقاله‌ها ←','inline-link'); echo '</div>'; course_cards($courses); echo '</div></section>';
    echo '<section class="section container"><div class="section-row">'; section_header('یادگیری در عمل','پروژه‌هایی برای دنیای واقعی','از مسئله‌های کوچک شروع کن و به ساختن راه‌حل‌های قابل ارائه برس.'); linkto('projects','همه پروژه‌ها ←','inline-link'); echo '</div>'; project_cards(3); echo '</section>';
    echo '<section class="section dark-band"><div class="container band-inner"><div><span class="eyebrow">متد یادگیری CRAFT</span><h2>هر قدم یادگیری،<br>یک قدم نزدیک‌تر به ساختن.</h2><p>از درک مسئله و تمرین تا کاربرد آموخته‌ها در موقعیت‌های تازه؛ مسیری که با عمل معنا پیدا می‌کند.</p>'; linkto('about','درباره رویکرد ما ←','btn btn-light'); echo '</div><div class="band-steps"><div><span>01</span><strong>درک مفهوم</strong><small>با سؤال درست شروع کن</small></div><div><span>02</span><strong>ساخت پروژه</strong><small>راه‌حل را امتحان کن</small></div><div><span>03</span><strong>انتقال مهارت</strong><small>در مسئله تازه مستقل باش</small></div></div></div></section>';
    echo '<section class="section container">'; section_header('پلن‌های یادگیری','برای هر مرحله، یک انتخاب','از رایگان شروع کن و هر وقت آماده بودی، امکانات بیشتر را فعال کن.'); plan_cards(true); echo '<div class="center-link">'; linkto('pricing','مقایسه کامل امکانات پلن‌ها ←','inline-link'); echo '</div></section>';
    echo '<section class="section container"><div class="cta-panel"><span class="eyebrow">آینده از همین قدم شروع می‌شود</span><h2>پروژه بعدی‌ات منتظر توست.</h2><p>مسیرت را انتخاب کن، یاد بگیر و چیزی بساز که بتوانی به آن افتخار کنی.</p>'; linkto('login','رایگان شروع کن ←','btn btn-light'); echo '</div></section></main>';
}
function panel_head(string $title,string $sub): void { echo '<div class="panel-heading"><h1>'.h($title).'</h1><p>'.h($sub).'</p></div>'; }
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
function bars(array $values,bool $gold=false): void {
    $max=max(1,max($values)); echo '<div class="chart-bars'.($gold?' is-gold':'').'">';
    foreach ($values as $i=>$v) echo '<span style="height:'.round($v/$max*100).'%" title="'.h(fa($v)).'"></span>';
    echo '</div>';
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
        panel_head('پروفایل من','اطلاعات نمایش داده‌شده در حساب کاربری.');
        echo '<div class="profile-head"><div><img src="/assets/nova.png" alt="" width="56" height="56" aria-hidden="true"><div><h2>'.h($me['name'] ?: 'کاربر Noventix').'</h2><p class="muted">'.h($me['phone']).' · Level '.fa('12').' · XP '.fa('۲۴۸۰').'</p><p class="muted">'.h($role==='admin'?'مدیر پلتفرم':($role==='owner'?'مالک پلتفرم':'سازنده‌ای در مسیر یادگیری هوش مصنوعی')).'</p></div></div>'.linkto('dashboard/settings','ویرایش پروفایل','btn btn-outline').'</div>';
        echo '<div class="panel-card narrow">'; form_start('profile/account','stack-form'); echo '<label>نام نمایشی<input name="name" maxlength="100" minlength="2" required value="'.h($me['name']).'" placeholder="نام شما"></label><label>ایمیل (اختیاری)<input type="email" dir="ltr" name="email" maxlength="255" placeholder="you@example.com"></label><label>شماره موبایل<input dir="ltr" disabled value="'.h($me['phone']).'"></label><button class="btn btn-primary">ذخیره تغییرات</button></form><p class="fine-print">شماره موبایل شناسه ورود شماست و از این صفحه قابل تغییر نیست.</p></div>';
    } elseif ($sub==='nova' && $role!=='admin') {
        panel_head('Nova AI','دستیار آموزشی برای پرسش‌های شما.');
        $limit=PLANS[$plan]['nova'];
        $used=(int)q('SELECT used FROM nova_usage WHERE user_id=? AND month=?',[$me['id'],gmdate('Y-m')])->fetchColumn();
        echo '<div class="panel-card narrow-left"><h2>از Nova بپرسید</h2><p class="muted">پاسخ‌ها با هوش مصنوعی تولید می‌شوند و ممکن است اشتباه باشند. اطلاعات محرمانه را در سؤال وارد نکنید.</p><p>اعتبار این ماه: '.fa(max(0,$limit-$used)).' از '.fa($limit).'</p>';
        if ($limit>$used) { form_start('nova/ask','stack-form'); echo '<label>سؤال شما<textarea name="question" required minlength="10" maxlength="2000" rows="4" placeholder="سؤالتان را با جزئیات بنویسید"></textarea></label><button class="btn btn-primary">پرسیدن از Nova</button></form>'; }
        else linkto('pricing','مشاهده پلن‌ها ←','btn btn-outline');
        echo '</div><div class="panel-list" aria-label="گفت‌وگوهای Nova">';
        foreach (array_reverse(q('SELECT question,answer,created_at FROM nova_messages WHERE user_id=? ORDER BY id DESC LIMIT 20',[$me['id']])->fetchAll()) as $turn) echo '<div class="panel-card"><div class="chat-bubble is-user"><p>'.nl2br(h($turn['question'])).'</p><small>'.h(jdate($turn['created_at'])).'</small></div><div class="chat-bubble is-nova"><span class="chip">Nova</span><p>'.nl2br(h($turn['answer'])).'</p></div></div>';
        echo '</div>';
    } elseif ($sub==='subscription' && $role!=='admin') {
        $active=(int)q("SELECT COUNT(*) FROM subscriptions WHERE user_id=? AND status='active' AND expires_at>?",[$me['id'],gmdate('Y-m-d H:i:s')])->fetchColumn()?1:0;
        $expires=q("SELECT expires_at FROM subscriptions WHERE user_id=? AND status='active' ORDER BY expires_at DESC LIMIT 1",[$me['id']])->fetchColumn();
        $limit=PLANS[$plan]['nova']; $used=min($limit,(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$me['id']])->fetchColumn());
        panel_head('اشتراک من','وضعیت پلن و ظرفیت امکانات شما.');
        echo '<div class="sub-layout"><section class="panel-card"><h2>Usage</h2><div class="usage-row"><span>AI Credits</span><strong>'.fa($used).' از '.fa($limit).'</strong></div><div class="progress"><span style="width:'.($limit?max(2,round($used/$limit*100)):2).'%"></span></div><div class="usage-row"><span>Projects</span><strong>'.(PLANS[$plan]['projects']===-1?'نامحدود':fa(PLANS[$plan]['projects'])).'</strong></div></section>';
        echo '<section class="plan-tile"><span class="eyebrow">Current Plan</span><h2>'.h(PLANS[$plan]['name']).'</h2><p>'.($expires?'تمدید: '.jdate((string)$expires):'اشتراک فعالی ثبت نشده است.').'</p>'; linkto('pricing',$plan==='titanium'?'مشاهده پلن‌ها':'ارتقای پلن',$plan==='titanium'?'btn btn-outline':'btn btn-gold'); echo '</section></div>';
        echo '<div class="panel-card"><h2>امکانات فعلی</h2><ul class="check-list">'; foreach (PLANS[$plan]['features'] as $f) echo '<li>'.h($f).'</li>'; echo '</ul><p class="muted">پرداخت آنلاین هنوز متصل نشده است؛ برای فعال‌سازی پلن پولی با پشتیبانی تماس بگیرید.</p>'; linkto('dashboard/payments','مشاهده پرداخت‌ها ←','btn btn-outline'); echo '</div>';
    } elseif ($sub==='learning' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>مسیر یادگیری من</h1><p>مقاله‌های ذخیره‌شده برای ادامه مطالعه.</p></div><div class="panel-list">'; $rows=q('SELECT course_slug FROM enrollments WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز مقاله‌ای اضافه نکردی. <a href="/courses">مقاله‌ها را ببین ←</a></div>'; foreach ($rows as $r) if(isset(COURSES[$r['course_slug']])) echo '<div class="list-item"><div><span class="pill">'.h(COURSES[$r['course_slug']]['topic']).'</span><h3>'.h(COURSES[$r['course_slug']]['title']).'</h3></div><a href="'.h(url('course/'.$r['course_slug'])).'">ادامه مطالعه ←</a></div>'; echo '</div>';
    } elseif ($sub==='learning' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>مسیر یادگیری من</h1><p>مقاله‌های ذخیره‌شده برای ادامه مطالعه.</p></div><div class="panel-list">'; $rows=q('SELECT course_slug FROM enrollments WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز مقاله‌ای اضافه نکردی. <a href="/courses">مقاله‌ها را ببین ←</a></div>'; foreach ($rows as $r) if(isset(COURSES[$r['course_slug']])) echo '<div class="list-item"><div><span class="pill">'.h(COURSES[$r['course_slug']]['topic']).'</span><h3>'.h(COURSES[$r['course_slug']]['title']).'</h3></div><a href="'.h(url('course/'.$r['course_slug'])).'">ادامه مطالعه ←</a></div>'; echo '</div>';
    } elseif ($sub==='projects' && $role!=='admin') {
        echo '<div class="panel-heading"><h1>پروژه‌های من</h1><p>مسئله‌هایی که برای ساختن انتخاب کرده‌ای.</p></div><div class="panel-list">'; $rows=q('SELECT catalog_index FROM projects WHERE user_id=? ORDER BY created_at DESC',[$me['id']])->fetchAll(); if (!$rows) echo '<div class="empty-state">هنوز پروژه‌ای شروع نکردی. <a href="/projects">پروژه‌ها را ببین ←</a></div>'; foreach ($rows as $r) { $p=PROJECTS[$r['catalog_index']] ?? null; if($p) echo '<div class="list-item"><div><span class="pill">'.h($p['topic']).'</span><h3>'.h($p['title']).'</h3><p>'.h($p['description']).'</p></div><a href="'.h(url('project/'.$r['catalog_index'])).'">جزئیات ←</a></div>'; } echo '</div>';
    } elseif ($sub==='community') {
        panel_head('Community','از سؤال کوچک تا نمایش بزرگ‌ترین پروژه‌ها.');
        $posts=q('SELECT p.id,p.user_id,p.title,p.body,p.created_at,u.name,u.phone FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 20')->fetchAll();
        echo '<div class="panel-card narrow-left"><h2>Create Post</h2>'; form_start('community/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label><label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="4"></textarea></label><button class="btn btn-primary">Create Post</button></form></div>';
        echo '<div class="panel-list">'; if (!$posts) echo '<div class="empty-state">هنوز پستی ثبت نشده است.</div>';
        foreach ($posts as $p) {
            $likes=(int)q("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='like'",[$p['id']])->fetchColumn();
            $saves=(int)q("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='save'",[$p['id']])->fetchColumn();
            $replies=(int)q('SELECT COUNT(*) FROM community_replies WHERE post_id=?',[$p['id']])->fetchColumn();
            echo '<article class="post-card"><div class="meta-row"><a class="profile-link" href="'.h(url('member/'.$p['user_id'])).'">'.h($p['name'] ?: mb_substr($p['phone'],0,4).'****').'</a><span>'.h(jdate($p['created_at'])).'</span>'; if ((int)$p['user_id']!==$me['id']) { $following=q('SELECT 1 FROM user_follows WHERE follower_id=? AND followed_id=?',[$me['id'],$p['user_id']])->fetchColumn(); if ($following) echo '<span class="pill">دنبال می‌کنید</span>'; else { form_start('community/follow','react-form'); echo '<input type="hidden" name="user_id" value="'.(int)$p['user_id'].'"><button class="text-button">دنبال کردن</button></form>'; } } echo '</div><h2>'.h($p['title']).'</h2><p>'.nl2br(h($p['body'])).'</p><div class="react-row">';
            foreach (['like'=>'Like','save'=>'Save'] as $kind=>$label) { form_start('community/react','react-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><input type="hidden" name="kind" value="'.h($kind).'"><button class="text-button">'.h($label).' '.fa($kind==='like'?$likes:$saves).'</button></form>'; }
            echo '<a class="text-button" href="'.h(url('community')).'">Comment '.fa($replies).'</a></div>'; echo '<div class="comment-list">'; foreach (q('SELECT r.body,r.created_at,u.name FROM community_replies r JOIN users u ON u.id=r.user_id WHERE r.post_id=? ORDER BY r.id',[$p['id']])->fetchAll() as $reply) echo '<div class="comment"><strong>'.h($reply['name'] ?: 'کاربر').'</strong><p>'.nl2br(h($reply['body'])).'</p></div>'; echo '</div>'; form_start('community/comment','comment-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><textarea name="body" required minlength="2" maxlength="2000" rows="2" placeholder="نظر خود را بنویسید…"></textarea><button class="btn btn-small btn-outline">ارسال نظر</button></form></article>';
        }
        echo '</div>';
    } elseif ($sub==='support') {
        panel_head('پشتیبانی','تیکت‌های باز و اولویت‌بندی آن‌ها.');
        $open=(int)q("SELECT COUNT(*) FROM tickets WHERE user_id=? AND status='open'",[$me['id']])->fetchColumn();
        echo '<div class="panel-card narrow-left"><span class="pill">Support Queue</span><h2>'.fa($open).' تیکت باز</h2><p class="muted">تیکت‌های اولویت‌دار را بررسی یا تیکت جدیدی ایجاد کن.</p><button type="button" class="btn btn-primary" data-toggle="#ticket-form">Create Ticket</button></div>';
        echo '<div id="ticket-form" class="panel-card narrow" hidden><h2>تیکت جدید</h2>'; form_start('tickets/create','stack-form'); echo '<label>موضوع<input name="subject" required minlength="5" maxlength="180"></label><label>اولویت<select name="priority">'; foreach (TICKET_PRIORITIES as $pr) echo '<option value="'.h($pr).'">'.h($pr).'</option>'; echo '</select></label><label>شرح مشکل<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت تیکت</button></form></div>';
        echo '<div class="panel-list">'; $rows=q('SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT 30',[$me['id']])->fetchAll();
        if (!$rows) echo '<div class="empty-state">هنوز تیکتی ثبت نکرده‌ای.</div>';
        foreach ($rows as $t) { echo '<div class="ticket-card"><div class="meta-row"><span class="pill">'.h($t['priority']).'</span><span>'.h($t['status']==='open'?'باز':'بسته').'</span><span>'.h(jdate($t['created_at'])).'</span></div><h3>'.h($t['subject']).'</h3><div class="ticket-thread">'; foreach (q('SELECT r.body,r.created_at,r.user_id,u.name FROM ticket_replies r JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.id',[$t['id']])->fetchAll() as $reply) echo '<div class="ticket-message'.((int)$reply['user_id']===(int)$me['id'] ? ' is-user' : '').'"><strong>'.((int)$reply['user_id']===(int)$me['id'] ? 'شما' : h($reply['name'] ?: 'پشتیبانی')).'</strong><p>'.nl2br(h($reply['body'])).'</p></div>'; echo '</div>'; form_start('tickets/reply','ticket-reply-form'); echo '<input type="hidden" name="ticket_id" value="'.(int)$t['id'].'"><textarea name="body" required minlength="2" maxlength="5000" rows="3" placeholder="پاسخ خود را بنویسید…"></textarea><button class="btn btn-small btn-outline">ارسال پاسخ</button></form></div>'; }
        echo '</div>';
    } elseif ($sub==='payments') {
        panel_head('پرداخت‌ها','تاریخچه اشتراک و صورتحساب شما.');
        $rows=q('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 30',[$me['id']])->fetchAll();
        echo '<div class="panel-card"><h2>صورت‌حساب و پرداخت</h2><p class="muted">پلن فعلی: '.h(PLANS[$plan]['name']).' — '.money((int)PLANS[$plan]['price']).'</p><p class="muted">درگاه پرداخت متصل نیست؛ فعال‌سازی فقط پس از تأیید پرداخت خارج از سامانه انجام می‌شود.</p>'; linkto('pricing','مشاهده پلن‌ها ←','btn btn-primary'); echo '</div>';
        echo '<div class="panel-card table-wrap"><table><thead><tr><th>پلن</th><th>مبلغ ماهانه</th><th>وضعیت</th><th>پایان</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4" class="muted">هنوز پرداختی ثبت نشده است.</td></tr>';
        foreach ($rows as $r) echo '<tr><td>'.h(PLANS[$r['plan']]['name'] ?? $r['plan']).'</td><td>'.money((int)(PLANS[$r['plan']]['price'] ?? 0)).'</td><td>'.h($r['status']).'</td><td>'.h(jdate($r['expires_at'])).'</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($sub==='gamification') {
        $xp=(int)q('SELECT COUNT(*) FROM challenge_progress WHERE user_id=?',[$me['id']])->fetchColumn()*120+2480;
        $streak=(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$me['id']])->fetchColumn()+7;
        panel_head('Gamification','سطح، امتیاز و نشان‌های یادگیری.');
        echo '<div class="stat-grid">'; foreach ([['Level','۱۲'],['XP',fa($xp)],['Streak',fa($streak).' روز'],['Missions',fa(count(CHALLENGES)).' فعال']] as [$l,$v]) echo '<div class="stat-card"><span>'.h($l).'</span><strong>'.h($v).'</strong></div>'; echo '</div>';
        echo '<div class="panel-columns"><section class="panel-card"><h2>Skill Tree</h2>'; foreach (TRACKS as $t) echo '<div class="skill-row"><div class="usage-row"><span>'.h($t['name']).'</span><strong>'.fa($t['percent']).'٪</strong></div><div class="progress"><span style="width:'.$t['percent'].'%"></span></div></div>'; echo '</section><section class="panel-card"><h2>Achievements &amp; Badges</h2><div class="badge-grid">'; foreach (BADGES as $b) echo '<div class="badge" title="'.h($b['need']).'"><span>'.h($b['icon']).'</span>'.h($b['title']).'</div>'; echo '</div></section></div>';
    } elseif ($sub==='activity' && $role!=='admin') {
        panel_head('فعالیت و Streak','هر روزی که واقعاً کار کرده باشی، در این تقویم ثبت می‌شود.');
        $kinds=[['projects','پروژه فعال','پروژه‌هایی که به فهرست خود اضافه کرده‌ای'],['challenges','چالش حل‌شده','چالش‌هایی که راه‌حل برایشان ثبت شده'],['enrollments','مقاله ذخیره‌شده','مقاله‌هایی که در مسیر یادگیری ذخیره کرده‌ای'],['posts','گفت‌وگوی انجمن','پست‌هایی که در انجمن منتشر کرده‌ای']];
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
        $labels=['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه']; $top=max(1,max($week));
        foreach ($week as $i=>$value) echo '<div class="activity-day"><span class="activity-bar" style="height:'.round($value/$top*100).'%" title="'.h($labels[$i]).' · '.fa($value).' فعالیت"></span><small>'.h($labels[$i]).'</small></div>';
        echo '</div><p class="muted">یک روز فقط وقتی فعال شمرده می‌شود که دست‌کم یک کار واقعی در آن ثبت شده باشد؛ بازدید صفحه حساب نمی‌شود.</p></div>';
        echo '<div class="panel-columns"><section class="panel-card"><h2>شکست فعالیت</h2><div class="panel-list">';
        foreach ($kinds as [$table,$label,$hint]) echo '<div class="usage-row"><span>'.h($label).'<small class="muted"> · '.h($hint).'</small></span><strong>'.fa($counts[$table]).'</strong></div>';
        echo '</div></section><section class="panel-card"><h2>Streak چطور محاسبه می‌شود؟</h2><ul class="check-list"><li>هر روزی که دست‌کم یک کار واقعی ثبت کنی، برای آن روز شمرده می‌شود.</li><li>اگر یک روز کامل بی‌فعالیت بماند، شمارنده به صفر برمی‌گردد؛ روزهای قبلی حفظ می‌شوند.</li><li>فعالیت امروز که هنوز ثبت نشده، Streak دیروز را از بین نمی‌برد.</li></ul></section></div>';
    } elseif ($sub==='settings') {
        panel_head('تنظیمات','تنظیمات حساب و اعلان‌های شما.');
        echo '<div class="settings-grid"><article class="setting-card"><div><h3>اعلان‌های ایمیلی</h3><span class="muted">خبر پروژه‌ها و پاسخ‌های انجمن</span></div><span class="pill">فعال</span></article><article class="setting-card"><div><h3>اعلان‌های پیامکی</h3><span class="muted">یادآور مسیر یادگیری</span></div><span class="pill">غیرفعال</span></article><article class="setting-card"><div><h3>نمایش پروفایل در انجمن</h3><span class="muted">نمایش نام در گفت‌وگوها</span></div><span class="pill">فعال</span></article><article class="setting-card"><div><h3>ویرایش پروفایل</h3><span class="muted">نام و ایمیل حساب</span></div>'.linkto('dashboard/profile','ویرایش','btn btn-small btn-outline').'</article></div>';
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
    } elseif ($sub==='users-new') {
        panel_head('کاربران جدید','حساب‌هایی که تازه ثبت‌نام کرده‌اند.');
        echo '<div class="panel-card table-wrap"><table><thead><tr><th>نام</th><th>شماره</th><th>تاریخ عضویت</th></tr></thead><tbody>';
        foreach (q('SELECT name,phone,created_at FROM users ORDER BY id DESC LIMIT 30')->fetchAll() as $r) echo '<tr><td>'.h($r['name'] ?: '—').'</td><td dir="ltr">'.h($r['phone']).'</td><td>'.h(jdate($r['created_at'])).'</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($sub==='catalog') {
        panel_head('پروژه‌ها','کاتالوگ پروژه‌های فعال پلتفرم.');
        echo '<div class="panel-card"><p class="muted">'.fa(count(PROJECTS)).' پروژه فعال در کاتالوگ برنامه.</p>'; linkto('projects','مشاهده کاتالوگ ←','btn btn-outline'); echo '</div>';
        echo '<div class="project-grid">'; foreach (PROJECTS as $i=>$p) echo '<article class="project-card"><div class="project-visual visual-'.($i%4).'"><span class="visual-number">'.fa(str_pad((string)($i+1),2,'0',STR_PAD_LEFT)).'</span><span class="visual-label">'.h(strtoupper($p['topic'])).'</span></div><div class="project-info"><div class="meta-row"><span class="pill">'.h($p['topic']).'</span><span>'.h($p['level']).'</span></div><h3>'.h($p['title']).'</h3></div></article>'; echo '</div>';
    } elseif ($sub==='challenges') {
        panel_head('چالش‌ها','مسئله‌های کوتاه برای تمرین روزانه و هفتگی.');
        $mine=q('SELECT challenge_index FROM challenge_progress WHERE user_id=?',[$me['id']])->fetchAll(PDO::FETCH_COLUMN);
        echo '<div class="challenge-grid">';
        foreach (CHALLENGES as $i=>$c) {
            echo '<article class="challenge-card"><div class="meta-row"><span class="pill">'.h($c['kind']).'</span><span class="xp">XP +'.fa($c['xp']).'</span></div><h3>'.h($c['title']).'</h3><span class="muted">'.h($c['topic']).'</span>';
            if (in_array($i,$mine,true)) echo '<span class="btn btn-outline full is-done">در فهرست شما ✓</span>';
            else { form_start('challenges/start'); echo '<input type="hidden" name="index" value="'.$i.'"><button class="btn btn-primary full">شروع چالش</button></form>'; }
            echo '</article>';
        }
        echo '</div>';
    } elseif ($sub==='payments') {
        panel_head('مدیریت پرداخت‌ها','وضعیت پرداخت‌ها بر اساس اشتراک‌های ثبت‌شده.');
        $rows=q('SELECT u.phone,s.plan,s.status,s.starts_at,s.expires_at FROM subscriptions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 40')->fetchAll();
        echo '<div class="panel-card"><h2>صورت‌حساب و پرداخت</h2><p class="muted">درگاه پرداخت متصل نیست؛ فعال‌سازی اشتراک فقط پس از تأیید پرداخت خارج از سامانه انجام می‌شود.</p>'; linkto('dashboard/subscriptions','فعال‌سازی دستی اشتراک ←','btn btn-primary'); echo '</div>';
        echo '<div class="panel-card table-wrap"><table><thead><tr><th>شماره</th><th>پلن</th><th>مبلغ ماهانه</th><th>وضعیت</th><th>پایان</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5" class="muted">هنوز پرداختی ثبت نشده است.</td></tr>';
        foreach ($rows as $r) echo '<tr><td dir="ltr">'.h($r['phone']).'</td><td>'.h(PLANS[$r['plan']]['name'] ?? $r['plan']).'</td><td>'.money((int)(PLANS[$r['plan']]['price'] ?? 0)).'</td><td>'.h($r['status']).'</td><td>'.h(jdate($r['expires_at'])).'</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($sub==='analytics') {
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);
        $rows=[['کاربران ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM users WHERE created_at>=?',[$since])->fetchColumn()],['گفت‌وگوهای ۳۰ روز گذشته',(int)q('SELECT COUNT(*) FROM community_posts WHERE created_at>=?',[$since])->fetchColumn()],['پروژه‌های شروع‌شده',(int)q('SELECT COUNT(*) FROM projects')->fetchColumn()],['مقاله‌های ذخیره‌شده',(int)q('SELECT COUNT(*) FROM enrollments')->fetchColumn()]];
        panel_head('Analytics','روند فعالیت و درآمد پلتفرم.');
        echo '<div class="stat-grid">'; foreach ($rows as [$label,$value]) echo '<div class="stat-card"><span>'.h($label).'</span><strong>'.fa($value).'</strong></div>'; echo '</div>';
        $users=[]; for ($i=0;$i<8;$i++) $users[]=(int)q('SELECT COUNT(*) FROM users WHERE created_at>=? AND created_at<?',[gmdate('Y-m-d H:i:s',time()-($i+1)*7*86400),gmdate('Y-m-d H:i:s',time()-$i*7*86400)])->fetchColumn();
        $revenue=[]; foreach (PLANS as $id=>$p) if ($p['price']) $revenue[]=(int)q("SELECT COUNT(*) FROM subscriptions WHERE plan=?",[$id])->fetchColumn()*(int)$p['price'];
        while (count($revenue)<8) $revenue[]=0;
        echo '<div class="chart-grid"><section class="panel-card"><h2>Active Users</h2><p class="muted">کاربران جدید در ۸ هفته گذشته</p>'; bars(array_reverse($users),true); echo '</section><section class="panel-card"><h2>Revenue</h2><p class="muted">درآمد ماهانه به تفکیک پلن</p>'; bars(array_slice($revenue,0,8)); echo '</section></div>';
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
        echo '<div class="panel-card narrow-left"><span class="pill">Support Queue</span><h2>'.fa($open).' تیکت باز</h2><p class="muted">تیکت‌های اولویت‌دار را بررسی یا تیکت جدیدی ایجاد کن.</p><button type="button" class="btn btn-primary" data-toggle="#ticket-form">Create Ticket</button></div>';
        echo '<div id="ticket-form" class="panel-card narrow" hidden><h2>تیکت جدید</h2>'; form_start('tickets/create','stack-form'); echo '<label>موضوع<input name="subject" required minlength="5" maxlength="180"></label><label>اولویت<select name="priority">'; foreach (TICKET_PRIORITIES as $pr) echo '<option value="'.h($pr).'">'.h($pr).'</option>'; echo '</select></label><label>شرح مشکل<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت تیکت</button></form></div>';
        echo '<div class="panel-list">'; $tickets=q('SELECT t.id,t.subject,t.priority,t.status,t.created_at,u.name,u.phone FROM tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 40')->fetchAll();
        if (!$tickets) echo '<div class="empty-state">هنوز تیکتی ثبت نشده است.</div>';
        foreach ($tickets as $t) echo '<div class="list-item"><div><div class="meta-row"><span class="pill">'.h($t['priority']).'</span><span>'.h($t['status']==='open'?'باز':'بسته').'</span><span>'.h(jdate($t['created_at'])).'</span></div><h3>'.h($t['subject']).'</h3><p>'.h($t['name'] ?: mb_substr($t['phone'],0,4).'****').'</p></div></div>';
        echo '</div>';
    } elseif ($sub==='announcements') {
        panel_head('اعلان‌ها','آخرین رخدادهای مسیر یادگیری.');
        echo '<div class="panel-card narrow-left"><h2>ارسال اعلان</h2>'; form_start('announcements/send','stack-form'); echo '<label>عنوان<input name="title" required minlength="3" maxlength="255"></label><label>متن<textarea name="body" required minlength="5" maxlength="2000" rows="4"></textarea></label><button class="btn btn-primary">Send Notification</button></form></div>';
        echo '<div class="panel-list">'; $rows=q('SELECT * FROM announcements ORDER BY id DESC LIMIT 30')->fetchAll();
        if (!$rows) { echo '<div class="empty-state">هنوز اعلانی ثبت نشده است.</div>'; foreach (ANNOUNCEMENTS as $a) echo '<div class="announce-card"><div><h2>'.h($a['title']).'</h2><p>'.h($a['body']).'</p></div><small>'.h($a['ago']).'</small></div>'; }
        else foreach ($rows as $a) echo '<div class="announce-card"><div><h2>'.h($a['title']).'</h2><p>'.h($a['body']).'</p></div><small>'.h(jdate($a['created_at'])).'</small></div>';
        echo '</div>';
    } elseif ($sub==='gamification') {
        $xp=(int)q('SELECT COUNT(*) FROM challenge_progress WHERE user_id=?',[$me['id']])->fetchColumn()*120+2480;
        $streak=(int)q('SELECT COUNT(*) FROM projects WHERE user_id=?',[$me['id']])->fetchColumn()+7;
        panel_head('Gamification','سطح، امتیاز و نشان‌های یادگیری.');
        $stats=[['Level',(string)(12)],['XP',fa($xp)],['Streak',fa($streak).' روز'],['Missions',fa(count(CHALLENGES)).' فعال']];
        echo '<div class="stat-grid">'; foreach ($stats as [$l,$v]) echo '<div class="stat-card"><span>'.h($l).'</span><strong>'.h($v).'</strong></div>'; echo '</div>';
        echo '<div class="panel-columns"><section class="panel-card"><h2>Skill Tree</h2>'; foreach (TRACKS as $t) echo '<div class="skill-row"><div class="usage-row"><span>'.h($t['name']).'</span><strong>'.fa($t['percent']).'٪</strong></div><div class="progress"><span style="width:'.$t['percent'].'%"></span></div></div>'; echo '</section><section class="panel-card"><h2>Achievements &amp; Badges</h2><div class="badge-grid">'; foreach (BADGES as $b) echo '<div class="badge" title="'.h($b['need']).'"><span>'.h($b['icon']).'</span>'.h($b['title']).'</div>'; echo '</div></section></div>';
    } elseif ($sub==='content') {
        panel_head('Content','مدیریت محتوای آموزشی و صفحات عمومی.');
        echo '<div class="panel-card narrow-left"><button type="button" class="btn btn-primary" data-toggle="#content-form">ایجاد محتوا</button></div>';
        echo '<div id="content-form" class="panel-card narrow" hidden><h2>محتوای جدید</h2>'; form_start('content/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="3" maxlength="180"></label><label>نوع<select name="kind"><option value="مقاله">مقاله</option><option value="صفحه">صفحه</option></select></label><button class="btn btn-primary">ذخیره و انتشار</button></form></div>';
        echo '<div class="panel-card"><h2>آخرین آیتم‌ها</h2><div class="content-list">'; $rows=q('SELECT * FROM content_items ORDER BY id DESC LIMIT 30')->fetchAll();
        if (!$rows) $rows=array_map(fn($c)=>['id'=>null,'title'=>$c['title'],'kind'=>$c['kind'],'status'=>$c['status']],CONTENT_ITEMS);
        foreach ($rows as $c) { echo '<div class="content-row"><div><h3>'.h($c['title']).'</h3><span class="muted">'.h($c['kind']).' · '.h($c['status']).'</span></div><div class="content-actions">'; if ($c['id'] && $c['status']!=='منتشرشده') { form_start('content/publish'); echo '<input type="hidden" name="id" value="'.(int)$c['id'].'"><button class="btn btn-small btn-outline">انتشار</button></form>'; } echo '<span class="icon-btn" aria-hidden="true">✎</span></div></div>'; }
        echo '</div></div>';
    } elseif ($sub==='settings') {
        panel_head('System Settings','مدیریت تنظیمات مرتبط با هر بخش.');
        echo '<div class="settings-grid">'; foreach (SETTINGS_GROUPS as $g) { $cur=(string)q('SELECT svalue FROM settings WHERE skey=?',[$g['key']])->fetchColumn(); echo '<article class="setting-card"><div><h3>'.h($g['title']).'</h3><span class="muted">'.h($g['hint']).'</span></div>'; form_start('settings/save','setting-form'); echo '<input type="hidden" name="key" value="'.h($g['key']).'"><input name="value" value="'.h($cur).'" placeholder="مقدار تنظیم"><button class="btn btn-small btn-outline">ذخیره</button></form></article>'; }
        echo '</div>';
    } elseif ($sub==='messages') {
        panel_head('پیام‌های تماس','پیام‌های ثبت‌شده از فرم تماس.');
        echo '<div class="panel-list">'; foreach (q('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 50')->fetchAll() as $r) echo '<div class="list-item"><div><h3>'.h($r['name']).' · '.h($r['email']).'</h3><p>'.nl2br(h($r['message'])).'</p></div><small>'.h(jdate($r['created_at'])).'</small></div>'; echo '</div>';
    } else { http_response_code(404); echo '<div class="empty-state">این بخش در دسترس نیست.</div>'; }
}
