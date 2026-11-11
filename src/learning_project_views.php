<?php
declare(strict_types=1);

function lp_progress_view(int $userId): void {
    panel_head('امتیاز، سکه و پیشرفت','XP و سکه فقط پس از ارزیابی موفق پروژه ثبت می‌شوند؛ شروع چالش یا اجرای عادی جایزه ندارد.');
    $rows=q('SELECT learning_key,xp,completed_at FROM project_completions WHERE user_id=? ORDER BY completed_at DESC',[$userId])->fetchAll();
    $balance=coin_balance($userId);
    $earned=(int)q("SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE user_id=? AND amount>0",[$userId])->fetchColumn();
    $spent=(int)q("SELECT COALESCE(-SUM(amount),0) FROM coin_ledger WHERE user_id=? AND amount<0",[$userId])->fetchColumn();
    echo '<div class="stat-grid"><div class="stat-card"><span>XP دریافت‌شده</span><strong>'.fa(lp_xp($userId)).'</strong></div><div class="stat-card"><span>موجودی سکه</span><strong>'.fa($balance).'</strong></div><div class="stat-card"><span>پروژه‌های تکمیل‌شده</span><strong>'.fa(count($rows)).' از '.fa(count(PROJECTS)).'</strong></div><div class="stat-card"><span>سکهٔ خرج‌شده</span><strong>'.fa($spent).'</strong></div></div>';
    echo '<section class="panel-card"><h2>سکه‌ها چطور کار می‌کنند؟</h2><ul class="check-list"><li>در شروع '.fa(COIN_WELCOME_BONUS).' سکهٔ هدیه می‌گیری.</li><li>هر چالش تکمیل‌شده هم XP می‌دهد و هم سکه؛ چالش سخت‌تر سکهٔ بیشتری دارد.</li><li>هر راهنمایی '.fa(COIN_HINT_COST).' سکه هزینه دارد و پس از بازشدن در حساب تو می‌ماند.</li><li>سکه فقط از راه تمرین واقعی به دست می‌آید؛ خرید سکه وجود ندارد.</li></ul><p class="muted">مجموع سکهٔ کسب‌شده تا امروز: '.fa($earned).' سکه.</p></section>';
    $history=coin_history($userId,15);
    if ($history) {
        echo '<section class="panel-card"><h2>آخرین گردش سکه</h2><div class="panel-list">';
        foreach ($history as $entry) echo '<div class="usage-row"><span>'.h(coin_reason_label((string)$entry['reason'])).'<small class="muted"> · '.h(jdate($entry['created_at'])).'</small></span><strong class="'.((int)$entry['amount']>0?'coin-plus':'coin-minus').'">'.((int)$entry['amount']>0?'+':'').fa((int)$entry['amount']).'</strong></div>';
        echo '</div></section>';
    }
    echo '<div class="panel-list">';
    if (!$rows) echo '<p class="empty-state">هنوز پروژه‌ای با ارزیابی معتبر تکمیل نشده است.</p>';
    foreach ($rows as $row) foreach (PROJECTS as $index=>$definition) if ($definition['key']===$row['learning_key']) echo '<article class="panel-card"><h2><a href="'.h(url('project/'.$index)).'">'.h($definition['title']).'</a></h2><p>'.fa($row['xp']).' XP · '.fa(coin_reward_for($definition)).' سکه · '.h(jdate($row['completed_at'])).'</p></article>';
    echo '</div>';
}

function lp_page(int $index): void {
    global $me;
    $p=PROJECTS[$index];
    $guidance=json_decode(file_get_contents(dirname(__DIR__).'/curriculum/project-guidance.json'),true,512,JSON_THROW_ON_ERROR)[$p['key']];
    head_page($p['title'],$p['description'],false,true); notice();
    echo '<link rel="stylesheet" href="/assets/learning-projects.css?v=3"><main class="article lp-page"><div class="container narrow-container"><span class="eyebrow">'.h($p['topic']).' · '.h($p['level']).'</span><h1>'.h($p['title']).'</h1><p class="lead">'.h($p['description']).'</p><p>'.fa($p['minutes']).' دقیقه · '.fa($p['xp']).' XP · '.h($p['skills']).'</p><section class="panel-card lp-craft"><h2>هدف و راهنمای انجام با روش CRAFT</h2><h3><bdi>C · Challenge</bdi> | چالش</h3><p>'.h($p['description']).'</p><p>'.h($p['goal']).'</p><h3><bdi>R · Route</bdi> | مسیر</h3><ol>';
    foreach ($p['steps'] as $step) echo '<li>'.h($step).'</li>';
    echo '</ol><h3><bdi>A · Applicable Knowledge</bdi> | دانش کاربردی</h3><p>'.h($p['skills']).'</p><p>کتابخانه‌های موردنیاز این مسئله: '.h(implode(', ',$p['dependencies']) ?: 'json, pathlib').'</p><h3><bdi>F · Forge</bdi> | ساختن</h3><p>در main.py تابع solve را کامل کن؛ پاسخ را طبق قرارداد زیر برگردان تا در result.json ذخیره شود. اجرای عادی برای بررسی خروجی است و ارزیابی برای سنجش حل مسئله با ورودی‌های دیگر.</p><h4>ورودی</h4><p>'.h($p['input']).'</p><h4>خروجی</h4><p>'.h($p['output']).'</p><p>'.h($p['notes']).'</p><h4>مثال ورودی و خروجی</h4><pre class="lp-output" dir="ltr">'.h(json_encode($p['example'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><h3><bdi>T · Transfer</bdi> | انتقال</h3><p>'.h($guidance[0]).'</p><p>این تمرین انتقال برای خودآزمایی است و جزو ارزیابی خودکار و XP این پروژه نیست.</p><h3>معیارهای موفقیت</h3><ul>';
    foreach ($p['criteria'] as $criterion) echo '<li>'.h($criterion).'</li>';
    echo '</ul></section><details class="panel-card lp-help"><summary>راهنمایی برای فهم مسئله و نوشتن کد</summary><p>'.h($guidance[1]).'</p><h3>از کجا شروع کنم؟</h3><ol><li>نمونهٔ ورودی را بخوان و نتیجهٔ موردانتظار را ابتدا بدون کد پیدا کن.</li><li>'.h($p['steps'][0]).'</li><li>تابع solve را کامل کن و نام کلیدها و نوع خروجی را دقیقاً مطابق نمونه نگه دار.</li><li>کد را ذخیره و اجرا کن؛ سپس ارزیابی را بزن. چاپ متن به‌تنهایی جای پاسخ result.json نیست.</li></ol><p>'.h($p['notes']).'</p></details>';
    if (!empty($p['prerequisites'])) {
        echo '<section class="panel-card"><h2>پیش‌نیاز و ترتیب یادگیری</h2><ul>';
        foreach ($p['prerequisites'] as $prerequisite) echo '<li>'.h($prerequisite).'</li>';
        echo '</ul>';
        foreach ($p['prerequisite_articles'] as $slug) { $article=article_entry($slug); if ($article) linkto('articles/'.$slug,$article['title'].' ←','inline-link'); }
        echo '<p>برای شروع، مقالهٔ بالا را بخوانید. Python 3 و کتابخانه‌های موردنیاز در میزکار آماده‌اند؛ نصب آنلاین از ترمینال میزکار لازم نیست.</p></section>';
    }
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
        echo '<script src="/assets/learning-projects.js?v=2" defer></script><section class="panel-card lp-editor" data-lp-id="'.(int)$row['project_id'].'" data-csrf="'.h(csrf()).'"><h2>محیط ساده Python</h2><details class="lp-help"><summary>راهنمایی هنگام کدنویسی</summary><p>'.h($guidance[1]).'</p><p>قرارداد خروجی: '.h($p['output']).'</p></details><p data-lp-completion>'.($completion?'تکمیل‌شده · '.fa($completion['xp']).' XP · '.h($completion['completed_at']):'برای دریافت XP، همین نسخه ذخیره‌شده باید تمام معیارهای ارزیابی را بگذراند.').'</p><p>اجرا فقط خروجی برنامه را نشان می‌دهد؛ ارزیابی جداگانه و با ورودی‌های تازه انجام می‌شود. تغییر کد، اعتبار ارزیابی نسخه قبلی را به نسخه جدید منتقل نمی‌کند.</p><p role="status" aria-live="polite" data-lp-status>در حال بارگذاری…</p><label>فایل<select data-lp-file aria-label="انتخاب فایل"></select></label><label for="lp-code">کد یا ورودی نمونه</label><textarea id="lp-code" dir="ltr" spellcheck="false" rows="20" data-lp-code disabled></textarea><div class="lp-toolbar"><button type="button" class="btn btn-outline" data-lp-action="save" disabled>ذخیره</button><button type="button" class="btn btn-primary" data-lp-action="run" disabled>اجرای واقعی</button><button type="button" class="btn btn-primary" data-lp-action="assess" disabled>ارزیابی و دریافت XP</button><button type="button" class="btn btn-outline" data-lp-action="solution" disabled>نمایش راه‌حل مرجع</button></div><h3>خروجی و خطا</h3><pre class="lp-output" dir="ltr" data-lp-output>اجرا نشده است.</pre><div data-lp-checks></div><div data-lp-artifacts></div><details data-lp-reference hidden><summary>راه‌حل مرجع مستقل از کد شما</summary><pre class="lp-output" dir="ltr" data-lp-solution></pre></details><p>راه‌حل پس از یک تلاش اجرایی واقعی قابل مشاهده است؛ بازکردن آن کد شما را تغییر نمی‌دهد. کد تغییرکرده ذخیره‌نشده، با بستن صفحه از دست می‌رود.</p></section>';
    }
    echo '</div></main>'; foot_page();
}
