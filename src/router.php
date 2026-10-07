<?php
declare(strict_types=1);
require_once __DIR__.'/app.php';

function route(): void {
    global $path,$me,$plan;
    $public=[''=>'home','courses'=>'courses','course'=>'course','projects'=>'projects','project'=>'project','pricing'=>'pricing','about'=>'about','contact'=>'contact','community'=>'community','login'=>'login','verify'=>'verify'];
    if ($path==='' ) { head_page('خانه','Noventix؛ آموزش پروژه‌محور پایتون، علم داده، یادگیری ماشین و یادگیری عمیق به زبان فارسی.'); notice(); home(published_courses()); foot_page(); return; }
    if ($path==='courses') { head_page('مسیر یادگیری','مقاله‌های کاربردی پایتون، علم داده، یادگیری ماشین، یادگیری عمیق و ارائه مدل.'); notice(); page_courses(published_courses()); foot_page(); return; }
    if (str_starts_with($path,'course/')) { page_course(trim(substr($path,7))); return; }
    if ($path==='projects') { head_page('پروژه‌ها','پروژه‌های واقعی پایتون، علم داده و هوش مصنوعی برای تبدیل دانش به مهارت.'); notice(); page_projects(); foot_page(); return; }
    if (str_starts_with($path,'project/')) { page_project(substr($path,8)); return; }
    if ($path==='pricing') { head_page('قیمت‌گذاری','مقایسه پلن‌های رایگان، برنزی، نقره‌ای، طلایی و تیتانیوم Noventix.'); notice(); page_pricing(); foot_page(); return; }
    if ($path==='about') { head_page('درباره ما','داستان، رویکرد آموزشی و اصول تجربه یادگیری در Noventix.'); notice(); page_about(); foot_page(); return; }
    if ($path==='contact') { head_page('تماس با ما','راه‌های ارتباط با تیم Noventix و شبکه‌های اجتماعی.'); notice(); page_contact(); foot_page(); return; }
    if ($path==='community') { page_community(); return; }
    if ($path==='terms') { head_page('قوانین و مقررات','شرایط استفاده از سایت، پنل، میزکار کد و انجمن Noventix به زبان ساده.'); notice(); page_terms(); foot_page(); return; }
    if ($path==='privacy') { head_page('حریم خصوصی','نحوه نگهداری و استفاده از اطلاعات شما در Noventix.'); notice(); page_privacy(); foot_page(); return; }
    if (preg_match('~^member/([1-9][0-9]*)$~D',$path,$match)) { page_member((int)$match[1]); return; }
    if ($path==='login') { page_login(); return; }
    if ($path==='verify') { page_verify(); return; }
    if ($path==='admin-login') { page_admin_login(); return; }
    if (preg_match('~^dashboard/course/([a-z0-9-]+)$~D',$path,$match)) {
        $u=require_user();
        if ($u['phone']===(config_value('ADMIN_PHONE') ?: '') && $u['role']!=='admin') redirect('admin-login');
        $course=course_workspace($match[1]);
        head_page('فضای یادگیری','',true); notice(); page_course_workspace($course,(string)($_GET['lesson'] ?? '')); foot_page(true);
        return;
    }
    if ($path==='dashboard' || str_starts_with($path,'dashboard/')) {
        $u=require_user();
        if ($u['phone']===(config_value('ADMIN_PHONE') ?: '') && $u['role']!=='admin') redirect('admin-login');
        $allowed=['profile','learning','projects','community','nova','subscription','users','users-new','courses','catalog','subscriptions','messages','analytics','challenges','payments','support','announcements','gamification','activity','content','settings'];
        if (str_starts_with($path,'dashboard/') && !in_array(substr($path,10),$allowed,true)) { http_response_code(404); head_page('یافت نشد',''); notice(); echo '<main class="container section"><h1>این صفحه پیدا نشد.</h1>'; linkto('dashboard','بازگشت به پنل ←','inline-link'); echo '</main>'; foot_page(); return; }
        head_page('پنل', '', true); notice(); panel(str_starts_with($path,'dashboard/') ? substr($path,10) : ''); foot_page(true); return;
    }
    http_response_code(404); head_page('یافت نشد','صفحه مورد نظر پیدا نشد.'); notice(); echo '<main class="container section"><span class="eyebrow">404</span><h1>این صفحه پیدا نشد.</h1><p>ممکن است نشانی تغییر کرده باشد.</p>'; linkto('','بازگشت به خانه ←','btn btn-primary'); echo '</main>'; foot_page();
}

function page_courses(array $courses): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">مسیر یادگیری</span><h1>قدم‌به‌قدم تا توانایی ساختن</h1><p>هر مقاله یک مفهوم کاربردی را با تمرین واقعی ترکیب می‌کند؛ از پایتون تا یادگیری عمیق.</p></section><section class="section container">'; course_cards($courses); echo '</section></main>';
}
function page_course(string $slug): void {
    $course=q("SELECT id,slug,title,description,estimated_duration_minutes FROM courses WHERE slug=? AND status='published'",[$slug])->fetch();
    if (!$course) { http_response_code(404); head_page('یافت نشد',''); notice(); echo '<main class="container section"><h1>Course پیدا نشد.</h1>'; linkto('courses','همه Courseها ←','inline-link'); echo '</main>'; foot_page(); return; }
    $lessons=q("SELECT title,content,position,duration_minutes,status FROM lessons WHERE course_id=? AND status='published' ORDER BY position ASC",[$course['id']])->fetchAll();
    $duration=$course['estimated_duration_minutes'];
    if ($duration===null) {
        $lessonDurations=array_column($lessons,'duration_minutes');
        $duration=$lessonDurations && !in_array(null,$lessonDurations,true) ? array_sum($lessonDurations) : null;
    }
    $durationLabel=$duration===null ? 'مدت ثبت نشده' : fa((int)$duration).' دقیقه';
    head_page($course['title'],$course['description']); notice(); echo '<main class="course-overview"><section class="course-overview-hero"><div class="container narrow-container"><span class="eyebrow">مسیر یادگیری · Course</span><h1>'.h($course['title']).'</h1><p class="lead">'.h($course['description']).'</p><div class="article-meta"><span>'.$durationLabel.'</span><span>'.fa(count($lessons)).' Lesson</span><span>رایگان</span><span>یادگیری پروژه‌محور</span></div><div class="course-hero-actions">'; linkto('login','شروع یادگیری ←','btn btn-primary'); echo '</div></div></section><section class="section container narrow-container"><div class="course-overview-grid"><div><span class="eyebrow">برای چه کسی؟</span><h2>شروعی روشن برای ساختن با AI</h2><p class="muted">برای مبتدی‌هایی که می‌خواهند مفاهیم هوش مصنوعی را با یک مسیر عملی و قابل فهم یاد بگیرند.</p></div><div class="course-audience-list"><span>مبتدی و علاقه‌مند به AI</span><span>یادگیری همراه با پروژه</span><span>حرکت از مفهوم به اجرا</span></div></div></section><section class="section alt-section"><div class="container"><div class="section-header"><span class="eyebrow">خروجی دوره</span><h2>چه چیزهایی یاد می‌گیری؟</h2></div><div class="features-grid course-benefits"><article class="feature"><h3>مفاهیم پایه AI</h3><p>درک اصولی مفاهیم مهم بدون پیچیده‌گویی.</p></article><article class="feature"><h3>روند ساخت پروژه</h3><p>آشنایی با مسیر تبدیل ایده به یک نمونه عملی.</p></article><article class="feature"><h3>تمرین واقعی</h3><p>یادگیری با مثال‌هایی که به ساختن کمک می‌کنند.</p></article><article class="feature"><h3>حرکت به اجرا</h3><p>اتصال دانسته‌ها به یک تجربه قابل ارائه.</p></article></div></div></section><section class="section container narrow-container"><div class="section-header"><span class="eyebrow">Curriculum</span><h2>مسیر جلسات</h2><p>جلسه‌ها از محتوای منتشرشدهٔ همین Course ساخته می‌شوند.</p></div>';
    if (!$lessons) echo '<div class="empty-state">این Course هنوز Lesson منتشرشده‌ای ندارد.</div>';
    else { echo '<div class="lesson-list">'; foreach ($lessons as $lesson) { echo '<article class="lesson-card"><div class="lesson-number">'.fa((int)$lesson['position']).'</div><div><div class="meta-row"><span class="pill">منتشرشده</span><span>'.($lesson['duration_minutes']===null?'مدت ثبت نشده':fa((int)$lesson['duration_minutes']).' دقیقه').'</span></div><h3>'.h($lesson['title']).'</h3><p>'.nl2br(h($lesson['content'])).'</p></div></article>'; } echo '</div>'; }
    echo '<div class="cta-panel course-final-cta"><span class="eyebrow">قدم بعدی</span><h2>آماده‌ای شروع کنی؟</h2><p>از اولین Lesson شروع کن و قدم‌به‌قدم جلو برو.</p>'; linkto('login','شروع یادگیری ←','btn btn-light'); echo '</div></section></main>'; foot_page();
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
    echo '<main><section class="page-hero container"><span class="eyebrow">قیمت‌گذاری</span><h1>پلنی که با مسیرت هماهنگ است</h1><p>از رایگان شروع کن و هر زمان که خواستی، امکانات بیشتر را فعال کن.</p></section><section class="section container">'; plan_cards(); echo '</section><section class="section container"><div class="section-header"><span class="eyebrow">مقایسه کامل</span><h2>دقیقاً چه چیزی به دست می‌آوری؟</h2><p>همه قیمت‌ها ماهانه و به تومان است.</p></div><div class="table-wrap compare-wrap"><table class="compare-table"><caption class="sr-only">مقایسه امکانات پلن‌های Noventix</caption><thead><tr><th scope="col">ویژگی</th>'; foreach (PLANS as $p) echo '<th scope="col">'.h($p['name']).'</th>'; echo '</tr></thead><tbody>'; foreach (COMPARISON as $feature=>$values) { echo '<tr><th scope="row">'.h($feature).'</th>'; foreach ($values as $v) echo '<td>'.h($v).'</td>'; echo '</tr>'; } echo '</tbody></table></div>';
    echo '<div class="table-wrap compare-wrap"><table class="compare-table"><caption class="sr-only">سقف دقیق امکانات هر پلن</caption><thead><tr><th scope="col">سقف دقیق</th>'; foreach (PLANS as $p) echo '<th scope="col">'.h($p['name']).'</th>'; echo '</tr></thead><tbody>';
    foreach (PLANS['free']['limits'] as $index=>$row) { echo '<tr><th scope="row">'.h($row[0]).'</th>'; foreach (PLANS as $p) echo '<td>'.h($p['limits'][$index][1]).'</td>'; echo '</tr>'; }
    echo '</tbody></table></div><p class="fine-print">سقف‌ها ماهانه شمرده می‌شوند و در ابتدای هر دوره بازنشانی می‌شوند. اگر به سقف برسی، دسترسی‌ات قطع نمی‌شود؛ فقط تا دورهٔ بعد امکان استفادهٔ بیشتر از آن مورد را نداری.</p></section><section class="section container"><div class="cta-panel"><span class="eyebrow">پرداخت</span><h2>فعال‌سازی اشتراک با پشتیبانی</h2><p>دروازه پرداخت هنوز متصل نشده است؛ برای فعال‌سازی مجوز خود را برای تیم ما بفرستید تا پس از تأیید، اشتراک شما فعال شود.</p>'; linkto('contact','تماس با پشتیبانی ←','btn btn-light'); echo '</div></section></main>';
}
function page_about(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">درباره ما</span><h1>ما Noventix را برای ساختن ساختیم</h1><p>اینجا فقط دوره نمی‌بینی؛ با یک مسیر روشن، مهارتت را در پروژه‌های واقعی می‌سازی.</p></section><section class="section container"><div class="split"><div class="article-section"><h2><span>۱</span>مسئله‌ای که دیدیم</h2><p>فاصله میان دانستن و ساختن، بزرگ‌ترین مشکل یادگیری برنامه‌نویسی است. بسیاری پایتون را می‌دانند، اما در برابر یک مسئله تازه نمی‌دانند از کجا شروع کنند.</p></div><div class="article-section"><h2><span>۲</span>رویکرد ما</h2><p>یادگیری باید با عمل گره بخورد. به همین دلیل هر مفهوم با یک پروژه واقعی همراه است و ارزیابی بر پایه انتقال مهارت انجام می‌شود.</p></div><div class="article-section"><h2><span>۳</span>تعهد ما</h2><p>شفافیت در آنچه ارائه می‌دهیم، پرهیز از وعده‌های بزرگ و احترام به زمان یادگیرنده؛ سه اصل ساده‌ای که به آن پایبندیم.</p></div></div></section><section class="section container"><div class="section-header"><span class="eyebrow">تجربه یادگیری</span><h2>سه اصل راهنمای ما</h2></div><div class="features-grid"><article class="feature"><div class="feature-icon">◇</div><h3>شفافیت</h3><p>هر مقاله هدف، پیش‌نیاز و نتیجه‌اش را از ابتدا روشن می‌کند.</p></article><article class="feature"><div class="feature-icon">⌘</div><h3>کاربردپذیری</h3><p>محتوایی می‌نویسیم که در پروژه واقعی به کار بیاید، نه فقط در آزمون.</p></article><article class="feature"><div class="feature-icon">✳</div><h3>احترام به یادگیرنده</h3><p>بدون وعده‌های غیرواقعی؛ مسیر و انتظارات روشن است.</p></article></div></section></main>';
}
function page_contact(): void {
    echo '<main><section class="contact-hero"><div class="container"><span class="eyebrow">راه‌های ارتباطی Noventix</span><h1>از اینجا با ما در ارتباط باشید</h1><p>برای پیگیری سؤال‌ها و پیشنهادها، راه ارتباطی مناسب خود را انتخاب کنید.</p><div class="contact-quick-links">'; linkto('support','مرکز پشتیبانی ←','btn btn-primary'); linkto('community','پرسش در انجمن ←','btn btn-outline'); echo '</div></div></section><section class="section container"><div class="contact-layout contact-page-layout"><div class="panel-card contact-form-card"><span class="eyebrow">پیام مستقیم</span><h2>برای ما پیام بفرستید</h2><p class="muted">درخواست خود را با جزئیات بنویسید تا تیم ما بتواند آن را بررسی کند.</p>'; form_start('contact/send','stack-form'); echo '<label>نام و نام خانوادگی<input name="name" autocomplete="name" required minlength="2" maxlength="100"></label><label>ایمیل برای پاسخ<input type="email" dir="ltr" name="email" autocomplete="email" required maxlength="255" placeholder="you@example.com"></label><label>متن پیام<textarea name="message" required minlength="10" maxlength="3000" rows="6" placeholder="موضوع درخواست و جزئیات آن را بنویسید"></textarea></label><button class="btn btn-primary">ثبت پیام</button></form></div><div class="contact-side"><div class="contact-info-card"><span class="eyebrow">راهنمای ارتباط</span><h2>کدام مسیر مناسب شماست؟</h2><p>برای مشکلات حساب یا خدمات، از مرکز پشتیبانی استفاده کنید؛ برای گفت‌وگوی آموزشی و تجربه‌ها به انجمن سر بزنید.</p>'; linkto('support','رفتن به پشتیبانی ←','inline-link'); echo '</div><div class="contact-social"><h2>ما را دنبال کنید</h2><p>آموزش‌ها و تازه‌های Noventix را در شبکه‌های اجتماعی دنبال کنید.</p><div class="social-column"><a href="https://instagram.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Instagram</strong><span dir="ltr">noventix.ir ↗</span></a><a href="https://t.me/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Telegram</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://rubika.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Rubika</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://ble.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Bale</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://www.aparat.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Aparat</strong><span dir="ltr">noventix.ir ↗</span></a></div></div></div></div></section></main>';
}
/* Kept in step with the static build's view_terms(); the shared shape is what
   keeps the two deliverables from telling users different things. */
const TERMS_SECTIONS = [
    ['scope','این قوانین چه چیزی را پوشش می‌دهد؟','این صفحه شرایط استفاده از سایت، پنل کاربری، میزکار کد و انجمن Noventix را توضیح می‌دهد. با ساختن حساب یا استفاده از امکانات سایت، این شرایط را می‌پذیرید. اگر با بخشی از آن موافق نیستید، می‌توانید از امکاناتی که نیاز به حساب ندارند استفاده کنید یا پیش از ادامه با پشتیبانی گفت‌وگو کنید.',['سایت، پنل، میزکار و انجمن را دربر می‌گیرد.','ساخت حساب یعنی پذیرش این شرایط.','بخش‌های عمومی سایت بدون حساب هم قابل مطالعه‌اند.']],
    ['account','حساب کاربری و امنیت','برای استفاده از بخش‌های کاربری، اطلاعات صحیح و متعلق به خودتان را وارد کنید. شماره موبایل شناسه ورود شماست و رمز عبور را باید محرمانه نگه دارید. اگر نشانه‌ای از دسترسی مشکوک دیدید، از طریق پشتیبانی اطلاع دهید تا دسترسی‌ها بررسی شود. تا زمانی که موضوع بررسی نشده، فعالیت‌های انجام‌شده با حساب شما به همان حساب نسبت داده می‌شود.',['اطلاعات باید واقعی و متعلق به خودتان باشد.','رمز عبور را در اختیار دیگران قرار ندهید.','دسترسی مشکوک را سریع به پشتیبانی گزارش دهید.','یک حساب برای یک نفر؛ ساخت حساب جعلی مجاز نیست.']],
    ['verification','ورود با کد پیامکی و تأیید هویت','ورود به حساب با شماره موبایل و کد یک‌بارمصرف انجام می‌شود. کد تأیید فقط برای شماست و نباید آن را برای کسی بفرستید؛ حتی اگر فرستنده خود را از تیم Noventix معرفی کند. تعداد درخواست‌های کد در هر نشست محدود است تا از سوءاستفاده جلوگیری شود. اگر کد را چند بار اشتباه وارد کنید، برای مدتی باید صبر کنید.',['کد تأیید را با هیچ‌کس به اشتراک نگذارید.','درخواست کد محدود است تا از سوءاستفاده جلوگیری شود.','تلاش‌های ناموفق موقتاً محدود می‌شوند.']],
    ['learning','محتوای آموزشی و حقوق نشر','دوره‌ها، تمرین‌ها و مطالب سایت برای یادگیری شخصی ارائه می‌شوند. بازنشر، فروش یا ارائه آن‌ها به نام خود بدون اجازه صاحب اثر مجاز نیست. استفاده از منابع بیرونی هم تابع شرایط و مجوز همان منابع است. اگر برای تدریس یا کار تیمی به استفاده گسترده‌تر نیاز دارید، پیش از انتشار با ما هماهنگ کنید.',['مطالب برای یادگیری شخصی شماست.','بازنشر یا فروش بدون اجازه مجاز نیست.','به شرایط استفاده منابع بیرونی هم پایبند باشید.']],
    ['workspace','میزکار کد و فایل‌ها','کد شما با مفسر واقعی Python در مرورگر خودتان اجرا می‌شود و فایل‌های پروژه تا زمانی که مرورگر داده‌ها را نگه دارد در همان دستگاه می‌مانند. پیش از بارگذاری فایل یا اجرای کد، اطلاعات محرمانه مانند رمز، کلید دسترسی و داده شخصی دیگران را حذف کنید. میزکار جایگزین فضای نگهداری دائمی نیست؛ از کارهای مهم خود نسخه پشتیبان بگیرید. اجرای کد می‌تواند به اتصال اینترنت و بسته‌های سازگار نیاز داشته باشد و برای جلوگیری از حلقه‌های بی‌پایان، زمان اجرا محدود است.',['کد در مرورگر خودتان اجرا می‌شود، نه روی سرور ما.','فایل‌های محرمانه را در میزکار بارگذاری نکنید.','میزکار فضای نگهداری دائمی نیست؛ پشتیبان بگیرید.','زمان اجرا محدود است تا حلقه‌های بی‌پایان متوقف شوند.']],
    ['ai','استفاده از Nova و ابزارهای کمکی','Nova برای کمک به یادگیری طراحی شده است؛ پاسخ‌های آن می‌تواند ناقص یا نادرست باشد و جای بررسی خودتان را نمی‌گیرد. خروجی Nova را پیش از استفاده در پروژه واقعی، آزمون یا کار درسی خودتان بسنجید. تعداد درخواست‌های هر پلن مشخص است و از سهمیه ماهانه شما کم می‌شود. اطلاعات محرمانه یا داده شخصی دیگران را در پرسش‌ها وارد نکنید.',['پاسخ Nova ممکن است نادرست باشد؛ خودتان بررسی کنید.','سهمیه هر پلن مشخص و محدود است.','داده محرمانه در پرسش‌ها وارد نکنید.']],
    ['community','انجمن و تعامل با دیگران','در پست‌ها، نظرات و گفت‌وگوها محترمانه و مرتبط با موضوع مشارکت کنید. انتشار محتوای آزاردهنده، فریبنده، ناقض حقوق دیگران یا اطلاعات خصوصی افراد بدون رضایتشان مجاز نیست. پست‌ها و نظرات شما برای دیگر اعضای انجمن قابل مشاهده است، پس پیش از انتشار مطمئن شوید که انتشارشان را تأیید می‌کنید. در صورت مشاهده تخلف می‌توانید موضوع را به پشتیبانی گزارش دهید.',['محتوا برای دیگر اعضا قابل مشاهده است.','توهین، فریب و نقض حقوق دیگران مجاز نیست.','اطلاعات خصوصی دیگران را بدون اجازه منتشر نکنید.','تخلف‌ها را به پشتیبانی گزارش دهید.']],
    ['content','محتوای کاربران و مسئولیت آن','مسئولیت آنچه منتشر می‌کنید با خودتان است. با انتشار محتوا در انجمن، به Noventix اجازه می‌دهید آن را در همان فضا نمایش دهد و برای خوانایی یا دسته‌بندی، ویرایش‌های جزئی روی آن انجام دهد. مالکیت اثر همچنان از شماست. اگر محتوایی منتشر کردید که باید حذف شود، از پشتیبانی درخواست کنید؛ در موارد نقض قوانین ممکن است محتوا بدون اطلاع قبلی حذف شود.',['مسئولیت محتوای منتشرشده با شماست.','مالکیت اثر شما حفظ می‌شود.','Noventix می‌تواند محتوای ناقض قوانین را حذف کند.']],
    ['plans','پلن‌ها، پرداخت و لغو','امکانات هر پلن در صفحه قیمت‌گذاری توضیح داده شده‌اند. فعال‌سازی اشتراک پس از تأیید پرداخت انجام می‌شود و تا پایان دوره اعتبار دارد. ثبت درخواست یا نمایش فرم اشتراک به‌معنای پرداخت موفق نیست. اگر دوره‌ای تمدید نشود، دسترسی به امکانات همان پلن در پایان دوره متوقف می‌شود و حساب شما حذف نمی‌شود. برای لغو یا تغییر پلن از پشتیبانی کمک بگیرید.',['دسترسی پولی پس از تأیید پرداخت فعال می‌شود.','پایان دوره یعنی پایان دسترسی، نه حذف حساب.','تغییر یا لغو پلن از مسیر پشتیبانی انجام می‌شود.']],
    ['fair-use','استفاده منصفانه و محدودیت‌ها','امکانات سایت برای یادگیری طراحی شده‌اند، نه برای بهره‌برداری خودکار یا انبوه. تلاش برای دورزدن محدودیت‌ها، استخراج انبوه محتوا، بارگذاری مخرب روی سرویس یا دسترسی برنامه‌نویسی‌شده بدون هماهنگی مجاز نیست. در صورت مشاهده چنین الگوهایی، دسترسی محدود یا موقتاً متوقف می‌شود تا موضوع بررسی شود.',['استفاده خودکار یا انبوه مجاز نیست.','دورزدن محدودیت‌ها و بارگذاری مخرب پیامد دارد.','در موارد مشکوک، دسترسی موقتاً محدود می‌شود.']],
    ['availability','دسترس‌پذیری خدمات','ممکن است برای نگهداری، به‌روزرسانی یا رفع خطا، بخشی از خدمات موقتاً در دسترس نباشد. عملکرد بعضی قابلیت‌ها مانند اجرای کد به اتصال اینترنت، مرورگر و بسته‌های سازگار نیز وابسته است. تلاش می‌کنیم تغییرات را از قبل اطلاع دهیم، اما در همه موارد ممکن نیست. در صورت بروز مشکل، جزئیات خطا و زمان آن را از طریق پشتیبانی ارسال کنید.',['نگهداری و رفع خطا می‌تواند باعث قطع موقت شود.','بعضی قابلیت‌ها به اینترنت و مرورگر وابسته‌اند.','گزارش دقیق خطا به رفع سریع‌تر کمک می‌کند.']],
    ['ip','مالکیت معنوی Noventix','نام، نشان و طراحی Noventix و ساختار محتوایی سایت متعلق به این مجموعه است. استفاده از آن‌ها در پروژه شخصی شما برای تمرین آزاد است، اما استفاده تجاری، ساخت سرویس مشابه با همان هویت یا معرفی محصول خود به نام Noventix مجاز نیست. برای همکاری یا استفاده خاص، پیش از هر اقدام با ما هماهنگ کنید.',['هویت و طراحی سایت متعلق به Noventix است.','استفاده تجاری یا معرفی محصول دیگر مجاز نیست.']],
    ['liability','محدوده مسئولیت','محتوای آموزشی با دقت تهیه می‌شود، اما تضمینی برای درست‌بودن همه‌جانبه یا مناسب‌بودن آن برای هدف خاص شما داده نمی‌شود. Noventix مسئول نتایج تصمیم‌هایی که بر پایه محتوا یا خروجی ابزارهای سایت می‌گیرید، نیست. استفاده از آموخته‌ها در پروژه واقعی، با ارزیابی و مسئولیت خودتان انجام می‌شود.',['محتوا با دقت تهیه می‌شود، اما تضمین مطلق نیست.','مسئولیت تصمیم‌های شما با خودتان است.']],
    ['privacy','حریم خصوصی و نگهداری اطلاعات','برای ارائه خدمات، اطلاعاتی مانند نام، شماره موبایل و پیشرفت یادگیری شما نگهداری می‌شود. این اطلاعات برای نمایش داشبورد، مدیریت دسترسی و پشتیبانی استفاده می‌شود و بدون دلیل روشن در اختیار دیگران قرار نمی‌گیرد. جزئیات بیشتر در صفحه حریم خصوصی آمده است. اگر می‌خواهید درباره اطلاعات حساب شما توضیح بیشتری بگیرید، از پشتیبانی بپرسید.',['اطلاعات حساب برای ارائه خدمات نگهداری می‌شود.','جزئیات بیشتر در صفحه حریم خصوصی است.']],
    ['termination','تعلیق و پایان دسترسی','اگر این شرایط نقض شود، ممکن است دسترسی شما محدود یا متوقف شود. در موارد کم‌اهمیت، ابتدا تذکر داده می‌شود؛ اما در مواردی مانند تهدید امنیت دیگران یا سوءاستفاده آشکار، ممکن است دسترسی بدون اطلاع قبلی محدود شود. اگر با این تصمیم موافق نیستید، می‌توانید از پشتیبانی درخواست بررسی مجدد کنید.',['نقض شرایط می‌تواند به محدودشدن دسترسی منجر شود.','امکان درخواست بررسی مجدد وجود دارد.']],
    ['updates','تغییر این شرایط و ارتباط با ما','ممکن است برای روشن‌ترشدن شیوه استفاده از خدمات، متن این صفحه تغییر کند. نسخه منتشرشده در همین صفحه مبنای اطلاع‌رسانی تغییرات است و در تغییرات مهم، از طریق اعلان‌های سایت اطلاع می‌دهیم. اگر درباره این شرایط یا نحوه استفاده از امکانات پرسشی دارید، پیش از ادامه با پشتیبانی تماس بگیرید.',['نسخه منتشرشده در همین صفحه معتبر است.','تغییرات مهم از طریق اعلان‌ها اطلاع داده می‌شود.']],
];
function terms_list(array $points): void {
    if (!$points) return;
    echo '<ul class="terms-points">';
    foreach ($points as $point) echo '<li>'.h($point).'</li>';
    echo '</ul>';
}
function page_terms(): void {
    echo '<main><header class="terms-hero"><div class="container terms-hero-inner"><span class="eyebrow">راهنمای استفاده از خدمات</span><h1>قوانین و مقررات</h1><p>پیش از استفاده از امکانات Noventix، با حقوق و مسئولیت‌های خود در این فضا آشنا شوید. این راهنما به زبان ساده نوشته شده تا بتوانید بخش موردنیازتان را سریع پیدا کنید.</p><div class="terms-hero-facts"><span>'.fa(count(TERMS_SECTIONS)).' بخش</span><span>زبان ساده</span><span>آخرین بازنگری: مهر ۱۴۰۵</span></div></div></header><div class="container terms-layout"><nav class="terms-nav" aria-label="فهرست بخش‌های قوانین"><strong>در این صفحه</strong><ol>';
    foreach (TERMS_SECTIONS as $section) echo '<li><a href="#'.h($section[0]).'">'.h($section[1]).'</a></li>';
    echo '</ol></nav><div class="terms-content"><div class="terms-intro"><strong>خلاصه‌ای برای شروع</strong><p>از حساب و فایل‌های خود محافظت کنید، به حقوق دیگران احترام بگذارید و برای پیگیری هر مشکل از مسیر پشتیبانی استفاده کنید.</p><ul class="terms-points"><li>اطلاعات محرمانه را در میزکار و پرسش‌های Nova وارد نکنید.</li><li>محتوا و کد شما در میزکار روی همان دستگاه می‌ماند؛ پشتیبان بگیرید.</li><li>پاسخ‌های Nova و خروجی کد را پیش از استفاده واقعی خودتان بسنجید.</li></ul></div>';
    foreach (TERMS_SECTIONS as $index => $section) {
        echo '<section id="'.h($section[0]).'" class="terms-section" aria-labelledby="heading-'.h($section[0]).'"><div class="terms-section-heading"><span aria-hidden="true">'.fa(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)).'</span><h2 id="heading-'.h($section[0]).'">'.h($section[1]).'</h2></div><p>'.h($section[2]).'</p>';
        terms_list($section[3]);
        echo '</section>';
    }
    echo '<div class="terms-contact"><div><h2>سؤالی درباره قوانین دارید؟</h2><p>برای روشن‌شدن شرایط استفاده یا گزارش مشکل، از پشتیبانی کمک بگیرید.</p></div>'; linkto('support','تماس با پشتیبانی ←','btn btn-primary'); echo '</div></div></div></main>';
}
function page_privacy(): void {
    echo '<main><header class="terms-hero"><div class="container terms-hero-inner"><span class="eyebrow">اطلاعات شما، شفاف</span><h1>حریم خصوصی</h1><p>چه اطلاعاتی نگهداری می‌شود، چرا و چگونه از آن محافظت می‌کنیم.</p></div></header><div class="container terms-layout"><div class="terms-content" style="grid-column:1/-1">';
    $items = [
        ['اطلاعات حساب','شماره موبایل، نام و رمز عبور (به‌صورت هش‌شده) برای ساخت حساب، ورود و ارتباط با شما نگهداری می‌شود. رمز عبور هرگز به‌صورت متن ساده ذخیره نمی‌شود.',['رمز عبور فقط به‌صورت هش نگهداری می‌شود.','شماره موبایل شناسه ورود شماست.']],
        ['داده‌های آموزشی','پیشرفت، ثبت‌نام‌ها، پروژه‌ها و فعالیت‌های آموزشی شما برای نمایش داشبورد و ادامه مسیر یادگیری ذخیره می‌شود.',['پیشرفت شما برای نمایش داشبورد ذخیره می‌شود.']],
        ['میزکار کد و فایل‌ها','کد و فایل‌های میزکار در مرورگر خودتان اجرا و نگهداری می‌شوند و به سرور فرستاده نمی‌شوند.',['کد شما در مرورگر خودتان می‌ماند، نه روی سرور.']],
        ['گفت‌وگوی Nova','پرسش و پاسخ‌های Nova برای نمایش تاریخچه و اعمال سهمیه ماهانه در حساب شما ذخیره می‌شود. اطلاعات محرمانه را در پرسش‌ها وارد نکنید.',['تاریخچه گفت‌وگو برای پیگیری سهمیه ذخیره می‌شود.']],
        ['محتوای انجمن','پست‌ها، نظرات و پروفایل شما برای سایر اعضای انجمن قابل مشاهده است. اطلاعاتی که نمی‌خواهید عمومی شود را منتشر نکنید.',['آنچه در انجمن منتشر می‌کنید برای دیگران قابل مشاهده است.']],
        ['امنیت و دسترسی','دسترسی به اطلاعات حساب محدود به ارائه خدمات و پشتیبانی است. اطلاعات شما به فروش نمی‌رسد و بدون دلیل روشن در اختیار شخص ثالث قرار نمی‌گیرد.',['اطلاعات شما فروخته نمی‌شود.']],
        ['حقوق شما','می‌توانید درباره اطلاعات حساب خود توضیح بخواهید، اصلاح آن را درخواست کنید یا حذف حساب را از پشتیبانی بخواهید.',['برای اصلاح یا حذف اطلاعات با پشتیبانی تماس بگیرید.']],
        ['تماس با ما','اگر درباره نگهداری اطلاعات پرسشی دارید، از طریق پشتیبانی یا فرم تماس با ما در ارتباط باشید.',[]],
    ];
    foreach ($items as $index => $item) {
        echo '<section id="privacy-'.fa((string)($index+1)).'" class="terms-section"><div class="terms-section-heading"><span aria-hidden="true">'.fa(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)).'</span><h2>'.h($item[0]).'</h2></div><p>'.h($item[1]).'</p>';
        terms_list($item[2]);
        echo '</section>';
    }
    echo '<div class="terms-contact"><div><h2>پرسشی درباره اطلاعات خود دارید؟</h2><p>برای توضیح درباره داده‌های حساب یا درخواست اصلاح آن با پشتیبانی تماس بگیرید.</p></div>'; linkto('contact','تماس با ما ←','btn btn-primary'); echo '</div></div></div></main>';
}
function page_member(int $id): void {
    $member=q('SELECT id,name,created_at FROM users WHERE id=?',[$id])->fetch();
    if (!$member) { http_response_code(404); exit('کاربر پیدا نشد.'); }
    $posts=(int)q('SELECT COUNT(*) FROM community_posts WHERE user_id=?',[$id])->fetchColumn();
    $followers=(int)q('SELECT COUNT(*) FROM user_follows WHERE followed_id=?',[$id])->fetchColumn();
    head_page('پروفایل '.($member['name'] ?: 'عضو انجمن'),'عضو انجمن Noventix'); notice();
    echo '<main class="container section"><section class="panel-card member-profile"><span class="profile-avatar">'.h(mb_substr($member['name'] ?: 'ن',0,1)).'</span><div><span class="eyebrow">عضو انجمن</span><h1>'.h($member['name'] ?: 'عضو انجمن').'</h1><p class="muted">'.fa($posts).' پست · '.fa($followers).' دنبال‌کننده</p></div>';
    $viewer=user();
    if ($viewer && (int)$viewer['id']!==$id) {
        $following=q('SELECT 1 FROM user_follows WHERE follower_id=? AND followed_id=?',[$viewer['id'],$id])->fetchColumn();
        if ($following) echo '<span class="pill">دنبال می‌کنید</span>';
        else { form_start('community/follow'); echo '<input type="hidden" name="user_id" value="'.$id.'"><button class="btn btn-primary">دنبال کردن</button></form>'; }
    }
    echo '</section><div class="panel-list">'; foreach (q('SELECT title,body,created_at FROM community_posts WHERE user_id=? ORDER BY id DESC LIMIT 20',[$id])->fetchAll() as $post) echo '<article class="post-card"><small>'.h(jdate($post['created_at'])).'</small><h2>'.h($post['title']).'</h2><p>'.nl2br(h($post['body'])).'</p></article>'; echo '</div></main>'; foot_page();
}
function page_community(): void {
    global $me;
    $posts=q('SELECT p.id,p.title,p.body,p.created_at,p.user_id,u.name,u.phone FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 30')->fetchAll();
    head_page('انجمن','پرسش، تجربه و گفت‌وگو میان یادگیرندگان و متخصصان Noventix.'); notice(); echo '<main><section class="page-hero container"><span class="eyebrow">انجمن</span><h1>کنار هم یاد می‌گیریم</h1><p>سؤال‌هایت را بپرس، تجربه‌ات را به اشتراک بگذار و مسیر یادگیری دیگران را بهتر کن.</p></section><section class="section container">';
    if ($me) { echo '<div class="panel-card narrow-left">'; form_start('community/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label><label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label><button class="btn btn-primary">ثبت در انجمن</button></form></div>'; } else { echo '<div class="panel-card">'; linkto('login','برای مشارکت در انجمن وارد شوید ←','btn btn-primary'); echo '</div>'; }
    echo '<div class="community-list">'; if (!$posts) echo '<div class="empty-state">هنوز گفت‌وگویی ثبت نشده است. اولین نفر باشید.</div>';
    foreach ($posts as $p) { echo '<article class="post-card"><div class="meta-row"><span class="pill">'.h($p['created_at']).'</span><a class="profile-link" href="'.h(url('member/'.$p['user_id'])).'">'.h($p['name'] ?: mb_substr($p['phone'],0,4).'****').'</a></div><h2>'.h($p['title']).'</h2><p>'.nl2br(h($p['body'])).'</p>'; $replies=q('SELECT r.body,r.created_at,u.name,u.phone FROM community_replies r JOIN users u ON u.id=r.user_id WHERE r.post_id=? ORDER BY r.id',[$p['id']])->fetchAll(); if ($replies) { echo '<div class="replies">'; foreach ($replies as $r) echo '<div class="reply"><strong>'.h($r['name'] ?: mb_substr($r['phone'],0,4).'****').'</strong><p>'.nl2br(h($r['body'])).'</p></div>'; echo '</div>'; } if ($me) { form_start('community/reply','reply-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><textarea name="body" required minlength="10" maxlength="5000" rows="3" placeholder="پاسخ شما"></textarea><button class="btn btn-outline">ارسال پاسخ</button></form>'; } echo '</article>'; } echo '</div></section></main>'; foot_page();
}
function page_login(): void {
    if (user() && trim((string)user()['name'])!=='') redirect('dashboard');
    $captcha=captcha_code();
    $registration=$_SESSION['registration_form'] ?? [];
    unset($_SESSION['registration_form']);
    head_page('ورود','ورود یا ثبت‌نام با شماره موبایل در Noventix.'); notice();
    echo '<main class="auth-page"><div class="auth-card"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><h1>ورود یا ثبت‌نام</h1><p class="muted">برای ساخت حساب، فرم ثبت‌نام را کامل کنید. ورودهای بعدی با شماره، رمز و کپچا انجام می‌شود.</p>';
    form_start('auth/request','stack-form',true); echo '<h2>ثبت‌نام</h2><label>نام<input name="first_name" required minlength="2" maxlength="50" autocomplete="given-name" value="'.h((string)($registration['first_name'] ?? '')).'" /></label><label>نام خانوادگی<input name="last_name" required minlength="2" maxlength="50" autocomplete="family-name" value="'.h((string)($registration['last_name'] ?? '')).'" /></label><label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" placeholder="09123456789" autocomplete="tel" value="'.h((string)($registration['phone'] ?? '')).'" /></label><label>رمز عبور<input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-validation="password"></label><p class="fine-print">رمز باید دست‌کم ۸ نویسه و شامل حرف انگلیسی و عدد باشد.</p><button class="btn btn-primary">ارسال کد تأیید</button></form>';
    echo '<hr><h2>ورود به حساب</h2>'; form_start('auth/login','stack-form',true); echo '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" autocomplete="username"></label><label>رمز عبور<input type="password" name="password" required autocomplete="current-password"></label><div class="captcha-box"><strong dir="ltr">'.h($captcha).'</strong><span>کد امنیتی را وارد کنید</span></div><label>کپچا<input name="captcha" required maxlength="5" pattern="[A-Za-z0-9]{5}" dir="ltr" autocomplete="off"></label><button class="btn btn-outline">ورود به پنل</button></form></div></main>'; foot_page();
}
function page_admin_login(): void {
    $u=require_user();
    if ($u['phone']!==(config_value('ADMIN_PHONE') ?: '')) { http_response_code(403); exit('دسترسی مجاز نیست.'); }
    if ($u['role']==='admin') redirect('dashboard');
    head_page('ورود مدیر','تأیید ورود به پنل مدیریت.'); notice();
    $captcha=captcha_code();
    echo '<main class="auth-page"><div class="auth-card"><h1>ورود به مدیریت</h1><p class="muted">برای ادامه، رمز مدیریت و کد امنیتی را وارد کنید.</p>';
    form_start('auth/admin','stack-form',true);
    echo '<label>رمز مدیریت<input type="password" name="password" required autocomplete="current-password"></label><div class="captcha-box"><strong dir="ltr">'.h($captcha).'</strong><span>کد امنیتی را وارد کنید</span></div><label>کپچا<input name="captcha" required maxlength="5" pattern="[A-Za-z0-9]{5}" dir="ltr" autocomplete="off"></label><button class="btn btn-primary">ورود به پنل مدیریت</button></form></div></main>';
    foot_page();
}
function page_verify(): void {
    $phone=$_SESSION['verify_phone'] ?? ''; if (!$phone) redirect('login');
    head_page('تأیید شماره','تأیید کد پیامک‌شده.'); notice(); echo '<main class="auth-page"><div class="auth-card"><h1>کد تأیید را وارد کنید</h1><p class="muted" dir="ltr">'.h($phone).'</p>'; form_start('auth/verify','stack-form',true); echo '<label>کد ۶ رقمی<input inputmode="numeric" dir="ltr" name="code" required pattern="[0-9۰-۹]{6}" autocomplete="one-time-code"></label><button class="btn btn-primary">تأیید و ورود</button></form>'; form_start('auth/resend','stack-form'); echo '<button class="btn btn-outline">ارسال دوباره کد</button></form>'; linkto('login','ویرایش شماره ←','inline-link'); echo '</div></main>'; foot_page();
}
