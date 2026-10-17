<?php
declare(strict_types=1);

function message_notice(): void {
    $code=(string)($_SESSION['message_notice'] ?? '');
    unset($_SESSION['message_notice']);
    $texts=[
        'sent'=>'پیام ارسال شد.',
        'request'=>'درخواست پیام ثبت شد؛ تا پذیرش این عضو، پیام برای او نمایش داده نمی‌شود.',
        'accepted'=>'درخواست پذیرفته شد؛ پیام‌های این عضو از این به بعد در صندوق شما دیده می‌شوند.',
        'rate_limited'=>'برای جلوگیری از ارسال تکراری، هر دو دقیقه یک پیام تازه می‌توانید بفرستید.',
        'duplicate'=>'برای این فرم پیامی ثبت شده است؛ اگر متن را تغییر داده‌اید صفحه را دوباره باز کنید.',
        'invalid'=>'متن پیام یا گیرنده معتبر نیست.',
    ];
    if (!isset($texts[$code])) return;
    echo '<div class="container"><div role="status" class="notice '.(in_array($code,['sent','request','accepted'],true)?'success':'error').'">'.h($texts[$code]).'</div></div>';
}

function page_messages(int $userId): void {
    $inbox=messages_inbox($userId);
    $requests=message_requests_for($userId);
    $unread=messages_unread($userId);
    message_notice();
    echo '<main class="profile-page"><div class="container"><header class="profile-hero"><div><span class="eyebrow">پیام‌ها</span><h1>گفت‌وگوهای من</h1><p class="muted">'.fa($unread).' پیام خوانده‌نشده · '.fa(count($requests)).' درخواست پیام</p></div><div class="profile-actions">'; linkto('learners','دیدن جامعه یادگیرندگان','btn btn-outline'); echo '</div></header>';
    if ($requests) {
        echo '<section class="profile-card"><span class="eyebrow">درخواست‌ها</span><h2>درخواست‌های پیام</h2><ul class="thread-list">';
        foreach ($requests as $request) {
            $name=trim((string)$request['name'])!=='' ? (string)$request['name'] : 'عضو Noventix';
            echo '<li class="thread-item"><a href="'.h(url('member/'.$request['sender_id'])).'"><strong>'.h($name).'</strong><span class="muted">'.h(jdate((string)$request['created_at'])).'</span></a><span class="thread-actions">';
            form_start('messages/accept','react-form');
            echo '<input type="hidden" name="requester" value="'.(int)$request['sender_id'].'"><button class="btn btn-small btn-primary">پذیرش</button></form>';
            form_start('messages/decline','react-form');
            echo '<input type="hidden" name="requester" value="'.(int)$request['sender_id'].'"><button class="btn btn-small btn-outline">رد</button></form>';
            echo '</span></li>';
        }
        echo '</ul></section>';
    }
    echo '<section class="profile-card"><span class="eyebrow">صندوق</span><h2>همهٔ گفت‌وگوها</h2>';
    if (!$inbox) {
        echo '<div class="empty-state"><p>هنوز گفت‌وگویی ندارید. از پروفایل اعضای جامعه می‌توانید پیام بفرستید.</p></div></section></div></main>';
        return;
    }
    echo '<ul class="thread-list">';
    foreach ($inbox as $thread) {
        echo '<li class="thread-item'.($thread['unread']?' is-unread':'').'"><a href="'.h(url('messages/'.$thread['peer'])).'"><strong>'.h($thread['peer_name']).'</strong><span class="thread-preview">'.h(mb_strimwidth($thread['last'],0,90,'…')).'</span><span class="muted">'.h(jdate($thread['at'])).' · '.fa($thread['count']).' پیام'.($thread['unread']?' · '.fa($thread['unread']).' خوانده‌نشده':'').'</span></a></li>';
    }
    echo '</ul></section></div></main>';
}

function page_message_thread(int $userId,int $peer): bool {
    $member=q('SELECT id,name FROM users WHERE id=? AND phone_verified_at IS NOT NULL',[$peer])->fetch();
    if (!$member || $peer===$userId) return false;
    messages_mark_read($userId,$peer);
    $rows=array_reverse(messages_thread($userId,$peer));
    $name=trim((string)$member['name'])!=='' ? (string)$member['name'] : 'عضو Noventix';
    echo '<main class="profile-page"><div class="container"><nav class="profile-crumb" aria-label="مسیر صفحه"><a href="'.h(url('messages')).'">پیام‌ها</a><span aria-current="page">'.h($name).'</span></nav><header class="profile-hero"><div class="profile-identity"><span class="profile-avatar">'.h(mb_substr($name,0,1)).'</span><div><span class="eyebrow">گفت‌وگو</span><h1>'.h($name).'</h1></div></div><div class="profile-actions">'; linkto('member/'.$peer,'دیدن پروفایل مهارتی','btn btn-outline'); echo '</div></header><section class="profile-card">';
    if (!$rows) echo '<div class="empty-state"><p>هنوز پیامی در این گفت‌وگو نیست.</p></div>';
    else {
        echo '<ol class="message-thread">';
        foreach ($rows as $row) {
            $mine=(int)$row['sender_id']===$userId;
            echo '<li class="message-bubble'.($mine?' is-mine':'').'"><p>'.nl2br(h((string)$row['body'])).'</p><small>'.h(jdate((string)$row['created_at'])).($mine ? ($row['read_at']!==null?' · خوانده شده':' · خوانده نشده') : '').'</small></li>';
        }
        echo '</ol>';
    }
    echo member_message_form($peer,true,'messages/'.$peer);
    echo '</section></div></main>';
    return true;
}
