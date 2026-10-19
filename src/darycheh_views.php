<?php
declare(strict_types=1);

function dr_form(string $path,?array $view=null,string $action='submit'): void {
    form_start($path);
    echo '<input type="hidden" name="_html" value="1"><input type="hidden" name="request_key" value="'.h(bin2hex(random_bytes(16))).'">';
    if ($view) echo '<input type="hidden" name="id" value="'.h($view['attempt_id']).'"><input type="hidden" name="revision" value="'.h($view['revision']).'"><input type="hidden" name="action" value="'.h($action).'">';
}
function dr_render_results(array $view): void {
    if (isset($view['main'])) {
        echo '<section class="panel-card"><h2>نتایج آخرین اجرا</h2><p>دور '.fa($view['round']).' · امتیاز بخش اصلی: '.fa($view['main']['score']).' از ۱۶</p><div class="table-wrap"><table><thead><tr><th>تست</th><th>خروجی واقعی</th><th>معیار پذیرش</th></tr></thead><tbody>';
        foreach ($view['main']['tests'] as $test) {
            echo '<tr><td>'.h($test['label']).'</td><td>ظرفیت '.fa($test['final']['capacity']).'؛ تعداد رزرو '.fa($test['final']['reservations']).'؛ '.h($test['final']['status']).($test['final']['replayed']?'؛ نتیجهٔ قبلی':'').'</td><td>'.($test['passed']?'موفق':'نیازمند اصلاح').'</td></tr>';
        }
        echo '</tbody></table></div><details><summary>ردگیری مراحل اجرا</summary>';
        foreach ($view['main']['tests'] as $test) {
            echo '<h3>'.h($test['label']).'</h3><ol>';
            foreach ($test['trace'] as $event) echo '<li><bdi>'.h($event['request']).' / '.h($event['operation']).'</bdi> — ظرفیت پیش از عمل: '.fa($event['capacity']).'، رزروها: '.fa($event['reservations']).'</li>';
            echo '</ol>';
        }
        echo '</details><p>در تست ناموفق، ترتیب کنترل‌ها و وضعیت قبل از تغییر ظرفیت را بررسی کن. پس از اصلاح، هر چهار تست دوباره اجرا می‌شوند.</p></section>';
    }
    if (isset($view['transfer'])) {
        echo '<section class="panel-card"><h2>نتیجهٔ انتقال</h2><p>امتیاز: '.fa($view['transfer']['score']).' از ۴؛ '.($view['transfer']['is_independent']?'بسته پیش از ارسال پاسخ افشا نشده بود.':'این بسته قبلاً دیده شده؛ نتیجه شاهد مستقل نیست.').'</p><p>اصل مسئله: تشخیص درخواست تکراری پیش از تغییر موجودی؛ آزمون تعداد نامعتبر و موجودی ناکافی.</p><p>موجودی صحیح این بسته: '.fa($view['transfer']['expected']['remaining']).'</p></section>';
    }
}
function dr_page(array $u,?int $id): void {
    echo '<main class="panel-content">';
    panel_head('دریچه','از نشانه تا راه‌حل قابل آزمون؛ تمرین تصمیم‌گیری، نه تضمین حرفه‌ای‌شدن');
    if ($id===null) {
        echo '<section class="panel-card"><h2>چرا یک کلیک، دو رزرو ساخته است؟</h2><p>یک تجربهٔ حدود ۲۰ تا ۳۰ دقیقه‌ای؛ بدون نیاز به کدنویسی، بدون تایمر و بدون سرویس هوش مصنوعی. پیشرفت به‌صورت خصوصی در حساب فعلی ذخیره می‌شود.</p>';
        dr_form('darycheh/start'); echo '<button class="btn" type="submit">شروع یا ادامهٔ تجربه</button></form></section>';
        $history=q('SELECT id,attempt_no,status,current_step_key,updated_at FROM darycheh_attempts WHERE user_id=? ORDER BY id DESC LIMIT 50',[(int)$u['id']])->fetchAll();
        if ($history) {
            echo '<section class="panel-card"><h2>تاریخچهٔ تجربه‌ها</h2><ul>';
            foreach ($history as $row) {
                $label=['in_progress'=>'در حال انجام','completed'=>'دارای گزارش','abandoned'=>'کنارگذاشته‌شده'][$row['status']];
                echo '<li>'; linkto('dashboard/darycheh/'.$row['id'],'تلاش '.fa($row['attempt_no']).' — '.$label); echo '</li>';
            }
            echo '</ul></section>';
        }
        $progress=dr_progress((int)$u['id']);
        if ($progress['groups']) {
            echo '<section class="panel-card"><h2>شواهد عملکرد</h2><p>'.h($progress['note']).'</p><ul>';
            foreach ($progress['groups'] as $group) echo '<li>اولین: '.fa($group['first']['score']).'؛ بهترین: '.fa($group['best']['score']).'؛ آخرین: '.fa($group['last']['score']).' از '.fa($group['last']['max_score']).'</li>';
            echo '</ul><p>گروه‌های نسخه و بخش ارزیابی با هم جمع نمی‌شوند. مشاهدهٔ پاسخ در تلاش تازه پاک نمی‌شود.</p></section>';
        }
        echo '</main>'; return;
    }
    try { $view=dr_view((int)$u['id'],$id); }
    catch (DarychehError $e) { http_response_code($e->status); echo '<section class="panel-card"><p>'.h($e->getMessage()).'</p>'; linkto('dashboard/darycheh','بازگشت به دریچه'); echo '</section></main>'; return; }
    $stage=$view['stage'];
    echo '<section class="panel-card"><p>تلاش '.fa($view['attempt_no']).' · نسخهٔ '.h($view['content_version']).' · آخرین وضعیت ذخیره‌شده '.fa($view['revision']).'</p><h2>'.h($stage['title']).'</h2><p>'.h($stage['text']).'</p>';
    if (isset($view['bundle'])) echo '<p>'.h($view['bundle']['text']).'</p>'.($view['bundle']['previously_exposed']?'<p>این بسته قبلاً پاسخ داده شده و برای تمرین است؛ شاهد مستقل تازه محسوب نمی‌شود.</p>':'');
    if ($view['hint']) echo '<p role="status">راهنمایی: '.h($view['hint']).'</p>';
    if ($view['status']==='in_progress') {
        dr_form('darycheh/act',$view);
        $answers=$view['answers'][$stage['kind']] ?? [];
        foreach ($stage['fields'] as $key=>$field) {
            if ($field['kind']==='multi') {
                echo '<fieldset><legend>'.h($field['label']).'</legend>';
                foreach ($field['options'] as $value=>$label) echo '<label><input type="checkbox" name="payload['.h($key).'][]" value="'.h($value).'"'.(in_array((string)$value,$answers[$key] ?? [],true)?' checked':'').'> '.h($label).'</label>';
                echo '</fieldset>';
            } elseif ($field['kind']==='order') {
                echo '<fieldset><legend>'.h($field['label']).'</legend>';
                for ($position=0;$position<$field['max'];$position++) {
                    echo '<label>گام '.fa($position+1).'<select name="payload['.h($key).'][]"><option value="">حذف</option>';
                    foreach ($field['options'] as $value=>$label) echo '<option value="'.h($value).'"'.(($answers[$key][$position] ?? '')===(string)$value?' selected':'').'>'.h($label).'</option>';
                    echo '</select></label>';
                }
                echo '</fieldset>';
            } else {
                echo '<label>'.h($field['label']).'<select required name="payload['.h($key).']"><option value="">انتخاب کن</option>';
                foreach ($field['options'] as $value=>$label) {
                    echo '<option value="'.h($value).'"'.(($answers[$key] ?? '')===(string)$value?' selected':'').'>'.h($label).'</option>';
                }
                echo '</select></label>';
            }
        }
        echo '<button class="btn" type="submit">'.($stage['kind']==='consequence'?'ثبت پیش‌بینی و اجرای تست‌ها':'ثبت و ادامه').'</button></form>';
        if (in_array($stage['kind'],['solution','feedback','retry'],true) && $view['help_level']<3) {
            dr_form('darycheh/act',$view,'hint'); echo '<button class="btn btn-secondary" type="submit">دریافت راهنمایی (در گزارش ثبت می‌شود)</button></form>';
        }
    }
    if (isset($view['summary'])) {
        $summary=$view['summary']; $endings=['independent'=>'شاهد عملکرد مستقل','supported'=>'تکمیل با پشتیبانی','needs_practice'=>'تجربه پایان یافت؛ هنوز نیازمند تمرین','needs_fresh_evidence'=>'تمرین موفق بود؛ برای ارزیابی مستقل، بستهٔ دیده‌نشده لازم است'];
        echo '<h3>'.h($endings[$summary['ending']]).'</h3><p>امتیاز این تجربه: '.fa($summary['score']).' از ۲۰</p><ul>';
        foreach ($summary['dimensions'] as $dimension=>$score) echo '<li>'.h(['evidence'=>'شواهد','problem'=>'تعریف مسئله','algorithm'=>'روند منطقی','testing'=>'آزمودن','transfer'=>'انتقال'][$dimension]).': '.fa($score).' از ۴</li>';
        echo '</ul><p>قبولی فقط به مجموع نمره وابسته نیست؛ حداقل هر بُعد، تست‌های حیاتی و انتقال مستقل نیز لازم‌اند.</p>';
    }
    echo '</section>';
    dr_render_results($view);
    if ($view['status']!=='abandoned') {
        echo '<section class="panel-card"><h2>شروع مجدد</h2><p>این کار یک تلاش تازه می‌سازد؛ تاریخچه و سابقهٔ مشاهدهٔ پاسخ پاک نمی‌شود. تلاش فعال فعلی کنار گذاشته خواهد شد.</p>';
        dr_form('darycheh/restart',$view); echo '<button class="btn btn-secondary" type="submit">شروع تلاش تازه</button></form></section>';
    }
    linkto('dashboard/darycheh','ذخیره شده؛ بازگشت به فهرست دریچه');
    echo '</main>';
}
