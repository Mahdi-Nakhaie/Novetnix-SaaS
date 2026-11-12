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
    if (!$course) q('INSERT INTO courses(slug,title,description,status) VALUES (?,?,?,?)',[FREE_COURSE_SLUG,FREE_COURSE_TITLE,'','published']);
    // The six-session outline and its quizzes live in one place: seed_ai_foundations().
    seed_ai_foundations();
    $database->commit();
    fwrite(STDOUT,"Free course published with six lessons and quizzes.\n");
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    throw $error;
}
