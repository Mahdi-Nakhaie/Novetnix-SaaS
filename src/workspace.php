<?php
declare(strict_types=1);

const WS_PROJECT_BYTES = 5242880;
const WS_USER_BYTES = 20971520;
const WS_FILE_BYTES = 1048576;
const WS_FILE_COUNT = 100;

require_once __DIR__.'/datasets.php';

function ws_reply(int $status,array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ws_fail(string $message,int $status=422): never { ws_reply($status,['error'=>$message]); }
function ws_path(string $path): bool {
    if (strlen($path)>160 || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_. /-]*$~D',$path) || str_contains($path,'//')) return false;
    foreach (explode('/',$path) as $part) if ($part==='' || $part==='.' || $part==='..' || $part[0]==='.' || trim($part)!==$part) return false;
    return true;
}
function ws_manifest(mixed $files): array {
    if (!is_array($files) || count($files)>WS_FILE_COUNT) throw new InvalidArgumentException('حداکثر ۱۰۰ فایل یا پوشه مجاز است.');
    $bytes=0; $clean=[];
    foreach ($files as $path=>$file) {
        if (!is_string($path) || !ws_path($path) || !is_array($file)) throw new InvalidArgumentException('مسیر فایل معتبر نیست.');
        $directory=($file['kind'] ?? '')==='directory';
        if (!$directory && !in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['py','txt','csv','json','md','png','joblib','pkl','npy','npz'],true)) throw new InvalidArgumentException('نوع فایل پشتیبانی نمی‌شود.');
        $encoded=$directory ? '' : ($file['data'] ?? null);
        if (!is_string($encoded) || strlen($encoded)>1398104) throw new InvalidArgumentException('حجم هر فایل حداکثر یک مگابایت است.');
        $raw=base64_decode($encoded,true);
        if ($raw===false || strlen($raw)>WS_FILE_BYTES) throw new InvalidArgumentException('محتوا یا حجم فایل معتبر نیست.');
        $bytes+=strlen($raw);
        $clean[$path]=['kind'=>$directory?'directory':'file','data'=>base64_encode($raw)];
    }
    foreach ($clean as $path=>$file) {
        $parts=explode('/',$path); array_pop($parts); $parent='';
        foreach ($parts as $part) {
            $parent=$parent===''?$part:$parent.'/'.$part;
            if (isset($clean[$parent]) && $clean[$parent]['kind']!=='directory') throw new InvalidArgumentException('فایل نمی‌تواند پوشه والد باشد.');
        }
    }
    if ($bytes>WS_PROJECT_BYTES) throw new InvalidArgumentException('حجم پروژه حداکثر پنج مگابایت است.');
    ksort($clean);
    return ['files'=>$clean,'json'=>json_encode((object)$clean,JSON_THROW_ON_ERROR),'bytes'=>$bytes];
}
function ws_owned(int $userId,int $projectId,bool $includeDeleted=false): array {
    $row=q('SELECT w.*,p.user_id,p.catalog_index FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.id=? AND p.user_id=?',[$projectId,$userId])->fetch();
    if (!$row || (!$includeDeleted && $row['deleted_at']!==null)) ws_fail('پروژه پیدا نشد.',404);
    return $row;
}
function ws_lock_user(int $userId): void {
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
    else q('UPDATE users SET name=name WHERE id=?',[$userId]);
}
function ws_quota(int $userId,int $bytes,int $exclude=0): void {
    $used=(int)q('SELECT COALESCE(SUM(w.bytes),0) FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=? AND p.id<>?',[$userId,$exclude])->fetchColumn();
    if ($used+$bytes+hf_reservations($userId)>WS_USER_BYTES) throw new InvalidArgumentException('سهمیه ذخیره‌سازی حساب بیست مگابایت است؛ پروژه‌های حذف‌شده نیز در سهمیه‌اند.');
}
function ws_template_allowed(array $template,int $userId): bool {
    if ($template['status']!=='published') return false;
    if ($template['course_id']!==null) {
        $course=q("SELECT c.slug FROM courses c JOIN enrollments e ON e.course_slug=c.slug AND e.user_id=? WHERE c.id=? AND c.status='published'",[$userId,$template['course_id']])->fetchColumn();
        if (!$course || !can_course($course,plan_for(['id'=>$userId]))) return false;
    }
    if ($template['lesson_id']===null) return true;
    if (!authorized_progress_lesson($userId,(int)$template['lesson_id'])) return false;
    return $template['course_id']===null || (bool)q('SELECT id FROM lessons WHERE id=? AND course_id=?',[$template['lesson_id'],$template['course_id']])->fetchColumn();
}
function ws_create(int $userId,int $templateId,string $title,?int $catalogIndex=null,?array $initial=null): int {
    $pdo=db(); $pdo->beginTransaction();
    try {
        ws_lock_user($userId);
        $template=$templateId ? q('SELECT * FROM workspace_templates WHERE id=?',[$templateId])->fetch() : false;
        if ($templateId && (!$template || !ws_template_allowed($template,$userId))) throw new InvalidArgumentException('تمرین منتشر نشده یا دسترسی آن را ندارید.');
        if ($template) {
            $existing=q('SELECT p.id FROM projects p JOIN project_workspaces w ON w.project_id=p.id WHERE p.user_id=? AND w.template_id=?',[$userId,$templateId])->fetchColumn();
            if ($existing) { $pdo->commit(); return (int)$existing; }
            $title=$template['title']; $manifest=ws_manifest(json_decode($template['files_json'],true,512,JSON_THROW_ON_ERROR));
            $catalogIndex=$template['catalog_index']===null ? null : (int)$template['catalog_index'];
        } else $manifest=$initial ?? ws_manifest(['main.py'=>['kind'=>'file','data'=>base64_encode("print('Hello, Noventix!')\n")]]);
        if ($catalogIndex!==null) {
            $workspace=q('SELECT p.id FROM projects p JOIN project_workspaces w ON w.project_id=p.id WHERE p.user_id=? AND p.catalog_index=?',[$userId,$catalogIndex])->fetchColumn();
            if ($workspace) { $pdo->commit(); return (int)$workspace; }
        }
        if (mb_strlen($title)<1 || mb_strlen($title)>180) throw new InvalidArgumentException('عنوان باید بین ۱ تا ۱۸۰ نویسه باشد.');
        if ((int)q('SELECT COUNT(*) FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=?',[$userId])->fetchColumn()>=20) throw new InvalidArgumentException('حداکثر بیست پروژه ذخیره‌شده مجاز است.');
        ws_quota($userId,$manifest['bytes']);
        if ($catalogIndex===null) $catalogIndex=min(0,(int)q('SELECT MIN(catalog_index) FROM projects WHERE user_id=?',[$userId])->fetchColumn())-1;
        $existing=q('SELECT id FROM projects WHERE user_id=? AND catalog_index=?',[$userId,$catalogIndex])->fetchColumn();
        if ($existing) $projectId=(int)$existing;
        else { q('INSERT INTO projects(user_id,catalog_index) VALUES (?,?)',[$userId,$catalogIndex]); $projectId=(int)$pdo->lastInsertId(); }
        q('INSERT INTO project_workspaces(project_id,template_id,title,files_json,bytes) VALUES (?,?,?,?,?)',[$projectId,$templateId?:null,$title,$manifest['json'],$manifest['bytes']]);
        $pdo->commit(); return $projectId;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function ws_save(int $userId,int $projectId,int $revision,array $manifest): void {
    $pdo=db(); $pdo->beginTransaction();
    try {
        ws_lock_user($userId); ws_owned($userId,$projectId);
        $refs=hf_refs($projectId);
        foreach ($manifest['files'] as $path=>$file) if ($refs && ($path==='datasets' || str_starts_with($path,'datasets/'))) {
            if (!isset($refs[$path]) || $file['kind']!=='file' || !hash_equals($refs[$path]['sha256'],hash('sha256',base64_decode($file['data'],true)))) throw new InvalidArgumentException('فایل‌های دیتاست فقط‌خواندنی‌اند؛ برای تغییر، کپی را با مسیر دیگری ذخیره کنید.');
        }
        $manifest=ws_manifest(hf_local($manifest['files'],$projectId));
        $bytes=$manifest['bytes']+hf_dataset_bytes($projectId);
        if ($bytes+hf_reservations($userId,$projectId)>WS_PROJECT_BYTES || count($manifest['files'])+count($refs)>WS_FILE_COUNT) throw new InvalidArgumentException('سهمیه پروژه با دیتاست‌ها و دریافت فعال تکمیل است.');
        ws_quota($userId,$bytes,$projectId);
        $saved=q('UPDATE project_workspaces SET files_json=?,bytes=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE project_id=? AND revision=? AND deleted_at IS NULL',[$manifest['json'],$bytes,$projectId,$revision]);
        if ($saved->rowCount()!==1) { $pdo->rollBack(); ws_fail('پروژه در تب دیگری تغییر کرده؛ پیش از ذخیره دوباره باز کنید.',409); }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function ws_uploads(array $existing=[]): array {
    if (isset($_FILES['archive']) && $_FILES['archive']['error']!==UPLOAD_ERR_NO_FILE) {
        $upload=$_FILES['archive'];
        if ($upload['error']!==UPLOAD_ERR_OK || $upload['size']>WS_PROJECT_BYTES || !is_uploaded_file($upload['tmp_name'])) throw new InvalidArgumentException('ZIP معتبر و حداکثر پنج مگابایتی بارگذاری کنید.');
        if (!class_exists('ZipArchive')) throw new InvalidArgumentException('افزونه ZIP روی سرور فعال نیست؛ فایل‌ها را جدا بارگذاری کنید.');
        $zip=new ZipArchive();
        if ($zip->open($upload['tmp_name'])!==true) throw new InvalidArgumentException('ZIP قابل خواندن نیست.');
        try {
            if ($zip->numFiles>WS_FILE_COUNT) throw new InvalidArgumentException('تعداد ورودی ZIP بیش از حد مجاز است.');
            $total=0; $seen=[];
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); if (!$stat) throw new InvalidArgumentException('ورودی ZIP نامعتبر است.');
                $path=rtrim($stat['name'],'/'); $dir=str_ends_with($stat['name'],'/');
                $opsys=0; $attr=0; $zip->getExternalAttributesIndex($i,$opsys,$attr);
                $type=($attr>>16)&0170000;
                if (!ws_path($path) || isset($seen[$path]) || ($type!==0 && $type!==0100000 && $type!==0040000) || ($stat['encryption_method'] ?? 0)!==0 || $stat['size']>WS_FILE_BYTES || (!$dir && $type===0040000)) throw new InvalidArgumentException('مسیر، نوع یا حجم ورودی ZIP غیرمجاز است.');
                $seen[$path]=true; $total+=$stat['size'];
                if ($total>WS_PROJECT_BYTES) throw new InvalidArgumentException('حجم بازشده ZIP بیش از حد مجاز است.');
                $raw=$dir?'':$zip->getFromIndex($i,WS_FILE_BYTES+1);
                if ($raw===false || strlen($raw)!==($dir?0:$stat['size'])) throw new InvalidArgumentException('محتوای ZIP معتبر نیست.');
                $existing[$path]=['kind'=>$dir?'directory':'file','data'=>base64_encode($raw)];
            }
        } finally { $zip->close(); }
    }
    $uploads=$_FILES['files'] ?? null;
    if ($uploads && (!is_array($uploads['name']) || count($uploads['name'])>WS_FILE_COUNT || array_sum($uploads['size'])>WS_PROJECT_BYTES)) throw new InvalidArgumentException('حجم یا تعداد فایل‌های بارگذاری‌شده بیش از حد مجاز است.');
    if ($uploads && is_array($uploads['name'])) foreach ($uploads['name'] as $i=>$path) {
        if ($uploads['error'][$i]===UPLOAD_ERR_NO_FILE) continue;
        if ($uploads['error'][$i]!==UPLOAD_ERR_OK || $uploads['size'][$i]>WS_FILE_BYTES || !is_uploaded_file($uploads['tmp_name'][$i]) || !ws_path($path)) throw new InvalidArgumentException('بارگذاری فایل معتبر نیست.');
        $raw=file_get_contents($uploads['tmp_name'][$i]);
        if ($raw===false) throw new InvalidArgumentException('خواندن فایل بارگذاری‌شده ممکن نشد.');
        $existing[$path]=['kind'=>'file','data'=>base64_encode($raw)];
    }
    return ws_manifest($existing);
}
function ws_runner_ready(): bool {
    return extension_loaded('curl') && (bool)config_value('PYTHON_RUNNER_URL') && (bool)config_value('PYTHON_RUNNER_TOKEN');
}
function ws_runner(string $method,string $path,?array $payload=null): array {
    if (!ws_runner_ready()) ws_fail('سرویس اجرای ایزوله راه‌اندازی نشده؛ ذخیره فایل‌ها در دسترس است.',503);
    $base=rtrim((string)config_value('PYTHON_RUNNER_URL'),'/');
    $url=parse_url($base);
    if (!$url || ($url['scheme'] ?? '')!=='https' || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) ws_fail('پیکربندی سرویس اجرا معتبر نیست.',503);
    $curl=curl_init($base.$path);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.config_value('PYTHON_RUNNER_TOKEN'),'Content-Type: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$response): int { $response=($response ?? '').$chunk; return strlen($response)>8388608?0:strlen($chunk); }]);
    if ($payload!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
    $response=''; $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    if ($ok===false || $status<200 || $status>=300) ws_fail($status===429?'صف یا سهمیه اجرا تکمیل است؛ بعداً تلاش کنید.':'ارتباط با سرویس اجرا ناموفق بود؛ درخواست یکسان را دوباره بررسی کنید.',$status===429?429:503);
    $result=json_decode($response,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($result)) throw new RuntimeException('Invalid runner response.');
    return $result;
}
function ws_run_owned(int $userId,int $runId): array {
    $run=q('SELECT r.* FROM workspace_runs r JOIN projects p ON p.id=r.project_id WHERE r.id=? AND p.user_id=?',[$runId,$userId])->fetch();
    if (!$run) ws_fail('اجرا پیدا نشد.',404);
    ws_owned($userId,(int)$run['project_id']); return $run;
}
function ws_run_sync(array $run,int $userId): array {
    if (!$run['remote_id']) ws_fail('ارسال اجرا کامل نشده؛ با همان شناسه درخواست دوباره تلاش کنید.',409);
    $result=ws_runner('GET','/jobs/'.$run['remote_id'].'?owner='.$userId);
    $states=['queued','running','cancelling','cancelled','succeeded','program_error','timeout','output_limit','artifact_limit','dependency_error','memory_limit','cpu_limit','infrastructure_error'];
    if (!in_array($result['state'] ?? '',$states,true)) throw new RuntimeException('Invalid run state.');
    if (isset($result['files'])) $result['files']=ws_manifest(hf_local($result['files'],(int)$run['project_id']))['files'];
    q('UPDATE workspace_runs SET state=?,result_json=? WHERE id=?',[$result['state'],json_encode($result,JSON_THROW_ON_ERROR),$run['id']]);
    $awarded=!empty($run['assessment_key']) && lp_grant($run,$result,$userId);
    return array_replace($result,['local_id'=>(int)$run['id'],'revision'=>(int)$run['revision'],'xp_awarded'=>$awarded]);
}
function ws_get(string $path): never {
    foreach ($_GET as $value) if (!is_string($value)) ws_fail('پارامتر درخواست معتبر نیست.');
    $u=require_user(); $userId=(int)$u['id'];
    try {
        if (str_starts_with($path,'workspace/datasets-')) hf_get($path,$userId);
        if ($path==='workspace/runtime') ws_reply(200,ws_runner('GET','/health'));
        if ($path==='workspace/run-state') ws_reply(200,ws_run_sync(ws_run_owned($userId,(int)($_GET['id'] ?? 0)),$userId));
        $row=ws_owned($userId,(int)($_GET['id'] ?? 0));
        if ($path==='workspace/project-solution') {
            $definition=lp_definition((int)$row['catalog_index']);
            if (!$definition) ws_fail('پروژه آموزشی پیدا نشد.',404);
            $attempt=q("SELECT id FROM workspace_runs WHERE project_id=? AND remote_id IS NOT NULL AND state IN ('succeeded','program_error','timeout','output_limit','cpu_limit','memory_limit','artifact_limit') LIMIT 1",[$row['project_id']])->fetchColumn();
            if (!$attempt) ws_fail('ابتدا یک تلاش اجرایی واقعی انجام دهید.',403);
            ws_reply(200,['solution'=>file_get_contents(dirname(__DIR__).'/curriculum/solutions/'.$definition['key'].'.py')]);
        }
        if ($path==='workspace/download') {
            $name=(string)($_GET['file'] ?? ''); $files=hf_materialize((int)$row['project_id'],json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR)+hf_refs((int)$row['project_id']));
            if (!ws_path($name) || !isset($files[$name]) || $files[$name]['kind']!=='file') ws_fail('فایل پیدا نشد.',404);
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.basename($name).'"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
            echo base64_decode($files[$name]['data'],true); exit;
        }
        $pending=q("SELECT id,request_key,entrypoint,remote_id FROM workspace_runs WHERE project_id=? AND state IN ('submitting','queued','running','cancelling') ORDER BY id DESC LIMIT 1",[$row['project_id']])->fetch();
        ws_reply(200,['id'=>(int)$row['project_id'],'pending_run'=>$pending?:null,'revision'=>(int)$row['revision'],'title'=>$row['title'],'files'=>(object)hf_materialize((int)$row['project_id'],json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR)+hf_refs((int)$row['project_id'])),'dataset_paths'=>array_keys(hf_refs((int)$row['project_id'])),'runner_ready'=>ws_runner_ready()]);
    } catch (InvalidArgumentException $e) { ws_fail($e->getMessage()); } catch (HfError $e) { ws_fail($e->getMessage(),503); } catch (Throwable $e) { ws_fail('خواندن پروژه یا سرویس اجرا ممکن نشد.',503); }
}
function ws_template_save(): never {
    require_admin();
    $id=(int)($_POST['id'] ?? 0); $old=$id?q('SELECT * FROM workspace_templates WHERE id=?',[$id])->fetch():false;
    if ($id && !$old) ws_fail('تمرین پیدا نشد.',404);
    $title=trim((string)($_POST['title'] ?? '')); $instructions=trim((string)($_POST['instructions'] ?? '')); $status=(string)($_POST['status'] ?? 'draft');
    $course=(int)($_POST['course_id'] ?? 0); $lesson=(int)($_POST['lesson_id'] ?? 0);
    $catalog=($_POST['catalog_index'] ?? '')===''?null:filter_var($_POST['catalog_index'],FILTER_VALIDATE_INT);
    if (mb_strlen($title)<1 || mb_strlen($title)>180 || mb_strlen($instructions)>20000 || !in_array($status,['draft','published'],true) || ($catalog!==null && ($catalog===false || !isset(PROJECTS[$catalog])))) throw new InvalidArgumentException('مشخصات تمرین معتبر نیست.');
    if ($course && !q('SELECT id FROM courses WHERE id=?',[$course])->fetchColumn()) throw new InvalidArgumentException('دوره موجود نیست.');
    if ($lesson && (!$course || !q('SELECT id FROM lessons WHERE id=? AND course_id=?',[$lesson,$course])->fetchColumn())) throw new InvalidArgumentException('درس باید متعلق به دوره انتخاب‌شده باشد.');
    $manifest=ws_uploads($old?json_decode($old['files_json'],true,512,JSON_THROW_ON_ERROR):[]);
    $requirements=trim((string)($_POST['requirements'] ?? ''));
    if ($requirements!=='') { $files=$manifest['files']; $files['requirements.txt']=['kind'=>'file','data'=>base64_encode($requirements."\n")]; $manifest=ws_manifest($files); }
    if ($status==='published' && !isset($manifest['files']['main.py'])) throw new InvalidArgumentException('تمرین منتشرشده باید main.py داشته باشد.');
    $params=[$title,$instructions,$course?:null,$lesson?:null,$catalog,$status,$manifest['json'],$manifest['bytes']];
    if ($old) {
        $params[]=$id; $params[]=(int)($_POST['revision'] ?? 0);
        if (q('UPDATE workspace_templates SET title=?,instructions=?,course_id=?,lesson_id=?,catalog_index=?,status=?,files_json=?,bytes=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND revision=?',$params)->rowCount()!==1) ws_fail('تمرین هم‌زمان تغییر کرده است؛ صفحه را تازه کنید.',409);
    } else { q('INSERT INTO workspace_templates(title,instructions,course_id,lesson_id,catalog_index,status,files_json,bytes) VALUES (?,?,?,?,?,?,?,?)',$params); $id=(int)db()->lastInsertId(); }
    ws_reply(200,['id'=>$id,'saved'=>true]);
}
function ws_post(string $path): never {
    foreach ($_POST as $value) if (!is_string($value)) ws_fail('فیلدهای فرم باید متنی باشند.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8388608 || strlen((string)($_POST['files'] ?? ''))>7500000) ws_fail('درخواست بیش از حد مجاز است.',413);
    $u=require_user(); $userId=(int)$u['id'];
    try {
        if (str_starts_with($path,'workspace/datasets-')) hf_post($path,$userId);
        if ($path==='workspace/admin-template-save') ws_template_save();
        if ($path==='workspace/create') ws_reply(201,['id'=>ws_create($userId,(int)($_POST['template_id'] ?? 0),trim((string)($_POST['title'] ?? 'پروژه پایتون')))]);
        if ($path==='workspace/legacy') {
            $project=q('SELECT * FROM projects WHERE id=? AND user_id=?',[(int)($_POST['id'] ?? 0),$userId])->fetch();
            if (!$project || !isset(LEGACY_PROJECTS[$project['catalog_index']])) ws_fail('پروژه پیدا نشد.',404);
            $existing=q('SELECT project_id FROM project_workspaces WHERE project_id=?',[$project['id']])->fetchColumn();
            $id=$existing?(int)$existing:ws_create($userId,0,LEGACY_PROJECTS[$project['catalog_index']]['title'],(int)$project['catalog_index']);
            ws_reply(200,['id'=>$id]);
        }
        $id=(int)($_POST['id'] ?? 0); $row=ws_owned($userId,$id,$path==='workspace/restore'); $revision=(int)($_POST['revision'] ?? 0);
        if ($path==='workspace/delete' || $path==='workspace/restore') {
            if (q('UPDATE project_workspaces SET deleted_at=?,revision=revision+1 WHERE project_id=? AND revision=?',[$path==='workspace/delete'?gmdate('Y-m-d H:i:s'):null,$id,$revision])->rowCount()!==1) ws_fail('نسخه پروژه تغییر کرده است.',409);
            ws_reply(200,['saved'=>true]);
        }
        if ($path==='workspace/save' || $path==='workspace/upload') {
            $manifest=$path==='workspace/upload'?ws_uploads(json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR)):ws_manifest(json_decode((string)($_POST['files'] ?? ''),true,512,JSON_THROW_ON_ERROR));
            ws_save($userId,$id,$revision,$manifest); ws_reply(200,['revision'=>$revision+1]);
        }
        if ($path==='workspace/run') {
            if (!ws_runner_ready()) ws_fail('موتور اجرای ایزوله هنوز راه‌اندازی نشده است؛ خروجی ساختگی نمایش داده نمی‌شود.',503);
            $key=(string)($_POST['request_key'] ?? ''); $entry=(string)($_POST['entrypoint'] ?? 'main.py');
            if (!preg_match('/^[a-f0-9-]{36}$/D',$key) || !ws_path($entry) || !str_ends_with($entry,'.py')) throw new InvalidArgumentException('شناسه اجرا یا فایل ورودی معتبر نیست.');
            $definition=lp_definition((int)$row['catalog_index']);
            $assessment=($_POST['assess'] ?? '')==='1';
            if ($assessment && (!$definition || $entry!=='main.py' || hf_refs($id))) throw new InvalidArgumentException('ارزیابی فقط برای پروژه آموزشی با main.py و داده‌های تعریف‌شده فعال است.');
            $pdo=db(); $pdo->beginTransaction();
            try {
                ws_lock_user($userId);
                $run=q('SELECT * FROM workspace_runs WHERE project_id=? AND request_key=?',[$id,$key])->fetch();
                if ($run && (($run['assessment_key']!==null)!==$assessment || ($run['assessment_key']!==null && $run['assessment_key']!==$definition['key']))) throw new InvalidArgumentException('شناسه درخواست قبلاً برای اجرای متفاوت استفاده شده است.');
                if (!$run) {
                    $row=ws_owned($userId,$id);
                    if ((int)$row['revision']!==$revision) { $pdo->rollBack(); ws_fail('ابتدا آخرین نسخه فایل‌ها را ذخیره کنید.',409); }
                    $files=json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR);
                    if (!isset($files[$entry]) || $files[$entry]['kind']!=='file') throw new InvalidArgumentException('فایل ورودی موجود نیست.');
                    if ((int)q('SELECT COUNT(*) FROM workspace_runs r JOIN projects p ON p.id=r.project_id WHERE p.user_id=?',[$userId])->fetchColumn()>=100) throw new InvalidArgumentException('سقف تاریخچه اجرا تکمیل است؛ مدیر باید نگهداری تاریخچه را بررسی کند.');
                    $history=(int)q('SELECT COALESCE(SUM(LENGTH(r.snapshot_json)+COALESCE(LENGTH(r.result_json),0)),0) FROM workspace_runs r JOIN projects p ON p.id=r.project_id WHERE p.user_id=?',[$userId])->fetchColumn();
                    if ($history+16777216>67108864) throw new InvalidArgumentException('سهمیه تاریخچه اجرا تکمیل است؛ فایل‌های پروژه محفوظ‌اند و مدیر باید تاریخچه را آرشیو کند.');
                    q('INSERT INTO workspace_runs(project_id,request_key,revision,entrypoint,snapshot_json,assessment_key,assessment_version) VALUES (?,?,?,?,?,?,?)',[$id,$key,$revision,$entry,json_encode((object)($files+hf_refs($id)),JSON_THROW_ON_ERROR),$assessment?$definition['key']:null,$assessment?lp_version():null]);
                    $run=q('SELECT * FROM workspace_runs WHERE id=?',[(int)$pdo->lastInsertId()])->fetch();
                }
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            if (!$run['remote_id']) {
                $payload=['owner'=>$userId,'request_id'=>'p'.$id.'-'.$key,'entrypoint'=>$run['entrypoint'],'dataset_paths'=>array_keys(array_filter(json_decode($run['snapshot_json'],true,512,JSON_THROW_ON_ERROR),static fn($file)=>($file['kind'] ?? '')==='dataset')),'files'=>ws_manifest(hf_materialize($id,json_decode($run['snapshot_json'],true,512,JSON_THROW_ON_ERROR)))['files']];
                if ($run['assessment_key']!==null) $payload['assessment']=['key'=>$run['assessment_key'],'version'=>$run['assessment_version']];
                $remote=ws_runner('POST','/jobs',$payload);
                if (!preg_match('/^[a-f0-9-]{36}$/D',(string)($remote['id'] ?? ''))) throw new RuntimeException('Invalid runner ID.');
                q("UPDATE workspace_runs SET remote_id=?,state='queued' WHERE id=?",[$remote['id'],$run['id']]);
            }
            ws_reply(202,['local_id'=>(int)$run['id']]);
        }
        if ($path==='workspace/cancel' || $path==='workspace/apply-output') {
            $run=ws_run_owned($userId,(int)($_POST['run_id'] ?? 0));
            if ((int)$run['project_id']!==$id) ws_fail('اجرا متعلق به پروژه نیست.',404);
            if ($path==='workspace/cancel') { if (!$run['remote_id']) ws_fail('ارسال اجرا هنوز کامل نشده است.',409); ws_reply(200,ws_runner('DELETE','/jobs/'.$run['remote_id'].'?owner='.$userId)); }
            $result=json_decode($run['result_json'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
            if (!in_array($run['state'],['succeeded','program_error'],true) || !isset($result['files'])) throw new InvalidArgumentException('خروجی قابل ذخیره موجود نیست.');
            if ($revision!==(int)$run['revision']) ws_fail('پس از اجرا فایل‌ها تغییر کرده‌اند؛ خروجی را روی نسخه جدید بازنویسی نمی‌کنیم.',409);
            ws_save($userId,$id,$revision,ws_manifest($result['files'])); ws_reply(200,['revision'=>$revision+1]);
        }
        ws_fail('مسیر میزکار پیدا نشد.',404);
    } catch (InvalidArgumentException|JsonException $e) { ws_fail($e instanceof JsonException?'JSON فایل‌ها معتبر نیست.':$e->getMessage()); }
    catch (HfError $e) { ws_fail($e->getMessage(),503); }
    catch (Throwable $e) { ws_fail('ذخیره یا پردازش درخواست انجام نشد؛ داده قبلی حفظ شده است.',503); }
}
