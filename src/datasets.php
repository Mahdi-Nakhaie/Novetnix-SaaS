<?php
declare(strict_types=1);

const HF_IMPORT_BYTES = 2097152;
const HF_IMPORT_FILES = 10;
const HF_IMPORT_SECONDS = 120;

class HfError extends RuntimeException {}

function hf_id(string $id): string {
    if (strlen($id)>96 || !preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*(?:/[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*)?$~D',$id)) throw new InvalidArgumentException('شناسه دیتاست معتبر نیست.');
    return $id;
}
function hf_url(string $url): string {
    $p=parse_url($url);
    if (!$p || ($p['scheme'] ?? '')!=='https' || ($p['host'] ?? '')!=='huggingface.co' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\x00-\x20\\\\]/',$url)) throw new InvalidArgumentException('مقصد دانلود مجاز نیست.');
    if (!str_starts_with($p['path'] ?? '','/api/') && !str_starts_with($p['path'] ?? '','/datasets/')) throw new InvalidArgumentException('مسیر منبع مجاز نیست.');
    return $url;
}
function hf_http(string $url,int $limit,int $seconds=10,?callable $cancelled=null): string {
    if (!extension_loaded('curl')) throw new HfError('افزونه cURL برای اتصال Hub نصب نیست.');
    $deadline=microtime(true)+$seconds;
    for ($redirect=0;$redirect<4;$redirect++) {
        hf_url($url);
        $addresses=gethostbynamel('huggingface.co') ?: [];
        $ip=null;
        foreach ($addresses as $address) if (filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)) { $ip=$address; break; }
        if ($ip===null) throw new HfError('نام منبع به نشانی عمومی معتبر تبدیل نشد.');
        $body=''; $location=''; $oversize=false; $remaining=(int)ceil($deadline-microtime(true));
        if ($remaining<1) throw new HfError('مهلت ارتباط با Hub پایان یافت.');
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>['huggingface.co:443:'.$ip],CURLOPT_CONNECTTIMEOUT=>min(3,$remaining),CURLOPT_TIMEOUT=>$remaining,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Encoding: identity','User-Agent: Noventix-Datasets/1.0'],CURLOPT_MAXFILESIZE=>$limit,CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function() use ($cancelled,$deadline): int { return microtime(true)>$deadline || ($cancelled && $cancelled()) ? 1 : 0; },CURLOPT_HEADERFUNCTION=>static function($c,string $line) use (&$location): int { if (stripos($line,'Location:')===0) $location=trim(substr($line,9)); return strlen($line); },CURLOPT_WRITEFUNCTION=>static function($c,string $chunk) use (&$body,&$oversize,$limit): int { if (strlen($body)+strlen($chunk)>$limit) { $oversize=true; return 0; } $body.=$chunk; return strlen($chunk); }]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($cancelled && $cancelled()) throw new HfError('دریافت لغو شد یا مهلت آن پایان یافت.');
        if ($oversize) throw new InvalidArgumentException('پاسخ منبع از سقف حجم بیشتر است.');
        if ($ok===false) throw new HfError('ارتباط با Hugging Face ناموفق بود یا از محدودیت حجم/زمان گذشت.');
        if (in_array($status,[301,302,303,307,308],true)) {
            if (str_starts_with($location,'/') && !str_starts_with($location,'//')) $location='https://huggingface.co'.$location;
            $url=hf_url($location); continue;
        }
        if ($status!==200) throw new HfError($status===429?'محدودیت درخواست Hugging Face؛ بعداً تلاش کنید.':'منبع عمومی بدون احراز هویت قابل دریافت نیست.');
        return $body;
    }
    throw new HfError('تعداد تغییر مسیر منبع بیش از حد مجاز است.');
}
function hf_api(string $path): array {
    try { $data=json_decode(hf_http('https://huggingface.co/api/datasets'.$path,524288),true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new HfError('پاسخ سرویس Hub معتبر نیست.'); }
    if (!is_array($data)) throw new HfError('پاسخ Hub معتبر نیست.');
    return $data;
}
function hf_public(array $info): bool {
    return ($info['private'] ?? null)===false && ($info['gated'] ?? null)===false && ($info['disabled'] ?? false)===false;
}
function hf_summary(array $info): array {
    $id=hf_id((string)($info['id'] ?? ''));
    $tags=is_array($info['tags'] ?? null)?$info['tags']:[]; $formats=[]; $license=$info['cardData']['license'] ?? null;
    foreach ($tags as $tag) if (is_string($tag)) {
        if (str_starts_with($tag,'format:')) $formats[]=substr($tag,7,40);
        if ($license===null && str_starts_with($tag,'license:')) $license=substr($tag,8,100);
    }
    if (is_array($license)) $license=array_values(array_slice(array_filter($license,'is_string'),0,10));
    elseif (!is_string($license)) $license=null;
    if (!$license) $license=null;
    return ['id'=>$id,'description'=>mb_substr((string)($info['description'] ?? ''),0,2000),'source'=>'https://huggingface.co/datasets/'.$id,'formats'=>$formats,'license'=>$license,'access'=>hf_public($info)?'public':'unavailable','approximate_bytes'=>null];
}
function hf_search(string $query): array {
    $query=trim($query);
    if (mb_strlen($query)<2 || mb_strlen($query)>80 || preg_match('/[\x00-\x1f]/',$query)) throw new InvalidArgumentException('جست‌وجو باید ۲ تا ۸۰ نویسه باشد.');
    $rows=hf_api('?'.http_build_query(['search'=>$query,'limit'=>15,'full'=>'true'])); $result=[];
    foreach ($rows as $row) if (is_array($row) && hf_public($row)) {
        try { $result[]=hf_summary($row); } catch (InvalidArgumentException $e) { continue; }
    }
    return $result;
}
function hf_detail(string $id,?string $revision=null): array {
    hf_id($id);
    if ($revision!==null && !preg_match('/^[a-f0-9]{40}$/D',$revision)) throw new InvalidArgumentException('نسخه دیتاست معتبر نیست.');
    $info=hf_api('/'.$id.($revision?'/revision/'.$revision:'').'?blobs=true');
    if (!hf_public($info) || ($info['id'] ?? '')!==$id) throw new InvalidArgumentException('فقط دیتاست عمومی، بدون gate و بدون نیاز به توکن مجاز است.');
    $sha=(string)($info['sha'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/D',$sha) || ($revision!==null && $sha!==$revision)) throw new HfError('نسخه ثابت منبع مشخص نیست یا با نسخه درخواست‌شده یکسان نیست.');
    $summary=hf_summary($info); $files=[]; $total=0; $known=true;
    $siblings=$info['siblings'] ?? [];
    if (!is_array($siblings) || count($siblings)>1000) throw new InvalidArgumentException('فهرست این دیتاست برای MVP بیش از حد بزرگ است.');
    foreach ($siblings as $entry) {
        $path=(string)($entry['rfilename'] ?? ''); $size=$entry['size'] ?? null;
        if (!is_int($size) || $size<0) $known=false; else $total+=$size;
        $hash=$entry['lfs']['sha256'] ?? ($entry['blobId'] ?? '');
        $algorithm=isset($entry['lfs'])?'sha256':'git-sha1';
        if (!ws_path($path) || strlen($path)>95 || !in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['csv','json','txt'],true) || !is_int($size) || $size<1 || $size>WS_FILE_BYTES || !is_string($hash) || !preg_match($algorithm==='sha256'?'/^[a-f0-9]{64}$/D':'/^[a-f0-9]{40}$/D',$hash)) continue;
        $files[]=['path'=>$path,'size'=>$size,'hash'=>$hash,'algorithm'=>$algorithm];
    }
    $summary['approximate_bytes']=$known?$total:null;
    return $summary+['revision'=>$sha,'files'=>$files,'limits'=>['file_bytes'=>WS_FILE_BYTES,'import_bytes'=>HF_IMPORT_BYTES,'files'=>HF_IMPORT_FILES,'seconds'=>HF_IMPORT_SECONDS],'notice'=>'فقط فایل‌های CSV/JSON/TXT کوچک؛ تغییر مسیر به CDN خارجی پشتیبانی نمی‌شود. مجوز نمایش‌داده‌شده به معنی تضمین حقوق استفاده نیست.'];
}
function hf_validate_bytes(string $raw,array $file): void {
    if (strlen($raw)!==$file['size'] || strlen($raw)>WS_FILE_BYTES || !mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0")) throw new InvalidArgumentException('حجم یا محتوای UTF-8 فایل داده معتبر نیست.');
    $actual=$file['algorithm']==='sha256'?hash('sha256',$raw):sha1('blob '.strlen($raw)."\0".$raw);
    if (!hash_equals($file['hash'],$actual)) throw new InvalidArgumentException('checksum فایل با نسخه ثابت منبع یکسان نیست.');
    if (str_ends_with(strtolower($file['path']),'.json')) json_decode($raw,true,32,JSON_THROW_ON_ERROR);
}
function hf_root(): string {
    $root=dirname(__DIR__).'/storage/datasets';
    if (is_link(dirname($root)) || is_link($root)) throw new HfError('فضای خصوصی معتبر نیست.');
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new HfError('فضای خصوصی قابل نوشتن نیست.');
    return $root;
}
function hf_directory(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('شناسه دریافت معتبر نیست.');
    $path=hf_root().'/'.$id;
    if (is_link($path)) throw new HfError('مسیر خصوصی معتبر نیست.');
    return $path;
}
function hf_job(int $owner,string $id): array {
    $job=q('SELECT d.* FROM workspace_dataset_imports d JOIN projects p ON p.id=d.project_id WHERE d.id=? AND p.user_id=?',[$id,$owner])->fetch();
    if (!$job) ws_fail('دریافت پیدا نشد.',404);
    ws_owned($owner,(int)$job['project_id']); return $job;
}
function hf_cleanup(array $job): void {
    $dir=hf_directory($job['id']);
    $files=json_decode($job['files_json'],true,32,JSON_THROW_ON_ERROR);
    foreach ($files as $i=>$file) foreach (['.data','.part'] as $suffix) {
        $path=$dir.'/'.$i.$suffix;
        if (is_file($path) && !is_link($path)) unlink($path);
    }
}
function hf_expire(int $owner): void {
    q("UPDATE workspace_dataset_imports SET state='failed',error='مهلت دریافت پایان یافت.' WHERE owner=? AND state='downloading' AND created<?",[$owner,time()-HF_IMPORT_SECONDS]);
    foreach (q("SELECT * FROM workspace_dataset_imports WHERE owner=? AND state IN ('failed','cancelled')",[$owner])->fetchAll() as $job) {
        $dir=hf_directory($job['id']);
        if (is_dir($dir)) {
            $lock=fopen($dir.'/lock','c');
            if ($lock && flock($lock,LOCK_EX|LOCK_NB)) { hf_cleanup($job); flock($lock,LOCK_UN); }
            if ($lock) fclose($lock);
        }
    }
}
function hf_reservations(int $owner,int $project=0): int {
    return (int)q("SELECT COALESCE(SUM(bytes),0) FROM workspace_dataset_imports WHERE owner=? AND state='downloading' AND created>=?".($project?' AND project_id=?':''),$project?[$owner,time()-HF_IMPORT_SECONDS,$project]:[$owner,time()-HF_IMPORT_SECONDS])->fetchColumn();
}
function hf_imports(int $project): array {
    return q("SELECT * FROM workspace_dataset_imports WHERE project_id=? AND state='succeeded' ORDER BY created,id",[$project])->fetchAll();
}
function hf_refs(int $project): array {
    $refs=[];
    foreach (hf_imports($project) as $job) foreach (json_decode($job['files_json'],true,32,JSON_THROW_ON_ERROR) as $i=>$file) {
        $refs['datasets/'.$job['id'].'/'.$file['path']]=['kind'=>'dataset','import_id'=>$job['id'],'index'=>$i,'sha256'=>$file['sha256'],'size'=>$file['size']];
    }
    return $refs;
}
function hf_materialize(int $project,array $files): array {
    $allowed=hf_refs($project);
    foreach ($files as $path=>$file) if (($file['kind'] ?? '')==='dataset') {
        if (!isset($allowed[$path]) || $file!==$allowed[$path]) throw new HfError('مرجع دیتاست معتبر نیست.');
        $disk=hf_directory($file['import_id']).'/'.$file['index'].'.data';
        if (is_link($disk) || !is_file($disk) || filesize($disk)!==$file['size']) throw new HfError('فایل خصوصی دیتاست در دسترس نیست.');
        $raw=file_get_contents($disk,false,null,0,WS_FILE_BYTES+1);
        if ($raw===false || !hash_equals($file['sha256'],hash('sha256',$raw))) throw new HfError('یکپارچگی فایل ذخیره‌شده تأیید نشد.');
        $files[$path]=['kind'=>'file','data'=>base64_encode($raw)];
    }
    return $files;
}
function hf_local(array $files,int $project): array {
    if (!hf_refs($project)) return $files;
    // Imported files remain on disk, including when the runner returns its input files.
    return array_filter($files,static fn($path)=>$path!=='datasets' && !str_starts_with((string)$path,'datasets/'),ARRAY_FILTER_USE_KEY);
}
function hf_dataset_bytes(int $project): int {
    return (int)q("SELECT COALESCE(SUM(bytes),0) FROM workspace_dataset_imports WHERE project_id=? AND state='succeeded'",[$project])->fetchColumn();
}
function hf_rate(int $owner): void {
    $pdo=db(); $pdo->beginTransaction();
    try {
        ws_lock_user($owner); $rate=q('SELECT * FROM workspace_hub_rates WHERE owner=?',[$owner])->fetch(); $now=time();
        if ($rate && (int)$rate['window_start']>$now-60 && (int)$rate['requests']>=30) { $pdo->rollBack(); ws_fail('حداکثر ۳۰ درخواست Hub در دقیقه مجاز است.',429); }
        if (!$rate) q('INSERT INTO workspace_hub_rates(owner,window_start,requests) VALUES (?,?,1)',[$owner,$now]);
        else q('UPDATE workspace_hub_rates SET window_start=?,requests=? WHERE owner=?',[(int)$rate['window_start']<=$now-60?$now:$rate['window_start'],(int)$rate['window_start']<=$now-60?1:(int)$rate['requests']+1,$owner]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function hf_begin(int $owner,int $project,string $id,string $revision,array $chosen): array {
    if (!array_is_list($chosen) || count($chosen)<1 || count($chosen)>HF_IMPORT_FILES || count(array_unique($chosen,SORT_REGULAR))!==count($chosen)) throw new InvalidArgumentException('بین ۱ تا ۱۰ فایل غیرتکراری انتخاب کنید.');
    foreach ($chosen as $path) if (!is_string($path)) throw new InvalidArgumentException('مسیر فایل معتبر نیست.');
    $detail=hf_detail($id,$revision); $files=[]; $bytes=0;
    foreach ($chosen as $path) {
        $found=array_values(array_filter($detail['files'],static fn($file)=>$file['path']===$path));
        if (!$found) throw new InvalidArgumentException('فایل انتخابی قابل دریافت نیست.');
        $files[]=$found[0]; $bytes+=$found[0]['size'];
    }
    if ($bytes>HF_IMPORT_BYTES) throw new InvalidArgumentException('مجموع فایل‌های هر دریافت حداکثر دو مگابایت است.');
    $free=disk_free_space(hf_root());
    if ($free===false || $free<$bytes+WS_FILE_BYTES) throw new HfError('فضای آزاد دیسک برای دریافت کافی نیست.');
    usort($files,static fn($a,$b)=>strcmp($a['path'],$b['path']));
    $fingerprint=hash('sha256',json_encode([$id,$revision,$files],JSON_THROW_ON_ERROR));
    $pdo=db(); $pdo->beginTransaction();
    try {
        ws_lock_user($owner); $row=ws_owned($owner,$project);
        $existing=q("SELECT * FROM workspace_dataset_imports WHERE project_id=? AND fingerprint=? AND state IN ('downloading','succeeded') ORDER BY created DESC LIMIT 1",[$project,$fingerprint])->fetch();
        if ($existing && in_array($existing['state'],['downloading','succeeded'],true)) { $pdo->commit(); return hf_status($existing); }
        foreach (hf_imports($project) as $import) if ($import['dataset_id']===$id && $import['revision']===$revision) {
            foreach (json_decode($import['files_json'],true,32,JSON_THROW_ON_ERROR) as $file) if (in_array($file['path'],$chosen,true)) throw new InvalidArgumentException('یکی از فایل‌های انتخابی قبلاً در همین نسخه به پروژه اضافه شده است؛ از فایل موجود استفاده کنید.');
        }
        if (q("SELECT id FROM workspace_dataset_imports WHERE owner=? AND state='downloading' AND created>=?",[$owner,time()-HF_IMPORT_SECONDS])->fetchColumn()) throw new InvalidArgumentException('یک دریافت دیگر فعال است؛ ابتدا آن را تمام یا لغو کنید.');
        if ((int)q('SELECT COUNT(*) FROM workspace_dataset_imports WHERE owner=?',[$owner])->fetchColumn()>=100) throw new InvalidArgumentException('سقف تاریخچه دریافت حساب تکمیل است؛ نیاز به نگهداری مدیر دارد.');
        $local=json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR);
        foreach ($local as $path=>$file) if ($path==='datasets' || str_starts_with($path,'datasets/')) throw new InvalidArgumentException('مسیر datasets از قبل در پروژه موجود است؛ ابتدا نام آن را تغییر دهید.');
        if ((int)$row['bytes']+$bytes>WS_PROJECT_BYTES || count($local)+count(hf_refs($project))+count($files)>WS_FILE_COUNT) throw new InvalidArgumentException('حجم یا تعداد فایل‌ها از ظرفیت پروژه بیشتر است.');
        ws_quota($owner,$bytes+(int)$row['bytes'],$project);
        $jobId=bin2hex(random_bytes(16));
        q('INSERT INTO workspace_dataset_imports(id,project_id,owner,dataset_id,revision,fingerprint,state,files_json,metadata_json,bytes,completed_files,created,error) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,NULL)',[$jobId,$project,$owner,$id,$revision,$fingerprint,'downloading',json_encode($files,JSON_THROW_ON_ERROR),json_encode(['source'=>$detail['source'],'license'=>$detail['license'],'description'=>$detail['description']],JSON_THROW_ON_ERROR),$bytes,time()]);
        $pdo->commit(); return hf_status(hf_job($owner,$jobId));
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function hf_status(array $job): array {
    return ['id'=>$job['id'],'project_id'=>(int)$job['project_id'],'dataset_id'=>$job['dataset_id'],'revision'=>$job['revision'],'state'=>$job['state'],'bytes'=>(int)$job['bytes'],'completed_files'=>(int)$job['completed_files'],'file_count'=>count(json_decode($job['files_json'],true,32,JSON_THROW_ON_ERROR)),'error'=>$job['error'],'metadata'=>json_decode($job['metadata_json'],true,32,JSON_THROW_ON_ERROR),'directory'=>'datasets/'.$job['id']];
}
function hf_step(int $owner,string $id): array {
    $job=hf_job($owner,$id);
    if ($job['state']!=='downloading') return hf_status($job);
    $dir=hf_directory($id);
    if (!is_dir($dir) && !mkdir($dir,0700) && !is_dir($dir)) throw new HfError('فضای دانلود در دسترس نیست.');
    $lock=fopen($dir.'/lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return hf_status($job); }
    try {
        $job=hf_job($owner,$id);
        if ($job['state']!=='downloading') return hf_status($job);
        $files=json_decode($job['files_json'],true,32,JSON_THROW_ON_ERROR); $i=(int)$job['completed_files'];
        $cancelled=static function() use ($owner,$id,$job): bool { return time()-(int)$job['created']>=HF_IMPORT_SECONDS || hf_job($owner,$id)['state']!=='downloading'; };
        if ($cancelled()) throw new HfError('دریافت لغو شد یا مهلت آن پایان یافت.');
        // Recheck access at the immutable revision before every anonymous download.
        hf_detail($job['dataset_id'],$job['revision']);
        $file=$files[$i]; $path=implode('/',array_map('rawurlencode',explode('/',$file['path'])));
        $raw=hf_http('https://huggingface.co/datasets/'.$job['dataset_id'].'/resolve/'.$job['revision'].'/'.$path,$file['size'],10,$cancelled);
        hf_validate_bytes($raw,$file);
        if ($cancelled()) throw new HfError('دریافت لغو شد یا مهلت آن پایان یافت.');
        $part=$dir.'/'.$i.'.part'; $final=$dir.'/'.$i.'.data';
        if (is_link($part) || is_link($final)) throw new HfError('مسیر خصوصی فایل معتبر نیست.');
        if (file_put_contents($part,$raw,LOCK_EX)!==strlen($raw) || !chmod($part,0600) || !rename($part,$final)) throw new HfError('ذخیره فایل ناموفق بود.');
        $files[$i]['sha256']=hash('sha256',$raw);
        $pdo=db(); $pdo->beginTransaction();
        try {
            ws_lock_user($owner); $current=hf_job($owner,$id);
            if ($current['state']!=='downloading' || $cancelled()) throw new HfError('دریافت لغو شد یا مهلت آن پایان یافت.');
            $done=$i+1===count($files); $row=ws_owned($owner,(int)$job['project_id']);
            if ($done) {
                $local=json_decode($row['files_json'],true,512,JSON_THROW_ON_ERROR);
                foreach ($local as $path=>$item) if ($path==='datasets' || str_starts_with($path,'datasets/')) throw new InvalidArgumentException('مسیر datasets هنگام دریافت با فایل‌های پروژه برخورد کرده است.');
                if ((int)$row['bytes']+(int)$job['bytes']>WS_PROJECT_BYTES || count($local)+count(hf_refs((int)$job['project_id']))+count($files)>WS_FILE_COUNT) throw new InvalidArgumentException('فضای پروژه حین دریافت تغییر کرده است.');
                // This job already reserves its bytes in the account quota.
                ws_quota($owner,(int)$row['bytes'],(int)$job['project_id']);
                q('UPDATE project_workspaces SET bytes=bytes+?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE project_id=?',[$job['bytes'],$job['project_id']]);
            }
            $updated=q("UPDATE workspace_dataset_imports SET files_json=?,completed_files=?,state=? WHERE id=? AND state='downloading'",[json_encode($files,JSON_THROW_ON_ERROR),$i+1,$done?'succeeded':'downloading',$id]);
            if ($updated->rowCount()!==1) throw new HfError('دریافت هم‌زمان لغو شد یا مهلت آن پایان یافت.');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return hf_status(hf_job($owner,$id));
    } catch (Throwable $e) {
        q("UPDATE workspace_dataset_imports SET state='failed',error=? WHERE id=? AND state='downloading'",[$e instanceof InvalidArgumentException || $e instanceof HfError ? $e->getMessage() : 'دریافت انجام نشد؛ شبکه، دسترسی عمومی، checksum، زمان یا فضای ذخیره‌سازی را بررسی کنید.',$id]);
        hf_cleanup(hf_job($owner,$id)); return hf_status(hf_job($owner,$id));
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function hf_get(string $path,int $owner): never {
    $project=(int)($_GET['id'] ?? 0); ws_owned($owner,$project); hf_expire($owner);
    if ($path==='workspace/datasets-state') {
        $rows=q('SELECT * FROM workspace_dataset_imports WHERE project_id=? ORDER BY created DESC LIMIT 20',[$project])->fetchAll();
        ws_reply(200,['imports'=>array_map('hf_status',$rows)]);
    }
    hf_rate($owner);
    if ($path==='workspace/datasets-search') ws_reply(200,['datasets'=>hf_search((string)($_GET['q'] ?? ''))]);
    if ($path==='workspace/datasets-detail') ws_reply(200,hf_detail((string)($_GET['dataset'] ?? '')));
    ws_fail('مسیر دیتاست پیدا نشد.',404);
}
function hf_post(string $path,int $owner): never {
    $project=(int)($_POST['id'] ?? 0); ws_owned($owner,$project); hf_expire($owner);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if ($path==='workspace/datasets-begin') {
        hf_rate($owner);
        $chosen=json_decode((string)($_POST['selected_files'] ?? ''),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($chosen)) throw new InvalidArgumentException('فایل‌ها را انتخاب کنید.');
        ws_reply(202,hf_begin($owner,$project,(string)($_POST['dataset'] ?? ''),(string)($_POST['dataset_revision'] ?? ''),$chosen));
    }
    $id=(string)($_POST['import_id'] ?? ''); $job=hf_job($owner,$id);
    if ((int)$job['project_id']!==$project) ws_fail('دریافت متعلق به پروژه نیست.',404);
    if ($path==='workspace/datasets-cancel') {
        q("UPDATE workspace_dataset_imports SET state='cancelled',error=NULL WHERE id=? AND state='downloading'",[$id]);
        hf_expire($owner); ws_reply(200,hf_status(hf_job($owner,$id)));
    }
    if ($path==='workspace/datasets-step') {
        if ($job['state']==='downloading') hf_rate($owner);
        ws_reply(200,hf_step($owner,$id));
    }
    ws_fail('مسیر دیتاست پیدا نشد.',404);
}
