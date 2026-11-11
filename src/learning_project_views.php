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
    $guidance=json_decode(file_get_contents(dirname(__DIR__).'/curriculum/project-guidance.json'),true,512,JSON_THROW_ON_ERROR)[$p['key']];
    $row=$me?q('SELECT w.* FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=? AND p.catalog_index=? AND w.deleted_at IS NULL',[$me['id'],100+$index])->fetch():false;
    $completion=$me?lp_completion((int)$me['id'],$p['key']):false;
    $sources=[];
    if ($p['datasets']) {
        $manifest=json_decode(file_get_contents(dirname(__DIR__).'/curriculum/data/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($manifest as $source) if (in_array($source['file'],$p['datasets'],true)) $sources[]=$source;
    }
    head_page($p['title'],$p['description'],false,true); notice();
    echo '<link rel="stylesheet" href="/assets/learning-projects.css?v=4"><main class="lp-page lp-detail">';
    echo '<section class="lp-hero"><div class="container lp-hero-inner"><div class="lp-hero-copy"><nav class="lp-breadcrumb" aria-label="مسیر صفحه">';
    linkto('projects','پروژه‌ها'); echo '<span aria-hidden="true">/</span><span aria-current="page">'.h($p['title']).'</span></nav>';
    echo '<div class="lp-tags"><span class="lp-tag is-topic" dir="ltr">'.h($p['topic']).'</span><span class="lp-tag">'.h($p['level']).'</span>'.($completion?'<span class="lp-tag is-done">تکمیل‌شده</span>':'').'</div>';
    echo '<h1>'.h($p['title']).'</h1><p class="lp-lead">'.h($p['description']).'</p><div class="lp-hero-actions">';
    if ($completion) linkto('dashboard/workspace/'.(int)$row['project_id'],'بازکردن در میزکار ←','btn btn-primary');
    elseif ($row) linkto('dashboard/workspace/'.(int)$row['project_id'],'ادامه در میزکار ←','btn btn-primary');
    elseif ($me) { form_start('projects/start','lp-start-form'); echo '<input type="hidden" name="index" value="'.$index.'"><button class="btn btn-primary">شروع پروژه و ذخیرهٔ فایل‌های اولیه</button></form>'; }
    else linkto('login','برای نوشتن و اجرای کد وارد شوید','btn btn-primary');
    linkto('dashboard/workspace','میزکار پایتون','btn btn-outline');
    echo '</div></div><dl class="lp-hero-facts"><div><dt>زمان تخمینی</dt><dd>'.fa((int)$p['minutes']).' دقیقه</dd></div><div><dt>پاداش</dt><dd>'.fa((int)$p['xp']).' XP</dd></div><div><dt>دیتاست</dt><dd>'.fa(count($p['datasets'])).' فایل</dd></div><div><dt>مراحل</dt><dd>'.fa(count($p['steps'])).' گام</dd></div></dl></div></section>';
    echo '<div class="container lp-detail-grid"><div class="lp-detail-main">';
    echo '<section class="lp-block"><h2>هدف این پروژه</h2><p class="lp-goal">'.h($p['goal']).'</p><div class="lp-skills"><span class="lp-block-label">مهارت‌هایی که تمرین می‌کنی</span><ul>';
    foreach (explode('·',$p['skills']) as $skill) { $skill=trim($skill); if ($skill!=='') echo '<li dir="ltr">'.h($skill).'</li>'; }
    echo '</ul></div></section>';
    echo '<section class="lp-block"><h2>مسیر انجام پروژه</h2><ol class="lp-steps">';
    foreach ($p['steps'] as $stepIndex=>$step) echo '<li><span class="lp-step-num">'.fa($stepIndex+1).'</span><p>'.h($step).'</p></li>';
    echo '</ol></section>';
    echo '<section class="lp-block"><h2>ورودی و خروجی</h2><div class="lp-io"><article class="lp-io-card"><span class="lp-block-label">ورودی</span><p>'.h($p['input']).'</p></article><article class="lp-io-card"><span class="lp-block-label">خروجی</span><p>'.h($p['output']).'</p></article></div><p class="lp-note">'.h($p['notes']).'</p><details class="lp-example"><summary>نمونهٔ ورودی و خروجی</summary><pre dir="ltr">'.h(json_encode($p['example'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></section>';
    echo '<section class="lp-block"><h2>معیارهای موفقیت</h2><ul class="lp-criteria">';
    foreach ($p['criteria'] as $criterion) echo '<li>'.h($criterion).'</li>';
    echo '</ul><p class="lp-footnote">ارزیابی خودکار با ورودی‌های تازه انجام می‌شود؛ اجرای عادی فقط خروجی برنامه را نشان می‌دهد.</p></section>';
    echo '<details class="lp-block lp-hints"><summary>راهنمایی برای فهم مسئله</summary><p>'.h($guidance[1]).'</p><h3>از کجا شروع کنم؟</h3><ol><li>نمونهٔ ورودی را بخوان و نتیجهٔ موردانتظار را ابتدا بدون کد پیدا کن.</li><li>'.h($p['steps'][0]).'</li><li>تابع solve را کامل کن و نام کلیدها و نوع خروجی را دقیقاً مطابق نمونه نگه دار.</li><li>کد را در میزکار ذخیره و اجرا کن؛ سپس ارزیابی را بزن.</li></ol></details>';
    echo '<section class="lp-block"><h2>تمرین انتقال</h2><p>'.h($guidance[0]).'</p><p class="lp-footnote">این تمرین برای خودآزمایی است و جزو ارزیابی خودکار و XP این پروژه نیست.</p></section>';
    echo '</div><aside class="lp-detail-side">';
    echo '<section class="lp-side-card"><span class="lp-block-label">شروع سریع</span><h2>'.($completion?'تکمیل‌شده':'آمادهٔ شروع').'</h2><p>'.($completion?'پاداش '.fa($completion['xp']).' XP در '.h(jdate($completion['completed_at'])).' ثبت شد.':'فایل‌های اولیه، دیتاست و ارزیابی خودکار از قبل آماده‌اند.').'</p>';
    if ($row||$completion) linkto('dashboard/workspace/'.(int)$row['project_id'],'بازکردن میزکار ←','btn btn-primary full');
    echo '</section>';
    if (!empty($p['dependencies'])) {
        echo '<section class="lp-side-card"><span class="lp-block-label">کتابخانه‌های لازم</span><ul class="lp-deps">';
        foreach ($p['dependencies'] as $dep) echo '<li dir="ltr">'.h($dep).'</li>';
        echo '</ul><p class="lp-footnote">در محیط میزکار آماده‌اند؛ نصب آنلاین لازم نیست.</p></section>';
    }
    if ($sources) {
        echo '<section class="lp-side-card"><span class="lp-block-label">منبع داده</span>';
        foreach ($sources as $source) echo '<div class="lp-source"><strong dir="ltr">'.h($source['file']).'</strong><span>'.fa($source['rows']).' ردیف · '.h($source['license']).'</span><small>'.h($source['attribution']).'</small><small dir="ltr">'.h($source['source']).'</small><p>نسخهٔ محلی همراه پروژه؛ هنگام اجرا دانلود خارجی لازم نیست.</p></div>';
        echo '</section>';
    }
    if (!empty($p['prerequisites'])) {
        echo '<section class="lp-side-card"><span class="lp-block-label">پیش‌نیاز</span><ul class="lp-prereq">';
        foreach ($p['prerequisites'] as $prerequisite) echo '<li>'.h($prerequisite).'</li>';
        echo '</ul>';
        foreach ($p['prerequisite_articles'] as $slug) { $article=article_entry($slug); if ($article) linkto('articles/'.$slug,$article['title'].' ←','inline-link'); }
        echo '</section>';
    }
    echo '<section class="lp-side-card lp-side-nav"><span class="lp-block-label">پروژه‌های دیگر</span>';
    if ($index>0) { $prev=PROJECTS[$index-1]; echo '<a href="'.h(url('project/'.($index-1))).'"><span>← قبلی</span><strong>'.h($prev['title']).'</strong></a>'; }
    if (isset(PROJECTS[$index+1])) { $next=PROJECTS[$index+1]; echo '<a class="is-next" href="'.h(url('project/'.($index+1))).'"><span>بعدی →</span><strong>'.h($next['title']).'</strong></a>'; }
    echo '</section></aside></div></main>';
    foot_page();
}
