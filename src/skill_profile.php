<?php
declare(strict_types=1);

// Skill profile: progress is derived from stored completions only, so a viewer
// never sees a number the learner has not actually earned.
const SKILL_LEVELS = [500, 1200, 2200, 3400, 5000, 7000, 9500, 12500, 16000];

function skill_level(int $xp): array {
    $level=1; $floor=0;
    foreach (SKILL_LEVELS as $index=>$floorXp) {
        if ($xp<$floorXp) break;
        $level=$index+2; $floor=$floorXp;
    }
    $next=SKILL_LEVELS[$level-1] ?? null;
    return ['level'=>$level,'xp'=>$xp,'floor'=>$floor,'next'=>$next,'progress'=>$next===null?100:(int)min(100,floor(($xp-$floor)/max(1,$next-$floor)*100))];
}

function skill_topic_stats(int $userId): array {
    $topics=[];
    foreach (PROJECTS as $project) {
        $topic=$project['topic'];
        $topics[$topic]??=['topic'=>$topic,'max'=>0,'earned'=>0,'completed'=>0,'total'=>0];
        $topics[$topic]['max']+=(int)$project['xp'];
        $topics[$topic]['total']++;
    }
    foreach (q('SELECT learning_key,xp FROM project_completions WHERE user_id=?',[$userId])->fetchAll() as $row) {
        foreach (PROJECTS as $project) {
            if ($project['key']!==$row['learning_key']) continue;
            $topic=$project['topic'];
            if (!isset($topics[$topic])) $topics[$topic]=['topic'=>$topic,'max'=>(int)$project['xp'],'earned'=>0,'completed'=>0,'total'=>1];
            $topics[$topic]['earned']+=(int)$row['xp'];
            $topics[$topic]['completed']++;
        }
    }
    foreach ($topics as &$topic) $topic['percent']=$topic['max']>0 ? (int)round($topic['earned']/$topic['max']*100) : 0;
    unset($topic);
    usort($topics,fn(array $a,array $b): int=>$b['earned']<=>$a['earned'] ?: strcmp($a['topic'],$b['topic']));
    return $topics;
}

function skill_profile_for(int $userId): ?array {
    $user=q('SELECT id,name,role,created_at FROM users WHERE id=?',[$userId])->fetch();
    if (!$user) return null;
    $completions=q('SELECT learning_key,xp,completed_at FROM project_completions WHERE user_id=? ORDER BY completed_at DESC,learning_key',[$userId])->fetchAll();
    $lessons=(int)q('SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND status=?',[$userId,'completed'])->fetchColumn();
    $posts=(int)q('SELECT COUNT(*) FROM community_posts WHERE user_id=?',[$userId])->fetchColumn();
    $replies=(int)q('SELECT COUNT(*) FROM community_replies WHERE user_id=?',[$userId])->fetchColumn();
    $followers=(int)q('SELECT COUNT(*) FROM user_follows WHERE followed_id=?',[$userId])->fetchColumn();
    $following=(int)q('SELECT COUNT(*) FROM user_follows WHERE follower_id=?',[$userId])->fetchColumn();
    $xp=0;
    foreach ($completions as $completion) $xp+=(int)$completion['xp'];
    return [
        'id'=>(int)$user['id'],
        'name'=>trim((string)$user['name'])!=='' ? (string)$user['name'] : 'عضو Noventix',
        'role'=>(string)$user['role'],
        'joined'=>(string)$user['created_at'],
        'xp'=>$xp,
        'level'=>skill_level($xp),
        'completions'=>$completions,
        'lessons'=>$lessons,
        'posts'=>$posts,
        'replies'=>$replies,
        'followers'=>$followers,
        'following'=>$following,
        'topics'=>skill_topic_stats((int)$user['id']),
    ];
}

function learner_directory(string $query,int $page): array {
    $where=["u.phone_verified_at IS NOT NULL","trim(u.name)<>''","u.role<>'admin'"];
    $params=[];
    if ($query!=='') {
        $where[]='(u.name LIKE ? OR EXISTS (SELECT 1 FROM projects p INNER JOIN project_completions c ON c.project_id=p.id WHERE p.user_id=u.id AND c.learning_key LIKE ?))';
        $params[]='%'.$query.'%'; $params[]='%'.$query.'%';
    }
    $perPage=24; $page=max(1,$page);
    $condition=implode(' AND ',$where);
    $total=(int)q('SELECT COUNT(*) FROM users u WHERE '.$condition,$params)->fetchColumn();
    // No LIMIT/OFFSET placeholders: neither driver accepts them as bound params in this shape.
    $rows=q('SELECT u.id,u.name,u.created_at,(SELECT COALESCE(SUM(c.xp),0) FROM project_completions c WHERE c.user_id=u.id) AS xp,(SELECT COUNT(*) FROM project_completions c WHERE c.user_id=u.id) AS completed FROM users u WHERE '.$condition.' ORDER BY xp DESC,u.id ASC LIMIT '.$perPage.' OFFSET '.min($page-1,10000)*$perPage,$params)->fetchAll();
    $learners=[];
    foreach ($rows as $row) {
        $xp=(int)$row['xp'];
        $learners[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'joined'=>(string)$row['created_at'],'xp'=>$xp,'completed'=>(int)$row['completed'],'level'=>skill_level($xp)];
    }
    return ['learners'=>$learners,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage))];
}
