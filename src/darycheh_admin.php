<?php
declare(strict_types=1);

function dr_admin_definition(mixed $json): array {
    dr_require(is_string($json) && strlen($json)<=131072,'تعریف باید JSON و حداکثر ۱۲۸ کیلوبایت باشد.');
    try { $d=json_decode($json,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new DarychehError('JSON تعریف قابل خواندن نیست.'); }
    dr_require(is_array($d) && !array_is_list($d),'تعریف باید یک شیء JSON باشد.');
    return $d;
}
function dr_admin_validate(array $d): void {
    try { dr_validate_definition($d); }
    catch (TypeError|ValueError $e) { throw new DarychehError('نوع دادهٔ مراحل یا معیارها معتبر نیست.'); }
}
function dr_admin_post(string $path): never {
    $admin=require_admin(); $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    try {
        dr_require($id!==false,'شناسه معتبر نیست.');
        $revision=filter_var($_POST['revision'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        dr_require($revision!==false,'نسخهٔ پیش‌نویس معتبر نیست.');
        if ($path==='darycheh/admin/save') {
            $d=dr_admin_definition($_POST['definition'] ?? null);
            if (!$id) {
                q('INSERT INTO darycheh_drafts(definition_json,updated_by) VALUES (?,?)',[dr_json($d),$admin['id']]);
                $id=(int)db()->lastInsertId();
            } else {
                $saved=q('UPDATE darycheh_drafts SET definition_json=?,updated_by=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND revision=?',[dr_json($d),$admin['id'],$id,$revision]);
                dr_require($saved->rowCount()===1,'پیش‌نویس تغییر کرده است؛ دوباره آن را باز کن.',409);
            }
            flash('پیش‌نویس ذخیره شد؛ ذخیره به معنای انتشار نیست.');
        } elseif ($path==='darycheh/admin/publish') {
            $db=db(); $db->beginTransaction();
            try {
                $locked=q('UPDATE darycheh_drafts SET revision=revision+1,updated_by=? WHERE id=? AND revision=?',[$admin['id'],$id,$revision]);
                dr_require($locked->rowCount()===1,'پیش‌نویس تغییر کرده است؛ پیش‌نمایش را دوباره بررسی کن.',409);
                $draft=q('SELECT * FROM darycheh_drafts WHERE id=?',[$id])->fetch();
                $d=dr_admin_definition($draft['definition_json']); dr_admin_validate($d);
                $existing=q('SELECT * FROM darycheh_releases WHERE scenario_key=? AND content_version=?',[$d['scenario_key'],$d['content_version']])->fetch();
                if ($existing) dr_require(hash_equals($existing['definition_hash'],dr_hash($d)),'این شمارهٔ نسخه قبلاً منتشر شده است؛ برای تغییر محتوا content_version را افزایش بده.',409);
                else q("INSERT INTO darycheh_releases(scenario_key,content_version,definition_schema_version,engine_version,assessment_version,definition_hash,definition_json,availability) VALUES (?,?,?,?,?,?,?,'available')",[$d['scenario_key'],$d['content_version'],$d['definition_schema_version'],$d['engine_version'],$d['assessment_version'],dr_hash($d),dr_json($d)]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            flash($existing?'این نسخه قبلاً ثبت شده است؛ وضعیت فعال یا غیرفعال آن را از فهرست نسخه‌ها کنترل کن.':'نسخهٔ مستقل منتشر شد. تلاش‌های قبلی روی نسخهٔ خود باقی می‌مانند.');
        } elseif ($path==='darycheh/admin/availability') {
            $release=q('SELECT * FROM darycheh_releases WHERE id=?',[$id])->fetch();
            dr_require((bool)$release,'نسخه پیدا نشد.',404);
            $availability=$_POST['availability'] ?? null;
            dr_require(in_array($availability,['available','retired'],true),'وضعیت انتشار معتبر نیست.');
            if ($availability==='available') dr_admin_validate(dr_release(['release_id'=>$id,'scenario_key'=>$release['scenario_key']]));
            q('UPDATE darycheh_releases SET availability=? WHERE id=?',[$availability,$id]);
            $id=0; flash('وضعیت شروع تلاش جدید به‌روز شد؛ ادامهٔ تلاش‌های موجود محفوظ است.');
        } else throw new DarychehError('عملیات پیدا نشد.',404);
    } catch (DarychehError $e) { flash($e->getMessage(),'error'); }
    catch (PDOException $e) { flash('نسخه هم‌زمان تغییر کرده یا ذخیره نشد؛ فهرست را دوباره بررسی کن.','error'); }
    redirect('dashboard/darycheh-manage'.($id?'?id='.$id:''));
}
function dr_admin_preview(array $d): void {
    dr_admin_validate($d);
    echo '<section class="panel-card"><h2>پیش‌نمایش ساختاری معتبر: '.h($d['title']).'</h2><p>نمایش فقط‌خواندنی؛ هیچ تلاش، نمره یا انتشاری ایجاد نمی‌شود. این موتور از قرارداد ده مرحله‌ای سفارش و آزمون‌های ترتیبی پشتیبانی می‌کند، نه اجرای کد دلخواه.</p><p>شناسه: <bdi>'.h($d['scenario_key']).'</bdi> · نسخه: '.h($d['content_version']).'</p><h3>اهداف یادگیری</h3><ul>';
    foreach ($d['objectives'] as $label) echo '<li>'.h($label).'</li>';
    echo '</ul><h3>مراحل، انتخاب‌ها و مسیر پیامد</h3>';
    foreach ($d['steps'] as $key=>$step) {
        echo '<details><summary>'.h($key).' — '.h($step['title']).'</summary><p>'.h($step['text']).'</p>';
        foreach ($step['fields'] as $field) {
            echo '<h4>'.h($field['label']).'</h4><ul>';
            foreach ($field['options'] as $value=>$label) echo '<li><bdi>'.h($value).'</bdi> — '.h($label).'</li>';
            echo '</ul>';
        }
        echo '<p>مرحلهٔ بعد: '.h($step['next'] ?? 'بر اساس مسیر یا پایان').'</p>';
        foreach ($step['routes'] ?? [] as $choice=>$target) echo '<p>'.h($choice).' ← '.h($target).'</p>';
        echo '</details>';
    }
    echo '<h3>معیارهای پذیرش و پیامد مرجع</h3><ul>';
    foreach ($d['tests'] as $test) echo '<li>'.h($test['label']).' — <bdi>'.h($test['expected']).'</bdi></li>';
    echo '</ul><h3>معیارهای ارزیابی</h3><pre dir="ltr">'.h(json_encode(['rubric'=>$d['rubric'],'skills'=>$d['skill_assessment'] ?? []],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></section>';
}
function dr_admin_page(): void {
    require_admin();
    $id=filter_var($_GET['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $draft=$id?q('SELECT * FROM darycheh_drafts WHERE id=?',[$id])->fetch():false;
    echo '<main class="panel-content"><section class="panel-card"><h1>مدیریت سناریوهای دریچه</h1><p>تعریف مراحل، گزینه‌ها، پیامد، اهداف و معیارها در ویرایشگر JSON انجام می‌شود. فقط قرارداد موتور فعلی پشتیبانی می‌شود. پیش‌نویس می‌تواند ناقص باشد؛ انتشار فقط پس از اعتبارسنجی مسیرها، گزینه‌ها و معیارها مجاز است. نسخهٔ منتشرشده قابل بازنویسی نیست.</p>';
    linkto('dashboard/darycheh-manage','ایجاد پیش‌نویس تازه','btn btn-outline');
    linkto('dashboard/darycheh','مشاهدهٔ فهرست کاربر','btn btn-outline');
    echo '<h2>پیش‌نویس‌های ذخیره‌شده</h2><ul>';
    foreach (q('SELECT id,revision,updated_at FROM darycheh_drafts ORDER BY id DESC')->fetchAll() as $item) {
        echo '<li>'; linkto('dashboard/darycheh-manage?id='.$item['id'],'پیش‌نویس '.$item['id'].' · بازبینی '.$item['revision']); echo '</li>';
    }
    echo '</ul></section>';
    if ($id && !$draft) echo '<section class="panel-card" role="alert">پیش‌نویس پیدا نشد.</section>';
    else {
        $d=$draft?dr_decode($draft['definition_json']):require dirname(__DIR__).'/content/darycheh.php';
        if (!$draft) { $d['scenario_key']='new-scenario'; $d['content_version']='1.0'; $d['title']='سناریوی تازه'; }
        echo '<section class="panel-card"><h2>ویرایش تعریف سناریو</h2><p>برای سناریوی مستقل scenario_key و برای اصلاح نسخهٔ منتشرشده content_version را تغییر بده. مراحل در steps، گزینه‌ها در fields/options، مسیرها در next/routes، پیامدها در operations/tests، اهداف در objectives و ارزیابی در rubric/skill_assessment هستند. ابتدا ذخیره کن تا پیش‌نمایش و اعتبارسنجی همین بازبینی نمایش داده شود.</p>';
        form_start('darycheh/admin/save','stack-form');
        echo '<input type="hidden" name="id" value="'.h($draft['id'] ?? 0).'"><input type="hidden" name="revision" value="'.h($draft['revision'] ?? 0).'"><label>تعریف JSON<textarea name="definition" rows="28" dir="ltr" spellcheck="false" required>'.h(json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</textarea></label><button class="btn btn-primary">ذخیرهٔ پیش‌نویس و بررسی</button></form></section>';
        if ($draft) {
            try {
                dr_admin_preview($d);
                echo '<section class="panel-card">'; form_start('darycheh/admin/publish','stack-form');
                echo '<input type="hidden" name="id" value="'.h($draft['id']).'"><input type="hidden" name="revision" value="'.h($draft['revision']).'"><p>انتشار بازبینی '.h($draft['revision']).' برای تلاش‌های جدید؛ داده‌های تلاش‌های قبلی تغییر نمی‌کند.</p><button class="btn btn-primary">انتشار همین بازبینی بررسی‌شده</button></form></section>';
            } catch (DarychehError $e) { echo '<section class="panel-card" role="alert"><h2>انتشار مجاز نیست</h2><p>'.h($e->getMessage()).'</p></section>'; }
        }
    }
    echo '<section class="panel-card"><h2>نسخه‌های منتشرشده</h2><p>غیرفعال‌کردن نسخه مانع شروع جدید روی همان نسخه می‌شود؛ تلاش‌های موجود همچنان از تصویر ثابت خود استفاده می‌کنند. اگر نسخهٔ دیگری فعال باشد، آن نسخه برای شروع در دسترس می‌ماند.</p>';
    foreach (q('SELECT id,scenario_key,content_version,availability,definition_json FROM darycheh_releases ORDER BY id DESC')->fetchAll() as $release) {
        echo '<details><summary>'.h($release['scenario_key']).' · '.h($release['content_version']).' · '.($release['availability']==='available'?'فعال':'غیرفعال').'</summary>';
        form_start('darycheh/admin/save','stack-form');
        echo '<input type="hidden" name="id" value="0"><input type="hidden" name="revision" value="0"><input type="hidden" name="definition" value="'.h($release['definition_json']).'"><button class="btn btn-outline">ایجاد پیش‌نویس از این نسخه</button></form>';
        form_start('darycheh/admin/availability','stack-form');
        echo '<input type="hidden" name="id" value="'.h($release['id']).'"><input type="hidden" name="availability" value="'.($release['availability']==='available'?'retired':'available').'"><button class="btn btn-outline">'.($release['availability']==='available'?'غیرفعال‌کردن شروع جدید':'فعال‌کردن نسخه').'</button></form></details>';
    }
    echo '</section></main>';
}
