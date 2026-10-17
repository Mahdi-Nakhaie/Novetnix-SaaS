<?php
declare(strict_types=1);

function ws_assets(): void {
    echo '<link rel="stylesheet" href="/assets/workspace.css"><script src="/assets/workspace.js" defer></script>';
}
function ws_form(string $action): void {
    echo '<form action="/workspace/'.h($action).'" method="post" enctype="multipart/form-data" class="stack-form" data-ws-form><input type="hidden" name="csrf" value="'.h(csrf()).'">';
}
function ws_index(array $u): void {
    ws_assets();
    echo '<section class="panel-content ws-index"><h1>پروژه‌ها و میزکار مشترک پایتون</h1><p>فایل‌ها به‌صورت خصوصی در حساب شما ذخیره می‌شوند. اجرای کد فقط با سرویس ایزوله فعال می‌شود.</p><p role="status" data-ws-message></p><div class="panel-card">';
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
function ws_editor(array $u,int $id): void {
    $row=ws_owned((int)$u['id'],$id); ws_assets();
    echo '<main class="panel-content ws-editor" data-ws-project="'.$id.'" data-csrf="'.h(csrf()).'"><div class="panel-heading"><h1>'.h($row['title']).'</h1><p>ذخیره خصوصی و نسخه‌دار؛ خروجی اجرا هیچ‌گاه خودکار روی تغییرات جدید شما نوشته نمی‌شود.</p></div>';
    if ($row['template_id']) {
        $instructions=q('SELECT instructions FROM workspace_templates WHERE id=?',[$row['template_id']])->fetchColumn();
        echo '<details class="panel-card"><summary>راهنمای تمرین</summary><p>'.nl2br(h((string)$instructions)).'</p></details>';
    }
    echo '<p role="status" aria-live="polite" data-ws-message>در حال بارگذاری پروژه…</p><div class="ws-toolbar"><button class="btn btn-primary" data-ws-action="save" disabled>ذخیره تغییرات</button><button class="btn btn-outline" data-ws-action="reload" disabled>بازکردن نسخه سرور</button><label>فایل اجرایی<input data-ws-entry value="main.py" dir="ltr" maxlength="160"></label><button class="btn btn-primary" data-ws-action="run" disabled>اجرای Python</button><button class="btn btn-outline" data-ws-action="cancel" disabled>توقف اجرا</button><button class="btn btn-outline" data-ws-action="apply" disabled>ذخیره فایل‌های خروجی اجرا</button></div>';
    echo '<section class="panel-card ws-datasets" aria-labelledby="ws-datasets-title"><h2 id="ws-datasets-title">Datasets · دیتاست‌های Hugging Face</h2><p>فقط منابع عمومی بدون توکن. هر فایل حداکثر ۱ MiB، هر دریافت حداکثر ۱۰ فایل و ۲ MiB. قالب CSV/JSON/TXT؛ دیتاست خصوصی، gated، آرشیو و انتقال به CDN خارجی در MVP پشتیبانی نمی‌شود.</p><form data-hf-search class="ws-toolbar"><label>جست‌وجوی دیتاست عمومی<input data-hf-query minlength="2" maxlength="80" required placeholder="iris" dir="auto"></label><button type="submit">جست‌وجو</button></form><p role="status" aria-live="polite" data-hf-message></p><div data-hf-results class="ws-dataset-results"></div><section data-hf-detail hidden></section><h3>دریافت‌های این پروژه</h3><button type="button" data-hf-refresh>تازه‌سازی وضعیت دریافت‌ها</button><div data-hf-imports></div></section>';
    echo '<div class="ws-layout"><aside class="panel-card"><h2>فایل‌ها و پوشه‌ها</h2><div class="ws-toolbar"><button data-ws-action="new-file" disabled>فایل جدید</button><button data-ws-action="new-folder" disabled>پوشه جدید</button></div><label>مسیر جدید<input data-ws-new-path dir="ltr" placeholder="data/example.csv" maxlength="160"></label><div data-ws-tree class="ws-tree"></div><button data-ws-action="rename" disabled>تغییر نام یا جابه‌جایی به مسیر جدید</button><button data-ws-action="remove-file" disabled>حذف فایل یا پوشه انتخاب‌شده</button><a data-ws-download hidden>دانلود نسخه ذخیره‌شده</a><label>بارگذاری فایل<input data-ws-upload type="file" multiple accept=".py,.csv,.json,.txt,.md,.png,.joblib,.pkl,.npy,.npz"></label><label>واردکردن ZIP<input data-ws-zip type="file" accept=".zip"></label><button data-ws-action="upload" disabled>ذخیره و بارگذاری</button></aside><section class="panel-card ws-code-panel"><h2 data-ws-filename>فایل را انتخاب کنید</h2><label class="sr-only" for="ws-code">ویرایشگر کد و متن فایل</label><div class="ws-code-wrap" dir="ltr"><pre aria-hidden="true" data-ws-lines>1</pre><textarea id="ws-code" data-ws-code spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" wrap="off" disabled></textarea></div><p>Tab چهار فاصله وارد می‌کند؛ Ctrl/Cmd+S ذخیره می‌کند. فایل‌های باینری فقط دانلود می‌شوند.</p></section></div><section class="panel-card"><h2>خروجی واقعی و Traceback</h2><p data-ws-runtime>Python و نسخه بسته‌ها پس از تأیید سلامت موتور نمایش داده می‌شوند.</p><p data-ws-run-state>اجرا نشده</p><pre class="ws-output" data-ws-output dir="ltr"></pre></section><details class="panel-card"><summary>حذف پروژه</summary><p>حذف به سطل بازیافت منتقل می‌کند؛ بازیابی از فهرست پروژه‌ها ممکن است.</p><button data-ws-action="delete" disabled>انتقال پروژه به سطل بازیافت</button></details></main>';
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
