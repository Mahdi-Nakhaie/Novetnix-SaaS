<?php
declare(strict_types=1);

function lp_catalog(): array {
    static $catalog;
    return $catalog ??= json_decode(file_get_contents(dirname(__DIR__).'/curriculum/catalog.json'),true,512,JSON_THROW_ON_ERROR);
}
function lp_version(): string {
    $root=dirname(__DIR__).'/curriculum/';
    return hash('sha256',file_get_contents($root.'catalog.json').file_get_contents($root.'grading.py').file_get_contents($root.'data/manifest.json'));
}
function lp_definition(int $catalogIndex): ?array { return lp_catalog()[$catalogIndex-100] ?? null; }
function lp_starter(array $definition): array {
    $example=json_encode($definition['example']['input'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $code="import json\nfrom pathlib import Path\n\ndef solve(data):\n    raise NotImplementedError('Implement solve using the project guide')\n\nif __name__ == '__main__':\n    data = json.loads(Path('request.json').read_text())\n    result = solve(data)\n    Path('result.json').write_text(json.dumps(result, allow_nan=False))\n    print(result)\n";
    $files=['main.py'=>['kind'=>'file','data'=>base64_encode($code)],'request.json'=>['kind'=>'file','data'=>base64_encode($example)]];
    foreach ($definition['datasets'] as $name) $files[$name]=['kind'=>'file','data'=>base64_encode(file_get_contents(dirname(__DIR__).'/curriculum/data/'.$name))];
    return ws_manifest($files);
}
function lp_xp(int $userId): int { return (int)q('SELECT COALESCE(SUM(xp),0) FROM project_completions WHERE user_id=?',[$userId])->fetchColumn(); }
function lp_completion(int $userId,string $key): array|false { return q('SELECT * FROM project_completions WHERE user_id=? AND learning_key=?',[$userId,$key])->fetch(); }
function lp_grant(array $run,array $result,int $userId): bool {
    $definition=lp_definition((int)q('SELECT catalog_index FROM projects WHERE id=? AND user_id=?',[$run['project_id'],$userId])->fetchColumn());
    $assessment=$result['assessment'] ?? null;
    if (!$definition || !is_array($assessment) || ($result['state'] ?? '')!=='succeeded' || ($assessment['passed'] ?? false)!==true || ($assessment['key'] ?? '')!==$definition['key'] || ($run['assessment_key'] ?? '')!==$definition['key'] || !hash_equals(lp_version(),(string)($run['assessment_version'] ?? '')) || !hash_equals(lp_version(),(string)($assessment['version'] ?? ''))) return false;
    $checks=$assessment['checks'] ?? [];
    if (!is_array($checks) || !$checks) return false;
    foreach ($checks as $check) if (($check['passed'] ?? false)!==true || ($check['execution_state'] ?? '')!=='succeeded') return false;
    $pdo=db(); $pdo->beginTransaction();
    try {
        ws_lock_user($userId);
        $workspace=ws_owned($userId,(int)$run['project_id']);
        $stored=ws_run_owned($userId,(int)$run['id']);
        if ((int)$workspace['revision']!==(int)$stored['revision'] || $stored['state']!=='succeeded' || !$stored['remote_id'] || !hash_equals((string)$stored['result_json'],json_encode($result,JSON_THROW_ON_ERROR)) || !hash_equals(hash('sha256',$workspace['files_json']),hash('sha256',$stored['snapshot_json'])) || lp_completion($userId,$definition['key'])) { $pdo->commit(); return false; }
        q('INSERT INTO project_completions(user_id,learning_key,project_id,run_id,revision,snapshot_hash,assessment_version,xp) VALUES (?,?,?,?,?,?,?,?)',[$userId,$definition['key'],$stored['project_id'],$stored['id'],$stored['revision'],hash('sha256',$stored['snapshot_json']),lp_version(),$definition['xp']]);
        record_activity_event($userId,'project_completed',['key'=>$definition['key'],'run_id'=>(int)$stored['id'],'xp'=>$definition['xp']]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
