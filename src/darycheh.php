<?php
declare(strict_types=1);
require_once __DIR__.'/darycheh_engine.php';

function dr_decode(string $json): array {
    try { $value=json_decode($json,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new DarychehError('دادهٔ ذخیره‌شدهٔ سناریو قابل خواندن نیست.',409); }
    dr_require(is_array($value),'دادهٔ ذخیره‌شده ناقص است.',409);
    return $value;
}
function dr_request_key(mixed $value): string {
    dr_require(is_string($value) && preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$value)===1,'شناسهٔ درخواست معتبر نیست.');
    return $value;
}
function dr_owned(int $userId,int $id): array {
    $row=q('SELECT * FROM darycheh_attempts WHERE id=? AND user_id=?',[$id,$userId])->fetch();
    dr_require((bool)$row,'تلاش پیدا نشد.',404);
    return $row;
}
function dr_release(array $attempt): array {
    $row=q('SELECT * FROM darycheh_releases WHERE id=? AND scenario_key=?',[$attempt['release_id'],$attempt['scenario_key']])->fetch();
    dr_require((bool)$row,'نسخهٔ این سناریو در دسترس نیست.',409);
    $d=dr_decode($row['definition_json']);
    dr_validate_definition($d);
    dr_require(hash_equals($row['definition_hash'],dr_hash($d)) && $d['scenario_key']===$row['scenario_key'] && $d['content_version']===$row['content_version'] && $d['engine_version']===$row['engine_version'] && $d['assessment_version']===$row['assessment_version'],'یکپارچگی نسخهٔ سناریو تأیید نشد.',409);
    return $d;
}
function dr_state(array $attempt,array $d): array {
    dr_require((int)$attempt['state_schema_version']===1 && isset($d['steps'][$attempt['current_step_key']]),'مرحله یا نسخهٔ وضعیت معتبر نیست.',409);
    $s=dr_decode($attempt['state_json']);
    $last=q('SELECT result_state_hash FROM darycheh_actions WHERE attempt_id=? AND seq=?',[$attempt['id'],$attempt['last_action_seq']])->fetchColumn();
    dr_require(is_string($last) && hash_equals($last,dr_hash($s)),'یکپارچگی وضعیت ذخیره‌شده تأیید نشد.',409);
    foreach (array_keys(dr_initial_state()) as $key) dr_require(array_key_exists($key,$s),'وضعیت ذخیره‌شده ناقص است.',409);
    dr_require(is_array($s['answers']) && is_int($s['round']) && $s['round']>=0 && is_int($s['help']) && $s['help']>=0 && $s['help']<=3 && is_array($s['exposed_keys']),'وضعیت ذخیره‌شده نامعتبر است.',409);
    $kind=$d['steps'][$attempt['current_step_key']]['kind'];
    $required=match($kind) {
        'solution'=>['evidence','problem'], 'consequence'=>['evidence','problem','solution'],
        'feedback','retry','transfer','transfer_feedback','report'=>['evidence','problem','solution','consequence'],
        'problem'=>['evidence'], default=>[],
    };
    foreach ($required as $answerKind) {
        dr_require(isset($s['answers'][$answerKind]) && is_array($s['answers'][$answerKind]),'پاسخ مرحلهٔ قبلی ناقص است.',409);
        foreach ($d['steps'] as $step) if ($step['kind']===$answerKind) {
            try { dr_validate_payload($step,$s['answers'][$answerKind]); }
            catch (DarychehError $e) { throw new DarychehError('پاسخ ذخیره‌شدهٔ مرحلهٔ قبلی معتبر نیست.',409); }
        }
    }
    if (in_array($kind,['feedback','retry','transfer','transfer_feedback','report'],true)) dr_require(is_array($s['main']) && isset($s['main']['passed'],$s['main']['dimensions'],$s['main']['tests']),'نتیجهٔ اجرای قبلی ناقص است.',409);
    if (in_array($kind,['transfer','transfer_feedback'],true)) dr_require(isset($d['transfer_bundles'][$s['bundle'] ?? '']),'بستهٔ انتقال معتبر نیست.',409);
    if ($kind==='report') dr_require(is_array($s['summary']) && isset($s['summary']['ending']),'گزارش نهایی ناقص است.',409);
    return $s;
}
function dr_exposed(int $userId,string $scenario): array {
    $rows=q("SELECT a.outcome_json FROM darycheh_actions a JOIN darycheh_attempts t ON t.id=a.attempt_id WHERE t.user_id=? AND t.scenario_key=? AND a.action_type='transfer_submitted'",[$userId,$scenario])->fetchAll();
    $seen=[];
    foreach ($rows as $row) $seen=array_merge($seen,dr_decode($row['outcome_json'])['exposed_keys'] ?? []);
    return array_values(array_unique($seen));
}
function dr_receipt(array $attempt): array {
    return ['attempt_id'=>(int)$attempt['id'],'revision'=>(int)$attempt['revision'],'step'=>$attempt['current_step_key'],'status'=>$attempt['status']];
}
function dr_append(array $attempt,string $requestKey,string $fingerprint,string $type,string $step,array $payload,array $state,array $receipt,array $exposed=[]): void {
    q('INSERT INTO darycheh_actions(attempt_id,seq,request_key,request_fingerprint,action_type,step_key,base_revision,result_revision,payload_json,outcome_json,result_state_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[
        $attempt['id'],$attempt['last_action_seq'],$requestKey,$fingerprint,$type,$step,$attempt['revision']-1,$attempt['revision'],dr_json($payload),dr_json(['receipt'=>$receipt,'exposed_keys'=>$exposed]),dr_hash($state),
    ]);
}
function dr_start(int $userId,string $scenario,string $requestKey,?int $restartId=null,?int $expectedRevision=null): array {
    dr_request_key($requestKey);
    dr_require($scenario==='first-real-problem','سناریو پیدا نشد.',404);
    $fingerprint=dr_hash([$scenario,$restartId,$expectedRevision]); $db=db(); $db->beginTransaction();
    try {
        ws_lock_user($userId);
        $existing=q('SELECT * FROM darycheh_attempts WHERE user_id=? AND start_request_key=?',[$userId,$requestKey])->fetch();
        if ($existing) {
            dr_require(hash_equals($existing['start_fingerprint'],$fingerprint),'این شناسه برای درخواست دیگری استفاده شده است.',409);
            $receipt=dr_decode(q('SELECT outcome_json FROM darycheh_actions WHERE attempt_id=? AND seq=1',[$existing['id']])->fetchColumn())['receipt'];
            $db->commit(); return $receipt;
        }
        $resume=q('SELECT a.request_fingerprint,a.outcome_json,a.action_type FROM darycheh_actions a JOIN darycheh_attempts t ON t.id=a.attempt_id WHERE t.user_id=? AND a.request_key=? ORDER BY a.id LIMIT 1',[$userId,$requestKey])->fetch();
        if ($resume) {
            dr_require($resume['action_type']==='resume_requested' && hash_equals($resume['request_fingerprint'],$fingerprint),'این شناسه برای درخواست دیگری استفاده شده است.',409);
            $db->commit(); return dr_decode($resume['outcome_json'])['receipt'];
        }
        $active=q("SELECT * FROM darycheh_attempts WHERE user_id=? AND scenario_key=? AND status='in_progress'",[$userId,$scenario])->fetch();
        if ($restartId===null && $active) {
            $activeState=dr_state($active,dr_release($active));
            $active['revision']++; $active['last_action_seq']++;
            q('UPDATE darycheh_attempts SET revision=?,last_action_seq=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$active['revision'],$active['last_action_seq'],$active['id']]);
            $receipt=dr_receipt($active);
            dr_append($active,$requestKey,$fingerprint,'resume_requested',$active['current_step_key'],[],$activeState,$receipt);
            $db->commit(); return $receipt;
        }
        $path=dirname(__DIR__).'/content/darycheh.php';
        dr_require(is_file($path),'تعریف سناریو در دسترس نیست.',404);
        $d=require $path;
        dr_require(is_array($d),'تعریف سناریو ناقص است.',409); dr_validate_definition($d);
        dr_require($d['scenario_key']===$scenario,'شناسهٔ محتوای سناریو ناسازگار است.',409);
        $release=q('SELECT * FROM darycheh_releases WHERE scenario_key=? AND content_version=?',[$scenario,$d['content_version']])->fetch();
        if (!$release) {
            $insert='INSERT INTO darycheh_releases(scenario_key,content_version,definition_schema_version,engine_version,assessment_version,definition_hash,definition_json,availability) VALUES (?,?,?,?,?,?,?,?)';
            $insert.=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(scenario_key,content_version) DO NOTHING';
            q($insert,[$scenario,$d['content_version'],1,$d['engine_version'],$d['assessment_version'],dr_hash($d),dr_json($d),'available']);
            $select='SELECT * FROM darycheh_releases WHERE scenario_key=? AND content_version=?';
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $select.=' FOR UPDATE';
            $release=q($select,[$scenario,$d['content_version']])->fetch();
            dr_require((bool)$release,'انتشار نسخهٔ سناریو کامل نشد.',409);
        }
        dr_require($release['availability']==='available' && hash_equals($release['definition_hash'],dr_hash($d)),'این نسخه برای شروع تازه در دسترس نیست.',409);
        if ($restartId!==null) {
            $old=dr_owned($userId,$restartId);
            dr_require($old['scenario_key']===$scenario && (int)$old['revision']===$expectedRevision && (!$active || (int)$active['id']===$restartId),'وضعیت تغییر کرده است؛ دوباره بارگذاری کن.',409);
            if ($old['status']==='in_progress') {
                $oldState=dr_state($old,dr_release($old));
                $old['status']='abandoned'; $old['revision']++; $old['last_action_seq']++;
                q("UPDATE darycheh_attempts SET status='abandoned',active_slot=NULL,abandoned_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,revision=?,last_action_seq=? WHERE id=?",[$old['revision'],$old['last_action_seq'],$old['id']]);
                dr_append($old,$requestKey,$fingerprint,'abandoned',$old['current_step_key'],[],$oldState,dr_receipt($old));
            }
        }
        $number=(int)q('SELECT COALESCE(MAX(attempt_no),0)+1 FROM darycheh_attempts WHERE user_id=? AND scenario_key=?',[$userId,$scenario])->fetchColumn();
        $s=dr_initial_state();
        q("INSERT INTO darycheh_attempts(user_id,release_id,scenario_key,attempt_no,start_request_key,start_fingerprint,status,active_slot,current_step_key,revision,last_action_seq,state_json) VALUES (?,?,?,?,?,?,'in_progress',1,?,1,1,?)",[$userId,$release['id'],$scenario,$number,$requestKey,$fingerprint,$d['entry_step_key'],dr_json($s)]);
        $attempt=dr_owned($userId,(int)$db->lastInsertId()); $receipt=dr_receipt($attempt);
        dr_append($attempt,$requestKey,$fingerprint,'started',$d['entry_step_key'],[],$s,$receipt);
        record_activity_event($userId,'darycheh_started',['attempt_id'=>(int)$attempt['id'],'scenario_key'=>$scenario]);
        $db->commit(); return $receipt;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function dr_act(int $userId,int $id,int $revision,string $requestKey,string $action,array $payload): array {
    dr_request_key($requestKey);
    dr_require(in_array($action,['submit','hint'],true) && strlen(dr_json($payload))<=8192,'نوع اقدام یا حجم پاسخ معتبر نیست.');
    $fingerprint=dr_hash([$revision,$action,$payload]); $db=db(); $db->beginTransaction();
    try {
        ws_lock_user($userId);
        $attempt=dr_owned($userId,$id);
        $prior=q('SELECT request_fingerprint,outcome_json FROM darycheh_actions WHERE attempt_id=? AND request_key=?',[$id,$requestKey])->fetch();
        if ($prior) {
            dr_require(hash_equals($prior['request_fingerprint'],$fingerprint),'شناسهٔ درخواست با دادهٔ دیگری استفاده شده است.',409);
            $db->commit(); return dr_decode($prior['outcome_json'])['receipt'];
        }
        dr_require($attempt['status']==='in_progress','این تلاش پایان یافته است؛ برای تمرین تازه شروع مجدد کن.',409);
        dr_require((int)$attempt['revision']===$revision,'پاسخ جدیدتری ذخیره شده است؛ صفحه را دوباره بارگذاری کن.',409);
        $d=dr_release($attempt); $s=dr_state($attempt,$d); $oldStep=$attempt['current_step_key'];
        $reduced=dr_reduce($d,$oldStep,$s,$action,$payload,dr_exposed($userId,$attempt['scenario_key']));
        $s=$reduced['state']; $attempt['current_step_key']=$reduced['next']; $attempt['revision']++; $attempt['last_action_seq']++;
        $finished=$d['steps'][$reduced['next']]['kind']==='report';
        if ($finished) $attempt['status']='completed';
        $updated=q('UPDATE darycheh_attempts SET state_json=?,current_step_key=?,revision=?,last_action_seq=?,status=?,active_slot=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND revision=?',[
            dr_json($s),$attempt['current_step_key'],$attempt['revision'],$attempt['last_action_seq'],$attempt['status'],$finished?null:1,$finished?gmdate('Y-m-d H:i:s'):null,$id,$userId,$revision,
        ]);
        dr_require($updated->rowCount()===1,'وضعیت هم‌زمان تغییر کرده است.',409);
        $receipt=dr_receipt($attempt);
        dr_append($attempt,$requestKey,$fingerprint,$reduced['type'],$oldStep,$payload,$s,$receipt,$reduced['exposed_keys']);
        if ($reduced['evaluation']) {
            $e=$reduced['evaluation']; $result=$e['result'];
            $evidence=['answers'=>$s['answers'],'help'=>$s['help'],'bundle'=>$s['bundle'],'round'=>$s['round'],'release_hash'=>dr_hash($d)];
            q('INSERT INTO darycheh_evaluations(attempt_id,source_action_seq,scope,round_no,assessment_hash,input_hash,evidence_json,result_json,score,max_score,passed,support_level,is_independent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $id,$attempt['last_action_seq'],$e['scope'],max(1,$s['round']),dr_hash([$d['assessment_version'],dr_hash($d)]),dr_hash($evidence),dr_json($evidence),dr_json($result),$result['score'],$result['max_score'],(int)$result['passed'],$result['support_level'],(int)$result['is_independent'],
            ]);
        }
        if ($finished) record_activity_event($userId,'darycheh_completed',['attempt_id'=>$id,'scenario_key'=>$attempt['scenario_key'],'ending'=>$s['summary']['ending']]);
        $db->commit(); return $receipt;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function dr_available_scenario(): ?array {
    $path=dirname(__DIR__).'/content/darycheh.php';
    if (!is_file($path)) return null;
    $d=require $path;
    dr_require(is_array($d),'تعریف سناریو ناقص است.',409);
    dr_validate_definition($d);
    $release=q('SELECT availability,definition_hash FROM darycheh_releases WHERE scenario_key=? AND content_version=?',[$d['scenario_key'],$d['content_version']])->fetch();
    if ($release) {
        if ($release['availability']!=='available') return null;
        dr_require(hash_equals($release['definition_hash'],dr_hash($d)),'این نسخه با محتوای منتشرشده سازگار نیست.',409);
    }
    return ['scenario_key'=>$d['scenario_key'],'title'=>$d['title'],'content_version'=>$d['content_version']];
}
function dr_view(int $userId,int $id): array {
    $attempt=dr_owned($userId,$id); $d=dr_release($attempt); $s=dr_state($attempt,$d); $step=$d['steps'][$attempt['current_step_key']];
    if ($step['kind']==='retry' && !($s['main']['passed'] ?? false)) unset($step['fields']['route']['options']['continue']);
    $view=dr_receipt($attempt)+['title'=>$d['title'],'context'=>$d['steps'][$d['entry_step_key']]['text'],'attempt_no'=>(int)$attempt['attempt_no'],'content_version'=>$d['content_version'],'stage'=>['kind'=>$step['kind'],'title'=>$step['title'],'text'=>$step['text'],'fields'=>$step['fields']],'answers'=>$s['answers'],'hint'=>$s['hint'],'help_level'=>$s['help'],'round'=>$s['round']];
    if (in_array($step['kind'],['feedback','retry','transfer','transfer_feedback','report'],true)) {
        $view['main']=$s['main'];
        foreach ($d['steps'] as $source) if ($source['kind']==='consequence') {
            foreach ($source['fields'] as $key=>$field) $view['predictions'][$key]=$field['options'][$s['answers']['consequence'][$key]];
        }
    }
    if (in_array($step['kind'],['transfer','transfer_feedback'],true)) {
        $bundle=$d['transfer_bundles'][$s['bundle']];
        $view['bundle']=['key'=>$s['bundle'],'label'=>$bundle['label'],'text'=>$bundle['text'],'previously_exposed'=>in_array($bundle['exposure_key'],dr_exposed($userId,$attempt['scenario_key']),true)];
    }
    if (in_array($step['kind'],['transfer_feedback','report'],true)) $view['transfer']=$s['transfer'];
    if ($step['kind']==='report') $view['summary']=$s['summary'];
    return $view;
}
function dr_progress(int $userId): array {
    $rows=q('SELECT e.id,e.attempt_id,e.scope,e.assessment_hash,e.result_json,e.created_at FROM darycheh_evaluations e JOIN darycheh_attempts t ON t.id=e.attempt_id WHERE t.user_id=? ORDER BY e.id',[$userId])->fetchAll();
    $groups=[]; $skillGroups=[];
    foreach ($rows as $row) {
        try { $result=dr_decode($row['result_json']); } catch (DarychehError $e) { continue; }
        if (!isset($result['score'],$result['max_score'],$result['passed'],$result['is_independent'],$result['dimensions']) || !is_array($result['dimensions'])) continue;
        $key=$row['assessment_hash'].':'.$row['scope'];
        $entry=['evaluation_id'=>(int)$row['id'],'attempt_id'=>(int)$row['attempt_id'],'score'=>$result['score'],'max_score'=>$result['max_score'],'passed'=>$result['passed'],'independent'=>$result['is_independent'],'dimensions'=>$result['dimensions'],'created_at'=>$row['created_at']];
        if (!isset($groups[$key])) $groups[$key]=['first'=>$entry,'best'=>$entry,'last'=>$entry];
        if ($entry['score']>$groups[$key]['best']['score']) $groups[$key]['best']=$entry;
        $groups[$key]['last']=$entry;
        if ($row['scope']==='summary' && isset($result['skills']) && is_array($result['skills'])) {
            foreach ($result['skills'] as $skill=>$value) {
                if (!is_array($value) || !isset($value['status'],$value['label'],$value['description'],$value['evidence']) || !in_array($value['status'],['assessed','not_assessed'],true)) continue;
                if ($value['status']==='assessed' && (!is_int($value['score'] ?? null) || $value['score']<0 || $value['score']>4)) continue;
                $item=$value+['attempt_id'=>$entry['attempt_id'],'independent'=>$entry['independent'],'evaluation_id'=>$entry['evaluation_id']];
                if (!isset($skillGroups[$row['assessment_hash']][$skill])) $skillGroups[$row['assessment_hash']][$skill]=['first'=>$item,'best'=>$item,'last'=>$item];
                $history=&$skillGroups[$row['assessment_hash']][$skill];
                if (($item['score'] ?? -1)>($history['best']['score'] ?? -1)) $history['best']=$item;
                $history['last']=$item;
                unset($history);
            }
        }
    }
    if (!$skillGroups && is_file(dirname(__DIR__).'/content/darycheh.php')) {
        $definition=require dirname(__DIR__).'/content/darycheh.php';
        if (is_array($definition)) foreach (dr_skill_evaluation($definition,[]) as $skill=>$item) $skillGroups['unassessed'][$skill]=['first'=>$item,'best'=>$item,'last'=>$item];
    }
    return ['groups'=>$groups,'skill_groups'=>$skillGroups,'note'=>'شواهد همین سناریو؛ نه درصد تسلط یا تضمین مهارت.'];
}
function dr_build_product(int $userId,int $id,int $revision): int {
    $attempt=dr_owned($userId,$id);
    dr_require($attempt['status']==='completed' && (int)$attempt['revision']===$revision,'ابتدا سناریو را کامل کن و گزارش فعلی را باز کن.',409);
    $definition=dr_release($attempt); $state=dr_state($attempt,$definition);
    $source=file_get_contents(dirname(__DIR__).'/content/darycheh_product.py');
    $source=str_replace(['__ORDER__','__TESTS__'],[base64_encode(dr_json($state['answers']['solution']['order'])),base64_encode(dr_json($definition['tests']))],$source);
    $spec=['source_attempt'=>$id,'source_revision'=>$revision,'content_version'=>$definition['content_version'],'problem'=>$definition['steps'][$definition['entry_step_key']]['text'],'decisions'=>$state['answers'],'operations'=>$definition['operations'],'acceptance_tests'=>$definition['tests'],'limitations'=>['فقط درخواست ترتیبی و دادهٔ ساختگی','حافظه موقت؛ بدون پایگاه داده، پرداخت یا کنترل هم‌زمانی','قبولی این تست‌ها تضمین آمادگی محصول واقعی نیست'],'analysis'=>'نتیجهٔ واقعی اجرا را در product-results.json بررسی کن؛ expected معیار و actual خروجی نسخهٔ توست.','next_step'=>'پس از ذخیره و اجرا، خطاها و محدودیت‌ها را اینجا ثبت کن؛ سپس ORDER یا process را اصلاح کن و آزمون مرزی اضافه کن.'];
    $files=['main.py'=>['kind'=>'file','data'=>base64_encode($source)],'specification.json'=>['kind'=>'file','data'=>base64_encode(json_encode($spec,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))],'instructions.txt'=>['kind'=>'file','data'=>base64_encode('این نمونه از تصمیم‌های خودت ساخته شده، نه پاسخ مرجع. ابتدا specification.json را بررسی و تحلیل و گام بعدی را تکمیل کن. در میزکار ذخیره کن و main.py را اجرا کن؛ هر آزمون expected و actual دارد. خروجی product-results.json فقط پس از اجرا ساخته می‌شود. تغییرها را در همین میزکار ذخیره و دوباره اجرا کن؛ نتیجهٔ هر اجرا به نسخهٔ ذخیره‌شده مرتبط است. هر بار زدن دکمهٔ ساخت، یک پروژهٔ تازه ایجاد می‌کند. این خروجی ارزیابی محلی نمونه است و امتیاز مهارت دریچه را تغییر نمی‌دهد.')]];
    try { return ws_create($userId,0,'نمونهٔ عملی دریچه؛ تلاش '.$attempt['attempt_no'],null,ws_manifest($files)); }
    catch (InvalidArgumentException $e) { throw new DarychehError($e->getMessage()); }
}
function dr_api(string $path): never {
    $u=user();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    try {
        dr_require($u!==null && trim($u['name'])!=='','برای ادامه وارد حساب شو.',401);
        $uid=(int)$u['id']; $post=$_SERVER['REQUEST_METHOD']==='POST';
        $integer=static function(mixed $v): int {
            dr_require((is_int($v) || is_string($v)) && filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false,'شناسه یا نسخه معتبر نیست.'); return (int)$v;
        };
        if (!$post && $path==='darycheh/state') $result=dr_view($uid,$integer($_GET['id'] ?? null));
        elseif (!$post && $path==='darycheh/progress') $result=dr_progress($uid);
        elseif ($post && $path==='darycheh/build') {
            dr_request_key($_POST['request_key'] ?? null);
            $projectId=dr_build_product($uid,$integer($_POST['id'] ?? null),$integer($_POST['revision'] ?? null));
            if (($_POST['_html'] ?? '')==='1') { header_remove('Content-Type'); redirect('dashboard/workspace/'.$projectId); }
            $result=['project_id'=>$projectId];
        }
        elseif ($post && in_array($path,['darycheh/start','darycheh/restart'],true)) {
            $scenario=$_POST['scenario_key'] ?? 'first-real-problem'; dr_require(is_string($scenario));
            $result=dr_start($uid,$scenario,dr_request_key($_POST['request_key'] ?? null),$path==='darycheh/restart'?$integer($_POST['id'] ?? null):null,$path==='darycheh/restart'?$integer($_POST['revision'] ?? null):null);
        } elseif ($post && $path==='darycheh/act') {
            $payload=$_POST['payload'] ?? [];
            if (is_string($payload)) {
                dr_require(strlen($payload)<=8192,'حجم پاسخ بیش از حد مجاز است.');
                try { $payload=json_decode($payload,true,32,JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { throw new DarychehError('ساختار پاسخ معتبر نیست.'); }
            }
            dr_require(is_array($payload)); $action=$_POST['action'] ?? 'submit'; dr_require(is_string($action));
            $result=dr_act($uid,$integer($_POST['id'] ?? null),$integer($_POST['revision'] ?? null),dr_request_key($_POST['request_key'] ?? null),$action,$payload);
        } else throw new DarychehError('مسیر پیدا نشد.',404);
        if ($post && ($_POST['_html'] ?? '')==='1') { header_remove('Content-Type'); flash('انتخابت ذخیره شد.'); redirect('dashboard/darycheh/'.$result['attempt_id']); }
        echo dr_json($result);
    } catch (DarychehError $e) {
        if ($post ?? false) {
            if (($_POST['_html'] ?? '')==='1') {
                header_remove('Content-Type'); flash($e->getMessage(),'error');
                $return='dashboard/darycheh';
                $id=filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if (isset($uid) && $id && in_array($e->status,[409,422],true) && q('SELECT 1 FROM darycheh_attempts WHERE id=? AND user_id=?',[$id,$uid])->fetchColumn()) $return.='/'.$id;
                redirect($return);
            }
        }
        http_response_code($e->status); echo dr_json(['error'=>$e->getMessage()]);
    }
    exit;
}
