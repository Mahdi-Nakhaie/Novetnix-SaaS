<?php
declare(strict_types=1);

function skill_badge(array $level,string $class=''): string {
    return '<span class="skill-level '.h($class).'"><span>سطح</span><strong>'.fa($level['level']).'</strong></span>';
}

function skill_meter(int $percent,string $label): void {
    $percent=max(0,min(100,$percent));
    echo '<div class="skill-meter" role="progressbar" aria-label="'.h($label).'" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.$percent.'"><span style="width:'.$percent.'%"></span><small>'.fa($percent).'٪</small></div>';
}

function skill_completion_title(string $key): array {
    foreach (PROJECTS as $index=>$project) if ($project['key']===$key) return ['title'=>$project['title'],'topic'=>$project['topic'],'index'=>$index];
    return ['title'=>$key,'topic'=>'','index'=>null];
}

function profile_progress_section(array $profile): void {
    $level=$profile['level'];
    echo '<section class="profile-card profile-progress"><div class="profile-progress-head"><div><span class="eyebrow">مهارت</span><h2>پیشرفت مهارتی</h2></div>'.skill_badge($level).'</div><div class="skill-stats">';
    foreach ([['XP',fa($level['xp']),'مجموع امتیاز پروژه‌های تأییدشده'],['پروژه تکمیل‌شده',fa(count($profile['completions'])).' از '.fa(count(PROJECTS)),'پس از ارزیابی موفق'],['درس تکمیل‌شده',fa($profile['lessons']),'دوره‌های منتشرشده']] as [$label,$value,$hint]) echo '<div class="skill-stat"><span>'.h($label).'</span><strong>'.h($value).'</strong><small>'.h($hint).'</small></div>';
    echo '</div><p class="skill-next">';
    if ($level['next']===null) echo 'به بالاترین سطح فعلی رسیده‌اید؛ هر پروژه تازه هنوز XP اضافه می‌کند.';
    else echo 'تا سطح '.fa($level['level']+1).'، '.fa($level['next']-$level['xp']).' XP دیگر لازم است.';
    echo '</p>';
    skill_meter($level['progress'],'پیشرفت تا سطح بعد');
    echo '</section>';
}

function profile_skills_section(array $profile): void {
    echo '<section class="profile-card"><span class="eyebrow">نقشه مهارت</span><h2>توانایی در هر حوزه</h2><p class="muted">درصدها فقط از پروژه‌های تأییدشدهٔ همین حساب محاسبه می‌شوند.</p><ul class="skill-topic-list">';
    foreach ($profile['topics'] as $topic) {
        echo '<li><div class="skill-topic-head"><strong>'.h($topic['topic']).'</strong><span>'.fa($topic['earned']).' از '.fa($topic['max']).' XP · '.fa($topic['completed']).' پروژه</span></div>';
        skill_meter($topic['percent'],'مهارت '.$topic['topic']);
        echo '</li>';
    }
    echo '</ul></section>';
}

function profile_activity_section(array $profile,bool $own): void {
    echo '<section class="profile-card"><span class="eyebrow">'.($own?'تاریخچه من':'فعالیت‌های تأییدشده').'</span><h2>پروژه‌های تکمیل‌شده</h2>';
    if (!$profile['completions']) {
        echo '<div class="empty-state"><p>'.($own?'هنوز پروژه‌ای با موفقیت ارزیابی نشده است.':'این عضو هنوز پروژه‌ای را کامل نکرده است.').'</p>';
        if ($own) linkto('projects','دیدن پروژه‌ها ←','btn btn-small btn-outline');
        echo '</div></section>';
        return;
    }
    echo '<ul class="completion-list">';
    foreach ($profile['completions'] as $completion) {
        $project=skill_completion_title((string)$completion['learning_key']);
        echo '<li><span class="pill">'.h($project['topic']).'</span><span class="completion-title">';
        if ($project['index']!==null) echo '<a href="'.h(url('project/'.$project['index'])).'">'.h($project['title']).'</a>'; else echo h($project['title']);
        echo '</span><span class="completion-meta">'.fa((int)$completion['xp']).' XP · '.h(jdate((string)$completion['completed_at'])).'</span></li>';
    }
    echo '</ul></section>';
}

function profile_follow_section(array $profile,bool $own): void {
    if ($own) return;
    $viewer=user();
    if (!$viewer) return;
    $following=(bool)q('SELECT 1 FROM user_follows WHERE follower_id=? AND followed_id=?',[(int)$viewer['id'],$profile['id']])->fetchColumn();
    echo '<section class="profile-card"><span class="eyebrow">دنبال کردن</span><h2>مسیر این عضو</h2>';
    if ($following) echo '<p class="muted">این عضو را دنبال می‌کنید؛ پیام‌های شما مستقیم به صندوق او می‌رسند.</p>';
    else {
        echo '<p class="muted">با دنبال کردن، پست‌های این عضو را در انجمن راحت‌تر پیدا می‌کنید و پیام شما بدون درخواست پذیرش ارسال می‌شود.</p>';
        form_start('community/follow','react-form');
        echo '<input type="hidden" name="user_id" value="'.$profile['id'].'"><button class="btn btn-small btn-outline">دنبال کردن</button></form>';
    }
    echo '</section>';
}

function profile_community_section(array $profile,bool $own): void {
    echo '<section class="profile-card"><span class="eyebrow">انجمن</span><h2>حضور در جامعه</h2><div class="skill-stats">';
    foreach ([['گفت‌وگو',fa($profile['posts'])],['پاسخ',fa($profile['replies'])],['دنبال‌کننده',fa($profile['followers'])],['دنبال‌شده',fa($profile['following'])]] as [$label,$value]) echo '<div class="skill-stat"><span>'.h($label).'</span><strong>'.h($value).'</strong></div>';
    echo '</div>';
    if ($own) linkto('community','رفتن به انجمن ←','inline-link');
    echo '</section>';
}

function page_skill_profile(array $profile,bool $own,bool $signedIn): void {
    message_notice();
    echo '<main class="profile-page"><div class="container"><nav class="profile-crumb" aria-label="مسیر صفحه"><a href="'.h(url('learners')).'">جامعه یادگیرندگان</a><span aria-current="page">'.h($profile['name']).'</span></nav>';
    echo '<section class="profile-hero"><div class="profile-identity"><span class="profile-avatar">'.h(mb_substr($profile['name'],0,1)).'</span><div><span class="eyebrow">'.($own?'پروفایل مهارتی من':'عضو انجمن Noventix').'</span><h1>'.h($profile['name']).'</h1><p class="muted">'.h($profile['role']==='admin'?'تیم Noventix':'عضو جامعه').' · عضویت از '.h(jdate($profile['joined'])).'</p></div>'.skill_badge($profile['level'],'is-large').'</div><div class="profile-actions">';
    if ($own) { linkto('dashboard/profile','ویرایش پروفایل','btn btn-outline'); linkto('messages','پیام‌های من','btn btn-outline'); }
    else echo member_message_form($profile['id'],$signedIn);
    echo '</div></section><div class="profile-grid"><div class="profile-column">';
    profile_progress_section($profile);
    profile_skills_section($profile);
    profile_activity_section($profile,$own);
    echo '</div><aside class="profile-column">';
    profile_community_section($profile,$own);
    profile_follow_section($profile,$own);
    echo '<section class="profile-card"><span class="eyebrow">دربارهٔ این پروفایل</span><h2>چه چیزی اینجا دیده می‌شود؟</h2><p class="muted">فقط پیشرفت واقعی ثبت‌شده نمایش داده می‌شود: پروژه‌هایی که ارزیابی را گذرانده‌اند و درس‌هایی که با آزمون تکمیل شده‌اند. شماره تماس و ایمیل هیچ‌وقت نمایش داده نمی‌شود.</p></section></aside></div></div></main>';
}

// Identity of the message control (ids, labels) lives here so the compact form on
// cards and the full form on a profile stay the same component.
function member_message_form(int $memberId,bool $signedIn,string $returnTo='member/'.$memberId,string $class='message-compose'): string {
    $me=user();
    if (!$signedIn || !$me) return '<a class="btn btn-small btn-outline" href="'.h(url('login')).'">برای پیام دادن وارد شوید</a>';
    if ((int)$me['id']===$memberId) return '';
    $state=message_peer_state((int)$me['id'],$memberId);
    if ($state==='self') return '';
    $label=$state==='request'?'درخواست پیام':'ارسال پیام';
    ob_start();
    form_start('messages/send',$class);
    echo '<input type="hidden" name="recipient" value="'.$memberId.'"><input type="hidden" name="return_to" value="'.h($returnTo).'"><input type="hidden" name="form_token" value="'.h(message_token()).'"><label for="message-'.$memberId.'">پیام برای این عضو</label><textarea id="message-'.$memberId.'" name="body" required minlength="1" maxlength="2000" rows="2" placeholder="پیام کوتاه و محترمانه…"></textarea><button class="btn btn-small btn-primary">'.$label.'</button>';
    if ($state==='request') echo '<p class="fine-print">تا وقتی این عضو درخواست را نپذیرد، پیام برای او نمایش داده نمی‌شود.</p>';
    echo '</form>';
    return (string)ob_get_clean();
}

function page_learners(array $directory,string $query,bool $signedIn): void {
    echo '<main class="profile-page"><div class="container"><section class="profile-hero"><div><span class="eyebrow">جامعه یادگیرندگان</span><h1>یک پروفایل برای هر یادگیرنده</h1><p class="lead">پیشرفت واقعی اعضای انجمن را ببین؛ از هر پروفایل می‌توانی گفت‌وگو را شروع کنی.</p></div></section>';
    echo '<form class="profile-search" action="'.h(url('learners')).'" method="get" role="search"><label class="sr-only" for="q">جست‌وجوی اعضا</label><input id="q" name="q" value="'.h($query).'" maxlength="60" placeholder="نام عضو یا موضوع مهارت، مثل Python"><button class="btn btn-primary">جست‌وجو</button></form>';
    echo '<p class="muted">'.fa($directory['total']).' عضو در جامعه؛ مرتب‌شده بر اساس XP.</p>';
    if (!$directory['learners']) echo '<div class="empty-state"><p>عضوی با این مشخصات پیدا نشد. عبارت دیگری را امتحان کنید.</p></div>';
    else {
        echo '<ul class="learner-grid">';
        foreach ($directory['learners'] as $learner) {
            echo '<li class="learner-card"><a href="'.h(url('member/'.$learner['id'])).'"><span class="profile-avatar">'.h(mb_substr($learner['name'],0,1)).'</span><strong>'.h($learner['name']).'</strong><span class="muted">سطح '.fa($learner['level']['level']).' · '.fa($learner['xp']).' XP</span><span class="muted">'.fa($learner['completed']).' پروژه تکمیل‌شده</span></a></li>';
        }
        echo '</ul>';
        if ($directory['pages']>1) {
            $suffix=$query!=='' ? '&q='.rawurlencode($query) : '';
            echo '<nav class="pager" aria-label="صفحه‌بندی">';
            if ($directory['page']>1) echo '<a href="'.h(url('learners?page='.($directory['page']-1).$suffix)).'">صفحه قبل</a>'; else echo '<span class="is-disabled">صفحه قبل</span>';
            echo '<span>صفحه '.fa($directory['page']).' از '.fa($directory['pages']).'</span>';
            if ($directory['page']<$directory['pages']) echo '<a href="'.h(url('learners?page='.($directory['page']+1).$suffix)).'">صفحه بعد</a>'; else echo '<span class="is-disabled">صفحه بعد</span>';
            echo '</nav>';
        }
    }
    echo '</div></main>';
}
