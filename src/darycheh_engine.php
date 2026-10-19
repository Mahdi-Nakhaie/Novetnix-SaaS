<?php
declare(strict_types=1);

final class DarychehError extends RuntimeException {
    public function __construct(string $message, public readonly int $status=422) { parent::__construct($message); }
}
function dr_json(mixed $value): string {
    return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function dr_canonical(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value,SORT_STRING);
    foreach ($value as &$child) $child=dr_canonical($child);
    return $value;
}
function dr_hash(mixed $value): string { return hash('sha256',dr_json(dr_canonical($value))); }
function dr_require(bool $condition,string $message='دادهٔ درخواست معتبر نیست.',int $status=422): void {
    if (!$condition) throw new DarychehError($message,$status);
}
function dr_identifier(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}$/D',$value)===1;
}
function dr_validate_definition(array $d): void {
    foreach (['scenario_key','content_version','engine_version','assessment_version','entry_step_key'] as $key) dr_require(dr_identifier($d[$key] ?? null),'تعریف سناریو ناقص است.',409);
    dr_require(($d['definition_schema_version'] ?? null)===1 && $d['engine_version']==='1' && $d['assessment_version']==='1','نسخهٔ موتور یا ارزیابی پشتیبانی نمی‌شود.',409);
    dr_require(is_string($d['title'] ?? null) && is_array($d['steps'] ?? null) && count($d['steps'])<=32,'تعریف مراحل ناقص است.',409);
    $kinds=['intro','evidence','problem','solution','consequence','feedback','retry','transfer','transfer_feedback','report'];
    $counts=[];
    foreach ($d['steps'] as $key=>$step) {
        dr_require(dr_identifier($key) && is_array($step) && in_array($step['kind'] ?? '',$kinds,true),'نوع مرحله معتبر نیست.',409);
        $counts[$step['kind']]=($counts[$step['kind']] ?? 0)+1;
        dr_require(is_string($step['title'] ?? null) && is_string($step['text'] ?? null) && is_array($step['fields'] ?? null),'اطلاعات مرحله ناقص است.',409);
        foreach ($step['fields'] as $field=>$spec) {
            dr_require(dr_identifier($field) && is_array($spec) && in_array($spec['kind'] ?? '',['single','multi','order'],true) && is_string($spec['label'] ?? null) && is_array($spec['options'] ?? null) && count($spec['options'])>0,'گزینه‌های مرحله ناقص است.',409);
            foreach ($spec['options'] as $option=>$label) dr_require(dr_identifier((string)$option) && is_string($label),'شناسهٔ گزینه نامعتبر است.',409);
            if ($spec['kind']!=='single') dr_require(is_int($spec['min'] ?? null) && is_int($spec['max'] ?? null) && $spec['min']>=0 && $spec['max']>=$spec['min'] && $spec['max']<=count($spec['options']),'محدودیت انتخاب معتبر نیست.',409);
        }
        dr_require(!isset($step['routes']) || is_array($step['routes']),'قواعد مسیر ناقص است.',409);
        $targets=isset($step['routes']) ? array_values($step['routes']) : (isset($step['next'])?[$step['next']]:[]);
        dr_require($step['kind']==='report' || count($targets)>0,'مسیر مرحله ناقص است.',409);
        foreach ($targets as $target) dr_require(is_string($target) && isset($d['steps'][$target]),'مقصد مرحله وجود ندارد.',409);
    }
    foreach ($kinds as $kind) dr_require(($counts[$kind] ?? 0)===1,'ساختار تجربهٔ سناریو ناقص است.',409);
    dr_require(isset($d['steps'][$d['entry_step_key']]),'مرحلهٔ آغاز وجود ندارد.',409);
    $visited=[]; $pending=[$d['entry_step_key']];
    while ($pending) {
        $key=array_pop($pending);
        if (isset($visited[$key])) continue;
        $visited[$key]=true; $step=$d['steps'][$key];
        foreach (isset($step['routes'])?array_values($step['routes']):(isset($step['next'])?[$step['next']]:[]) as $target) $pending[]=$target;
    }
    dr_require(count($visited)===count($d['steps']),'مرحلهٔ غیرقابل دسترس وجود دارد.',409);
    dr_require(is_array($d['operations'] ?? null) && array_keys($d['operations'])===['duplicate','validate','capacity','reserve','remember'],'عملیات شبیه‌سازی پشتیبانی نمی‌شود.',409);
    dr_require(is_array($d['tests'] ?? null) && count($d['tests'])===4 && is_array($d['rubric'] ?? null) && is_array($d['transfer_bundles'] ?? null) && count($d['transfer_bundles'])===2,'قواعد ارزیابی ناقص است.',409);
    dr_require(is_array($d['rubric']['evidence'] ?? null) && is_array($d['rubric']['problem'] ?? null),'شواهد ارزیابی ناقص است.',409);
    dr_require(($d['rubric']['minimum_total'] ?? null)===16 && ($d['rubric']['minimum_dimension'] ?? null)===3 && count($d['rubric']['evidence'])===4 && count($d['rubric']['problem'])===4,'قرارداد ارزیابی نامعتبر است.',409);
    foreach (['evidence','problem','algorithm','testing','transfer'] as $key) dr_require(is_string($d['objectives'][$key] ?? null) && is_string($d['skills'][$key] ?? null),'هدف یادگیری ناقص است.',409);
    dr_require(is_array($d['hints'] ?? null) && count($d['hints'])===3 && count(array_filter($d['hints'],'is_string'))===3,'راهنمایی ناقص است.',409);
    $testKeys=[];
    foreach ($d['tests'] as $test) {
        dr_require(is_array($test) && dr_identifier($test['key'] ?? null) && is_string($test['label'] ?? null) && is_int($test['capacity'] ?? null) && $test['capacity']>=0 && is_array($test['requests'] ?? null) && count($test['requests'])>0 && count($test['requests'])<=10 && is_string($test['expected'] ?? null),'آزمون سناریو ناقص است.',409);
        $testKeys[]=$test['key'];
        foreach ($test['requests'] as $request) dr_require(is_array($request) && count($request)===2 && dr_identifier($request[0] ?? null) && is_int($request[1] ?? null),'ورودی شبیه‌سازی ناقص است.',409);
    }
    dr_require(count(array_unique($testKeys))===4,'شناسهٔ آزمون تکراری است.',409);
    foreach ($d['transfer_bundles'] as $key=>$bundle) dr_require(dr_identifier($key) && is_array($bundle) && dr_identifier($bundle['exposure_key'] ?? null) && is_string($bundle['text'] ?? null) && is_string($bundle['label'] ?? null) && in_array($bundle['remaining'] ?? null,['0','1','2','3','4'],true),'بستهٔ انتقال ناقص است.',409);
    $byKind=[];
    foreach ($d['steps'] as $step) $byKind[$step['kind']]=$step;
    $contracts=['intro'=>[],'evidence'=>['selected'],'problem'=>array_keys($d['rubric']['problem']),'solution'=>['order'],'consequence'=>$testKeys,'feedback'=>[],'retry'=>['route'],'transfer'=>['cause','order','remaining','test'],'transfer_feedback'=>['route'],'report'=>[]];
    foreach ($contracts as $kind=>$fields) {
        $actual=array_keys($byKind[$kind]['fields']); sort($actual); sort($fields);
        dr_require($actual===$fields,'قرارداد ورودی مرحله با موتور سازگار نیست.',409);
    }
    dr_require($d['steps'][$d['entry_step_key']]['kind']==='intro','مرحلهٔ آغاز باید ورود به موقعیت باشد.',409);
    foreach (['intro'=>'evidence','evidence'=>'problem','problem'=>'solution','solution'=>'consequence','consequence'=>'feedback','feedback'=>'retry','transfer'=>'transfer_feedback'] as $kind=>$nextKind) {
        $target=$byKind[$kind]['next'] ?? null;
        dr_require(is_string($target) && ($d['steps'][$target]['kind'] ?? null)===$nextKind && !isset($byKind[$kind]['routes']),'ترتیب مراحل با قرارداد تجربه سازگار نیست.',409);
    }
    foreach (['retry'=>['revise'=>'solution','redefine'=>'evidence','continue'=>'transfer'],'transfer_feedback'=>['retry'=>'transfer','finish'=>'report']] as $kind=>$routes) {
        dr_require(array_keys($byKind[$kind]['routes'] ?? [])===array_keys($routes),'شاخه‌های مرحله ناقص است.',409);
        foreach ($routes as $route=>$targetKind) dr_require(($d['steps'][$byKind[$kind]['routes'][$route]]['kind'] ?? null)===$targetKind && isset($byKind[$kind]['fields']['route']['options'][$route]),'مقصد شاخه نامعتبر است.',409);
    }
    dr_require($byKind['evidence']['fields']['selected']['kind']==='multi' && $byKind['solution']['fields']['order']['kind']==='order','نوع ورودی مرحله نامعتبر است.',409);
    foreach ($d['rubric']['evidence'] as $id) dr_require(is_string($id) && isset($byKind['evidence']['fields']['selected']['options'][$id]),'ارجاع شاهد نامعتبر است.',409);
    foreach ($d['rubric']['problem'] as $field=>$correct) dr_require(is_string($correct) && isset($byKind['problem']['fields'][$field]['options'][$correct]),'ارجاع معیار مسئله نامعتبر است.',409);
    dr_require(array_keys($byKind['solution']['fields']['order']['options'])===array_keys($d['operations']),'کارت‌های روند با موتور سازگار نیست.',409);
    foreach ($d['tests'] as $test) dr_require(isset($byKind['consequence']['fields'][$test['key']]['options'][$test['expected']]) && dr_simulate($test,array_keys($d['operations']))['passed'],'نتیجهٔ مرجع تست با قواعد سازگار نیست.',409);
    foreach (['cause'=>'duplicate','order'=>'before','test'=>'invalid'] as $field=>$correct) dr_require(isset($byKind['transfer']['fields'][$field]['options'][$correct]),'گزینهٔ انتقال ناقص است.',409);
    foreach ($d['transfer_bundles'] as $bundle) dr_require(isset($byKind['transfer']['fields']['remaining']['options'][$bundle['remaining']]),'پاسخ بستهٔ انتقال در گزینه‌ها وجود ندارد.',409);
}
function dr_validate_payload(array $step,array $payload): array {
    dr_require(strlen(dr_json($payload))<=8192,'حجم پاسخ بیش از حد مجاز است.');
    dr_require(!array_diff(array_keys($payload),array_keys($step['fields'])),'فیلد ناشناخته ارسال شده است.');
    $answer=[];
    foreach ($step['fields'] as $key=>$spec) {
        $value=$payload[$key] ?? null;
        if ($spec['kind']==='single') {
            dr_require(is_string($value) && array_key_exists($value,$spec['options']),'یک گزینهٔ مجاز برای هر بخش انتخاب کن.');
        } else {
            dr_require(is_array($value) && array_is_list($value),'ساختار انتخاب معتبر نیست.');
            if ($spec['kind']==='order') $value=array_values(array_filter($value,fn($v)=>$v!==''));
            dr_require(count($value)>=$spec['min'] && count($value)<=$spec['max'],'تعداد انتخاب‌ها معتبر نیست.');
            foreach ($value as $item) dr_require(is_string($item) && array_key_exists($item,$spec['options']),'انتخاب ناشناخته ارسال شده است.');
            dr_require(count(array_unique($value))===count($value),'کارت یا گزینهٔ تکراری مجاز نیست.');
            if ($spec['kind']==='multi') sort($value,SORT_STRING);
        }
        $answer[$key]=$value;
    }
    return $answer;
}
function dr_simulate(array $test,array $order): array {
    $capacity=$test['capacity']; $reservations=0; $cache=[]; $trace=[]; $last=[];
    foreach ($test['requests'] as [$id,$amount]) {
        $status='pending'; $replayed=false;
        foreach ($order as $operation) {
            $trace[]=['request'=>$id,'operation'=>$operation,'capacity'=>$capacity,'reservations'=>$reservations];
            if ($operation==='duplicate' && isset($cache[$id])) { $status=$cache[$id]; $replayed=true; break; }
            if (($operation==='validate' && $amount<=0) || ($operation==='capacity' && $amount>$capacity)) {
                $status='rejected'; $cache[$id]=$status; break;
            }
            if ($operation==='reserve') { $capacity-=$amount; $reservations++; $status='reserved'; }
            if ($operation==='remember') $cache[$id]=$status;
        }
        $last=['status'=>$status,'replayed'=>$replayed,'capacity'=>$capacity,'reservations'=>$reservations];
    }
    $code=($last['replayed']?'replayed':$last['status']).':'.$capacity.':'.$reservations;
    return ['code'=>$code,'passed'=>$code===$test['expected'],'final'=>$last,'trace'=>$trace];
}
function dr_initial_state(): array {
    return ['answers'=>[],'round'=>0,'help'=>0,'hint'=>null,'main'=>null,'transfer'=>null,'bundle'=>null,'exposed_keys'=>[],'summary'=>null];
}
function dr_criterion(array $d,string $dimension,string $key,bool $passed): array {
    return ['criterion_key'=>$key,'objective_key'=>$dimension,'skill_key'=>$d['skills'][$dimension],'score'=>(int)$passed,'max_score'=>1];
}
function dr_main_evaluation(array $d,array $s): array {
    $criteria=[]; $dimensions=[];
    foreach ($d['rubric']['evidence'] as $id) $criteria[]=dr_criterion($d,'evidence',$id,in_array($id,$s['answers']['evidence']['selected'],true));
    foreach ($d['rubric']['problem'] as $key=>$correct) $criteria[]=dr_criterion($d,'problem',$key,$s['answers']['problem'][$key]===$correct);
    $order=$s['answers']['solution']['order'];
    $before=fn($a,$b)=>in_array($a,$order,true) && in_array($b,$order,true) && array_search($a,$order,true)<array_search($b,$order,true);
    $checks=[
        'history_before_change'=>$before('duplicate','reserve'),
        'return_previous'=>$before('duplicate','validate') && $before('duplicate','capacity'),
        'guard_before_reserve'=>$before('validate','reserve') && $before('capacity','reserve'),
        'remember_result'=>$before('reserve','remember'),
    ];
    foreach ($checks as $key=>$passed) $criteria[]=dr_criterion($d,'algorithm',$key,$passed);
    $tests=[];
    foreach ($d['tests'] as $test) {
        $tests[$test['key']]=dr_simulate($test,$order);
        $tests[$test['key']]['label']=$test['label'];
        $criteria[]=dr_criterion($d,'testing',$test['key'],$s['answers']['consequence'][$test['key']]===$test['expected']);
    }
    foreach ($criteria as $criterion) $dimensions[$criterion['objective_key']]=($dimensions[$criterion['objective_key']] ?? 0)+$criterion['score'];
    return ['criteria'=>$criteria,'dimensions'=>$dimensions,'tests'=>$tests,'score'=>array_sum($dimensions),'max_score'=>16,'passed'=>count(array_filter($tests,fn($test)=>$test['passed']))===count($tests),'support_level'=>dr_support($s),'is_independent'=>$s['help']===0];
}
function dr_support(array $s): string { return $s['help']===0?'none':($s['help']<3?'hinted':'solution_revealed'); }
function dr_bundle(array $d,array $seen): string {
    foreach ($d['transfer_bundles'] as $key=>$bundle) if (!in_array($bundle['exposure_key'],$seen,true)) return $key;
    return array_key_first($d['transfer_bundles']);
}
function dr_reduce(array $d,string $stepKey,array $s,string $action,array $payload,array $seen): array {
    dr_require(isset($d['steps'][$stepKey]),'مرحلهٔ ذخیره‌شده معتبر نیست.',409);
    $step=$d['steps'][$stepKey]; $kind=$step['kind']; $evaluation=null; $exposed=[];
    if ($action==='hint') {
        dr_require(in_array($kind,['solution','feedback','retry'],true) && $payload===[] && $s['help']<3,'در این مرحله راهنمایی دیگری موجود نیست.');
        $s['hint']=$d['hints'][$s['help']]; $s['help']++;
        return ['state'=>$s,'next'=>$stepKey,'evaluation'=>null,'exposed_keys'=>[],'type'=>$s['help']===3?'answer_revealed':'hint_viewed'];
    }
    dr_require($action==='submit' && $kind!=='report','اقدام در این مرحله مجاز نیست.',409);
    $answer=dr_validate_payload($step,$payload);
    $s['answers'][$kind]=$answer;
    $next=$step['next'] ?? null; $type=$kind.'_submitted';
    if ($kind==='solution') {
        $s['round']++; $s['main']=null; $s['transfer']=null; $s['summary']=null;
    }
    if ($kind==='consequence') {
        foreach (['evidence','problem','solution'] as $required) dr_require(isset($s['answers'][$required]),'اطلاعات مراحل قبلی ناقص است.',409);
        $s['main']=dr_main_evaluation($d,$s); $evaluation=['scope'=>'main','result'=>$s['main']]; $type='simulation_run';
    }
    if ($kind==='retry') {
        $route=$answer['route'];
        dr_require($route!=='continue' || ($s['main']['passed'] ?? false),'ابتدا چهار تست را با موفقیت اجرا کن.',409);
        $next=$step['routes'][$route];
        if ($route==='continue') $s['bundle']=dr_bundle($d,$seen);
        else { $s['main']=null; $s['transfer']=null; $s['summary']=null; }
    }
    if ($kind==='transfer') {
        dr_require(isset($d['transfer_bundles'][$s['bundle'] ?? '']),'بستهٔ انتقال مشخص نیست.',409);
        $bundle=$d['transfer_bundles'][$s['bundle']];
        $expected=['cause'=>'duplicate','order'=>'before','remaining'=>$bundle['remaining'],'test'=>'invalid'];
        $criteria=[];
        foreach ($expected as $key=>$value) $criteria[]=dr_criterion($d,'transfer',$key,$answer[$key]===$value);
        $score=array_sum(array_column($criteria,'score'));
        $independent=!in_array($bundle['exposure_key'],$seen,true);
        $s['transfer']=['criteria'=>$criteria,'dimensions'=>['transfer'=>$score],'score'=>$score,'max_score'=>4,'passed'=>$score>=3,'is_independent'=>$independent,'support_level'=>$independent?'none':'solution_revealed','bundle'=>$s['bundle'],'exposure_key'=>$bundle['exposure_key'],'expected'=>$expected];
        $evaluation=['scope'=>'transfer','result'=>$s['transfer']];
        $exposed=[$bundle['exposure_key']];
        $s['exposed_keys']=array_values(array_unique(array_merge($s['exposed_keys'],$exposed)));
    }
    if ($kind==='transfer_feedback') {
        dr_require(isset($s['main'],$s['transfer']),'نتایج ارزیابی ناقص است.',409);
        $next=$step['routes'][$answer['route']];
        if ($answer['route']==='retry') $s['bundle']=dr_bundle($d,$seen);
        else {
            $dimensions=$s['main']['dimensions']+$s['transfer']['dimensions'];
            $score=array_sum($dimensions);
            $eligible=$s['main']['passed'] && $s['transfer']['passed'] && $score>=$d['rubric']['minimum_total'] && min($dimensions)>=$d['rubric']['minimum_dimension'];
            $passed=$eligible && $s['transfer']['is_independent'];
            $independent=$passed && $s['help']===0;
            $support=dr_support($s);
            if (!$s['transfer']['is_independent']) $support='solution_revealed';
            $ending=$passed?($independent?'independent':'supported'):($eligible?'needs_fresh_evidence':'needs_practice');
            $s['summary']=['criteria'=>array_merge($s['main']['criteria'],$s['transfer']['criteria']),'dimensions'=>$dimensions,'score'=>$score,'max_score'=>20,'passed'=>$passed,'is_independent'=>$independent,'support_level'=>$support,'ending'=>$ending];
            $evaluation=['scope'=>'summary','result'=>$s['summary']]; $type='completed';
        }
    }
    dr_require(is_string($next) && isset($d['steps'][$next]),'مسیر بعدی سناریو ناقص است.',409);
    return ['state'=>$s,'next'=>$next,'evaluation'=>$evaluation,'exposed_keys'=>$exposed,'type'=>$type];
}
