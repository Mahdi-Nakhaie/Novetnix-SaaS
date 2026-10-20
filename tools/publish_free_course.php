<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || $argc!==2) { exit(1); }
require_once dirname(__DIR__).'/src/app.php';
$database=db();
$database->beginTransaction();
try {
    $course=q('SELECT id,slug,title,description,estimated_duration_minutes,status FROM courses WHERE slug=?',[FREE_COURSE_SLUG])->fetch();
    $lessons=$course ? q('SELECT id,course_id,title,content,position,duration_minutes,status FROM lessons WHERE course_id=? ORDER BY position',[$course['id']])->fetchAll() : [];
    $backup=json_encode(['course'=>$course,'lessons'=>$lessons],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $file=fopen($argv[1],'x');
    if (!$file) throw new RuntimeException('Cannot create content backup');
    try { if (fwrite($file,$backup)!==strlen($backup)) throw new RuntimeException('Incomplete content backup'); }
    finally { fclose($file); }
    $description='دورهٔ رایگان هوش مصنوعی برای شروع از صفر؛ جلسهٔ اول: مبانی هوش مصنوعی. جلسهٔ دوم به‌زودی منتشر می‌شود.';
    if (!$course) {
        q('INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)',[FREE_COURSE_SLUG,FREE_COURSE_TITLE,$description,'published']);
        $courseId=(int)$database->lastInsertId();
    } else {
        $courseId=(int)$course['id'];
        q('UPDATE courses SET title=?,description=?,estimated_duration_minutes=NULL,status=? WHERE id=?',[FREE_COURSE_TITLE,$description,'published',$courseId]);
    }
    $first=q('SELECT id FROM lessons WHERE course_id=? AND position=?',[$courseId,1])->fetchColumn();
    if ($first) q('UPDATE lessons SET title=?,status=? WHERE id=?',['مبانی هوش مصنوعی','published',$first]);
    else q('INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)',[$courseId,'مبانی هوش مصنوعی','جلسهٔ اول دورهٔ رایگان؛ آشنایی با مبانی هوش مصنوعی.',1,'published']);
    $second=q('SELECT id FROM lessons WHERE course_id=? AND position=?',[$courseId,2])->fetchColumn();
    if ($second) q('UPDATE lessons SET status=? WHERE id=?',['draft',$second]);
    else q('INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)',[$courseId,'جلسهٔ دوم','به‌زودی',2,'draft']);
    seed_foundations_quiz($database);
    $database->commit();
    fwrite(STDOUT,"Free course published; lesson 1 available, lesson 2 coming soon.\n");
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    throw $error;
}
