<?php
declare(strict_types=1);

function dr_form(string $path,?array $view=null,string $action='submit'): void {
    form_start($path,'dr-form');
    echo '<input type="hidden" name="_html" value="1"><input type="hidden" name="request_key" value="'.h(bin2hex(random_bytes(16))).'">';
    if ($view) echo '<input type="hidden" name="id" value="'.h($view['attempt_id']).'"><input type="hidden" name="revision" value="'.h($view['revision']).'"><input type="hidden" name="action" value="'.h($action).'">';
}
function dr_stage_labels(): array {
    return ['intro'=>'ورود به موقعیت','evidence'=>'جمع‌آوری اطلاعات','problem'=>'تعریف مسئله','solution'=>'ساخت راه‌حل','consequence'=>'مشاهدهٔ پیامد','feedback'=>'بازخورد آموزشی','retry'=>'بازبینی و اصلاح','transfer'=>'انتقال آموخته','transfer_feedback'=>'بازخورد انتقال','report'=>'گزارش تجربه'];
}
function dr_position(string $kind): int {
    return ['intro'=>0,'evidence'=>1,'problem'=>2,'solution'=>3,'consequence'=>4,'feedback'=>5,'retry'=>6,'transfer'=>7,'transfer_feedback'=>7,'report'=>8][$kind];
}
function dr_journey(?string $kind=null): void {
    $labels=['ورود به موقعیت','جمع‌آوری اطلاعات','تعریف مسئله','ساخت راه‌حل','مشاهدهٔ پیامد','بازخورد آموزشی','بازبینی و اصلاح','انتقال آموخته'];
    $position=$kind===null?-1:dr_position($kind);
    echo '<section class="dr-card dr-journey" aria-labelledby="dr-journey-title"><div class="dr-section-heading"><div><span class="dr-kicker">مسیر کوتاه یادگیری</span><h2 id="dr-journey-title">از نشانه تا راه‌حل قابل آزمون</h2></div><span class="dr-badge">۸ گام · یک تجربه</span></div><ol class="dr-steps">';
    foreach ($labels as $index=>$label) {
        $current=$index===$position;
        echo '<li'.($current?' aria-current="step"':'').' class="'.($current?'is-current':($position===8?'is-finished':'')).'"><span class="dr-step-number" aria-hidden="true">'.fa($index+1).'</span><span>'.h($label).($current?'<small>اکنون اینجایی</small>':'').'</span></li>';
    }
    echo '</ol><p class="dr-footnote">این مسیر، جایگاه تجربه را نشان می‌دهد؛ نه درصد تسلط بر مهارت. بازبینی و برگشت بخشی از یادگیری است.</p></section>';
}
function dr_hero(): void {
    echo '<header class="dr-hero"><div class="dr-hero-copy"><span class="dr-kicker">NOVENTIX / DARYCHEH</span><h1>دریچه؛ فراتر از یادگیری، به‌سوی توانایی ساختن.</h1><p>وارد یک موقعیت واقعی‌نما شو؛ سؤال بپرس، تصمیم بگیر و اثر انتخابت را ببین. اینجا اشتباه، نقطهٔ آغازِ یک راه‌حل بهتر است.</p><div class="dr-hero-notes"><span>تصمیم، نه تماشای محتوا</span><span>تمرین با پیامد قابل مشاهده</span></div></div><div class="dr-portal" aria-hidden="true"><span class="dr-portal-caption">از دانستن</span><div class="dr-portal-frame"><span class="dr-portal-inner"></span><span class="dr-portal-door"></span><span class="dr-portal-marker"></span></div><span class="dr-portal-ground"></span><span class="dr-portal-destination">به ساختن</span><span class="dr-portal-coordinate">01 / ENTER A NEW PERSPECTIVE</span></div></header>';
}
function dr_status_region(): void {
    echo '<div id="dr-save-status" class="dr-message dr-message-loading" role="status" aria-live="polite" hidden><span class="dr-loading-mark" aria-hidden="true"></span><span>در حال ثبت انتخاب و آماده‌سازی مرحلهٔ بعد…</span></div><div id="dr-form-error" class="dr-message dr-message-error" role="alert" hidden></div>';
}
function dr_error_card(string $title,string $message,int $status,?int $id=null): void {
    echo '<section class="dr-card dr-empty dr-error" id="dr-content" tabindex="-1" role="alert"><span class="dr-kicker">'.h((string)$status).' / دریچه</span><h2>'.h($title).'</h2><p>'.h($message).'</p><div class="dr-actions">';
    linkto('dashboard/darycheh','بازگشت به فهرست دریچه','btn dr-button dr-button-primary');
    if ($id!==null && $status!==404) linkto('dashboard/darycheh/'.$id,'بارگذاری دوباره','btn dr-button dr-button-secondary');
    echo '</div></section>';
}
function dr_render_results(array $view): void {
    $operations=['duplicate'=>'بررسی سابقه','validate'=>'اعتبارسنجی تعداد','capacity'=>'کنترل ظرفیت','reserve'=>'ثبت رزرو','remember'=>'ثبت نتیجه'];
    $statuses=['reserved'=>'ثبت شد','rejected'=>'رد شد','pending'=>'روند بدون نتیجه پایان یافت'];
    if (isset($view['main'])) {
        $main=$view['main'];
        echo '<section class="dr-results" aria-labelledby="dr-results-title"><div class="dr-section-heading"><div><span class="dr-kicker">انتخاب تو، پیامد واقعی در شبیه‌سازی</span><h3 id="dr-results-title">نتایج اجرای '.fa($view['round']).'</h3></div><span class="dr-badge">'.fa($main['score']).' از ۱۶</span></div><div class="dr-test-grid">';
        foreach ($main['tests'] as $key=>$test) {
            echo '<article class="dr-test '.($test['passed']?'is-success':'needs-review').'"><div class="dr-test-heading"><span class="dr-test-id">'.h(strtoupper($key)).'</span><span class="dr-outcome '.($test['passed']?'dr-outcome-success':'dr-outcome-review').'">'.($test['passed']?'معیار برقرار است':'نیازمند اصلاح').'</span></div><h4>'.h($test['label']).'</h4>';
            if (isset($view['predictions'][$key])) echo '<p class="dr-prediction">پیش‌بینی تو: '.h($view['predictions'][$key]).'</p>';
            echo '<dl class="dr-test-facts"><div><dt>ظرفیت نهایی</dt><dd>'.fa($test['final']['capacity']).'</dd></div><div><dt>تعداد رزرو</dt><dd>'.fa($test['final']['reservations']).'</dd></div></dl><p class="dr-test-result">'.h($statuses[$test['final']['status']]).($test['final']['replayed']?'؛ نتیجهٔ درخواست قبلی بازگردانده شد.':'').'</p>';
            if (!$test['passed']) echo '<p class="dr-review-note">این خروجی با معیار پذیرش سازگار نیست. ردگیری را باز کن و ترتیب کنترل‌ها را پیش از تغییر ظرفیت بررسی کن.</p>';
            echo '<details class="dr-trace"><summary>ردگیری این تست</summary><ol>';
            foreach ($test['trace'] as $event) echo '<li><span><bdi>'.h($event['request']).'</bdi> · '.h($operations[$event['operation']]).'</span><small>پیش از عمل: ظرفیت '.fa($event['capacity']).'، رزرو '.fa($event['reservations']).'</small></li>';
            echo '</ol></details></article>';
        }
        echo '</div><p class="dr-footnote">نتایج، اجرای روند انتخابی تو هستند. پس از اصلاح، همهٔ تست‌ها دوباره اجرا می‌شوند؛ نه فقط تست ناموفق.</p></section>';
    }
    if (isset($view['transfer'])) {
        $transfer=$view['transfer'];
        echo '<section class="dr-transfer-result" aria-labelledby="dr-transfer-title"><div class="dr-section-heading"><h3 id="dr-transfer-title">همان اصل، در موقعیتی تازه</h3><span class="dr-badge">'.fa($transfer['score']).' از ۴</span></div><p>'.($transfer['is_independent']?'بسته پیش از ارسال، پاسخ افشاشده نداشت.':'این بسته قبلاً دیده شده است؛ این نتیجه شاهد مستقل تازه نیست.').'</p><p>اصل قابل انتقال: درخواست تکراری را پیش از تغییر موجودی تشخیص بده و ورودی نامعتبر و موجودی ناکافی را بیازما.</p><p>موجودی صحیح این بسته: <strong>'.fa($transfer['expected']['remaining']).'</strong></p></section>';
    }
}
function dr_render_fields(array $view): void {
    $stage=$view['stage']; $answers=$view['answers'][$stage['kind']] ?? [];
    foreach ($stage['fields'] as $key=>$field) {
        $id='dr-field-'.$key;
        echo '<fieldset class="dr-fieldset" id="'.h($id).'" data-dr-kind="'.h($field['kind']).'"'.($field['kind']!=='single'?' data-dr-min="'.h($field['min']).'" data-dr-max="'.h($field['max']).'"':'').'><legend>'.h($field['label']).'</legend>';
        if ($field['kind']==='order') {
            echo '<p class="dr-field-help">هر کارت فقط یک‌بار. ترتیب با انتخاب گام‌ها مشخص می‌شود؛ نیازی به کشیدن کارت‌ها نیست.</p><div class="dr-order-list">';
            for ($position=0;$position<$field['max'];$position++) {
                echo '<label class="dr-order-row"><span class="dr-order-number">'.fa($position+1).'</span><span class="dr-order-label">گام '.fa($position+1).'<select name="payload['.h($key).'][]" aria-describedby="dr-order-help"><option value="">حذف این گام</option>';
                foreach ($field['options'] as $value=>$label) echo '<option value="'.h($value).'"'.(($answers[$key][$position] ?? '')===(string)$value?' selected':'').'>'.h($label).'</option>';
                echo '</select></span></label>';
            }
            echo '</div><p id="dr-order-help" class="dr-field-help">حذف کنترل مجاز است تا پیامدش را ببینی؛ تکرار همان کارت مجاز نیست.</p>';
        } else {
            if ($field['kind']==='multi') echo '<p class="dr-field-help">'.fa($field['min']).' گزینه انتخاب کن؛ انتخاب و تفسیر شاهد ارزیابی می‌شود.</p>';
            echo '<div class="dr-choice-grid">';
            foreach ($field['options'] as $value=>$label) {
                $value=(string)$value; $multi=$field['kind']==='multi';
                $checked=$multi?in_array($value,$answers[$key] ?? [],true):($answers[$key] ?? '')===$value;
                echo '<label class="dr-choice"><input type="'.($multi?'checkbox':'radio').'" name="payload['.h($key).']'.($multi?'[]':'').'" value="'.h($value).'"'.($checked?' checked':'').($multi?'':' required').'><span>'.h($label).'</span></label>';
            }
            echo '</div>';
        }
        echo '</fieldset>';
    }
}
function dr_summary(array $summary): void {
    $endings=['independent'=>'شاهد عملکرد مستقل','supported'=>'تکمیل با پشتیبانی','needs_practice'=>'پایان تجربه؛ یک فرصت برای تمرین بیشتر','needs_fresh_evidence'=>'تمرین موفق؛ شاهد مستقل تازه لازم است'];
    $labels=['evidence'=>'تشخیص شواهد','problem'=>'تعریف مسئله','algorithm'=>'روند منطقی','testing'=>'آزمودن','transfer'=>'انتقال آموخته'];
    echo '<section class="dr-summary" aria-labelledby="dr-summary-title"><span class="dr-kicker">گزارش این تجربه</span><h3 id="dr-summary-title">'.h($endings[$summary['ending']]).'</h3><div class="dr-summary-score"><strong>'.fa($summary['score']).'</strong><span>از ۲۰ امتیاز قابل مشاهده</span></div><div class="dr-skill-grid">';
    foreach ($summary['dimensions'] as $dimension=>$score) echo '<div class="dr-skill"><label for="dr-skill-'.h($dimension).'">'.h($labels[$dimension]).'<span>'.fa($score).' از ۴</span></label><meter id="dr-skill-'.h($dimension).'" min="0" max="4" value="'.h($score).'">'.fa($score).' از ۴</meter></div>';
    echo '</div><p>قبولی فقط به مجموع نمره وابسته نیست؛ حداقل هر بُعد، تست‌های حیاتی و انتقال مستقل نیز لازم‌اند. این نتیجه تضمین تسلط یا حرفه‌ای‌شدن نیست.</p></section>';
}
function dr_landing(array $u): void {
    $history=q('SELECT id,attempt_no,status,current_step_key,updated_at FROM darycheh_attempts WHERE user_id=? ORDER BY id DESC LIMIT 50',[(int)$u['id']])->fetchAll();
    $active=null;
    foreach ($history as $row) if ($row['status']==='in_progress') { $active=$row; break; }
    $scenario=null; $availabilityError=null;
    try { $scenario=dr_available_scenario(); }
    catch (DarychehError $e) { $availabilityError=$e->getMessage(); }
    dr_hero();
    echo '<div class="dr-home-grid"><section class="dr-card dr-feature" aria-labelledby="dr-feature-title"><div class="dr-section-heading"><span class="dr-kicker">تجربهٔ اول / تفکر پیش از کدنویسی</span><span class="dr-badge">بدون پیش‌نیاز کدنویسی</span></div>';
    if ($active) {
        $labels=dr_stage_labels();
        echo '<span class="dr-resume-label">تجربه‌ای برای ادامه داری</span><h2 id="dr-feature-title">از همان‌جا که بودی، ادامه بده.</h2><p>تلاش '.fa($active['attempt_no']).' · '.h($labels[$active['current_step_key']] ?? 'مرحلهٔ ذخیره‌شده').'</p><p>تصمیم‌های ثبت‌شده در حساب تو مانده‌اند. ادامهٔ این تجربه به شروع تازه یا از دست‌دادن تاریخچه نیاز ندارد.</p><div class="dr-actions">';
        linkto('dashboard/darycheh/'.$active['id'],'ادامهٔ تجربهٔ ذخیره‌شده','btn dr-button dr-button-primary');
        echo '</div>';
    } elseif ($scenario) {
        echo '<h2 id="dr-feature-title">'.h($scenario['title']).'</h2><p>یک درخواست، دو رزرو. مشکل از کلیک است یا از روشی که سیستم درخواست را می‌فهمد؟ با شواهد جلو برو، راه‌حل بساز و آن را محک بزن.</p><ul class="dr-feature-facts"><li>حدود ۲۰ تا ۳۰ دقیقه</li><li>بدون تایمر و جریمهٔ سرعت</li><li>ذخیره در حساب فعلی</li></ul><div class="dr-actions">';
        dr_form('darycheh/start'); echo '<button class="btn dr-button dr-button-primary" type="submit">ورود به اولین تجربه</button></form></div>';
    } else {
        echo '<h2 id="dr-feature-title">'.($availabilityError?'این سناریو فعلاً قابل بارگذاری نیست':'هنوز سناریویی برای شروع در دسترس نیست').'</h2><p'.($availabilityError?' role="alert"':'').'>'.h($availabilityError ?? 'وقتی سناریوی معتبری منتشر شود، شروع آن از همین‌جا امکان‌پذیر است. تجربه‌های قبلی، در صورت وجود، از تاریخچه قابل دسترسی‌اند.').'</p>';
        linkto('dashboard/darycheh','بررسی دوبارهٔ دسترسی','btn dr-button dr-button-secondary');
    }
    echo '</section><aside class="dr-card dr-principles"><span class="dr-kicker">در این سوی دریچه</span><h2>جواب آماده کافی نیست.</h2><ol><li><strong>ببین</strong><span>نشانه را از فرضیه جدا کن.</span></li><li><strong>بساز</strong><span>تصمیمت را به یک روند تبدیل کن.</span></li><li><strong>بیازما</strong><span>پیامد را ببین و راهت را اصلاح کن.</span></li></ol><p class="dr-footnote">پیشرفت از عملکرد تو به دست می‌آید؛ نه از تعداد کلیک یا زمان حضور.</p></aside></div>';
    dr_journey();
    echo '<section class="dr-card" aria-labelledby="dr-history-title"><div class="dr-section-heading"><div><span class="dr-kicker">تجربه‌های تو</span><h2 id="dr-history-title">هر تلاش، بخشی از مسیر</h2></div><span class="dr-badge">خصوصی</span></div>';
    if (!$history) echo '<div class="dr-empty"><span class="dr-empty-mark" aria-hidden="true"></span><h3>هنوز قدم اول را ثبت نکرده‌ای.</h3><p>با شروع اولین تجربه، تصمیم‌ها، بازخوردها و گزارش تو اینجا در دسترس خواهند بود.</p></div>';
    else {
        $labels=dr_stage_labels(); $statuses=['in_progress'=>'در حال انجام','completed'=>'گزارش آماده','abandoned'=>'کنارگذاشته‌شده'];
        echo '<ul class="dr-history">';
        foreach ($history as $row) {
            echo '<li><div><span class="dr-history-number">'.fa($row['attempt_no']).'</span><div><strong>تلاش '.fa($row['attempt_no']).'</strong><span>'.h($statuses[$row['status']]).' · '.h($labels[$row['current_step_key']] ?? 'وضعیت ذخیره‌شده').'</span></div></div>';
            linkto('dashboard/darycheh/'.$row['id'],$row['status']==='in_progress'?'ادامهٔ تجربه':($row['status']==='completed'?'مشاهدهٔ گزارش':'مرور تلاش'),'dr-history-link');
            echo '</li>';
        }
        echo '</ul><p class="dr-footnote">حداکثر ۵۰ تجربهٔ اخیر نمایش داده می‌شود. شروع مجدد، تاریخچه و سابقهٔ مشاهدهٔ پاسخ را پاک نمی‌کند.</p>';
    }
    echo '</section>';
    $progress=dr_progress((int)$u['id']);
    if ($progress['groups']) {
        echo '<section class="dr-card" aria-labelledby="dr-progress-title"><div class="dr-section-heading"><div><span class="dr-kicker">شواهد، نه وعده</span><h2 id="dr-progress-title">تغییر عملکردت را ببین</h2></div></div><p>'.h($progress['note']).'</p><div class="dr-progress-grid">';
        $scopes=['main'=>'تحلیل و آزمودن','transfer'=>'انتقال آموخته','summary'=>'گزارش نهایی'];
        foreach ($progress['groups'] as $key=>$group) {
            $scope=substr($key,strrpos($key,':')+1);
            echo '<article class="dr-progress-card"><h3>'.h($scopes[$scope]).'</h3><span class="dr-progress-reference">گروه ارزیابی '.fa($group['first']['evaluation_id']).' · سقف '.fa($group['last']['max_score']).'</span><dl>';
            foreach (['first'=>'اولین','best'=>'بهترین','last'=>'آخرین'] as $metric=>$label) echo '<div><dt>'.h($label).'</dt><dd>'.fa($group[$metric]['score']).'</dd></div>';
            echo '</dl>';
            linkto('dashboard/darycheh/'.$group['last']['attempt_id'],'مرور تجربهٔ آخر','dr-inline-link'); echo '</article>';
        }
        echo '</div><p class="dr-footnote">نمره‌های بخش‌ها و نسخه‌های مختلف با هم جمع نمی‌شوند. بهترین نمره لزوماً به معنای عملکرد مستقل نیست.</p></section>';
    }
}
function dr_page(array $u,?int $id): void {
    echo '<main class="panel-content dr-page" id="dr-main"><a class="dr-skip-link" href="#dr-content">رفتن به محتوای دریچه</a><div class="dr-topline"><span>Noventix <span aria-hidden="true">/</span> دریچه</span>';
    if ($id!==null) linkto('dashboard/darycheh','بازگشت به مسیر','dr-inline-link');
    echo '</div>'; dr_status_region();
    if ($id===null) { echo '<div id="dr-content" tabindex="-1">'; dr_landing($u); echo '</div></main>'; return; }
    try { $view=dr_view((int)$u['id'],$id); }
    catch (DarychehError $e) { http_response_code($e->status); dr_error_card($e->status===404?'تجربه پیدا نشد':'این تجربه فعلاً قابل ادامه نیست',$e->getMessage(),$e->status,$id); echo '</main>'; return; }
    $stage=$view['stage'];
    echo '<header class="dr-experience-header"><div><span class="dr-kicker">دریچه / تجربهٔ تصمیم‌گیری</span><h1>'.h($view['title']).'</h1></div><span class="dr-badge">'.($view['status']==='completed'?'تجربه پایان یافته':($view['status']==='abandoned'?'فقط مرور':'در حال تجربه')).'</span></header>';
    dr_journey($stage['kind']);
    echo '<div class="dr-workspace"><section class="dr-card dr-stage" id="dr-content" tabindex="-1" aria-labelledby="dr-stage-title"><div class="dr-stage-meta"><span>تلاش '.fa($view['attempt_no']).' · نسخهٔ '.h($view['content_version']).'</span><span>آخرین ثبت سرور: '.fa($view['revision']).'</span></div><span class="dr-kicker">'.($stage['kind']==='report'?'پس از عبور':('گام '.fa(min(8,dr_position($stage['kind'])+1)).' از ۸')).'</span><h2 id="dr-stage-title">'.h($stage['title']).'</h2><p class="dr-stage-intro">'.h($stage['text']).'</p>';
    if (isset($view['bundle'])) echo '<div class="dr-situation"><span class="dr-kicker">موقعیت تازه / '.h($view['bundle']['label']).'</span><p>'.h($view['bundle']['text']).'</p>'.($view['bundle']['previously_exposed']?'<p class="dr-footnote">این بسته قبلاً پاسخ داده شده و برای تمرین است؛ شاهد مستقل تازه محسوب نمی‌شود.</p>':'').'</div>';
    if ($view['hint']) echo '<div class="dr-message dr-message-hint" role="status"><strong>راهنمایی ثبت‌شده</strong><p>'.h($view['hint']).'</p></div>';
    if (isset($view['summary'])) dr_summary($view['summary']);
    if (in_array($stage['kind'],['feedback','transfer_feedback'],true)) dr_render_results($view);
    if ($view['status']==='in_progress') {
        dr_form('darycheh/act',$view); dr_render_fields($view);
        echo '<div class="dr-form-footer"><button class="btn dr-button dr-button-primary" type="submit">'.($stage['kind']==='consequence'?'ثبت پیش‌بینی و اجرای تست‌ها':($stage['kind']==='intro'?'ورود به موقعیت':($stage['kind']==='transfer_feedback'?'ثبت گام بعد':'ثبت و ادامه'))).'</button><p id="dr-edit-status" class="dr-footnote" aria-live="polite">فقط انتخاب‌های ارسال و تأییدشده ذخیره می‌شوند.</p></div></form>';
        if (in_array($stage['kind'],['solution','feedback','retry'],true) && $view['help_level']<3) {
            dr_form('darycheh/act',$view,'hint'); echo '<button class="btn dr-button dr-button-secondary" type="submit">دریافت راهنمایی · در گزارش ثبت می‌شود</button></form>';
        }
    }
    if (!in_array($stage['kind'],['feedback','transfer_feedback'],true) && (isset($view['main']) || isset($view['transfer']))) { echo '<details class="dr-recap"><summary>مرور پیامدها و شواهد این تلاش</summary>'; dr_render_results($view); echo '</details>'; }
    echo '</section><aside class="dr-side"><section class="dr-card dr-context"><span class="dr-kicker">پروندهٔ موقعیت</span><h2>پیش از راه‌حل، مسئله را ببین.</h2><p>'.h($view['context']).'</p><dl><div><dt>محیط</dt><dd>شبیه‌سازی آموزشی</dd></div><div><dt>اجرای درخواست‌ها</dt><dd>ترتیبی، نه هم‌زمان</dd></div><div><dt>اطلاعات و رزروها</dt><dd>ساختگی و بدون پرداخت</dd></div></dl><p class="dr-footnote">موفقیت این مدل، تضمین آماده‌بودن یک سامانهٔ رزرو واقعی نیست.</p></section>';
    if ($view['status']!=='abandoned') {
        echo '<details class="dr-card dr-restart"><summary>شروع یک تلاش تازه</summary><p>شروع مجدد، تلاش فعال را کنار می‌گذارد؛ تاریخچه و سابقهٔ مشاهدهٔ پاسخ باقی می‌مانند.</p>';
        dr_form('darycheh/restart',$view); echo '<button class="btn dr-button dr-button-secondary" type="submit">تأیید شروع مجدد</button></form></details>';
    }
    echo '<p class="dr-footnote dr-private-note">پیشرفت این تجربه خصوصی است و در پروفایل عمومی نمایش داده نمی‌شود.</p></aside></div></main>';
}
