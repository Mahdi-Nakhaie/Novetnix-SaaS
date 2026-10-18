<?php
declare(strict_types=1);
require_once __DIR__.'/app.php';
require_once __DIR__.'/workspace_views.php';

function route(): void {
    global $path,$me,$plan;
    if (seo_route($path)) return;
    if ($path==='payment/zarinpal/callback') payment_callback();
    if ($path==='dashboard/payment-history') { $u=require_user(); head_page('تاریخچه پرداخت','',true); notice(); echo '<main class="panel-content">'; payment_history($u); echo '</main>'; foot_page(true); return; }
    if (in_array($path,['workspace/project-solution','workspace/data','workspace/download','workspace/run-state','workspace/runtime','workspace/datasets-search','workspace/datasets-detail','workspace/datasets-state'],true)) ws_get($path);
    if ($path==='dashboard/workspace' || preg_match('~^dashboard/workspace/([1-9][0-9]*)$~D',$path,$workspaceMatch)) {
        $u=require_user(); $workspaceId=isset($workspaceMatch[1])?(int)$workspaceMatch[1]:0;
        if ($workspaceId) ws_owned((int)$u['id'],$workspaceId);
        head_page('میزکار پایتون','',true); notice();
        if ($workspaceId) ws_editor($u,$workspaceId); else ws_index($u);
        foot_page(true); return;
    }
    if ($path==='dashboard/workspace-templates') {
        require_admin(); head_page('مدیریت تمرین‌های پایتون','',true); notice(); ws_admin(); foot_page(true); return;
    }
    $public=[''=>'home','courses'=>'courses','course'=>'course','projects'=>'projects','project'=>'project','pricing'=>'pricing','about'=>'about','contact'=>'contact','community'=>'community','login'=>'login','verify'=>'verify'];
    if ($path==='' ) { head_page('خانه','Noventix؛ آموزش پروژه‌محور پایتون، علم داده، یادگیری ماشین و یادگیری عمیق به زبان فارسی.'); notice(); home(published_courses()); foot_page(); return; }
    if ($path==='courses') { head_page('مسیر یادگیری','مقاله‌های کاربردی پایتون، علم داده، یادگیری ماشین، یادگیری عمیق و ارائه مدل.'); notice(); page_courses(published_courses()); foot_page(); return; }
    if (str_starts_with($path,'course/')) { page_course(trim(substr($path,7))); return; }
    if ($path==='projects') { head_page('پروژه‌ها','پروژه‌های واقعی پایتون، علم داده و هوش مصنوعی برای تبدیل دانش به مهارت.'); notice(); page_projects(); foot_page(); return; }
    if (str_starts_with($path,'project/')) { page_project(substr($path,8)); return; }
    if ($path==='pricing') { head_page('قیمت‌گذاری','امکانات فعال و وضعیت پلن‌های یادگیری Noventix.'); notice(); if (COMMERCE_ENABLED) page_pricing(); else commerce_paused(); foot_page(); return; }
    if ($path==='about') { head_page('درباره ما','داستان، رویکرد آموزشی و اصول تجربه یادگیری در Noventix.'); notice(); page_about(); foot_page(); return; }
    if ($path==='contact') { head_page('تماس با ما','راه‌های ارتباط با تیم Noventix و شبکه‌های اجتماعی.'); notice(); page_contact(); foot_page(); return; }
    if ($path==='community') { page_community(); return; }
    if ($path==='help') { head_page('مرکز راهنما','راهنمای شروع حساب، مسیر یادگیری، میزکار کد، انجمن و پشتیبانی Noventix.'); notice(); page_help(); foot_page(); return; }
    if ($path==='terms') { head_page('قوانین و مقررات','شرایط استفاده از سایت، پنل، میزکار کد و انجمن Noventix به زبان ساده.'); notice(); page_terms(); foot_page(); return; }
    if ($path==='privacy') { head_page('حریم خصوصی','نحوه نگهداری و استفاده از اطلاعات شما در Noventix.'); notice(); page_privacy(); foot_page(); return; }
    if ($path==='glossary/suggest') {
        header('Content-Type: application/json; charset=utf-8');
        $query=trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($query)>80) $query=mb_substr($query,0,80);
        try { echo json_encode(['suggestions'=>glossary_suggestions($query)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); }
        catch (Throwable $e) { http_response_code(503); echo json_encode(['error'=>'جست‌وجو موقتاً در دسترس نیست.'],JSON_UNESCAPED_UNICODE); }
        return;
    }
    if ($path==='glossary') {
        $query=trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($query)>80) $query=mb_substr($query,0,80);
        $category=preg_match('/^[a-z0-9-]+$/',(string)($_GET['category'] ?? '')) ? (string)$_GET['category'] : '';
        $letter=preg_match('/^[a-z]$/i',(string)($_GET['letter'] ?? '')) ? strtoupper((string)$_GET['letter']) : '';
        $page=filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
        $result=glossary_entries($query,$category,$letter,(int)$page);
        $indexable=$query==='' && $category==='' && $letter==='' && (int)$page===1 && user()===null;
        head_page('لغت‌نامهٔ هوش مصنوعی',fa((int)$result['total']).' اصطلاح منتشرشدهٔ هوش مصنوعی، یادگیری ماشین و علم داده با تعریف روشن و مفاهیم مرتبط.',false,$indexable);
        notice(); page_glossary($result,$query,$category,$letter); foot_page(); return;
    }
    if (preg_match('~^glossary/([a-z0-9-]+)$~D',$path,$match)) {
        $entry=glossary_entry($match[1]);
        if (!$entry) { page_not_found(); return; }
        head_page($entry['term_en'].' | لغت‌نامهٔ هوش مصنوعی',$entry['short_definition'],false,true);
        page_glossary_entry($entry); foot_page(); return;
    }
    if ($path==='learners') {
        $query=trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($query)>60) $query=mb_substr($query,0,60);
        $page=filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
        $directory=learner_directory($query,(int)$page);
        $viewer=user();
        $indexable=$query==='' && (int)$page===1 && $viewer===null;
        head_page('جامعه یادگیرندگان',$query!=='' ? 'اعضای جامعه Noventix با نام '.$query.' و پیشرفت مهارتی ثبت‌شدهٔ آن‌ها.' : 'پروفایل مهارتی اعضای انجمن Noventix؛ پیشرفت واقعی هر یادگیرنده بر اساس پروژه‌های تأییدشده.',false,$indexable);
        notice(); page_learners($directory,$query,(bool)user()); foot_page(); return;
    }
    if ($path==='messages') { $u=require_user(); head_page('پیام‌های من','گفت‌وگوهای مستقیم میان اعضای جامعه Noventix.',true); notice(); page_messages((int)$u['id']); foot_page(); return; }
    if (preg_match('~^messages/([1-9][0-9]*)$~D',$path,$match)) {
        $u=require_user(); head_page('گفت‌وگو','گفت‌وگوی مستقیم با یک عضو جامعه Noventix.',true); notice();
        if (!page_message_thread((int)$u['id'],(int)$match[1])) {
            http_response_code(404);
            echo '<main class="panel-content"><div class="empty-state"><h1>گفت‌وگو پیدا نشد</h1><p>این عضو وجود ندارد یا دیگر در دسترس نیست.</p>'; linkto('messages','بازگشت به پیام‌ها ←','btn btn-primary'); echo '</div></main>';
        }
        foot_page(true); return;
    }
    if (preg_match('~^member/([1-9][0-9]*)$~D',$path,$match)) { page_member((int)$match[1]); return; }
    if ($path==='login') { page_login(); return; }
    if ($path==='verify') { page_verify(); return; }
    if ($path==='admin-login') { page_admin_login(); return; }
    if (preg_match('~^dashboard/course/([a-z0-9-]+)$~D',$path,$match)) {
        $u=require_user();
        if ($u['phone']===(config_value('ADMIN_PHONE') ?: '') && $u['role']!=='admin') redirect('admin-login');
        $requestedLesson=(string)($_GET['lesson'] ?? '');
        if ($requestedLesson!=='') {
            $requestedLesson=filter_var($requestedLesson,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($requestedLesson===false || $requestedLesson===null) { http_response_code(404); exit; }
        } else $requestedLesson=null;
        $course=course_workspace((int)$u['id'],$match[1],$requestedLesson);
        head_page('فضای یادگیری','',true); notice(); page_course_workspace($course,$requestedLesson===null?'':(string)$requestedLesson); foot_page(true);
        return;
    }
    if (preg_match('~^progress/lesson/([1-9][0-9]*)$~D',$path,$match)) {
        $u=user();
        if (!$u || trim($u['name'])==='') progress_response(401,['error'=>'برای مشاهده پیشرفت وارد شوید.']);
        lesson_progress_for_user($u,$match[1]);
    }
    if (preg_match('~^dashboard/(users|users-new|courses|catalog|subscriptions|messages|analytics|payments|announcements|content|settings|glossary|glossary/new|glossary/edit)$~D',$path)) require_admin();
    if ($path==='dashboard' || str_starts_with($path,'dashboard/')) {
        $u=require_user();
        if ($u['phone']===(config_value('ADMIN_PHONE') ?: '') && $u['role']!=='admin') redirect('admin-login');
        $allowed=['profile','learning','projects','community','nova','subscription','users','users-new','courses','catalog','subscriptions','messages','analytics','challenges','payments','support','announcements','gamification','activity','content','settings','glossary','glossary/new','glossary/edit'];
        if (str_starts_with($path,'dashboard/') && !in_array(substr($path,10),$allowed,true)) { page_not_found(); return; }
        if (!COMMERCE_ENABLED && in_array($path,['dashboard/subscription','dashboard/subscriptions','dashboard/payments'],true)) {
            head_page('یادگیری رایگان','',true); notice(); commerce_paused(); foot_page(true); return;
        }
        head_page('پنل', '', true); notice(); panel(str_starts_with($path,'dashboard/') ? substr($path,10) : ''); foot_page(true); return;
    }
    page_not_found();
}

function page_courses(array $courses): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">مسیر یادگیری</span><h1>قدم‌به‌قدم تا توانایی ساختن</h1><p>هر مقاله یک مفهوم کاربردی را با تمرین واقعی ترکیب می‌کند؛ از پایتون تا یادگیری عمیق.</p></section><section class="section container">'; course_cards($courses); echo '</section></main>';
}
function course_start_action(array $course,?array $me,bool $enrolled,string $class): void {
    if (!$me || trim($me['name'])==='') { linkto('login','برای شروع وارد شوید ←',$class); return; }
    if (!can_course($course['slug'],plan_for($me))) { linkto('dashboard/subscription','مشاهده پلن موردنیاز ←',$class); return; }
    if ($enrolled) { linkto('dashboard/course/'.$course['slug'],'ورود به فضای یادگیری ←',$class); return; }
    if (!COMMERCE_ENABLED && $course['slug']!==FREE_COURSE_SLUG) { echo '<p class="muted">ثبت‌نام این دوره فعلاً در دسترس نیست.</p>'; return; }
    form_start('courses/enroll');
    echo '<input type="hidden" name="slug" value="'.h($course['slug']).'"><button class="'.h($class).'">شروع یادگیری دوره ←</button></form>';
}
function page_course(string $slug): void {
    global $me;
    $course=q("SELECT id,slug,title,description,estimated_duration_minutes FROM courses WHERE slug=? AND status='published'",[$slug])->fetch();
    if (!$course) { page_not_found(); return; }
    $lessons=q("SELECT title,position,duration_minutes,status FROM lessons WHERE course_id=? AND status='published' ORDER BY position ASC",[$course['id']])->fetchAll();
    $duration=$course['estimated_duration_minutes'];
    if ($duration===null) {
        $lessonDurations=array_column($lessons,'duration_minutes');
        $duration=$lessonDurations && !in_array(null,$lessonDurations,true) ? array_sum($lessonDurations) : null;
    }
    $durationLabel=$duration===null ? 'مدت ثبت نشده' : fa((int)$duration).' دقیقه';
    $enrolled=$me && trim($me['name'])!=='' && (bool)q('SELECT 1 FROM enrollments WHERE user_id=? AND course_slug=?',[$me['id'],$course['slug']])->fetchColumn();
    head_page($course['title'],$course['description']); notice(); echo '<main class="course-overview"><section class="course-overview-hero"><div class="container narrow-container"><span class="eyebrow">مسیر یادگیری · Course</span><h1>'.h($course['title']).'</h1><p class="lead">'.h($course['description']).'</p><div class="article-meta"><span>'.$durationLabel.'</span><span>'.fa(count($lessons)).' Lesson</span><span>'.($course['slug']===FREE_COURSE_SLUG?'رایگان':'دسترسی مطابق پلن فعال').'</span><span>یادگیری پروژه‌محور</span></div><div class="course-hero-actions">'; course_start_action($course,$me,$enrolled,'btn btn-primary'); echo '</div></div></section><section class="section container narrow-container"><div class="course-overview-grid"><div><span class="eyebrow">برای چه کسی؟</span><h2>شروعی روشن برای ساختن با AI</h2><p class="muted">برای مبتدی‌هایی که می‌خواهند مفاهیم هوش مصنوعی را با یک مسیر عملی و قابل فهم یاد بگیرند.</p></div><div class="course-audience-list"><span>مبتدی و علاقه‌مند به AI</span><span>یادگیری همراه با پروژه</span><span>حرکت از مفهوم به اجرا</span></div></div></section><section class="section alt-section"><div class="container"><div class="section-header"><span class="eyebrow">خروجی دوره</span><h2>چه چیزهایی یاد می‌گیری؟</h2></div><div class="features-grid course-benefits"><article class="feature"><h3>مفاهیم پایه AI</h3><p>درک اصولی مفاهیم مهم بدون پیچیده‌گویی.</p></article><article class="feature"><h3>روند ساخت پروژه</h3><p>آشنایی با مسیر تبدیل ایده به یک نمونه عملی.</p></article><article class="feature"><h3>تمرین واقعی</h3><p>یادگیری با مثال‌هایی که به ساختن کمک می‌کنند.</p></article><article class="feature"><h3>حرکت به اجرا</h3><p>اتصال دانسته‌ها به یک تجربه قابل ارائه.</p></article></div></div></section><section class="section container narrow-container"><div class="section-header"><span class="eyebrow">Curriculum</span><h2>مسیر جلسات</h2><p>جلسه‌ها از محتوای منتشرشدهٔ همین Course ساخته می‌شوند.</p></div>';
    if (!$lessons) echo '<div class="empty-state">این Course هنوز Lesson منتشرشده‌ای ندارد.</div>';
    else { echo '<div class="lesson-list">'; foreach ($lessons as $lesson) { echo '<article class="lesson-card"><div class="lesson-number">'.fa((int)$lesson['position']).'</div><div><div class="meta-row"><span class="pill">منتشرشده</span><span>'.($lesson['duration_minutes']===null?'مدت ثبت نشده':fa((int)$lesson['duration_minutes']).' دقیقه').'</span></div><h3>'.h($lesson['title']).'</h3></div></article>'; } echo '</div>'; }
    echo '<div class="cta-panel course-final-cta"><span class="eyebrow">قدم بعدی</span><h2>آماده‌ای شروع کنی؟</h2><p>از اولین Lesson شروع کن و قدم‌به‌قدم جلو برو.</p>'; course_start_action($course,$me,$enrolled,'btn btn-light'); echo '</div></section></main>'; foot_page();
}
function page_projects(): void {
    echo '<main><section class="page-hero container"><span class="eyebrow">پروژه‌ها</span><h1>یادگیری با ساختن، نه فقط خواندن</h1><p>هر پروژه یک مسئله واقعی با ورودی، خروجی و معیار موفقیت روشن است.</p></section><section class="section container">'; project_cards(); echo '</section></main>';
}
function page_project(string $index): void {
    global $me,$plan;
    $i=filter_var($index,FILTER_VALIDATE_INT);
    if ($i===false || !isset(PROJECTS[$i])) { page_not_found(); return; }
    lp_page($i);
}
function page_pricing(): void {
    $available=available_plan_ids();
    $runner=ws_runner_ready();
    echo '<main><section class="pricing-hero"><div class="container"><span class="eyebrow">قیمت‌گذاری شفاف</span><h1>همین امکاناتی که امروز فعال‌اند</h1><p>در Noventix فقط برای قابلیت‌هایی توضیح می‌دهیم که واقعاً منتشر و قابل استفاده‌اند. فعلاً می‌توانی بدون پرداخت مسیر یادگیری را شروع کنی.</p><div class="pricing-hero-facts"><span><strong>'.fa(count($available)).'</strong> پلن فعال</span><span><strong>'.($runner?'فعال':'آماده‌سازی').'</strong> اجرای کد</span><span><strong>۰</strong> قابلیت وعده‌داده‌شدهٔ بدون انتشار</span></div></div></section><section class="section container"><div class="section-header"><span class="eyebrow">پلن‌های فعال</span><h2>از مسیر واقعی شروع کن</h2><p>هر زمان محتوای پولی و درگاه فروش واقعاً آماده شود، پلن‌های بعدی همین‌جا با جزئیات دقیق اضافه می‌شوند.</p></div>'; plan_cards(); echo '</section>';
    if (count($available)===1) echo '<section class="section pricing-status-section"><div class="container"><div class="pricing-status"><span class="pricing-status-icon" aria-hidden="true">✓</span><div><span class="eyebrow">وضعیت فعلی</span><h2>پلن رایگان تنها گزینهٔ فعال است</h2><p>محتوای پولی منتشرشده یا مزیت پولی قابل‌فروش دیگری در سایت فعال نیست؛ بنابراین خرید اشتراک‌های پولی عمداً غیرفعال است و مبلغی دریافت نمی‌شود.</p></div></div></div></section>';
    echo '<section class="section container"><div class="section-header"><span class="eyebrow">آنچه اکنون دریافت می‌کنی</span><h2>امکانات واقعی Noventix</h2></div><div class="pricing-facts"><article><span>۰۱</span><h3>یادگیری رایگان</h3><p>دورهٔ مقدماتی هوش مصنوعی با درس، آزمون و ثبت پیشرفت واقعی.</p></article><article><span>۰۲</span><h3>ساختن و تمرین</h3><p>پروژه‌های عمومی، میزکار خصوصی و ذخیرهٔ فایل‌های تمرین در حساب کاربری.</p></article><article><span>۰۳</span><h3>جامعهٔ یادگیری</h3><p>انجمن، پروفایل مهارتی، پیام‌ها و گفت‌وگو با اعضای جامعه.</p></article><article><span>۰۴</span><h3>اجرای کد</h3><p>'.($runner?'اجرای ایزولهٔ Python در میزکار فعال است.':'این قابلیت پس از آماده‌شدن سرویس اجرا فعال می‌شود.').'</p></article></div><p class="fine-print">Nova AI، منتورینگ، گواهی و محتوای پولی در این صفحه به‌عنوان قابلیت فعال محاسبه نشده‌اند.</p></section><section class="section container"><div class="cta-panel"><span class="eyebrow">بدون پیچیدگی</span><h2>همین امروز شروع کن</h2><p>برای شروع دورهٔ رایگان نیازی به پرداخت نیست؛ حساب بساز و مسیرت را از اولین درس ادامه بده.</p>'; linkto('course/'.FREE_COURSE_SLUG,'شروع دورهٔ رایگان ←','btn btn-light'); echo '</div></section></main>';
}
function page_about(): void {
    echo '<main><header class="about-hero"><div class="container about-hero-grid"><div><span class="eyebrow">درباره Noventix</span><h1>یادگیری را به توانایی ساختن تبدیل می‌کنیم.</h1><p class="lead">Noventix برای کسانی ساخته شده که نمی‌خواهند فقط ویدیو ببینند؛ می‌خواهند مسئله را بفهمند، کد بنویسند و نتیجه‌ای قابل ارائه بسازند.</p><div class="hero-actions">'; linkto('projects','دیدن پروژه‌ها ←','btn btn-primary'); linkto('courses','مسیرهای یادگیری','btn btn-outline'); echo '</div></div><div class="about-hero-card"><span class="about-card-mark">N</span><strong>از مفهوم تا اجرا</strong><p>محتوای فارسی، تمرین واقعی، میزکار کد و بازخورد مرحله‌به‌مرحله در یک مسیر واحد.</p><div class="about-mini-stats"><span><b>۳۴۵+</b> اصطلاح آموزشی</span><span><b>۱۲</b> پروژه داده‌محور</span></div></div></div></header><section class="section container"><div class="about-intro"><div><span class="eyebrow">چرا ساخته شد؟</span><h2>فاصلهٔ بین دانستن و ساختن</h2></div><div><p>خیلی از مسیرهای آموزشی با توضیح تمام می‌شوند؛ درست جایی که یادگیرنده باید تصمیم بگیرد، خطا کند و راه‌حل خودش را بسازد. Noventix این فاصله را با پروژه، تمرین و بازخورد پر می‌کند.</p><p>ما تلاش می‌کنیم هر مفهوم یک خروجی روشن داشته باشد: یک برنامه، یک تحلیل، یک مدل یا مهارتی که بتوانی در پروژهٔ بعدی دوباره از آن استفاده کنی.</p></div></div></section><section class="section about-values-section"><div class="container"><div class="section-header"><span class="eyebrow">روش ما</span><h2>چه چیزی تجربهٔ Noventix را متفاوت می‌کند؟</h2><p>مسیر یادگیری باید روشن، قابل اندازه‌گیری و نزدیک به دنیای واقعی باشد.</p></div><div class="features-grid about-values"><article class="feature"><span class="feature-n">۰۱ · مسیر روشن</span><h3>از هدف شروع می‌کنیم</h3><p>پیش‌نیاز، خروجی و قدم بعدی هر درس و پروژه از ابتدا مشخص است تا در مسیر گم نشوی.</p></article><article class="feature"><span class="feature-n">۰۲ · تمرین واقعی</span><h3>با ساختن یاد می‌گیریم</h3><p>به‌جای تمرین‌های بی‌هدف، با داده، کد و مسئله‌ای کار می‌کنی که نتیجهٔ آن قابل بررسی است.</p></article><article class="feature"><span class="feature-n">۰۳ · بازخورد معتبر</span><h3>پیشرفتت قابل مشاهده است</h3><p>ارزیابی پروژه‌ها و پیشرفت درس‌ها کمک می‌کند بدانی چه چیزی را یاد گرفته‌ای و کجا باید بهتر شوی.</p></article><article class="feature"><span class="feature-n">۰۴ · صداقت</span><h3>بدون وعدهٔ غیرواقعی</h3><p>دربارهٔ سطح محتوا، محدودیت ابزارها و نتیجه‌ای که می‌توانی انتظار داشته باشی شفاف صحبت می‌کنیم.</p></article></div></div></section><section class="section container"><div class="about-process"><div class="section-header"><span class="eyebrow">چرخهٔ یادگیری</span><h2>از اولین سؤال تا پروژهٔ قابل ارائه</h2></div><div class="about-process-grid"><div><span>۱</span><h3>یاد بگیر</h3><p>مفهوم را با توضیح کوتاه و مثال کاربردی می‌فهمی.</p></div><div><span>۲</span><h3>امتحان کن</h3><p>در تمرین و میزکار کد، ایده را به اجرا تبدیل می‌کنی.</p></div><div><span>۳</span><h3>ارزیابی کن</h3><p>خروجی را با معیار روشن بررسی و اصلاح می‌کنی.</p></div><div><span>۴</span><h3>به اشتراک بگذار</h3><p>در انجمن تجربه‌ات را مطرح می‌کنی و از مسیر دیگران یاد می‌گیری.</p></div></div></div></section><section class="section container"><div class="cta-panel about-cta"><span class="eyebrow">قدم بعدی</span><h2>یادگیری را با یک پروژه شروع کن</h2><p>مسیر مناسب خودت را انتخاب کن و اولین خروجی واقعی‌ات را بساز.</p>'; linkto('projects','شروع از پروژه‌ها ←','btn btn-light'); echo '</div></section></main>';
}
function page_contact(): void {
    echo '<main><section class="contact-hero"><div class="container"><span class="eyebrow">راه‌های ارتباطی Noventix</span><h1>از اینجا با ما در ارتباط باشید</h1><p>برای پیگیری سؤال‌ها و پیشنهادها، راه ارتباطی مناسب خود را انتخاب کنید.</p><div class="contact-quick-links">'; linkto('dashboard/support','مرکز پشتیبانی ←','btn btn-primary'); linkto('community','پرسش در انجمن ←','btn btn-outline'); echo '</div></div></section><section class="section container"><div class="contact-layout contact-page-layout"><div class="panel-card contact-form-card"><span class="eyebrow">پیام مستقیم</span><h2>برای ما پیام بفرستید</h2><p class="muted">درخواست خود را با جزئیات بنویسید تا تیم ما بتواند آن را بررسی کند.</p>'; form_start('contact/send','stack-form'); echo '<label>نام و نام خانوادگی<input name="name" autocomplete="name" required minlength="2" maxlength="100"></label><label>ایمیل برای پاسخ<input type="email" dir="ltr" name="email" autocomplete="email" required maxlength="255" placeholder="you@example.com"></label><label>متن پیام<textarea name="message" required minlength="10" maxlength="3000" rows="6" placeholder="موضوع درخواست و جزئیات آن را بنویسید"></textarea></label><button class="btn btn-primary">ثبت پیام</button></form></div><div class="contact-side"><div class="contact-info-card"><span class="eyebrow">راهنمای ارتباط</span><h2>کدام مسیر مناسب شماست؟</h2><p>برای مشکلات حساب یا خدمات، از مرکز پشتیبانی استفاده کنید؛ برای گفت‌وگوی آموزشی و تجربه‌ها به انجمن سر بزنید.</p>'; linkto('dashboard/support','رفتن به پشتیبانی ←','inline-link'); echo '</div><div class="contact-social"><h2>ما را دنبال کنید</h2><p>آموزش‌ها و تازه‌های Noventix را در شبکه‌های اجتماعی دنبال کنید.</p><div class="social-column"><a href="https://instagram.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Instagram</strong><span dir="ltr">noventix.ir ↗</span></a><a href="https://t.me/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Telegram</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://rubika.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Rubika</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://ble.ir/noventix_ir" target="_blank" rel="noopener noreferrer"><strong>Bale</strong><span dir="ltr">noventix_ir@ ↗</span></a><a href="https://www.aparat.com/noventix.ir" target="_blank" rel="noopener noreferrer"><strong>Aparat</strong><span dir="ltr">noventix.ir ↗</span></a></div></div></div></div></section></main>';
}
/* Kept in step with the static build's view_terms(); the shared shape is what
   keeps the two deliverables from telling users different things. */
const TERMS_SECTIONS = [
    ['scope','این قوانین چه چیزی را پوشش می‌دهد؟','این صفحه شرایط استفاده از سایت، پنل کاربری، میزکار کد و انجمن Noventix را توضیح می‌دهد. با ساختن حساب یا استفاده از امکانات سایت، این شرایط را می‌پذیرید. اگر با بخشی از آن موافق نیستید، می‌توانید از امکاناتی که نیاز به حساب ندارند استفاده کنید یا پیش از ادامه با پشتیبانی گفت‌وگو کنید.',['سایت، پنل، میزکار و انجمن را دربر می‌گیرد.','ساخت حساب یعنی پذیرش این شرایط.','بخش‌های عمومی سایت بدون حساب هم قابل مطالعه‌اند.']],
    ['account','حساب کاربری و امنیت','برای استفاده از بخش‌های کاربری، اطلاعات صحیح و متعلق به خودتان را وارد کنید. شماره موبایل شناسه ورود شماست و رمز عبور را باید محرمانه نگه دارید. اگر نشانه‌ای از دسترسی مشکوک دیدید، از طریق پشتیبانی اطلاع دهید تا دسترسی‌ها بررسی شود. تا زمانی که موضوع بررسی نشده، فعالیت‌های انجام‌شده با حساب شما به همان حساب نسبت داده می‌شود.',['اطلاعات باید واقعی و متعلق به خودتان باشد.','رمز عبور را در اختیار دیگران قرار ندهید.','دسترسی مشکوک را سریع به پشتیبانی گزارش دهید.','یک حساب برای یک نفر؛ ساخت حساب جعلی مجاز نیست.']],
    ['verification','ورود با کد پیامکی و تأیید هویت','برای ثبت‌نام، شماره موبایل با کد یک‌بارمصرف تأیید می‌شود. ورودهای بعدی با شماره، رمز عبور و کپچا انجام می‌شود. کد تأیید فقط برای شماست و نباید آن را برای کسی بفرستید؛ حتی اگر فرستنده خود را از تیم Noventix معرفی کند. تعداد درخواست‌های کد در هر نشست محدود است تا از سوءاستفاده جلوگیری شود. اگر کد را چند بار اشتباه وارد کنید، برای مدتی باید صبر کنید.',['کد تأیید را با هیچ‌کس به اشتراک نگذارید.','درخواست کد محدود است تا از سوءاستفاده جلوگیری شود.','تلاش‌های ناموفق موقتاً محدود می‌شوند.']],
    ['learning','محتوای آموزشی و حقوق نشر','دوره‌ها، تمرین‌ها و مطالب سایت برای یادگیری شخصی ارائه می‌شوند. بازنشر، فروش یا ارائه آن‌ها به نام خود بدون اجازه صاحب اثر مجاز نیست. استفاده از منابع بیرونی هم تابع شرایط و مجوز همان منابع است. اگر برای تدریس یا کار تیمی به استفاده گسترده‌تر نیاز دارید، پیش از انتشار با ما هماهنگ کنید.',['مطالب برای یادگیری شخصی شماست.','بازنشر یا فروش بدون اجازه مجاز نیست.','به شرایط استفاده منابع بیرونی هم پایبند باشید.']],
    ['workspace','میزکار کد و فایل‌ها','در نسخه PHP، فایل‌های میزکار در پایگاه‌داده خصوصی حساب شما ذخیره می‌شوند. هنگام اجرای برنامه، یک نسخه از فایل‌ها به سرویس اجرای ایزوله ارسال می‌شود؛ این قابلیت فقط پس از راه‌اندازی سرویس در دسترس است. نسخه نمایشی استاتیک همچنان در مرورگر اجرا و نگهداری می‌شود. اطلاعات محرمانه یا داده شخصی دیگران را بارگذاری نکنید و از کارهای مهم نسخه پشتیبان بگیرید.',['فایل‌های نسخه PHP به حساب کاربری شما تعلق دارند.','کد فقط در سرویس ایزوله پیکربندی‌شده اجرا می‌شود.','محدودیت حجم، زمان و منابع اعمال می‌شود.','نسخه نمایشی استاتیک ذخیره‌سازی سروری ندارد.']],
    ['ai','استفاده از Nova و ابزارهای کمکی','Nova AI فعلاً غیرفعال و در وضعیت «به‌زودی» است؛ سهمیه‌ای مصرف نمی‌شود. تاریخچهٔ قبلی حذف نشده است. اطلاعات محرمانه یا داده شخصی دیگران را در پرسش‌ها وارد نکنید.',['پاسخ Nova ممکن است نادرست باشد؛ خودتان بررسی کنید.','Nova فعلاً فعال نیست و سهمیه مصرف نمی‌کند.','داده محرمانه در پرسش‌ها وارد نکنید.']],
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
    echo '</ol></nav><div class="terms-content"><div class="terms-intro"><strong>خلاصه‌ای برای شروع</strong><p>از حساب و فایل‌های خود محافظت کنید، به حقوق دیگران احترام بگذارید و برای پیگیری هر مشکل از مسیر پشتیبانی استفاده کنید.</p><ul class="terms-points"><li>اطلاعات محرمانه را در میزکار و پرسش‌های Nova وارد نکنید.</li><li>فایل‌های نسخه PHP خصوصی و سروری‌اند؛ از کارهای مهم پشتیبان بگیرید.</li><li>پاسخ‌های Nova و خروجی کد را پیش از استفاده واقعی خودتان بسنجید.</li></ul></div>';
    foreach (TERMS_SECTIONS as $index => $section) {
        echo '<section id="'.h($section[0]).'" class="terms-section" aria-labelledby="heading-'.h($section[0]).'"><div class="terms-section-heading"><span aria-hidden="true">'.fa(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)).'</span><h2 id="heading-'.h($section[0]).'">'.h($section[1]).'</h2></div><p>'.h($section[2]).'</p>';
        terms_list($section[3]);
        echo '</section>';
    }
    echo '<div class="terms-contact"><div><h2>سؤالی درباره قوانین دارید؟</h2><p>برای روشن‌شدن شرایط استفاده یا گزارش مشکل، از پشتیبانی کمک بگیرید.</p></div>'; linkto('dashboard/support','تماس با پشتیبانی ←','btn btn-primary'); echo '</div></div></div></main>';
}
function page_privacy(): void {
    $items = [
        ['account','اطلاعات حساب','برای ساخت حساب و ورود، نام، شماره موبایل و رمز عبور دریافت می‌شود. شماره موبایل پس از نرمال‌سازی به‌عنوان شناسهٔ حساب ذخیره می‌شود و رمز عبور هرگز به‌صورت متن ساده نگهداری نمی‌شود.',['رمز عبور با الگوریتم هش ذخیره می‌شود.','شماره موبایل برای ورود و ارتباط ضروری است.']],
        ['learning','داده‌های یادگیری','ثبت‌نام دوره‌ها، پیشرفت درس‌ها، پروژه‌های تکمیل‌شده و رویدادهای آموزشی برای نمایش مسیر شخصی و محاسبهٔ پیشرفت نگهداری می‌شوند. این داده‌ها برای بهترکردن تجربهٔ همان حساب استفاده می‌شوند.',['پیشرفت شما به حساب خودتان متصل است.','پروژهٔ تأییدشده می‌تواند در پروفایل مهارتی نمایش داده شود.']],
        ['workspace','میزکار کد و فایل‌ها','فایل‌های میزکار سروری در فضای خصوصی حساب ذخیره می‌شوند و اجرای کد در سرویس ایزولهٔ پیکربندی‌شده انجام می‌شود. فایل‌ها و خروجی‌ها عمومی نیستند؛ بااین‌حال نباید رمز، کلید دسترسی یا دادهٔ شخصی دیگران را بارگذاری کنید.',['دسترسی فایل‌ها به مالک حساب محدود است.','برای کارهای مهم، نسخهٔ پشتیبان جداگانه نگه دارید.','حذف پروژه ابتدا آن را از دسترس عادی خارج می‌کند.']],
        ['community','انجمن و پروفایل مهارتی','پست‌ها، پاسخ‌ها، نام نمایشی و بخش‌های عمومی پروفایل مهارتی برای اعضای انجمن قابل مشاهده است. شماره موبایل و اطلاعات ورود عمومی نمی‌شود. پیش از انتشار، مطمئن شوید محتوای شما برای فضای عمومی مناسب است.',['محتوای انجمن عمومی است.','شماره موبایل شما نمایش داده نمی‌شود.','اطلاعات خصوصی دیگران را منتشر نکنید.']],
        ['messages','پیام‌های مستقیم','گفت‌وگوهای مستقیم فقط میان اعضایی شکل می‌گیرد که درخواست ارتباط را پذیرفته‌اند. متن پیام‌ها برای ارائهٔ قابلیت پیام‌رسانی و رسیدگی به گزارش‌های سوءاستفاده نگهداری می‌شود.',['درخواست پیام پیش از شروع گفت‌وگو نیاز به پذیرش دارد.','محتوای پیام را با اطلاعات حساس پر نکنید.']],
        ['security','امنیت و نشست','برای محافظت از حساب، نشست‌ها با کوکی‌های امن، محدودیت زمانی، توکن CSRF و تأیید شماره مدیریت می‌شوند. کدهای یک‌بارمصرف به‌صورت هش نگهداری و پس از مصرف یا انقضا بی‌اعتبار می‌شوند.',['کد تأیید و رمز را با کسی به اشتراک نگذارید.','در صورت مشاهدهٔ ورود مشکوک، سریعاً پشتیبانی را خبر کنید.']],
        ['usage','استفاده از داده','اطلاعات برای ورود، ارائهٔ دوره‌ها، اجرای پروژه، نمایش داشبورد، پشتیبانی و بهبود پایداری سرویس استفاده می‌شود. اطلاعات حساب برای فروش یا تبلیغات نامرتبط در اختیار دیگران قرار نمی‌گیرد.',['استفاده از داده باید با ارائهٔ خدمت مرتبط باشد.','اطلاعات شما فروخته نمی‌شود.']],
        ['rights','حقوق شما','می‌توانید دربارهٔ داده‌های حساب خود توضیح بخواهید، اصلاح اطلاعات را درخواست کنید یا برای حذف حساب و داده‌های مرتبط با پشتیبانی تماس بگیرید. بعضی سوابق لازم برای امنیت، پرداخت یا الزامات قانونی ممکن است طبق مدت نگهداری مربوط حفظ شوند.',['درخواست اصلاح یا حذف را از پشتیبانی ارسال کنید.','پاسخ درخواست‌ها پس از بررسی مالکیت حساب انجام می‌شود.']],
        ['changes','تغییرات این صفحه','اگر شیوهٔ نگهداری یا استفاده از داده‌ها تغییر کند، متن این صفحه به‌روزرسانی می‌شود. نسخهٔ منتشرشده در همین صفحه مرجع اطلاع‌رسانی است و تغییرات مهم از مسیر اعلان‌های سایت اطلاع داده می‌شود.',['تاریخ و محتوای صفحه را پیش از استفادهٔ مهم بررسی کنید.']],
    ];
    echo '<main><header class="terms-hero privacy-hero"><div class="container terms-hero-inner"><span class="eyebrow">شفافیت دربارهٔ داده‌ها</span><h1>حریم خصوصی</h1><p>در Noventix می‌خواهیم بدانید چه اطلاعاتی دریافت می‌شود، چرا به آن نیاز داریم، چه کسانی به آن دسترسی دارند و برای کنترل آن چه گزینه‌هایی دارید.</p><div class="terms-hero-facts"><span>'.fa(count($items)).' موضوع روشن</span><span>رمزها به‌صورت هش</span><span>عدم فروش اطلاعات</span></div></div></header><div class="container terms-layout"><nav class="terms-nav" aria-label="فهرست حریم خصوصی"><strong>در این صفحه</strong><ol>';
    foreach ($items as $item) echo '<li><a href="#privacy-'.h($item[0]).'">'.h($item[1]).'</a></li>';
    echo '</ol></nav><div class="terms-content"><div class="terms-intro"><strong>خلاصهٔ سریع</strong><p>اطلاعات لازم برای ارائهٔ خدمت را جمع‌آوری می‌کنیم، دسترسی را محدود نگه می‌داریم و اطلاعات ورود را به‌صورت قابل خواندن ذخیره نمی‌کنیم.</p><ul class="terms-points"><li>شماره موبایل شناسهٔ ورود و تأیید حساب است.</li><li>محتوای انجمن عمومی است؛ فایل‌های میزکار سروری خصوصی‌اند.</li><li>برای اصلاح یا حذف اطلاعات، از مسیر پشتیبانی درخواست بدهید.</li></ul></div>';
    foreach ($items as $index => $item) {
        echo '<section id="privacy-'.h($item[0]).'" class="terms-section" aria-labelledby="privacy-heading-'.h($item[0]).'"><div class="terms-section-heading"><span aria-hidden="true">'.fa(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)).'</span><h2 id="privacy-heading-'.h($item[0]).'">'.h($item[1]).'</h2></div><p>'.h($item[2]).'</p>'; terms_list($item[3]); echo '</section>';
    }
    echo '<div class="terms-contact"><div><h2>دربارهٔ اطلاعات حسابتان پرسشی دارید؟</h2><p>درخواست توضیح، اصلاح یا حذف اطلاعات را از مسیر امن پشتیبانی ارسال کنید.</p></div>'; linkto('contact','تماس با ما ←','btn btn-primary'); echo '</div></div></div></main>';
}
function page_member(int $id): void {
    $profile=skill_profile_for($id);
    if (!$profile) { page_not_found(); return; }
    $viewer=user();
    $own=$viewer && (int)$viewer['id']===$id;
    $description='پروفایل مهارتی '.$profile['name'].' در Noventix؛ '.fa($profile['level']['xp']).' XP و '.fa(count($profile['completions'])).' پروژه تکمیل‌شده.';
    head_page('پروفایل مهارتی '.$profile['name'],$description,false,!$own); notice();
    page_skill_profile($profile,$own,(bool)$viewer); foot_page();
}
function page_community(): void {
    global $me;
    $posts=q('SELECT p.id,p.title,p.body,p.created_at,p.user_id,u.name,u.phone FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 30')->fetchAll();
    $memberCount=(int)q('SELECT COUNT(*) FROM users WHERE phone_verified_at IS NOT NULL')->fetchColumn();
    $projectCount=(int)q('SELECT COUNT(*) FROM project_completions')->fetchColumn();
    head_page('انجمن','پرسش، تجربه و گفت‌وگو میان یادگیرندگان و متخصصان Noventix.'); notice(); echo '<main><header class="community-hero"><div class="container community-hero-grid"><div><span class="eyebrow">جامعهٔ Noventix</span><h1>یادگیری وقتی عمیق‌تر می‌شود که با دیگران ادامه پیدا کند.</h1><p class="lead">اینجا جایی برای پرسیدن سؤال، نشان‌دادن مسیر حل مسئله و پیدا کردن هم‌مسیرهای یادگیری است؛ محترمانه، کاربردی و نزدیک به پروژه.</p><div class="hero-actions">'; linkto('learners','دیدن پروفایل‌های مهارتی ←','btn btn-primary'); if (!$me) linkto('login','عضویت در جامعه','btn btn-outline'); echo '</div></div><div class="community-hero-card"><span class="about-card-mark">◇</span><strong>یک سؤال خوب، شروع یک مسیر است.</strong><p>قبل از پرسیدن، مسئله و خطایی را که دیده‌ای بنویس؛ پاسخ‌های دقیق‌تری می‌گیری و دیگران هم بهتر یاد می‌گیرند.</p><div class="about-mini-stats"><span><b>'.fa($memberCount).'</b> عضو تأییدشده</span><span><b>'.fa($projectCount).'</b> تکمیل پروژه</span></div></div></div></header><section class="section container"><div class="community-guidelines"><div><span class="eyebrow">راهنمای مشارکت</span><h2>چطور عضو مفیدتری برای جامعه باشیم؟</h2><p class="muted">جامعه زمانی ارزشمند است که هر پست، یا مسئله‌ای را روشن کند یا تجربه‌ای را به تجربهٔ دیگران اضافه کند.</p></div><div class="guideline-list"><article><span>۱</span><div><h3>مسئله را دقیق بنویس</h3><p>موضوع، تلاش‌های انجام‌شده و متن خطا را بدون اطلاعات حساس اضافه کن.</p></div></article><article><span>۲</span><div><h3>به تجربه‌ها احترام بگذار</h3><p>پاسخ را نقد کن، نه شخص را؛ اگر راه‌حل دیگری داری با دلیل توضیح بده.</p></div></article><article><span>۳</span><div><h3>نتیجه را برگردان</h3><p>اگر راه‌حل جواب داد یا نداد، نتیجه را بنویس تا پست برای نفر بعد هم مفید بماند.</p></div></article></div></div></section><section class="section community-feed-section"><div class="container"><div class="section-header"><span class="eyebrow">گفت‌وگوهای اخیر</span><h2>از تجربه‌های هم یاد می‌گیریم</h2><p>پرسش‌ها و پاسخ‌ها را دنبال کن یا اولین گفت‌وگوی خودت را شروع کن.</p></div>';
    if ($me) { echo '<div class="panel-card community-compose"><div><span class="eyebrow">شروع یک گفت‌وگو</span><h2>چه چیزی در مسیرت چالش‌برانگیز است؟</h2><p class="muted">سؤال واضح، زمینهٔ کافی و یک عنوان مشخص بنویس.</p></div>'; form_start('community/create','stack-form'); echo '<label>عنوان<input name="title" required minlength="5" maxlength="180" placeholder="مثلاً چرا مدل من روی دادهٔ جدید ضعیف است؟"></label><label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="5" placeholder="زمینه، تلاش‌های انجام‌شده و سؤال اصلی را بنویسید"></textarea></label><button class="btn btn-primary">ثبت در انجمن</button></form></div>'; } else { echo '<div class="community-join panel-card"><div><span class="eyebrow">عضویت رایگان</span><h2>برای پرسیدن و پاسخ‌دادن وارد شوید</h2><p class="muted">با یک حساب تأییدشده می‌توانی پست بسازی، به گفت‌وگوها پاسخ بدهی و پروفایل مهارتی داشته باشی.</p></div>'; linkto('login','ورود یا ثبت‌نام ←','btn btn-primary'); echo '</div>'; }
    echo '<div class="community-list">'; if (!$posts) echo '<div class="empty-state">هنوز گفت‌وگویی ثبت نشده است. اولین سؤال را شما شروع کنید.</div>';
    foreach ($posts as $p) { echo '<article class="post-card"><div class="meta-row"><span class="pill">'.h($p['created_at']).'</span><a class="profile-link" href="'.h(url('member/'.$p['user_id'])).'">'.h($p['name'] ?: mb_substr($p['phone'],0,4).'****').'</a>'.($me && (int)$p['user_id']!==(int)$me['id'] ? '<a class="text-button" href="'.h(url('member/'.$p['user_id'])).'">پروفایل مهارتی</a>' : '').'</div><h2>'.h($p['title']).'</h2><p>'.nl2br(h($p['body'])).'</p>'; $replies=q('SELECT r.body,r.created_at,u.name,u.phone FROM community_replies r JOIN users u ON u.id=r.user_id WHERE r.post_id=? ORDER BY r.id',[$p['id']])->fetchAll(); if ($replies) { echo '<div class="replies">'; foreach ($replies as $r) echo '<div class="reply"><strong>'.h($r['name'] ?: mb_substr($r['phone'],0,4).'****').'</strong><p>'.nl2br(h($r['body'])).'</p></div>'; echo '</div>'; } if ($me) { form_start('community/reply','reply-form'); echo '<input type="hidden" name="post_id" value="'.(int)$p['id'].'"><textarea name="body" required minlength="10" maxlength="5000" rows="3" placeholder="پاسخ شما"></textarea><button class="btn btn-outline">ارسال پاسخ</button></form>'; } echo '</article>'; } echo '</div></div></section></main>'; foot_page();
}
function page_help(): void {
    $topics = [
        ['account','حساب و ورود','ثبت‌نام با شماره موبایل، تأیید کد پیامکی، ورود با رمز و رفع خطاهای رایج حساب.','login'],
        ['learning','مسیر یادگیری','انتخاب دوره، ثبت پیشرفت درس‌ها، تکمیل تمرین و استفاده از پروفایل مهارتی برای دنبال‌کردن رشد.','courses'],
        ['workspace','میزکار کد','ساخت پروژه، افزودن فایل Python و CSV، اجرای کد و خواندن خروجی؛ همراه با نکات ایمنی برای داده‌ها.','projects'],
        ['community','انجمن و پیام‌ها','پرسیدن سؤال، پاسخ‌دادن، دیدن پروفایل مهارتی و شروع گفت‌وگو با اعضای پذیرفته‌شده.','community'],
        ['privacy','امنیت و حریم خصوصی','اطلاعاتی که نگهداری می‌شود، شیوهٔ محافظت از حساب و راه درخواست اصلاح یا حذف داده‌ها.','privacy'],
        ['support','پشتیبانی','اگر خطای فنی، مشکل پرداخت یا مسئله‌ای دربارهٔ حساب داری، درخواستت را با جزئیات ارسال کن.','dashboard/support'],
    ];
    echo '<main><header class="help-hero"><div class="container help-hero-inner"><span class="eyebrow">مرکز راهنمای Noventix</span><h1>برای هر قدم، یک راهنمای روشن</h1><p>از شروع حساب تا اجرای اولین پروژه، پاسخ‌های کاربردی را سریع پیدا کن. اگر جواب سؤال خودت را پیدا نکردی، تیم پشتیبانی و جامعهٔ یادگیرندگان کنار تو هستند.</p><div class="help-search-hint"><span aria-hidden="true">⌕</span><span>موضوعت را انتخاب کن یا از جست‌وجوی مرورگر برای پیدا کردن واژه استفاده کن.</span></div></div></header><section class="section container"><div class="help-topic-grid">';
    foreach ($topics as $topic) { echo '<article class="help-topic"><div class="help-topic-icon">'.h($topic[0][0]).'</div><div><span class="eyebrow">راهنمای '.h($topic[0]).'</span><h2>'.h($topic[1]).'</h2><p>'.h($topic[2]).'</p>'; linkto($topic[3],'مشاهدهٔ راهنما ←','inline-link'); echo '</div></article>'; }
    echo '</div></section><section class="section help-faq-section"><div class="container"><div class="section-header"><span class="eyebrow">سؤال‌های متداول</span><h2>پاسخ کوتاه به شروع‌های رایج</h2></div><div class="help-faq"><details open><summary>برای شروع یادگیری از کجا بروم؟</summary><p>اگر تازه شروع کرده‌ای، یک دورهٔ پایه را انتخاب کن و هم‌زمان یکی از پروژه‌های کوچک را در میزکار اجرا کن. هدف این است که هر جلسه به یک خروجی قابل مشاهده ختم شود.</p></details><details><summary>چرا پیشرفت من ذخیره نشده است؟</summary><p>ذخیرهٔ پیشرفت به حساب واردشده و ثبت موفق فعالیت وابسته است. یک‌بار از فعال‌بودن نشست مطمئن شو و اگر مشکل ادامه داشت، نام دوره، زمان و پیام خطا را برای پشتیبانی بفرست.</p></details><details><summary>آیا فایل‌های میزکار عمومی هستند؟</summary><p>فایل‌های میزکار سروری فقط برای مالک حساب در دسترس‌اند؛ اما برای امنیت، رمزها، کلیدها و دادهٔ شخصی دیگران را در هیچ پروژه‌ای قرار نده.</p></details><details><summary>چطور سؤال بهتری در انجمن بپرسم؟</summary><p>عنوان مشخص، هدف، کد کوتاه قابل اجرا، ورودی نمونه و متن کامل خطا را بنویس. اطلاعات حساس و شماره تماس را حذف کن.</p></details></div></div></section><section class="section container"><div class="help-contact"><div><span class="eyebrow">هنوز پاسخ را پیدا نکردی؟</span><h2>با جامعه یا پشتیبانی ادامه بده</h2><p>برای سؤال آموزشی به انجمن برو و برای مشکل حساب یا سرویس، درخواست پشتیبانی ثبت کن.</p></div><div class="hero-actions">'; linkto('community','رفتن به انجمن','btn btn-outline'); linkto('dashboard/support','ثبت درخواست پشتیبانی','btn btn-primary'); echo '</div></div></section></main>';
}
function page_login(): void {
    $existing=user();
    if ($existing && trim((string)$existing['name'])!=='') {
        head_page('ورود','مدیریت نشست و ورود یا ثبت‌نام دوباره در Noventix.'); notice();
        echo '<main class="auth-page"><div class="auth-card"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><h1>حساب شما فعال است</h1><p class="muted">برای ثبت‌نام با حساب دیگری، ابتدا به‌طور کامل خارج شوید.</p>';
        form_start('logout','stack-form'); echo '<button class="btn btn-outline" type="submit">خروج کامل از حساب</button></form>'; linkto('dashboard','بازگشت به پنل ←','btn btn-primary');
        echo '</div></main>'; foot_page(); return;
    }
    $captcha=captcha_code();
    $registration=$_SESSION['registration_form'] ?? [];
    unset($_SESSION['registration_form']);
    head_page('ورود','ورود یا ثبت‌نام با شماره موبایل در Noventix.'); notice();
    echo '<main class="auth-page"><div class="auth-card"><img src="/assets/nova.png" alt="مسکات Nova" width="70" height="70"><h1>ورود یا ثبت‌نام</h1><p class="muted">برای ساخت حساب، فرم ثبت‌نام را کامل کنید. ورودهای بعدی با شماره، رمز و کپچا انجام می‌شود.</p>';
    form_start('auth/request','stack-form',true); echo '<h2>ثبت‌نام</h2><label>نام<input name="first_name" required minlength="2" maxlength="50" autocomplete="given-name" value="'.h((string)($registration['first_name'] ?? '')).'" /></label><label>نام خانوادگی<input name="last_name" required minlength="2" maxlength="50" autocomplete="family-name" value="'.h((string)($registration['last_name'] ?? '')).'" /></label><label>شماره موبایل<input type="tel" dir="ltr" name="phone" required data-validation="phone" placeholder="09123456789 یا +989123456789" autocomplete="tel" value="'.h((string)($registration['phone'] ?? '')).'" /></label><label>رمز عبور<input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-validation="password"></label><p class="fine-print">رمز باید دست‌کم ۸ نویسه و شامل حرف انگلیسی و عدد باشد.</p><button class="btn btn-primary">ارسال کد تأیید</button></form>';
    echo '<hr><h2>ورود به حساب</h2>'; form_start('auth/login','stack-form',true); echo '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required data-validation="phone" placeholder="09123456789 یا +989123456789" autocomplete="username"></label><label>رمز عبور<input type="password" name="password" required autocomplete="current-password"></label><div class="captcha-box"><strong dir="ltr">'.h($captcha).'</strong><span>کد امنیتی را وارد کنید</span></div><label>کپچا<input name="captcha" required maxlength="5" pattern="[A-Za-z0-9]{5}" dir="ltr" autocomplete="off"></label><button class="btn btn-outline">ورود به پنل</button></form></div></main>'; foot_page();
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
