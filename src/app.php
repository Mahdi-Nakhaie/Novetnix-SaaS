<?php
declare(strict_types=1);
require_once __DIR__.'/data.php';
require_once dirname(__DIR__).'/database/connection.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/entitlements.php';
require_once __DIR__.'/workspace.php';
require_once __DIR__.'/darycheh.php';
require_once __DIR__.'/darycheh_admin.php';
require_once __DIR__.'/learning_projects.php';
require_once __DIR__.'/learning_project_views.php';
require_once __DIR__.'/payments.php';
require_once __DIR__.'/seo.php';
require_once __DIR__.'/course_admin.php';
require_once __DIR__.'/lesson_video.php';
require_once __DIR__.'/skill_profile.php';
require_once __DIR__.'/messaging.php';
require_once __DIR__.'/profile_views.php';
require_once __DIR__.'/message_views.php';
require_once __DIR__.'/glossary.php';
require_once __DIR__.'/glossary_admin.php';
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
// Array form is required: three-argument strtr() maps bytes, which corrupts multibyte Persian digits.
function fa(int|string $v): string { return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function latin_digits(string $v): string { return strtr($v,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']); }
function published_courses(): array { return q("SELECT slug,title,description FROM courses WHERE status='published' ORDER BY id ASC")->fetchAll(); }
// Published curriculum: hash, title, summary, minutes, wide summary, lesson text.
const AI_FOUNDATIONS_LESSONS = [
    ['ereh151','هوش مصنوعی چیست؟',22,'آشنایی با مفهوم هوش مصنوعی، تفاوت آن با برنامه‌نویسی معمولی و نمونه‌های واقعی از زندگی روزمره.','هوش مصنوعی شاخه‌ای از علوم رایانه است که به ساختن سامانه‌هایی می‌پردازد که کاری انجام می‌دهند که تا پیش از این به هوش انسان نیاز داشت: تشخیص چهره در عکس، ترجمهٔ متن، پیشنهاد فیلم و پاسخ دادن به پرسش‌ها.<br>تفاوت اصلی هوش مصنوعی با برنامهٔ معمولی در این است که برنامهٔ معمولی قاعده‌ها را از پیش می‌داند، اما یک مدل هوش مصنوعی این قاعده‌ها را از تعداد زیادی نمونه یاد می‌گیرد.<br>در این جلسه مسیر کلی دوره را هم مرور می‌کنیم: ابتدا مفهوم‌ها، بعد داده، سپس اولین مدل، و در پایان نمونه‌ای از کاربردهای واقعی.'],
    ['jlckro2','داده؛ سوخت هوش مصنوعی',25,'داده چیست، چه انواعی دارد و چرا کیفیت داده تعیین‌کنندهٔ کیفیت نتیجهٔ مدل است.','هر مدل هوش مصنوعی با داده آموزش می‌بیند. داده می‌تواند عدد باشد، مثل قیمت خانه؛ متن باشد، مثل نظرهای کاربران؛ یا تصویر و صدا باشد.<br>داده، مثل هر منبع دیگری، باید پاک‌سازی و آماده‌سازی شود. دادهٔ ناقص یا پر از خطا نتیجهٔ نادرست تولید می‌کند و این موضوع را اصطلاحاً «آشغال وارد شود، آشغال خارج می‌شود» می‌نامند.'],
    ['ooz6d75','یادگیری ماشین چگونه کار می‌کند؟',23,'منطق آموزش مدل: نمونه‌ها، ویژگی‌ها، پاسخ‌های درست و اصلاح خطا در طول آموزش.','یادگیری ماشین زیرمجموعهٔ هوش مصنوعی است که در آن، به‌جای نوشتن قاعده‌های ثابت، نمونه‌های کافی به کامپیوتر می‌دهیم تا خودش رابطهٔ بین ورودی و خروجی را پیدا کند.<br>برای نمونه، اگر هزاران عکس از گربه و سگ را با برچسب درست به مدل بدهیم، مدل یاد می‌گیرد عکسی را که قبلاً ندیده است دسته‌بندی کند.'],
    ['bakrn6p','نقشهٔ راه ورود به هوش مصنوعی',20,'ترتیب منطقی یادگیری از پایتون و آمار تا یادگیری ماشین و اولین پروژهٔ واقعی.','ورود به هوش مصنوعی به یک نقشهٔ راه روشن نیاز دارد. پیشنهاد ما این ترتیب است: یادگیری پایهٔ پایتون، آشنایی با کتابخانه‌هایی مانند NumPy و pandas، مرور آمار پایه، سپس اولین مدل یادگیری ماشین با scikit-learn و در پایان یک پروژهٔ کوچک کامل.<br>هدف این جلسه آن است که بدانید قدم بعدی چه چیزی است تا یادگیری‌تان پراکنده نشود.'],
    ['atrkj08','تمرین و بررسی یادگیری',20,'پایان هر بخش با تمرین، آسیب‌شناسی اشتباه‌های رایج و بررسی پیشرفت واقعی.','یادگیری فعال یعنی بعد از دیدن هر مطلب، خودتان آن را به کار ببرید. در این جلسه به تمرین‌های کوتاهی می‌پردازیم که بعد از هر مفهوم انجام‌شان می‌دهید و در پایان، اشتباه‌های رایج تازه‌کارها را مرور می‌کنیم.'],
    ['frzh61x','اولین قدم عملی',20,'پروژهٔ کوچک پایانی: از تعریف مسئله تا بررسی خروجی مدل و پرسش بعدی.','در این جلسه یک مسئلهٔ کوچک را از ابتدا تا پایان بررسی می‌کنیم: مسئله را تعریف می‌کنیم، داده را بررسی می‌کنیم، یک مدل ساده می‌سازیم و نتیجه را می‌سنجیم.<br>هدف، کامل‌بودن پروژه نیست؛ هدف این است که مسیر یک پروژهٔ هوش مصنوعی را یک بار زیر نظر بگذرانید تا در پروژهٔ بعدی خودتان شروع کنید.'],
];

function seed_ai_foundations(): void {
    $course=q("SELECT id FROM courses WHERE slug=?",['ai-foundations'])->fetchColumn();
    if (!$course) {
        q('INSERT INTO courses(slug,title,description,estimated_duration_minutes,status) VALUES (?,?,?,?,?)',['ai-foundations','ورود به دنیای هوش مصنوعی از صفر','شش جلسهٔ کوتاه و مرحله‌به‌مرحله برای شناخت هوش مصنوعی: از مفهوم‌های پایه تا داده و اولین قدم عملی.',390,'published']);
        $course=(int)db()->lastInsertId();
    }
    $course=(int)$course;
    $expected=sum_curriculum_minutes();
    q('UPDATE courses SET title=?,description=?,estimated_duration_minutes=?,status=? WHERE id=?',['ورود به دنیای هوش مصنوعی از صفر','شش جلسهٔ کوتاه و مرحله‌به‌مرحله برای شناخت هوش مصنوعی: از مفهوم‌های پایه تا داده و اولین قدم عملی.',$expected,'published',$course]);
    $seen=[];
    foreach (AI_FOUNDATIONS_LESSONS as $index=>[$hash,$title,$minutes,$summary,$text]) {
        $position=$index+1;
        $seen[]=$position;
        $id=q('SELECT id FROM lessons WHERE course_id=? AND position=?',[$course,$position])->fetchColumn();
        if ($id) q('UPDATE lessons SET title=?,content=?,duration_minutes=?,status=?,video_url=? WHERE id=?',[$title,$summary."\n\n".$text,$minutes,'published',$hash,(int)$id]);
        else q('INSERT INTO lessons(course_id,title,content,position,duration_minutes,status,video_url) VALUES (?,?,?,?,?,?,?)',[$course,$title,$summary."\n\n".$text,$position,$minutes,'published',$hash]);
    }
    // Older seeds published extra lessons; releasing them keeps the outline at
    // the six sessions the course page promises.
    $stale=q('SELECT id FROM lessons WHERE course_id=? AND status=? AND position NOT IN ('.implode(',',array_map('intval',$seen)).')',[$course,'published'])->fetchAll();
    foreach ($stale as $row) q('UPDATE lessons SET status=? WHERE id=?',['draft',(int)$row['id']]);
    // The subject-matter quiz stays in curriculum/ so editors can revise it
    // without touching PHP; every session is graded on the same question set.
    $quiz=json_decode((string)file_get_contents(dirname(__DIR__).'/curriculum/ai-foundations-session1-quiz.json'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($quiz['questions'] ?? null) || !$quiz['questions']) throw new RuntimeException('Quiz content is missing.');
    $json=json_encode($quiz['questions'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $pass=(int)$quiz['pass_percent'];
    $lessonIds=q('SELECT id FROM lessons WHERE course_id=? ORDER BY position ASC',[$course])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($lessonIds as $lessonId) {
        $sql='INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?)';
        $sql.=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' ON DUPLICATE KEY UPDATE pass_percent=VALUES(pass_percent),questions_json=VALUES(questions_json)' : ' ON CONFLICT(lesson_id) DO UPDATE SET pass_percent=excluded.pass_percent,questions_json=excluded.questions_json';
        q($sql,[(int)$lessonId,$pass,$json]);
    }
}

function sum_curriculum_minutes(): int {
    $total=0;
    foreach (AI_FOUNDATIONS_LESSONS as $lesson) $total+=(int)$lesson[2];
    return $total;
}

function seed_demo_courses(): void {
    seed_ai_foundations();
}
function verified_lesson_completion(?array $progress,?array $quiz): bool {
    return $progress!==null && $quiz!==null && (int)$progress['completed']===1 && $progress['status']==='completed' && !empty($progress['completed_at']) && hash_equals(hash('sha256',json_encode($quiz,JSON_THROW_ON_ERROR)),(string)($progress['quiz_version'] ?? ''));
}
function lesson_learning_status(?array $progress,?array $quiz=null): string {
    if ($progress===null) return 'not_started';
    $position=progress_number($progress['last_position']);
    $watched=progress_number($progress['watched_seconds']);
    $percentage=progress_number($progress['percentage']);
    if ($position===null || $watched===null || $percentage===null || $percentage>100) return 'not_started';
    if (verified_lesson_completion($progress,$quiz)) return 'completed';
    if ((int)$progress['completed']===1) return 'not_started';
    return (int)$progress['completed']===0 && $progress['status']==='started' && $progress['completed_at']===null && ($position>0 || $watched>0 || $percentage>0) ? 'in_progress' : 'not_started';
}
function course_workspace(int $userId,string $slug,?int $requestedLesson=null): ?array {
    $course=q("SELECT c.id,c.slug,c.title,c.description,c.estimated_duration_minutes FROM courses c INNER JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE c.slug=? AND c.status='published'",[$userId,$slug])->fetch();
    if (!$course || !can_course($slug,plan_for(['id'=>$userId]))) return null;
    if ($requestedLesson!==null && !q("SELECT id FROM lessons WHERE id=? AND course_id=? AND status='published'",[$requestedLesson,$course['id']])->fetch()) return null;
    $course['lessons']=q("SELECT id,title,content,position,duration_minutes,video_url FROM lessons WHERE course_id=? AND status='published' ORDER BY position ASC",[$course['id']])->fetchAll();
    $progress=[];
    foreach (q("SELECT p.lesson_id,p.watched_seconds,p.last_position,p.percentage,p.completed,p.status,p.completed_at,p.quiz_version FROM lesson_progress p INNER JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? AND l.course_id=? AND l.status='published'",[$userId,$course['id']])->fetchAll() as $row) $progress[(int)$row['lesson_id']]=$row;
    foreach ($course['lessons'] as &$lesson) $lesson['learning_status']=lesson_learning_status($progress[(int)$lesson['id']] ?? null,lesson_quiz_for_lesson((int)$lesson['id']));
    unset($lesson);
    $selectedId=$requestedLesson ?? (int)($course['lessons'][0]['id'] ?? 0);
    $course['quiz']=$selectedId ? lesson_quiz_for_lesson($selectedId) : null;
    return $course;
}
function dashboard_course_progress(int $userId): ?array {
    $course=q("SELECT c.id FROM courses c INNER JOIN enrollments e ON e.course_slug=c.slug WHERE e.user_id=? AND c.status='published' ORDER BY e.created_at DESC,c.id DESC LIMIT 1",[$userId])->fetch();
    return $course ? dashboard_course_progress_for_course($userId,(int)$course['id']) : null;
}
function dashboard_active_lesson(int $userId,array $courseProgress): array {
    $lessons=q("SELECT id,title,position FROM lessons WHERE course_id=? AND status='published' ORDER BY position ASC,id ASC",[$courseProgress['id']])->fetchAll();
    if (!$lessons) return ['course_status'=>$courseProgress['status'],'lesson'=>null,'lesson_status'=>null];
    $rows=q("SELECT p.id,p.lesson_id,p.last_position,p.completed,p.updated_at FROM lesson_progress p INNER JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? AND l.course_id=? AND l.status='published' ORDER BY p.updated_at DESC,p.id DESC",[$userId,$courseProgress['id']])->fetchAll();
    $progress=[]; $active=null; $latestCompleted=null;
    foreach ($rows as $row) {
        $id=(int)$row['lesson_id'];
        if (isset($progress[$id])) continue;
        $progress[$id]=$row;
        if (in_array($id,$courseProgress['verified_lessons'],true) && $latestCompleted===null) $latestCompleted=$id;
        if (!in_array($id,$courseProgress['verified_lessons'],true) && progress_number($row['last_position'])!==null && $active===null) $active=$id;
    }
    if ($courseProgress['status']==='completed') {
        foreach ($lessons as $lesson) if ((int)$lesson['id']===$latestCompleted) return ['course_status'=>'completed','lesson'=>$lesson,'lesson_status'=>'completed'];
        return ['course_status'=>'completed','lesson'=>null,'lesson_status'=>null];
    }
    if ($active!==null) foreach ($lessons as $lesson) if ((int)$lesson['id']===$active) return ['course_status'=>$courseProgress['status'],'lesson'=>$lesson,'lesson_status'=>'in_progress'];
    $completedPosition=null;
    foreach ($lessons as $lesson) if ((int)$lesson['id']===$latestCompleted) $completedPosition=(int)$lesson['position'];
    $next=null;
    foreach ($lessons as $lesson) {
        if (in_array((int)$lesson['id'],$courseProgress['verified_lessons'],true)) continue;
        if ($next===null) $next=$lesson;
        if ($completedPosition!==null && (int)$lesson['position']>$completedPosition) return ['course_status'=>$courseProgress['status'],'lesson'=>$lesson,'lesson_status'=>'not_started'];
    }
    return ['course_status'=>$courseProgress['status'],'lesson'=>$next,'lesson_status'=>$next?'not_started':null];
}
function course_duration_minutes(array $course): ?int {
    if ($course['estimated_duration_minutes']!==null) return (int)$course['estimated_duration_minutes'];
    $durations=array_column($course['lessons'],'duration_minutes');
    return $durations && !in_array(null,$durations,true) ? (int)array_sum($durations) : null;
}
function course_workspace_selection(?array $course,string $requested): array {
    if (!$course || !$course['lessons']) return ['current'=>null,'index'=>-1,'previous'=>null,'next'=>null];
    $index=0;
    if ($requested!=='') {
        $lessonId=filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($lessonId===false || $lessonId===null) return ['current'=>null,'index'=>-1,'previous'=>null,'next'=>null];
        $index=-1;
        foreach ($course['lessons'] as $i=>$lesson) if ((int)$lesson['id']===$lessonId) { $index=$i; break; }
        if ($index<0) return ['current'=>null,'index'=>-1,'previous'=>null,'next'=>null];
    }
    return ['current'=>$course['lessons'][$index],'index'=>$index,'previous'=>$course['lessons'][$index-1] ?? null,'next'=>$course['lessons'][$index+1] ?? null];
}
function url(string $path=''): string { return '/'.ltrim($path,'/'); }
function plan_for(array $u): string {
    $row=q("SELECT plan FROM subscriptions WHERE user_id=? AND status='active' AND starts_at<=? AND expires_at>? ORDER BY expires_at DESC LIMIT 1",[$u['id'],gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')])->fetch();
    return $row && isset(PLANS[$row['plan']]) ? $row['plan'] : 'free';
}
// Course access is granted by a purchase or by the plan tier that unlocks the
// course; can_course() keeps the plan-only form used by catalog filtering.
function can_course(string $slug, string $plan): bool {
    if ($slug===FREE_COURSE_SLUG) return true;
    if (!COMMERCE_ENABLED) return false;
    return entitlement_at_least($plan,entitlement_course_tier($slug));
}
function nova_answer(array $messages): ?string {
    $key=config_value('AVALAI_API_KEY');
    if (!$key || !function_exists('curl_init')) return null;
    $ch=curl_init('https://api.avalai.ir/v1/chat/completions');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>35,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>config_value('AVALAI_MODEL') ?: 'gpt-6-astra','messages'=>$messages,'max_tokens'=>700],JSON_THROW_ON_ERROR),
    ]);
    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body===false || $status<200 || $status>=300 || strlen($body)>1048576) return null;
    $data=json_decode($body,true);
    $answer=$data['choices'][0]['message']['content'] ?? null;
    return is_string($answer) && trim($answer)!=='' ? mb_substr(trim($answer),0,6000) : null;
}
function progress_response(int $status,array $body): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($body,JSON_THROW_ON_ERROR); exit;
}
function authorized_progress_lesson(int $userId,int $lessonId): bool {
    $lesson=q("SELECT l.id FROM lessons l INNER JOIN courses c ON c.id=l.course_id INNER JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE l.id=? AND l.status='published' AND c.status='published'",[$userId,$lessonId])->fetch();
    if (!$lesson) return false;
    $slug=q('SELECT c.slug FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=?',[$lessonId])->fetchColumn();
    return can_course($slug,plan_for(['id'=>$userId]));
}
function progress_number(mixed $value): ?float {
    if ($value===null || $value==='' || !is_numeric($value)) return null;
    $number=(float)$value;
    return is_finite($number) && $number>=0 ? $number : null;
}
function lesson_progress_for_user(array $u,string $requestedLesson): void {
    $lessonId=filter_var($requestedLesson,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($lessonId===false || $lessonId===null) progress_response(404,['error'=>'Lesson پیدا نشد یا دسترسی ندارید.']);
    try {
        if (!authorized_progress_lesson((int)$u['id'],$lessonId)) progress_response(404,['error'=>'Lesson پیدا نشد یا دسترسی ندارید.']);
        $progress=q('SELECT lesson_id,last_position,watched_seconds,percentage,completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=? AND lesson_id=?',[$u['id'],$lessonId])->fetch();
        if ($progress) $progress['completed']=verified_lesson_completion($progress,lesson_quiz_for_lesson($lessonId)) ? 1 : 0;
    } catch (Throwable $e) {
        progress_response(500,['error'=>'دریافت پیشرفت انجام نشد.']);
    }
    progress_response(200,$progress ? (function () use ($progress) {
        $position=progress_number($progress['last_position']);
        return [
            'lesson_id'=>(int)$progress['lesson_id'],
            'last_position'=>$position,
            'watched_seconds'=>progress_number($progress['watched_seconds']),
            'percentage'=>progress_number($progress['percentage']),
            'completed'=>(bool)$progress['completed'],
            'position_valid'=>$position!==null,
            'progress_status'=>$position===null?'invalid':'valid'
        ];
    })() : ['lesson_id'=>$lessonId,'last_position'=>0,'watched_seconds'=>0,'percentage'=>0,'completed'=>false,'position_valid'=>true,'progress_status'=>'missing']);
}
function lesson_quiz_for_lesson(int $lessonId): ?array {
    $row=q('SELECT pass_percent,questions_json FROM lesson_quizzes WHERE lesson_id=?',[$lessonId])->fetch();
    if (!$row) return null;
    $pass=(int)$row['pass_percent'];
    $questions=json_decode($row['questions_json'],true);
    if ($pass<1 || $pass>100 || !is_array($questions) || !$questions || array_keys($questions)!==range(0,count($questions)-1)) return null;
    foreach ($questions as $question) {
        if (!is_array($question) || !is_string($question['prompt'] ?? null) || trim($question['prompt'])==='') return null;
        $options=$question['options'] ?? null;
        if (!is_array($options) || count($options)<2 || array_keys($options)!==range(0,count($options)-1)) return null;
        foreach ($options as $option) if (!is_string($option) || trim($option)==='') return null;
        if (!is_int($question['correct'] ?? null) || $question['correct']<0 || $question['correct']>=count($options)) return null;
    }
    return ['pass_percent'=>$pass,'questions'=>$questions];
}
function lesson_quiz_passed(array $quiz,array $answers): ?bool {
    if (count($answers)!==count($quiz['questions'])) return null;
    $correct=0;
    foreach ($quiz['questions'] as $i=>$question) {
        $answer=$answers[$i] ?? null;
        if (!is_string($answer) || !preg_match('/^(0|[1-9][0-9]*)$/D',$answer) || (int)$answer>=count($question['options'])) return null;
        if ((int)$answer===$question['correct']) $correct++;
    }
    return $correct*100 >= $quiz['pass_percent']*count($quiz['questions']);
}
// Single source of truth for lead stages: ordered stages and their thresholds.
const LEAD_STAGES = [
    ['stage'=>'registered','label'=>'Registered','threshold'=>0.0],
    ['stage'=>'verified','label'=>'Verified','threshold'=>0.0],
    ['stage'=>'qualified','label'=>'Qualified','threshold'=>50.0],
    ['stage'=>'high_intent','label'=>'High Intent','threshold'=>100.0],
];
function dashboard_course_progress_for_course(int $userId,int $courseId): ?array {
    $course=q("SELECT c.id,c.slug,c.title FROM courses c INNER JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE c.id=? AND c.status='published'",[$userId,$courseId])->fetch();
    if (!$course) return null;
    $lessons=q("SELECT l.id,p.completed,p.status,p.completed_at,p.quiz_version FROM lessons l LEFT JOIN lesson_progress p ON p.lesson_id=l.id AND p.user_id=? WHERE l.course_id=? AND l.status='published'",[$userId,$course['id']])->fetchAll();
    $total=count($lessons); $completed=0; $verified=[];
    foreach ($lessons as $lesson) {
        if (verified_lesson_completion($lesson,lesson_quiz_for_lesson((int)$lesson['id']))) {
            $completed++;
            $verified[]=(int)$lesson['id'];
        }
    }
    $status=$total>0 && $completed===$total ? 'completed' : ($completed>0 ? 'in_progress' : 'not_started');
    return ['id'=>(int)$course['id'],'title'=>$course['title'],'slug'=>$course['slug'],'total'=>$total,'completed'=>$completed,'remaining'=>$total-$completed,'percentage'=>$total ? round($completed/$total*100,1) : 0,'status'=>$status,'verified_lessons'=>$verified];
}
function lead_progress_summary(int $userId): ?array {
    $courses=q("SELECT c.id FROM courses c INNER JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE c.status='published' ORDER BY c.id ASC",[$userId])->fetchAll();
    $best=null;
    foreach ($courses as $course) {
        $progress=dashboard_course_progress_for_course($userId,(int)$course['id']);
        if ($progress===null || $progress['total']===0) continue;
        $percentage=$progress['completed']/$progress['total']*100;
        if ($best===null || $percentage>$best['percentage']) $best=array_replace($progress,['percentage'=>$percentage]);
    }
    return $best;
}
function lead_progress_percentage(int $userId): ?float {
    $progress=lead_progress_summary($userId);
    return $progress['percentage'] ?? null;
}
function lead_stage_for_user(int $userId): ?array {
    $user=q('SELECT phone_verified_at FROM users WHERE id=?',[$userId])->fetch();
    if (!$user) return null;
    $percentage=null;
    // While SMS is off every account is unverified, so its own password stands in for the phone check.
    if ($user['phone_verified_at']!==null || !SMS_VERIFICATION_ENABLED) {
        $index=1;
        $percentage=lead_progress_percentage($userId);
        if ($percentage!==null) foreach (LEAD_STAGES as $i=>$rule) if ($i>=2 && $percentage>=$rule['threshold']) $index=$i;
    }
    return ['stage'=>LEAD_STAGES[$index]['stage'],'label'=>LEAD_STAGES[$index]['label'],'index'=>$index,'percentage'=>$percentage];
}
function record_learning_event_once(int $userId,string $eventName,string $key,int $id,array $metadata): void {
    foreach (q('SELECT metadata FROM activities WHERE user_id=? AND event_name=?',[$userId,$eventName])->fetchAll() as $event) {
        $stored=json_decode((string)$event['metadata'],true);
        if (is_array($stored) && ($stored[$key] ?? null)===$id) return;
    }
    record_activity_event($userId,$eventName,$metadata);
}
function course_lessons_complete(int $userId,int $courseId): bool {
    $lessons=q("SELECT l.id,p.completed,p.status,p.completed_at,p.quiz_version FROM lessons l LEFT JOIN lesson_progress p ON p.lesson_id=l.id AND p.user_id=? WHERE l.course_id=? AND l.status='published'",[$userId,$courseId])->fetchAll();
    if (!$lessons) return false;
    foreach ($lessons as $lesson) if (!verified_lesson_completion($lesson,lesson_quiz_for_lesson((int)$lesson['id']))) return false;
    return true;
}
function submit_lesson_quiz(array $u): void {
    $lessonId=filter_var($_POST['lesson_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($lessonId===false || $lessonId===null || !authorized_progress_lesson((int)$u['id'],$lessonId)) progress_response(404,['error'=>'Lesson پیدا نشد یا دسترسی ندارید.']);
    $course=q('SELECT c.id,c.slug FROM lessons l INNER JOIN courses c ON c.id=l.course_id WHERE l.id=?',[$lessonId])->fetch();
    $target='dashboard/course/'.$course['slug'].'?lesson='.$lessonId;
    $quiz=lesson_quiz_for_lesson($lessonId);
    if ($quiz===null) { flash('آزمون معتبر برای این درس منتشر نشده است.','error'); redirect($target); }
    $answers=$_POST['answers'] ?? null;
    $passed=is_array($answers) ? lesson_quiz_passed($quiz,$answers) : null;
    if ($passed===null) { flash('پاسخ‌های آزمون معتبر نیستند.','error'); redirect($target); }
    $userId=(int)$u['id']; $courseId=(int)$course['id'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('SELECT user_id FROM enrollments WHERE user_id=? AND course_slug=? FOR UPDATE',[$userId,$course['slug']]);
        else q('UPDATE enrollments SET created_at=created_at WHERE user_id=? AND course_slug=?',[$userId,$course['slug']]);
        record_learning_event_once($userId,'course_started','course_id',$courseId,['course_id'=>$courseId]);
        record_learning_event_once($userId,'lesson_started','lesson_id',$lessonId,['course_id'=>$courseId,'lesson_id'=>$lessonId]);
        if ($passed) {
            $at=gmdate('Y-m-d H:i:s');
            $version=hash('sha256',json_encode($quiz,JSON_THROW_ON_ERROR));
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at,quiz_version) VALUES (?,?,1,'completed',?,?) ON DUPLICATE KEY UPDATE completed_at=IF(completed=1 AND completed_at IS NOT NULL,completed_at,VALUES(completed_at)),completed=1,status='completed',quiz_version=VALUES(quiz_version),updated_at=CURRENT_TIMESTAMP",[$userId,$lessonId,$at,$version]);
            else q("INSERT INTO lesson_progress(user_id,lesson_id,completed,status,completed_at,quiz_version) VALUES (?,?,1,'completed',?,?) ON CONFLICT(user_id,lesson_id) DO UPDATE SET completed_at=CASE WHEN lesson_progress.completed=1 AND lesson_progress.completed_at IS NOT NULL THEN lesson_progress.completed_at ELSE excluded.completed_at END,completed=1,status='completed',quiz_version=excluded.quiz_version,updated_at=CURRENT_TIMESTAMP",[$userId,$lessonId,$at,$version]);
            $progress=q('SELECT completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=? AND lesson_id=?',[$userId,$lessonId])->fetch();
            if (verified_lesson_completion($progress ?: null,lesson_quiz_for_lesson($lessonId))) {
                record_learning_event_once($userId,'lesson_completed','lesson_id',$lessonId,['course_id'=>$courseId,'lesson_id'=>$lessonId]);
                if (course_lessons_complete($userId,$courseId)) record_learning_event_once($userId,'course_completed','course_id',$courseId,['course_id'=>$courseId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        progress_response(500,['error'=>'ثبت وضعیت یادگیری انجام نشد.']);
    }
    if (!$passed) { flash('در آزمون پذیرفته نشدید؛ می‌توانید دوباره تلاش کنید.','error'); redirect($target); }
    flash('آزمون را با موفقیت گذراندید؛ درس تکمیل شد.'); redirect($target);
}
function save_lesson_progress(array $u): void {
    if (isset($_POST['quiz_submit'])) { submit_lesson_quiz($u); return; }
    $lessonId=filter_var($_POST['lesson_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $completed=filter_var($_POST['completed'] ?? null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    $watched=progress_number($_POST['watched_seconds'] ?? null);
    $position=progress_number($_POST['last_position'] ?? null);
    $percentage=progress_number($_POST['percentage'] ?? null);
    if ($lessonId===false || $lessonId===null || $watched===null || $position===null || $percentage===null || $completed===null || $percentage>100) progress_response(422,['error'=>'اطلاعات پیشرفت معتبر نیست.']);
    if (!authorized_progress_lesson((int)$u['id'],$lessonId)) progress_response(404,['error'=>'Lesson پیدا نشد یا دسترسی ندارید.']);
    try {
        if ($completed) {
            $existing=q('SELECT completed,status,completed_at,quiz_version FROM lesson_progress WHERE user_id=? AND lesson_id=?',[$u['id'],$lessonId])->fetch();
            if (verified_lesson_completion($existing ?: null,lesson_quiz_for_lesson($lessonId))) progress_response(200,['saved'=>true]);
            progress_response(422,['error'=>'تکمیل درس بدون معیار تأییدشدهٔ سمت سرور قابل ثبت نیست.']);
        }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds,last_position,percentage) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE watched_seconds=GREATEST(watched_seconds,VALUES(watched_seconds)),last_position=VALUES(last_position),percentage=GREATEST(percentage,VALUES(percentage)),updated_at=CURRENT_TIMESTAMP",[$u['id'],$lessonId,$watched,$position,$percentage]);
        else q("INSERT INTO lesson_progress(user_id,lesson_id,watched_seconds,last_position,percentage) VALUES (?,?,?,?,?) ON CONFLICT(user_id,lesson_id) DO UPDATE SET watched_seconds=MAX(lesson_progress.watched_seconds,excluded.watched_seconds),last_position=excluded.last_position,percentage=MAX(lesson_progress.percentage,excluded.percentage),updated_at=CURRENT_TIMESTAMP",[$u['id'],$lessonId,$watched,$position,$percentage]);
    } catch (Throwable $e) {
        progress_response(500,['error'=>'ذخیره پیشرفت انجام نشد.']);
    }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['saved'=>true],JSON_THROW_ON_ERROR); exit;
}
function handle_post(string $path): void {
    if (handle_auth_post($path)) return;
    validate_csrf();
    if (str_starts_with($path,'darycheh/admin/')) dr_admin_post($path);
    if (str_starts_with($path,'darycheh/')) dr_api($path);
    if (str_starts_with($path,'workspace/')) ws_post($path);
    if ($path==='contact/send') {
        $name=trim((string)($_POST['name'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $message=trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($message)<10 || mb_strlen($message)>3000) { flash('نام، ایمیل و پیام معتبر وارد کنید.','error'); redirect('contact'); }
        q('INSERT INTO contact_messages(name,email,message) VALUES (?,?,?)',[$name,$email,$message]); flash('پیام شما ثبت شد.'); redirect('contact');
    }
    if ($path==='cart/add') cart_add((string)($_POST['key'] ?? ''));
    if ($path==='cart/remove') cart_remove((string)($_POST['key'] ?? ''));
    if ($path==='cart/clear') cart_clear();
    if ($path==='payment/start' && !user()) {
        $selected=(string)($_POST['plan'] ?? ''); $key=(string)($_POST['request_key'] ?? '');
        if (!plan_is_available($selected) || $selected==='free' || !preg_match('/^[a-f0-9]{32}$/D',$key)) { flash('این پلن فعلاً برای خرید در دسترس نیست.','error'); redirect('pricing'); }
        $_SESSION['pending_plan_purchase']=['plan'=>$selected,'request_key'=>$key,'expires_at'=>time()+900];
        $_SESSION['auth_return_to']='payment/continue';
        redirect('login');
    }
    $u=require_user(); $plan=plan_for($u);
    if (glossary_admin_post($path)) return;
    if ($path==='admin/course-save' || $path==='admin/lesson-save' || $path==='admin/quiz-save') course_admin_save($path);
    if ($path==='progress/save') { save_lesson_progress($u); }
    if ($path==='payment/start') payment_start($u);
    if ($path==='cart/checkout') payment_cart_start($u);
    if ($path==='payment/retry') {
        $order=q('SELECT * FROM payment_orders WHERE id=? AND user_id=?',[(int)($_POST['id'] ?? 0),$u['id']])->fetch();
        if (!$order || !$order['authority']) { http_response_code(404); exit; }
        try { $paid=payment_verify_order($order); flash($paid?'پرداخت تأیید و اشتراک فعال شد.':'پرداخت هنوز تأیید نشده است.',$paid?'success':'error'); }
        catch (PaymentError $e) { flash($e->getMessage(),'error'); }
        redirect('dashboard/payment-history');
    }
    if ($path==='nova/ask') {
        flash('Nova AI — به‌زودی','error');
        redirect('dashboard/nova');
    }
    if ($path==='profile/save') {
        $name=trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100) { flash('نام باید بین ۲ تا ۱۰۰ نویسه باشد.','error'); redirect('dashboard/profile'); }
        q('UPDATE users SET name=? WHERE id=?',[$name,$u['id']]); flash('پروفایل ذخیره شد.'); redirect('dashboard/profile');
    }
    if ($path==='courses/enroll') {
        $slug=trim((string)($_POST['slug'] ?? ''));
        $course=q("SELECT slug FROM courses WHERE slug=? AND status='published'",[$slug])->fetch();
        if (!$course) { http_response_code(404); exit; }
        if (!can_course($slug,$plan)) { flash('ثبت‌نام این دوره فعلاً در دسترس نیست؛ دوره مقدماتی هوش مصنوعی رایگان است.','error'); redirect('course/'.FREE_COURSE_SLUG); }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$u['id'],$slug]);
        else q('INSERT OR IGNORE INTO enrollments(user_id,course_slug) VALUES (?,?)',[$u['id'],$slug]);
        flash('دوره به مسیر یادگیری شما اضافه شد.'); redirect('dashboard/learning');
    }
    if ($path==='projects/start') {
        $index=filter_var($_POST['index'] ?? '',FILTER_VALIDATE_INT);
        if ($index===false || $index<0 || !isset(PROJECTS[$index])) { http_response_code(404); exit; }
        try { $workspaceId=ws_create((int)$u['id'],0,PROJECTS[$index]['title'],100+$index,lp_starter(PROJECTS[$index])); }
        catch (InvalidArgumentException $e) { flash($e->getMessage(),'error'); redirect('project/'.$index); }
        redirect('dashboard/workspace/'.$workspaceId);
    }
    if ($path==='community/create' || $path==='community/reply') {
        $body=trim((string)($_POST['body'] ?? ''));
        if (mb_strlen($body)<10 || mb_strlen($body)>5000) { flash('متن باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.','error'); redirect('community'); }
        if ($path==='community/create') {
            $title=trim((string)($_POST['title'] ?? ''));
            if (mb_strlen($title)<5 || mb_strlen($title)>180) { flash('عنوان باید بین ۵ تا ۱۸۰ نویسه باشد.','error'); redirect('community'); }
            q('INSERT INTO community_posts(user_id,title,body) VALUES (?,?,?)',[$u['id'],$title,$body]);
        } else {
            $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT);
            if (!$id || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { http_response_code(404); exit; }
            q('INSERT INTO community_replies(post_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],$body]);
        }
        flash('پیام شما در انجمن ثبت شد.'); redirect('community');
    }
    if ($path==='admin/activate') {
        require_admin();
        if (!COMMERCE_ENABLED) { http_response_code(403); exit('فعال‌سازی اشتراک و پرداخت موقتاً غیرفعال است.'); }
        $phone=normalized_phone((string)($_POST['phone'] ?? '')); $selected=(string)($_POST['plan'] ?? '');
        if (!$phone || !isset(PLANS[$selected]) || $selected==='free') { flash('شماره و پلن معتبر وارد کنید.','error'); redirect('dashboard/subscriptions'); }
        $target=q('SELECT id FROM users WHERE phone=?',[$phone])->fetch();
        if (!$target) { flash('کاربر باید ابتدا با شماره خود ثبت‌نام کرده باشد.','error'); redirect('dashboard/subscriptions'); }
        q("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'",[$target['id']]);
        q('INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (?,?,\'active\',?,?)',[$target['id'],$selected,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+30*86400)]);
        flash('اشتراک ۳۰ روزه فعال شد.'); redirect('dashboard/subscriptions');
    }
    if ($path==='challenges/start') {
        $index=filter_var($_POST['index'] ?? '',FILTER_VALIDATE_INT);
        if ($index===false || !isset(CHALLENGES[$index])) { http_response_code(404); exit; }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q("INSERT INTO challenge_progress(user_id,challenge_index,status) VALUES (?,?,'started') ON DUPLICATE KEY UPDATE status='started'",[$u['id'],$index]);
        else q("INSERT INTO challenge_progress(user_id,challenge_index,status) VALUES (?,?,'started') ON CONFLICT(user_id,challenge_index) DO UPDATE SET status='started'",[$u['id'],$index]);
        flash('چالش به فهرست شما اضافه شد.'); redirect('dashboard/challenges');
    }
    if ($path==='community/comment') {
        $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT); $body=trim((string)($_POST['body'] ?? ''));
        if (!$id || mb_strlen($body)<2 || mb_strlen($body)>2000 || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { flash('نظر معتبر وارد کنید.','error'); redirect('dashboard/community'); }
        q('INSERT INTO community_replies(post_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],$body]); flash('نظر شما ثبت شد.'); redirect('dashboard/community');
    }
    if ($path==='community/follow') {
        $target=filter_var($_POST['user_id'] ?? '',FILTER_VALIDATE_INT);
        if (!$target || $target===$u['id'] || !q('SELECT id FROM users WHERE id=?',[$target])->fetch()) { http_response_code(404); exit; }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$u['id'],$target]);
        else q('INSERT OR IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$u['id'],$target]);
        flash('این کاربر به فهرست دنبال‌شده‌ها اضافه شد.'); redirect('dashboard/community');
    }
    if ($path==='messages/send') {
        $recipient=filter_var($_POST['recipient'] ?? '',FILTER_VALIDATE_INT);
        $recipient=$recipient ? (int)$recipient : 0;
        $returnTo=(string)($_POST['return_to'] ?? '');
        $returnTo=preg_match('~^(member|messages)/[1-9][0-9]*$~D',$returnTo) && (int)substr($returnTo,strpos($returnTo,'/')+1)===$recipient ? $returnTo : ($recipient ? 'member/'.$recipient : 'messages');
        $result=message_send((int)$u['id'],$recipient,(string)($_POST['body'] ?? ''),(string)($_POST['form_token'] ?? ''));
        $_SESSION['message_notice']=$result ?? (message_peer_state((int)$u['id'],$recipient)==='request' ? 'request' : 'sent');
        redirect($returnTo);
    }
    if ($path==='messages/accept' || $path==='messages/decline') {
        $requester=filter_var($_POST['requester'] ?? '',FILTER_VALIDATE_INT);
        if (!$requester) { http_response_code(404); exit; }
        if ($path==='messages/accept') { message_accept((int)$requester,(int)$u['id']); $_SESSION['message_notice']='accepted'; }
        else q('DELETE FROM message_requests WHERE sender_id=? AND recipient_id=?',[$requester,$u['id']]);
        redirect('messages');
    }
    if ($path==='tickets/reply') {
        $ticket=filter_var($_POST['ticket_id'] ?? '',FILTER_VALIDATE_INT); $body=trim((string)($_POST['body'] ?? ''));
        $owned=$ticket ? q('SELECT id FROM tickets WHERE id=? AND user_id=?',[$ticket,$u['id']])->fetch() : false;
        if (!$owned || mb_strlen($body)<2 || mb_strlen($body)>5000) { flash('پاسخ معتبر وارد کنید.','error'); redirect('dashboard/support'); }
        q('INSERT INTO ticket_replies(ticket_id,user_id,body) VALUES (?,?,?)',[$ticket,$u['id'],$body]); q("UPDATE tickets SET status='open' WHERE id=?",[$ticket]); flash('پاسخ شما به تیکت اضافه شد.'); redirect('dashboard/support');
    }
    if ($path==='community/delete') {
        $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT);
        $target=($_POST['return_to'] ?? '')==='community' ? 'community' : 'dashboard/community';
        if (!$id || ($_POST['confirm'] ?? '')!=='delete') { http_response_code(422); exit('حذف پست را تأیید کنید.'); }
        $pdo=db(); $pdo->beginTransaction();
        try {
            $post=q('SELECT user_id FROM community_posts WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id])->fetch();
            if (!$post || ((int)$post['user_id']!==(int)$u['id'] && $u['role']!=='admin')) { $pdo->rollBack(); http_response_code(404); exit; }
            q('DELETE FROM post_reactions WHERE post_id=?',[$id]);
            q('DELETE FROM community_replies WHERE post_id=?',[$id]);
            q('DELETE FROM community_posts WHERE id=?',[$id]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        flash('پست و پاسخ‌های آن حذف شد.'); redirect($target);
    }
    if ($path==='account/delete') {
        if (($_POST['confirmation'] ?? '')!=='حذف حساب') { flash('برای تأیید، عبارت «حذف حساب» را وارد کنید.','error'); redirect('dashboard/profile'); }
        $attempts=(int)($_SESSION['delete_account_attempts'] ?? 0);
        if ($attempts>=5) { http_response_code(429); exit('تعداد تلاش‌ها بیش از حد مجاز است. دوباره وارد شوید.'); }
        $_SESSION['delete_account_attempts']=$attempts+1;
        $account=q('SELECT password_hash,role FROM users WHERE id=?',[$u['id']])->fetch();
        if (!$account || $account['role']!=='student') { http_response_code(403); exit('حذف حساب مدیریتی از این بخش مجاز نیست.'); }
        if (!password_verify((string)($_POST['password'] ?? ''),$account['password_hash'])) { flash('رمز عبور صحیح نیست؛ حساب حذف نشد.','error'); redirect('dashboard/profile'); }
        if (q('SELECT 1 FROM darycheh_drafts WHERE updated_by=? LIMIT 1',[$u['id']])->fetchColumn()) { flash('ابتدا از پشتیبانی بخواهید مسئولیت پیش‌نویس‌های مدیریتی قبلی شما را منتقل کند؛ حساب هنوز حذف نشده است.','error'); redirect('dashboard/profile'); }
        if (!delete_account((int)$u['id'])) { flash('ابتدا اجراهای فعال میزکار و دانلودهای در حال انجام را متوقف کنید؛ سپس دوباره حساب را حذف کنید.','error'); redirect('dashboard/profile'); }
        logout_user(); flash('حساب و داده‌های وابستهٔ شما حذف شد.'); redirect('login');
    }
    if ($path==='community/react') {
        $id=filter_var($_POST['post_id'] ?? '',FILTER_VALIDATE_INT); $kind=(string)($_POST['kind'] ?? '');
        if (!$id || !in_array($kind,['like','save'],true) || !q('SELECT id FROM community_posts WHERE id=?',[$id])->fetch()) { http_response_code(404); exit; }
        $active=(string)($_POST['active'] ?? '1');
        if (!in_array($active,['0','1'],true)) { http_response_code(422); exit; }
        if ($active==='0') q('DELETE FROM post_reactions WHERE post_id=? AND user_id=? AND kind=?',[$id,$u['id'],$kind]);
        elseif (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO post_reactions(post_id,user_id,kind) VALUES (?,?,?)',[$id,$u['id'],$kind]);
        else q('INSERT OR IGNORE INTO post_reactions(post_id,user_id,kind) VALUES (?,?,?)',[$id,$u['id'],$kind]);
        redirect(($_POST['return_to'] ?? '')==='community' ? 'community' : 'dashboard/community');
    }
    if ($path==='tickets/create') {
        $subject=trim((string)($_POST['subject'] ?? '')); $body=trim((string)($_POST['body'] ?? '')); $priority=(string)($_POST['priority'] ?? 'متوسط');
        if (mb_strlen($subject)<5 || mb_strlen($subject)>180 || mb_strlen($body)<10 || mb_strlen($body)>5000 || !in_array($priority,TICKET_PRIORITIES,true)) { flash('موضوع، متن و اولویت معتبر وارد کنید.','error'); redirect('dashboard/support'); }
        $pdo=db(); $pdo->beginTransaction();
        try {
            q('INSERT INTO tickets(user_id,subject,priority) VALUES (?,?,?)',[$u['id'],$subject,$priority]);
            q('INSERT INTO ticket_replies(ticket_id,user_id,body) VALUES (?,?,?)',[$pdo->lastInsertId(),$u['id'],$body]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('تیکت شما ثبت شد.'); redirect('dashboard/support');
    }
    if ($path==='profile/image') {
        $raw=null; $mime='';
        $cropped=trim((string)($_POST['cropped_image'] ?? ''));
        if ($cropped!=='') {
            if (!preg_match('~^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$~D',$cropped,$match)) { flash('نسخهٔ برش‌خوردهٔ تصویر معتبر نیست.','error'); redirect('dashboard/profile'); }
            $raw=base64_decode($match[1],true);
            if ($raw===false) { flash('نسخهٔ برش‌خوردهٔ تصویر معتبر نیست.','error'); redirect('dashboard/profile'); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error']!==UPLOAD_ERR_NO_FILE) {
            $upload=$_FILES['profile_image'];
            if ($upload['error']!==UPLOAD_ERR_OK || $upload['size']>2097152 || !is_uploaded_file($upload['tmp_name'])) { flash('تصویر معتبر نیست؛ فایل JPG، PNG یا WebP تا حداکثر ۲ مگابایت انتخاب کنید.','error'); redirect('dashboard/profile'); }
            $raw=file_get_contents($upload['tmp_name']);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($raw===false?'':$raw);
        }
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!is_string($raw) || strlen($raw)>2097152 || !$raw || !isset($types[$mime]) || @getimagesizefromstring($raw)===false) { flash('تصویر معتبر نیست؛ فایل JPG، PNG یا WebP تا حداکثر ۲ مگابایت انتخاب کنید.','error'); redirect('dashboard/profile'); }
        $directory=dirname(__DIR__).'/public/uploads/profiles';
        if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) { flash('ذخیرهٔ تصویر ممکن نشد.','error'); redirect('dashboard/profile'); }
        $image=bin2hex(random_bytes(16)).'.'.$types[$mime];
        if (file_put_contents($directory.'/'.$image,$raw,LOCK_EX)!==strlen($raw)) { flash('ذخیرهٔ تصویر ممکن نشد.','error'); redirect('dashboard/profile'); }
        if (is_string($u['profile_image'] ?? null) && preg_match('/^[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/',$u['profile_image'])) @unlink($directory.'/'.$u['profile_image']);
        q('UPDATE users SET profile_image=? WHERE id=?',[$image,$u['id']]);
        flash('تصویر پروفایل با موفقیت تغییر کرد.'); redirect('dashboard/profile');
    }
    if ($path==='profile/account') {
        $name=trim((string)($_POST['name'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $bio=trim((string)($_POST['bio'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || mb_strlen($bio)>600 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) { flash('نام، معرفی کوتاه و ایمیل معتبر وارد کنید.','error'); redirect('dashboard/profile'); }
        $image=$u['profile_image'] ?? '';
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error']!==UPLOAD_ERR_NO_FILE) {
            $upload=$_FILES['profile_image'];
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if ($upload['error']!==UPLOAD_ERR_OK || $upload['size']>2097152 || !is_uploaded_file($upload['tmp_name']) || !isset($types[$mime]) || @getimagesize($upload['tmp_name'])===false) { flash('تصویر معتبر نیست؛ فایل JPG، PNG یا WebP تا حداکثر ۲ مگابایت انتخاب کنید.','error'); redirect('dashboard/profile'); }
            $directory=dirname(__DIR__).'/public/uploads/profiles';
            if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) { flash('ذخیرهٔ تصویر ممکن نشد.','error'); redirect('dashboard/profile'); }
            $image=bin2hex(random_bytes(16)).'.'.$types[$mime];
            if (!move_uploaded_file($upload['tmp_name'],$directory.'/'.$image)) { flash('ذخیرهٔ تصویر ممکن نشد.','error'); redirect('dashboard/profile'); }
            if (is_string($u['profile_image'] ?? null) && preg_match('/^[a-f0-9]{32}\\.(?:jpg|jpeg|png|webp)$/',$u['profile_image'])) @unlink($directory.'/'.$u['profile_image']);
        }
        q('UPDATE users SET name=?,profile_image=?,bio=? WHERE id=?',[$name,$image,$bio,$u['id']]);
        flash('پروفایل به‌روزرسانی شد.'); redirect('dashboard/profile');
    }
    if ($path==='settings/save') {
        require_admin();
        $key=(string)($_POST['key'] ?? '');
        if (!in_array($key,array_column(SETTINGS_GROUPS,'key'),true)) { flash('تنظیم نامعتبر است.','error'); redirect('dashboard/settings'); }
        $value=trim((string)($_POST['value'] ?? ''));
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT INTO settings(skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=?',[$key,$value,$value]);
        else q('INSERT INTO settings(skey,svalue) VALUES (?,?) ON CONFLICT(skey) DO UPDATE SET svalue=?',[$key,$value,$value]);
        flash('تنظیم ذخیره شد.'); redirect('dashboard/settings');
    }
    if ($path==='content/create') {
        require_admin();
        $title=trim((string)($_POST['title'] ?? '')); $kind=trim((string)($_POST['kind'] ?? 'مقاله'));
        if (mb_strlen($title)<3 || mb_strlen($title)>180) { flash('عنوان باید بین ۳ تا ۱۸۰ نویسه باشد.','error'); redirect('dashboard/content'); }
        if (!in_array($kind,['مقاله','صفحه'],true)) $kind='مقاله';
        q('INSERT INTO content_items(title,kind,status) VALUES (?,?,?)',[$title,$kind,'پیش‌نویس']);
        flash('محتوای جدید به‌صورت پیش‌نویس ذخیره شد.'); redirect('dashboard/content');
    }
    if ($path==='content/publish') {
        require_admin();
        $id=filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(404); exit; }
        q("UPDATE content_items SET status='منتشرشده' WHERE id=?",[$id]);
        flash('محتوا منتشر شد.'); redirect('dashboard/content');
    }
    if ($path==='announcements/send') {
        require_admin();
        $title=trim((string)($_POST['title'] ?? '')); $body=trim((string)($_POST['body'] ?? ''));
        if (mb_strlen($title)<3 || mb_strlen($title)>255 || mb_strlen($body)<5 || mb_strlen($body)>2000) { flash('عنوان و متن اعلان معتبر وارد کنید.','error'); redirect('dashboard/announcements'); }
        q('INSERT INTO announcements(title,body) VALUES (?,?)',[$title,$body]);
        flash('اعلان برای کاربران ارسال شد.'); redirect('dashboard/announcements');
    }
    if ($path==='admin/seed-demo') {
        require_admin();
        seed_demo_courses();
        foreach (ANNOUNCEMENTS as $a) q('INSERT INTO announcements(title,body) VALUES (?,?)',[$a['title'],$a['body']]);
        foreach (CONTENT_ITEMS as $c) q('INSERT INTO content_items(title,kind,status) VALUES (?,?,?)',[$c['title'],$c['kind'],$c['status']]);
        flash('داده‌های نمونه ایجاد شد.'); redirect('dashboard');
    }
    http_response_code(404); exit('صفحه پیدا نشد.');
}
