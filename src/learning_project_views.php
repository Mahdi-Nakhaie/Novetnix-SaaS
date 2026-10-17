<?php
declare(strict_types=1);

function lp_progress_view(int $userId): void {
    panel_head('امتیاز و پیشرفت واقعی','XP فقط پس از ارزیابی موفق پروژه ثبت می‌شود؛ شروع چالش یا اجرای عادی جایزه ندارد.');
    $rows=q('SELECT learning_key,xp,completed_at FROM project_completions WHERE user_id=? ORDER BY completed_at DESC',[$userId])->fetchAll();
    echo '<div class="stat-grid"><div class="stat-card"><span>XP دریافت‌شده</span><strong>'.fa(lp_xp($userId)).'</strong></div><div class="stat-card"><span>پروژه‌های تکمیل‌شده</span><strong>'.fa(count($rows)).' از '.fa(count(PROJECTS)).'</strong></div></div><div class="panel-list">';
    if (!$rows) echo '<p class="empty-state">هنوز پروژه‌ای با ارزیابی معتبر تکمیل نشده است.</p>';
    foreach ($rows as $row) foreach (PROJECTS as $index=>$definition) if ($definition['key']===$row['learning_key']) echo '<article class="panel-card"><h2><a href="'.h(url('project/'.$index)).'">'.h($definition['title']).'</a></h2><p>'.fa($row['xp']).' XP · '.h(jdate($row['completed_at'])).'</p></article>';
    echo '</div>';
}

function lp_page(int $index): void {
    global $me;
    $p=PROJECTS[$index];
    head_page($p['title'],$p['description']); notice();
    echo '<main class="article"><div class="container narrow-container"><span class="eyebrow">'.h($p['topic']).' · '.h($p['level']).'</span><h1>'.h($p['title']).'</h1><p class="lead">'.h($p['description']).'</p><p>'.fa($p['minutes']).' دقیقه · '.fa($p['xp']).' XP · '.h($p['skills']).'</p><section class="panel-card"><h2>هدف و راهنمای انجام</h2><p>'.h($p['goal']).'</p><h3>ورودی</h3><p>'.h($p['input']).'</p><h3>خروجی</h3><p>'.h($p['output']).'</p><ol>';
    foreach ($p['steps'] as $step) echo '<li>'.h($step).'</li>';
    echo '</ol><p>'.h($p['notes']).'</p><h3>مثال ورودی و خروجی</h3><pre class="lp-output" dir="ltr">'.h(json_encode($p['example'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><h3>معیارهای موفقیت</h3><ul>';
    foreach ($p['criteria'] as $criterion) echo '<li>'.h($criterion).'</li>';
    echo '</ul><p>کتابخانه‌ها: '.h(implode(', ',$p['dependencies']) ?: 'json, pathlib').'</p></section>';
    if ($p['datasets']) {
        echo '<details class="panel-card"><summary>منبع و وضعیت دیتاست‌ها</summary>';
        $sources=json_decode(file_get_contents(dirname(__DIR__).'/curriculum/data/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($sources as $source) if (in_array($source['file'],$p['datasets'],true)) echo '<p dir="auto">'.h($source['file'].' · '.$source['license'].' · '.$source['attribution']).'</p><p dir="ltr">'.h($source['source']).'</p><p>نسخه محلی همراه پروژه؛ '.fa($source['rows']).' ردیف. هنگام اجرا دانلود خارجی لازم نیست.</p>';
        echo '</details>';
    }
    $row=$me?q('SELECT w.* FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=? AND p.catalog_index=? AND w.deleted_at IS NULL',[$me['id'],100+$index])->fetch():false;
    if (!$row) {
        if ($me) { form_start('projects/start'); echo '<input type="hidden" name="index" value="'.$index.'"><button class="btn btn-primary">شروع پروژه و ذخیره فایل‌های اولیه</button></form>'; }
        else linkto('login','برای نوشتن و اجرای کد وارد شوید','btn btn-primary');
    } else {
        $completion=lp_completion((int)$me['id'],$p['key']);
        echo '<link rel="stylesheet" href="/assets/learning-projects.css"><script src="/assets/learning-projects.js" defer></script><section class="panel-card lp-editor" data-lp-id="'.(int)$row['project_id'].'" data-csrf="'.h(csrf()).'"><h2>محیط ساده Python</h2><p data-lp-completion>'.($completion?'تکمیل‌شده · '.fa($completion['xp']).' XP · '.h($completion['completed_at']):'برای دریافت XP، همین نسخه ذخیره‌شده باید تمام معیارهای ارزیابی را بگذراند.').'</p><p>اجرا فقط خروجی برنامه را نشان می‌دهد؛ ارزیابی جداگانه و با ورودی‌های تازه انجام می‌شود. تغییر کد، اعتبار ارزیابی نسخه قبلی را به نسخه جدید منتقل نمی‌کند.</p><p role="status" aria-live="polite" data-lp-status>در حال بارگذاری…</p><label>فایل<select data-lp-file aria-label="انتخاب فایل"></select></label><label for="lp-code">کد یا ورودی نمونه</label><textarea id="lp-code" dir="ltr" spellcheck="false" rows="20" data-lp-code disabled></textarea><div class="lp-toolbar"><button type="button" class="btn btn-outline" data-lp-action="save" disabled>ذخیره</button><button type="button" class="btn btn-primary" data-lp-action="run" disabled>اجرای واقعی</button><button type="button" class="btn btn-primary" data-lp-action="assess" disabled>ارزیابی و دریافت XP</button><button type="button" class="btn btn-outline" data-lp-action="solution" disabled>نمایش راه‌حل مرجع</button></div><h3>خروجی و خطا</h3><pre class="lp-output" dir="ltr" data-lp-output>اجرا نشده است.</pre><div data-lp-checks></div><div data-lp-artifacts></div><details data-lp-reference hidden><summary>راه‌حل مرجع مستقل از کد شما</summary><pre class="lp-output" dir="ltr" data-lp-solution></pre></details><p>راه‌حل پس از یک تلاش اجرایی واقعی قابل مشاهده است؛ بازکردن آن کد شما را تغییر نمی‌دهد. کد تغییرکرده ذخیره‌نشده، با بستن صفحه از دست می‌رود.</p></section>';
    }
    echo '</div></main>'; foot_page();
}
