<?php
declare(strict_types=1);
require_once __DIR__.'/app.php';

function route(): void {
    global $path,$me,$plan;
    $public=[''=>'home','courses'=>'courses','course'=>'course','projects'=>'projects','project'=>'project','pricing'=>'pricing','about'=>'about','contact'=>'contact','community'=>'community','login'=>'login','verify'=>'verify'];
    if ($path==='' ) { head_page('خانه','Noventix؛ آموزش پروژه‌محور پایتون، علم داده، یادگیری ماشین و یادگیری عمیق به زبان فارسی.'); notice(); home(); foot_page(); return; }
    if ($path==='courses') { head_page('مسیر یادگیری','مقاله‌های کاربردی پایتون، علم داده، یادگیری ماشین، یادگیری عمیق و ارائه مدل.'); notice(); page_courses(); foot_page(); return; }
    if (str_starts_with($path,'course/')) { page_course(trim(substr($path,7))); return; }
    if ($path==='projects') { head_page('پروژه‌ها','پروژه‌های واقعی پایتون، علم داده و هوش مصنوعی برای تبدیل دانش به مهارت.'); notice(); page_projects(); foot_page(); return; }
    if (str_starts_with($path,'project/')) { page_project(substr($path,8)); return; }
    if ($path==='pricing') { head_page('قیمت‌گذاری','مقایسه پلن‌های رایگان، برنزی، نقره‌ای، طلایی و تیتانیوم Noventix.'); notice(); page_pricing(); foot_page(); return; }
    if ($path==='about') { head_page('درباره ما','داستان، رویکرد آموزشی و اصول تجربه یادگیری در Noventix.'); notice(); page_about(); foot_page(); return; }
    if ($path==='contact') { head_page('تماس با ما','راه‌های ارتباط با تیم Noventix و شبکه‌های اجتماعی.'); notice(); page_contact(); foot_page(); return; }
    if ($path==='community') { page_community(); return; }
    if ($path==='login') { page_login(); return; }
    if ($path==='verify') { page_verify(); return; }
    if ($path==='dashboard' || str_starts_with($path,'dashboard/')) {
        $u=require_user();
        $allowed=['profile','learning','projects','community','subscription','users','users-new','courses','catalog','subscriptions','messages','analytics','challenges','payments','support','announcements','gamification','content','settings'];
        if (str_starts_with($path,'dashboard/') && !in_array(substr($path,10),$allowed,true)) { http_response_code(404); head_page('یافت نشد',''); notice(); echo '<main class="container section"><h1>این صفحه پیدا نشد.</h1>'; linkto('dashboard','بازگشت به پنل ←','inline-link'); echo '</main>'; foot_page(); return; }
        head_page('پنل', '', true); notice(); panel(str_starts_with($path,'dashboard/') ? substr($path,10) : ''); foot_page(true); return;
    }
    http_response_code(404); head_page('یافت نشد','صفحه مورد نظر پیدا نشد.'); notice(); echo '<main class="container section"><span class="eyebrow">404</span><h1>این صفحه پیدا نشد.</h1><p>ممکن است نشانی تغییر کرده باشد.</p>'; linkto('','بازگشت به خانه ←','btn btn-primary'); echo '</main>'; foot_page();
}

function page_courses(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">مسیر یادگیری</span><h1>قدم‌به‌قدم تا توانایی ساختن</h1><p>هر مقاله یک مفهوم کاربردی را با تمرین واقعی ترکیب می‌کند؛ از پایتون تا یادگیری عمیق.</p></section><section class="section container">'; course_cards(); echo '</section></main>';
}
function page_course(string $slug): void {
    global $me,$plan;
    if (!isset(COURSES[$slug])) { http_response_code(404); head_page('یافت نشد',''); notice(); echo '<main class="container section"><h1>مقاله پیدا نشد.</h1>'; linkto('courses','همه مقاله‌ها ←','inline-link'); echo '</main>'; foot_page(); return; }
    $c=COURSES[$slug]; head_page($c['title'],$c['intro']); notice(); echo '<main class="article"><div class="container narrow-container"><span class="eyebrow">'.h($c['topic']).' · '.h($c['level']).'</span><h1>'.h($c['title']).'</h1><p class="lead">'.h($c['intro']).'</p><div class="article-meta"><span>زمان مطالعه: '.fa($c['minutes']).' دقیقه</span><span>به‌روزرسانی: '.fa(date('Y')).'</span></div>';
    foreach ($c['sections'] as $i=>$s) echo '<section class="article-section"><h2><span>'.fa($i+1).'</span>'.h($s[0]).'</h2><p>'.h($s[1]).'</p></section>';
    echo '<div class="article-footer">';
    if ($me) { if (can_course($slug,$plan)) { form_start('courses/enroll'); echo '<input type="hidden" name="slug" value="'.h($slug).'"><button class="btn btn-primary">افزودن به مسیر یادگیری</button></form>'; } else { echo '<p class="muted">برای ذخیره این مقاله به پلن بالاتر نیاز دارید.</p>'; linkto('pricing','مشاهده پلن‌ها ←','btn btn-outline'); } }
    else { linkto('login','برای ادامه وارد شوید ←','btn btn-primary'); }
    linkto('projects','پروژه مرتبط را ببین ←','btn btn-outline'); echo '</div></div></main>'; foot_page();
}
function page_projects(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">پروژه‌ها</span><h1>یادگیری با ساختن، نه فقط خواندن</h1><p>هر پروژه یک مسئله واقعی با ورودی، خروجی و معیار موفقیت روشن است.</p></section><section class="section container">'; project_cards(); echo '</section></main>';
}
function page_project(string $index): void {
    global $me,$plan;
    $i=filter_var($index,FILTER_VALIDATE_INT);
    if ($i===false || !isset(PROJECTS[$i])) { http_response_code(404); head_page('یافت نشد',''); notice(); echo '<main class="container section"><h1>پروژه پیدا نشد.</h1>'; linkto('projects','همه پروژه‌ها ←','inline-link'); echo '</main>'; foot_page(); return; }
    $p=PROJECTS[$i]; head_page($p['title'],$p['description']); notice(); echo '<main class="article"><div class="container narrow-container"><span class="eyebrow">'.h($p['topic']).' · '.h($p['level']).'</span><h1>'.h($p['title']).'</h1><p class="lead">'.h($p['description']).'</p><div class="article-meta"><span dir="ltr">'.h($p['skills']).'</span></div><section class="article-section"><h2><span>۱</span>صورت مسئله</h2><p>مسئله را با داده و محدودیت‌های واقعی تعریف کن؛ خروجی مورد انتظار و معیار موفقیت را از ابتدا روشن کن.</p></section><section class="article-section"><h2><span>۲</span>مسیر پیشنهادی</h2><p>با یک راه‌حل ساده شروع کن، نتیجه را بسنج و سپس گام‌به‌گام پیچیدگی را فقط در جایی که لازم است اضافه کن.</p></section><section class="article-section"><h2><span>۳</span>ارزیابی و انتقال</h2><p>کیفیت راه‌حل را با معیار روشن بسنج و همان الگو را روی یک مسئله تازه امتحان کن تا مهارت واقعی شکل بگیرد.</p></section><div class="article-footer">';
    if ($me) { form_start('projects/start'); echo '<input type="hidden" name="index" value="'.$i.'"><button class="btn btn-primary">افزودن به پروژه‌های من</button></form>'; } else linkto('login','برای شروع وارد شوید ←','btn btn-primary');
    linkto('community','پرسش در انجمن ←','btn btn-outline'); echo '</div></div></main>'; foot_page();
}
function page_pricing(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">قیمت‌گذاری</span><h1>پلنی که با مسیرت هماهنگ است</h1><p>از رایگان شروع کن و هر زمان که خواستی، امکانات بیشتر را فعال کن.</p></section><section class="section container">'; plan_cards(); echo '</section><section class="section container"><div class="section-header"><span class="eyebrow">مقایسه کامل</span><h2>دقیقاً چه چیزی به دست می‌آوری؟</h2><p>همه قیمت‌ها ماهانه و به تومان است.</p></div><div class="table-wrap compare-wrap"><table class="compare-table"><caption class="sr-only">مقایسه امکانات پلن‌های Noventix</caption><thead><tr><th scope="col">ویژگی</th>'; foreach (PLANS as $p) echo '<th scope="col">'.h($p['name']).'</th>'; echo '</tr></thead><tbody>'; foreach (COMPARISON as $feature=>$values) { echo '<tr><th scope="row">'.h($feature).'</th>'; foreach ($values as $v) echo '<td>'.h($v).'</td>'; echo '</tr>'; } echo '</tbody></table></div></section><section class="section container"><div class="cta-panel"><span class="eyebrow">پرداخت</span><h2>فعال‌سازی اشتراک با پشتیبانی</h2><p>دروازه پرداخت هنوز متصل نشده است؛ برای فعال‌سازی مجوز خود را برای تیم ما بفرستید تا پس از تأیید، اشتراک شما فعال شود.</p>'; linkto('contact','تماس با پشتیبانی ←','btn btn-light'); echo '</div></section></main>';
}
function page_about(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">درباره ما</span><h1>ما Noventix را برای ساختن ساختیم</h1><p>اینجا فقط دوره نمی‌بینی؛ با یک مسیر روشن، مهارتت را در پروژه‌های واقعی می‌سازی.</p></section><section class="section container"><div class="split"><div class="article-section"><h2><span>۱</span>مسئله‌ای که دیدیم</h2><p>فاصله میان دانستن و ساختن، بزرگ‌ترین مشکل یادگیری برنامه‌نویسی است. بسیاری پایتون را می‌دانند، اما در برابر یک مسئله تازه نمی‌دانند از کجا شروع کنند.</p></div><div class="article-section"><h2><span>۲</span>رویکرد ما</h2><p>یادگیری باید با عمل گره بخورد. به همین دلیل هر مفهوم با یک پروژه واقعی همراه است و ارزیابی بر پایه انتقال مهارت انجام می‌شود.</p></div><div class="article-section"><h2><span>۳</span>تعهد ما</h2><p>شفافیت در آنچه ارائه می‌دهیم، پرهیز از وعده‌های بزرگ و احترام به زمان یادگیرنده؛ سه اصل ساده‌ای که به آن پایبندیم.</p></div></div></section><section class="section container"><div class="section-header"><span class="eyebrow">تجربه یادگیری</span><h2>سه اصل راهنمای ما</h2></div><div class="features-grid"><article class="feature"><div class="feature-icon">◇</div><h3>شفافیت</h3><p>هر مقاله هدف، پیش‌نیاز و نتیجه‌اش را از ابتدا روشن می‌کند.</p></article><article class="feature"><div class="feature-icon">⌘</div><h3>کاربردپذیری</h3><p>محتوایی می‌نویسیم که در پروژه واقعی به کار بیاید، نه فقط در آزمون.</p></article><article class="feature"><div class="feature-icon">✳</div><h3>احترام به یادگیرنده</h3><p>بدون وعده‌های غیرواقعی؛ مسیر و انتظارات روشن است.</p></article></div></section></main>';
}
function page_contact(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">تماس با ما</span><h1>با Noventix در ارتباط باشید</h1><p>برای دریافت آموزش‌ها، اخبار و پروژه‌ها همراه ما باشید و پرسش‌های خود را در انجمن مطرح کنید.</p></section><section class="section container"><div class="contact-layout"><div class="panel-card"><h2>ارسال پیام</h2><p class="muted">پیام شما در سیستم ثبت می‌شود و در اولین فرصت بررسی خواهد شد.</p>'; form_start('contact/send','stack-form'); echo '<label>نام و نام خانوادگی<input name="name" required minlength="2" maxlength="100"></label><label>ایمیل<input type="email" dir="ltr" name="email" required maxlength="255" placeholder="you@example.com"></label><label>پیام<textarea name="message" required minlength="10" maxlength="3000" rows="6"></textarea></label><button class="btn btn-primary">ارسال پیام</button></form></div><div class="social-column"><a href="https://instagram.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Instagram</strong><span>noventix.ir</span></a><a href="https://t.me/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Telegram</strong><span>noventix_ir@</span></a><a href="https://rubika.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Rubika</strong><span>noventix_ir@</span></a><a href="https://ble.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Bale</strong><span>noventix_ir@</span></a><a href="https://www.aparat.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Aparat</strong><span>noventix.ir</span></a></div></div></section></main>';
}
function page_community(): void {
    global $me;
    $posts=q('SELECT p.id,p.title,p.body,p.created_at,u.name,u.phone FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 30')->fetchAll();
    head_page('انجمن','پرسش، تجربه و گفت‌وگو میان یادگیرندگان و متخصصان Noventix.'); notice(); echo '<main><section class="page-hero container"><span class="eyebrow">انجمن</span><h1>کنار هم یاد می‌گیریم</h1><p>سؤال‌هایت را بپرس، تجربه‌ات را به اشتراک بگذار و مسیر یادگیری دیگران را بهتر کن.</p></section><section class="section container">';
    if ($me) { echo '<div class="panel-card narrow-left">'; form_start('community/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label><label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت در انجمن</button></form></div>'; } else { echo '<div class="panel-card">'; linkto('login','برای مشارکت در انجمن وارد شوید ←','btn btn-primary'); echo '</div>'; }
    echo '<div class="community-list">'; if (!$posts) echo '<div class="empty-state">هنوز گفت‌وگویی ثبت نشده است. اولین نفر باشید.</div>';
    foreach ($posts as $p) { echo '<article class="post-card"><div class="meta-row"><span class="pill">'.h($p['created_at']).'</span><span>'.h($p['name'] ?: mb_substr($p['phone'],0,4).'****').'</span></div><h2>'.h($p['title']).'</h2><p>'.nl2br(h($p['body'])).'</p>'; $replies=q('SELECT r.body,r.created_at,u.name,u.phone FROM community_replies r JOIN users u ON u.id=r.user_id WHERE r.post_id=? ORDER BY r.id',[$p['id']])->fetchAll(); if ($replies) { echo '<div class="replies">'; foreach ($replies as $r) echo '<div class="reply"><strong>'.h($r['name'] ?: mb_substr($r['phone'],0,4).'****').'</strong><p>'.nl2br(h($r['body'])).'</p></div>'; echo '</div>'; } if ($me) { form_start('community/reply','reply-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><textarea name="body" required minlength="10" maxlength="5000" rows="3" placeholder="پاسخ شما"></textarea><button class="btn btn-outline">ارسال پاسخ</button></form>'; } echo '</article>'; } echo '</div></section></main>'; foot_page();
}
function page_login(): void {
    if (user()) redirect('dashboard');
    head_page('ورود','ورود یا ثبت‌نام با شماره موبایل در Noventix.'); notice(); echo '<main class="auth-page"><div class="auth-card"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><h1>ورود یا ثبت‌نام</h1><p class="muted">شماره موبایل خود را وارد کنید تا کد تأیید برایتان ارسال شود.</p>'; form_start('auth/request','stack-form'); echo '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" placeholder="09123456789" autocomplete="tel"></label><button class="btn btn-primary">ارسال کد تأیید</button></form><p class="fine-print">کد تأیید فقط از طریق سرویس پیامک تنظیم‌شده ارسال می‌شود؛ تا زمان تنظیم سرویس، ورود فعال نیست.</p></div></main>'; foot_page();
}
function page_verify(): void {
    $phone=$_SESSION['verify_phone'] ?? ''; if (!$phone) redirect('login');
    head_page('تأیید شماره','تأیید کد پیامک‌شده.'); notice(); echo '<main class="auth-page"><div class="auth-card"><h1>کد تأیید را وارد کنید</h1><p class="muted" dir="ltr">'.h($phone).'</p>'; form_start('auth/verify','stack-form'); echo '<label>کد ۶ رقمی<input inputmode="numeric" dir="ltr" name="code" required pattern="[0-9۰-۹]{6}" autocomplete="one-time-code"></label><button class="btn btn-primary">تأیید و ورود</button></form>'; linkto('login','ویرایش شماره ←','inline-link'); echo '</div></main>'; foot_page();
}
