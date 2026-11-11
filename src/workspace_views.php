<?php
declare(strict_types=1);

function ws_assets(): void {
    echo '<link rel="stylesheet" href="/assets/workspace.css?v=11"><script src="/assets/workspace.js?v=7" defer></script>';
}
function ws_form(string $action): void {
    echo '<form action="/workspace/'.h($action).'" method="post" enctype="multipart/form-data" class="stack-form" data-ws-form><input type="hidden" name="csrf" value="'.h(csrf()).'">';
}
function ws_index(array $u): void {
    ws_assets();
    echo '<section class="panel-content ws-index"><div class="ws-heading"><div><h1>پروژه‌ها و میزکار مشترک پایتون</h1><p>فایل‌ها به‌صورت خصوصی در حساب شما ذخیره می‌شوند. اجرای کد فقط با سرویس ایزوله فعال می‌شود.</p></div><a class="btn btn-outline" href="/dashboard/challenges#weekly-challenge">چالش این هفته</a></div><p role="status" data-ws-message></p><div class="panel-card">';
    ws_form('create'); echo '<label>نام پروژه<input name="title" required maxlength="180" value="پروژه پایتون"></label><button class="btn btn-primary">ساخت پروژه پایتون</button></form></div><h2>پروژه‌های ذخیره‌شده</h2><div class="panel-list">';
    $rows=q('SELECT w.project_id,w.title,w.bytes,w.revision,w.deleted_at FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=? ORDER BY w.updated_at DESC',[$u['id']])->fetchAll();
    if (!$rows) echo '<p>هنوز پروژه ذخیره‌شده‌ای ندارید.</p>';
    foreach ($rows as $row) {
        echo '<article class="panel-card"><h3>'.h($row['title']).'</h3><p>'.fa($row['bytes']).' بایت · نسخه '.fa($row['revision']).'</p>';
        if ($row['deleted_at']===null) linkto('dashboard/workspace/'.$row['project_id'],'بازکردن میزکار ←','btn btn-outline');
        else { ws_form('restore'); echo '<input type="hidden" name="id" value="'.(int)$row['project_id'].'"><input type="hidden" name="revision" value="'.(int)$row['revision'].'"><p>در سطل بازیافت؛ همچنان جزو سهمیه است.</p><button class="btn btn-outline">بازیابی پروژه</button></form>'; }
        echo '</article>';
    }
    echo '</div><h2>تمرین‌های منتشرشده</h2><div class="panel-list">'; $found=false;
    foreach (q("SELECT id,title,instructions,course_id,lesson_id,status FROM workspace_templates WHERE status='published' ORDER BY id DESC LIMIT 100")->fetchAll() as $template) {
        if (!ws_template_allowed($template,(int)$u['id'])) continue;
        $found=true; echo '<article class="panel-card"><h3>'.h($template['title']).'</h3><p>'.nl2br(h($template['instructions'])).'</p>';
        ws_form('create'); echo '<input type="hidden" name="template_id" value="'.(int)$template['id'].'"><button class="btn btn-primary">شروع یا ادامه در میزکار مشترک</button></form></article>';
    }
    if (!$found) echo '<p>تمرین منتشرشده و قابل‌دسترسی موجود نیست. مدیر می‌تواند فایل‌های اولیه و دیتاست را در همین پنل اضافه کند.</p>';
    echo '</div><h2>اتصال پروژه‌های قبلی</h2>';
    foreach (q('SELECT p.id,p.catalog_index FROM projects p LEFT JOIN project_workspaces w ON w.project_id=p.id WHERE p.user_id=? AND w.project_id IS NULL AND p.catalog_index>=0',[$u['id']])->fetchAll() as $project) {
        if (!isset(LEGACY_PROJECTS[$project['catalog_index']])) continue;
        ws_form('legacy'); echo '<input type="hidden" name="id" value="'.(int)$project['id'].'"><p>'.h(LEGACY_PROJECTS[$project['catalog_index']]['title']).' — ایجاد فایل main.py خالی از منطق پروژه، بدون دیتاست ساختگی</p><button class="btn btn-outline">بازکردن میزکار</button></form>';
    }
    echo '</section>';
}
function ws_challenge_card(int $index,array $p,?array $workspace,bool $done): void {
    $state=$done ? 'done' : ($workspace ? 'active' : 'new');
    echo '<article class="ws-challenge-card is-'.$state.'" id="challenge-'.$index.'">';
    echo '<div class="ws-challenge-top"><span class="ws-challenge-index" aria-label="چالش '.fa($index+1).'">'.fa($index+1).'</span><span class="ws-challenge-topic">'.h($p['topic']).'</span>'.($done?'<span class="ws-challenge-badge">تکمیل‌شده</span>':'').'</div>';
    echo '<h3><a href="/project/'.$index.'">'.h($p['title']).'</a></h3>';
    echo '<div class="ws-challenge-meta-row"><span class="ws-challenge-time"><span class="ws-challenge-time-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 1.8"/></svg></span>'.fa((int)$p['minutes']).' دقیقه</span><span class="ws-reward-xp">'.fa((int)$p['xp']).' XP</span></div>';
    echo '<p class="ws-challenge-desc">'.h($p['description']).'</p>';
    echo '<p class="ws-challenge-goal"><span class="ws-challenge-goal-label">هدف</span>'.h($p['goal']).'</p>';
    echo '<a class="ws-challenge-guide-link" href="/project/'.$index.'">راهنمای کامل پروژه، ورودی و خروجی ←</a>';
    if ($workspace && $workspace['deleted_at']!==null) {
        ws_form('restore'); echo '<input type="hidden" name="id" value="'.(int)$workspace['project_id'].'"><input type="hidden" name="revision" value="'.(int)$workspace['revision'].'"><button class="btn btn-outline">بازیابی از سطل بازیافت</button></form>';
    } elseif ($workspace) echo '<a class="btn btn-primary" href="/dashboard/workspace/'.(int)$workspace['project_id'].'">ادامه در میزکار</a>';
    else { ws_form('create'); echo '<input type="hidden" name="challenge_index" value="'.$index.'"><button class="btn btn-primary">شروع در میزکار</button></form>'; }
    echo '</article>';
}
function ws_challenges(array $u): void {
    ws_assets();
    $userId=(int)$u['id'];
    $weekly=lp_weekly_challenge();
    $workspaces=[];
    foreach (q('SELECT p.catalog_index,w.project_id,w.deleted_at,w.revision FROM projects p JOIN project_workspaces w ON w.project_id=p.id WHERE p.user_id=? AND p.catalog_index>=100',[$userId])->fetchAll() as $row) $workspaces[(int)$row['catalog_index']-100]=$row;
    $completed=q('SELECT learning_key FROM project_completions WHERE user_id=?',[$userId])->fetchAll(PDO::FETCH_COLUMN);
    $doneCount=count($completed);
    $totalXp=lp_xp($userId);
    echo '<section class="ws-challenges"><div class="ws-heading"><div><span class="eyebrow">تمرین، اجرا، پیشرفت</span><h1>چالش‌های پایتون و داده</h1><p>هر چالش با هدف، ورودی، خروجی و معیار موفقیت روشن آمده است. با ارزیابی موفق XP می‌گیری.</p></div><a class="btn btn-outline" href="/dashboard/workspace">میزکار من</a></div><p role="status" aria-live="polite" data-ws-message></p>';
    $p=PROJECTS[$weekly['index']];
    echo '<article class="ws-weekly" id="weekly-challenge"><div><span class="ws-weekly-label">چالش هفتگی</span><h2>'.h($p['title']).'</h2><p>'.h($p['goal']).'</p><p class="ws-weekly-dates">از '.h(jdate($weekly['start'])).' تا '.h(jdate(gmdate('Y-m-d H:i:s',strtotime($weekly['end'].' UTC')-1))).' · تعویض دوشنبه، ساعت ۰۰:۰۰ UTC</p></div><div class="ws-weekly-action"><span>'.fa((int)$p['xp']).' XP با ارزیابی موفق</span><a class="btn btn-primary" href="#challenge-'.$weekly['index'].'">مشاهده چالش این هفته</a></div></article>';
    echo '<div class="ws-section-heading"><h2>همهٔ چالش‌ها</h2><span>'.fa(count(PROJECTS)).' تمرین قابل اجرا</span></div><p class="muted">چالش هفتگی از همین مجموعه انتخاب می‌شود. امتیاز هر مسئله فقط یک بار ثبت می‌شود؛ شروع یا اجرای عادی جایزه ندارد و پایان هفته دسترسی تمرین را نمی‌بندد.</p><div class="ws-challenge-grid">';
    foreach (PROJECTS as $index=>$p) ws_challenge_card((int)$index,$p,$workspaces[$index] ?? null,in_array($p['key'],$completed,true));
    echo '</div></section>';
}
function ws_editor(array $u,int $id): void {
    $row=ws_owned((int)$u['id'],$id); $definition=lp_definition((int)$row['catalog_index']); ws_assets();
    echo '<main class="panel-content ws-editor" data-ws-project="'.$id.'" data-csrf="'.h(csrf()).'"><div class="panel-heading ws-heading"><div><span class="eyebrow" lang="en" dir="ltr">NOVENTIX / PYTHON STUDIO</span><h1>'.h($row['title']).'</h1><p>بنویس، اجرا کن و بساز؛ کد در محیط ایزولهٔ سرور خودمان اجرا می‌شود.</p></div><a class="btn btn-outline" href="/dashboard/workspace">پروژه‌های من</a></div><div class="ws-limits"><span>ذخیرهٔ خصوصی</span><span>۵ مگابایت در هر پروژه</span><span>۲۰ مگابایت در حساب</span><span>اجرای محدود و بدون شبکه</span></div>';
    if ($row['template_id']) {
        $instructions=q('SELECT instructions FROM workspace_templates WHERE id=?',[$row['template_id']])->fetchColumn();
        echo '<details class="panel-card"><summary>راهنمای تمرین</summary><p>'.nl2br(h((string)$instructions)).'</p></details>';
    }
    echo '<p role="status" aria-live="polite" data-ws-message>در حال بارگذاری پروژه…</p><nav class="ws-quick-nav" aria-label="ابزارهای میزکار"><a href="#ws-code">ویرایشگر کد</a><a href="#ws-datasets-title">کتابخانهٔ دیتاست</a><a href="/dashboard/challenges">همهٔ چالش‌ها</a><a href="/dashboard/challenges#weekly-challenge">چالش هفتگی</a></nav>';
    if ($definition) {
        echo '<details class="panel-card ws-challenge-guide" open><summary>راهنمای چالش · '.h($definition['title']).'</summary><p>'.h($definition['goal']).'</p><h3>معیارهای موفقیت</h3><ul>';
        foreach ($definition['criteria'] as $criterion) echo '<li>'.h($criterion).'</li>';
        echo '</ul><p>پس از پیاده‌سازی در main.py، «ارزیابی چالش» را بزنید. '.fa($definition['xp']).' XP فقط برای اولین ارزیابی موفق ثبت می‌شود.</p><a class="ws-text-link" href="/project/'.((int)$row['catalog_index']-100).'">راهنمای کامل و مثال ورودی و خروجی</a></details>';
    }
    echo '<details class="panel-card ws-datasets"><summary id="ws-datasets-title"><span class="ws-dataset-heading"><span class="eyebrow">DATA LIBRARY</span><span>کتابخانهٔ دیتاست</span><small>جست‌وجو، بررسی و افزودن داده به پروژه</small></span></summary><div class="ws-dataset-body"><form data-hf-search class="ws-dataset-search"><label for="ws-dataset-query">چه داده‌ای نیاز داری؟<input id="ws-dataset-query" data-hf-query minlength="2" maxlength="80" required placeholder="مثلاً iris یا sales" dir="auto"></label><button class="btn btn-primary" type="submit">جست‌وجوی دیتاست</button></form><div class="ws-dataset-hints"><span>منابع عمومی Hugging Face</span><span>CSV · JSON · TXT</span><span>بدون نیاز به توکن</span></div><details class="ws-dataset-policy"><summary>سهمیه و شرایط دریافت</summary><p>هر فایل حداکثر ۱ مگابایت؛ هر دریافت حداکثر ۱۰ فایل و ۲ مگابایت. دیتاست خصوصی، نیازمند تأیید دسترسی، آرشیو و انتقال به CDN خارجی پشتیبانی نمی‌شود.</p></details><p role="status" aria-live="polite" data-hf-message>نام یا موضوع دیتاست را جست‌وجو کن؛ قبل از دریافت می‌توانی فایل‌ها و مجوز را بررسی کنی.</p><div data-hf-results class="ws-dataset-results"></div><section data-hf-detail hidden aria-label="جزئیات دیتاست"></section><div class="ws-section-heading"><h3>داده‌های این پروژه</h3><button class="btn btn-outline" type="button" data-hf-refresh>به‌روزرسانی وضعیت</button></div><div data-hf-imports></div></div></details>';
    echo '<div class="ws-layout"><aside class="panel-card ws-explorer"><div class="ws-explorer-heading"><div><span class="ws-micro-label" lang="en">EXPLORER</span><h2>فایل‌های پروژه</h2></div><span data-ws-file-count class="ws-count">۰</span></div><div class="ws-toolbar"><button data-ws-action="new-file" disabled>فایل جدید</button><button data-ws-action="new-folder" disabled>پوشه جدید</button></div><label>مسیر جدید<input data-ws-new-path dir="ltr" placeholder="data/example.csv" maxlength="160"></label><div data-ws-tree class="ws-tree"></div><button data-ws-action="rename" disabled>تغییر نام یا جابه‌جایی به مسیر جدید</button><button data-ws-action="remove-file" disabled>حذف فایل یا پوشه انتخاب‌شده</button><a data-ws-download hidden>دانلود نسخه ذخیره‌شده</a><details class="ws-upload-panel" open><summary>افزودن فایل به پروژه</summary><p>فایل داده، کد یا مجموعهٔ ZIP را انتخاب کن.</p><label class="ws-upload-picker">انتخاب فایل‌ها<input data-ws-upload type="file" multiple accept=".py,.csv,.json,.txt,.md,.png,.joblib,.pkl,.npy,.npz"></label><label class="ws-upload-picker">مجموعهٔ فشرده ZIP<input data-ws-zip type="file" accept=".zip"></label><ul class="ws-upload-list" data-ws-upload-list aria-live="polite"></ul><p class="ws-upload-note" data-ws-upload-note>هر فایل حداکثر ۱ مگابایت؛ کل پروژه ۵ مگابایت.</p><button class="ws-upload-submit" data-ws-action="upload" disabled>بارگذاری فایل‌های انتخابی</button></details></aside><section class="panel-card ws-code-panel"><div class="ws-editor-topbar"><div class="ws-file-tab"><span class="ws-file-indicator" aria-hidden="true"></span><h2 data-ws-filename>فایل را انتخاب کنید</h2></div></div><div class="ws-editor-options"><label>فایل اجرایی<input data-ws-entry value="main.py" dir="ltr" maxlength="160"></label><button class="ws-control" data-ws-action="reload" disabled>بازکردن نسخهٔ سرور</button>'.($definition?'<button class="ws-control ws-assess-button" data-ws-action="assess" disabled>ارزیابی چالش</button>':'').'</div><label class="sr-only" for="ws-code">ویرایشگر کد و متن فایل</label><div class="ws-code-wrap" data-ws-code-wrap dir="ltr"><pre aria-hidden="true" data-ws-lines>1</pre><textarea id="ws-code" data-ws-code spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" wrap="off" disabled></textarea></div><div class="ws-image-preview" data-ws-image-preview hidden><img data-ws-image alt="پیش‌نمایش تصویر انتخاب‌شده از پروژه"><p>تصویر ذخیره‌شدهٔ پروژه؛ برای دریافت نسخهٔ اصلی از لینک دانلود استفاده کن.</p></div><div class="ws-editor-status"><span data-ws-editor-status>Python · UTF-8</span><span>Tab: 4 · Ctrl/Cmd+S</span></div><div class="ws-editor-actions"><button class="ws-control" data-ws-action="save" disabled>ذخیره</button><button class="ws-run-button" data-ws-action="run" disabled><span aria-hidden="true">▷</span> اجرای Python</button><button class="ws-control ws-stop-button" data-ws-action="cancel" disabled>توقف</button></div></section></div><section class="panel-card ws-console"><div class="ws-console-heading"><div><span class="ws-micro-label" lang="en">RUN & RESULTS</span><h2>کنسول و نتایج اجرا</h2></div><div class="ws-console-actions"><span data-ws-run-state role="status">اجرا نشده</span><button class="ws-control" data-ws-action="apply" disabled>ذخیره فایل‌های خروجی</button></div></div><p data-ws-runtime>در حال بررسی اتصال به موتور Python…</p><div class="ws-console-tabs" role="tablist" aria-label="نتایج و ابزارهای اجرا"><button type="button" id="ws-tab-output" role="tab" aria-selected="true" aria-controls="ws-pane-output" data-ws-tab="output">خروجی و خطا</button><button type="button" id="ws-tab-plots" role="tab" aria-selected="false" aria-controls="ws-pane-plots" tabindex="-1" data-ws-tab="plots">نمودارها <span data-ws-plot-count>۰</span></button><button type="button" id="ws-tab-terminal" role="tab" aria-selected="false" aria-controls="ws-pane-terminal" tabindex="-1" data-ws-tab="terminal">ترمینال Python</button></div><section id="ws-pane-output" role="tabpanel" aria-labelledby="ws-tab-output" data-ws-pane="output"><pre class="ws-output" data-ws-output dir="ltr" tabindex="0" aria-label="خروجی استاندارد اجرا"></pre><div class="ws-error-output" data-ws-error-panel hidden><h3>خطا و پیام‌های تشخیصی</h3><pre data-ws-errors dir="ltr" tabindex="0"></pre></div></section><section id="ws-pane-plots" role="tabpanel" aria-labelledby="ws-tab-plots" data-ws-pane="plots" hidden><p class="ws-plots-help">نمودارهای باز Matplotlib و Seaborn در پایان اجرا خودکار نمایش داده می‌شوند. برای نگهداری در پروژه، «ذخیره فایل‌های خروجی» را بزن.</p><div class="ws-plot-grid" data-ws-plots><p class="ws-plots-empty">هنوز نموداری اجرا نشده است؛ با plt.show() یا plt.savefig() شروع کن.</p></div></section><section class="ws-assessment" data-ws-assessment hidden aria-live="polite"><h3 data-ws-assessment-title>نتیجهٔ ارزیابی</h3><ul data-ws-checks></ul></section><div class="ws-terminal" id="ws-pane-terminal" role="tabpanel" aria-labelledby="ws-tab-terminal" data-ws-pane="terminal" hidden><div class="ws-console-heading"><h2>ترمینال Python</h2><button type="button" data-ws-terminal-clear>پاک‌کردن نمایش</button></div><p id="ws-terminal-help">فرمان‌ها داخل محیط ایزوله و از ریشهٔ پروژه اجرا می‌شوند؛ این ترمینال دسترسی به سیستم‌عامل سرور ندارد. برای راهنما help را بزنید. تاریخچه با کلیدهای بالا و پایین.</p><pre class="ws-terminal-log" data-ws-terminal-log dir="ltr" tabindex="0" aria-label="تاریخچه ترمینال">Noventix Python Terminal — help</pre><form data-ws-terminal-form><label class="sr-only" for="ws-command">فرمان ترمینال</label><span aria-hidden="true" dir="ltr">/workspace $</span><input id="ws-command" data-ws-command dir="ltr" maxlength="2000" autocomplete="off" spellcheck="false" placeholder="python main.py" aria-describedby="ws-terminal-help" disabled><button type="submit" data-ws-terminal-submit disabled>اجرا</button></form></div></section><details class="panel-card"><summary>حذف پروژه</summary><p>حذف به سطل بازیافت منتقل می‌کند؛ بازیابی از فهرست پروژه‌ها ممکن است.</p><button data-ws-action="delete" disabled>انتقال پروژه به سطل بازیافت</button></details></main>';
}
function ws_lesson_exercises(int $userId,int $lessonId): void {
    $templates=q("SELECT id,title,instructions,course_id,lesson_id,status FROM workspace_templates WHERE lesson_id=? AND status='published' LIMIT 100",[$lessonId])->fetchAll();
    if (!$templates) return;
    ws_assets(); echo '<section class="panel-card"><h2>تمرین‌های کدنویسی این درس</h2><p data-ws-message role="status"></p>';
    foreach ($templates as $template) {
        if (!ws_template_allowed($template,$userId)) continue;
        ws_form('create'); echo '<input type="hidden" name="template_id" value="'.(int)$template['id'].'"><p>'.h($template['title']).'</p><button class="btn btn-primary">شروع یا ادامه تمرین</button></form>';
    }
    echo '</section>';
}
function ws_admin(): void {
    ws_assets(); $id=(int)($_GET['edit'] ?? 0); $template=$id?q('SELECT * FROM workspace_templates WHERE id=?',[$id])->fetch():false;
    echo '<main class="panel-content"><h1>مدیریت تمرین‌های پایتون</h1><p>این محتوا در میزکار مشترک باز می‌شود. به‌روزرسانی الگو فایل‌های کاربران را بازنویسی نمی‌کند. فایل‌های هم‌نام بارگذاری‌شده جایگزین نسخه الگو می‌شوند.</p><p role="status" data-ws-message></p><section class="panel-card">';
    ws_form('admin-template-save');
    echo '<input type="hidden" name="id" value="'.(int)($template['id'] ?? 0).'"><input type="hidden" name="revision" value="'.(int)($template['revision'] ?? 0).'"><label>عنوان<input name="title" required maxlength="180" value="'.h($template['title'] ?? '').'"></label><label>توضیحات و دستورالعمل<textarea name="instructions" maxlength="20000">'.h($template['instructions'] ?? '').'</textarea></label><label>وضعیت<select name="status"><option value="draft">پیش‌نویس</option><option value="published"'.(($template['status'] ?? '')==='published'?' selected':'').'>منتشرشده</option></select></label><label>دوره مرتبط<select name="course_id"><option value="">بدون دوره</option>';
    foreach (q('SELECT id,title FROM courses ORDER BY id')->fetchAll() as $course) echo '<option value="'.(int)$course['id'].'"'.((int)($template['course_id'] ?? 0)===(int)$course['id']?' selected':'').'>'.h($course['title']).'</option>';
    echo '</select></label><label>درس مرتبط<select name="lesson_id"><option value="">بدون درس</option>';
    foreach (q('SELECT id,title,course_id FROM lessons ORDER BY course_id,position')->fetchAll() as $lesson) echo '<option value="'.(int)$lesson['id'].'"'.((int)($template['lesson_id'] ?? 0)===(int)$lesson['id']?' selected':'').'>'.h($lesson['title']).' — Course '.(int)$lesson['course_id'].'</option>';
    echo '</select></label><label>پروژه کاتالوگ<select name="catalog_index"><option value="">تمرین مستقل</option>';
    foreach (PROJECTS as $i=>$project) echo '<option value="'.$i.'"'.($template && $template['catalog_index']!==null && (int)$template['catalog_index']===$i?' selected':'').'>'.h($project['title']).'</option>';
    echo '</select></label><label>وابستگی‌های مجاز requirements.txt<textarea name="requirements" dir="ltr" placeholder="numpy==2.2.3"></textarea></label><p>نصب اینترنتی در زمان اجرا مجاز نیست. فقط نسخه‌های ازپیش‌تأییدشدهٔ تصویر اجرایی پشتیبانی می‌شوند.</p><label>فایل‌های اولیه و دیتاست<input type="file" name="files[]" multiple></label><label>ZIP مجموعه فایل‌ها<input type="file" name="archive" accept=".zip"></label><button class="btn btn-primary">ذخیره تمرین</button></form></section><section class="panel-list">';
    foreach (q('SELECT id,title,status,revision,bytes FROM workspace_templates ORDER BY id DESC')->fetchAll() as $row) { echo '<article class="panel-card"><h2>'.h($row['title']).'</h2><p>'.h($row['status']).' · نسخه '.fa($row['revision']).' · '.fa($row['bytes']).' بایت</p>'; linkto('dashboard/workspace-templates?edit='.$row['id'],'ویرایش تمرین ←','btn btn-outline'); echo '</article>'; }
    echo '</section></main>';
}
