<?php
declare(strict_types=1);

function course_admin_save(string $path): never {
    require_admin();
    foreach ($_POST as $value) if (!is_string($value)) { http_response_code(422); exit('دادهٔ فرم معتبر نیست.'); }
    $pdo=db();
    try {
        $id=filter_var($_POST['id'] ?? '0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($id===false) throw new InvalidArgumentException('شناسه معتبر نیست.');
        $pdo->beginTransaction();
        if ($path==='admin/quiz-save') {
            $lesson=filter_var($_POST['lesson_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$lesson || !q('SELECT id FROM lessons WHERE id=?',[$lesson])->fetchColumn()) throw new InvalidArgumentException('جلسه پیدا نشد.');
            $pass=filter_var($_POST['pass_percent'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
            $questions=json_decode($_POST['questions'] ?? '',true,32,JSON_THROW_ON_ERROR);
            if (!$pass || !is_array($questions) || !array_is_list($questions) || !$questions || count($questions)>50) throw new InvalidArgumentException('ساختار آزمون معتبر نیست.');
            foreach ($questions as $question) {
                if (!is_array($question) || !is_string($question['prompt'] ?? null) || trim($question['prompt'])==='' || !is_array($question['options'] ?? null) || !array_is_list($question['options']) || count($question['options'])<2 || count($question['options'])>10 || !is_int($question['correct'] ?? null) || !isset($question['options'][$question['correct']])) throw new InvalidArgumentException('سؤال یا پاسخ آزمون معتبر نیست.');
                foreach ($question['options'] as $option) if (!is_string($option) || trim($option)==='') throw new InvalidArgumentException('گزینه خالی مجاز نیست.');
            }
            $json=json_encode($questions,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if (strlen($json)>100000) throw new InvalidArgumentException('آزمون بیش از حد بزرگ است.');
            $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
                ? 'INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pass_percent=VALUES(pass_percent),questions_json=VALUES(questions_json)'
                : 'INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?) ON CONFLICT(lesson_id) DO UPDATE SET pass_percent=excluded.pass_percent,questions_json=excluded.questions_json';
            q($sql,[$lesson,$pass,$json]);
        } else {
            $title=trim($_POST['title'] ?? '');
            $content=trim($_POST['content'] ?? '');
            $status=$_POST['status'] ?? 'draft';
            if (mb_strlen($title)<2 || mb_strlen($title)>180 || mb_strlen($content)<10 || mb_strlen($content)>50000 || !in_array($status,['draft','published'],true)) throw new InvalidArgumentException('عنوان، متن یا وضعیت معتبر نیست.');
            if ($path==='admin/course-save') {
                $slug=$_POST['slug'] ?? '';
                if (!in_array($slug,array_merge([FREE_COURSE_SLUG],array_keys(COURSES)),true)) throw new InvalidArgumentException('شناسهٔ دوره باید با سیاست دسترسی کاتالوگ مطابقت داشته باشد.');
                if ($id) {
                    $old=q('SELECT slug FROM courses WHERE id=?',[$id])->fetchColumn();
                    if ($old!==$slug) throw new InvalidArgumentException('شناسهٔ پایدار دوره قابل تغییر نیست.');
                    q('UPDATE courses SET title=?,description=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$title,$content,$status,$id]);
                } else q('INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)',[$slug,$title,$content,$status]);
            } else {
                $course=filter_var($_POST['course_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $position=filter_var($_POST['position'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
                if (!$course || !$position || !q('SELECT id FROM courses WHERE id=?',[$course])->fetchColumn()) throw new InvalidArgumentException('دوره یا ترتیب جلسه معتبر نیست.');
                if ($id) {
                    if (!q('SELECT id FROM lessons WHERE id=? AND course_id=?',[$id,$course])->fetchColumn()) throw new InvalidArgumentException('جلسه متعلق به این دوره نیست.');
                    q('UPDATE lessons SET title=?,content=?,position=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$title,$content,$position,$status,$id]);
                } else q('INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)',[$course,$title,$content,$position,$status]);
            }
        }
        $pdo->commit();
        flash('محتوا با وضعیت انتخاب‌شده ذخیره شد. تغییر آزمون، تکمیل‌های نسخهٔ قبلی آن را نامعتبر می‌کند.');
    } catch (InvalidArgumentException|JsonException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('اطلاعات معتبر نیست؛ شناسه‌ها، متن، ترتیب یکتای جلسه و JSON آزمون را بررسی کنید.','error');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Course authoring failed: '.get_class($e));
        flash('ذخیره انجام نشد؛ داده تکراری یا خطای پایگاه‌داده را بررسی کنید.','error');
    }
    redirect('dashboard/courses');
}
function course_admin_forms(): void {
    echo '<section class="panel-card"><h2>ثبت یا ویرایش محتوای واقعی</h2><p>شناسهٔ صفر یعنی ایجاد. برای ویرایش از شناسهٔ فهرست استفاده کنید. فقط دوره‌های دارای سیاست دسترسی کاتالوگ پذیرفته می‌شوند.</p>';
    foreach (['course'=>'دوره','lesson'=>'جلسه'] as $kind=>$label) {
        form_start('admin/'.$kind.'-save');
        echo '<h3>'.h($label).'</h3><label>شناسه (۰ برای ایجاد)<input name="id" type="number" min="0" value="0" required></label>';
        if ($kind==='course') echo '<label>شناسهٔ متنی پایدار<input name="slug" dir="ltr" required></label>';
        else echo '<label>شناسه دوره<input name="course_id" type="number" min="1" required></label><label>ترتیب جلسه<input name="position" type="number" min="1" required></label>';
        echo '<label>عنوان<input name="title" maxlength="180" required></label><label>متن / شرح<textarea name="content" rows="5" required></textarea></label><label>وضعیت<select name="status"><option value="draft">پیش‌نویس</option><option value="published">منتشرشده</option></select></label><button class="btn btn-primary">ذخیره '.h($label).'</button></form>';
    }
    form_start('admin/quiz-save');
    echo '<h3>آزمون جلسه</h3><p>پاسخ صحیح، اندیس صفرمبنا دارد. تغییر سؤال، ارزیابی مجدد را لازم می‌کند.</p><label>شناسه جلسه<input name="lesson_id" type="number" min="1" required></label><label>درصد قبولی<input name="pass_percent" type="number" min="1" max="100" value="100" required></label><label>سؤال‌ها (JSON)<textarea name="questions" dir="ltr" rows="6" required placeholder="[{&quot;prompt&quot;:&quot;سؤال&quot;,&quot;options&quot;:[&quot;گزینه اول&quot;,&quot;گزینه دوم&quot;],&quot;correct&quot;:0}]"></textarea></label><button class="btn btn-primary">ذخیره آزمون</button></form></section>';
}
