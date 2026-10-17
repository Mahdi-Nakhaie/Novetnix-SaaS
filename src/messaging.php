<?php
declare(strict_types=1);

// Direct messages between members. The per-form token is folded into a unique
// (sender, entity_hash) pair, so a duplicated submit of the same form collides
// with the original write instead of storing the message twice.
const MESSAGE_INVALID = 'invalid';
const MESSAGE_RATE_LIMITED = 'rate_limited';
const MESSAGE_DUPLICATE = 'duplicate';
const MESSAGE_RATE_WINDOW = 120;

// A fresh token per rendered form: a resubmitted page hits the unique
// (sender, entity_hash) index instead of writing the same message twice.
function message_token(): string {
    return bin2hex(random_bytes(16));
}

function message_entity_hash(int $recipientId,string $formToken): string {
    return hash('sha256','message|'.$recipientId.'|'.$formToken);
}

function message_rate_ok(): bool {
    $now=time();
    $events=[];
    foreach ((array)($_SESSION['message_rate'] ?? []) as $event) {
        if (is_int($event) && $event>$now-MESSAGE_RATE_WINDOW) $events[]=$event;
    }
    $_SESSION['message_rate']=$events;
    return count($events)<1;
}

function message_record_send(): void {
    $events=(array)($_SESSION['message_rate'] ?? []);
    $events[]=time();
    $_SESSION['message_rate']=array_slice($events,-10);
}

function message_pending_request(int $sender,int $recipient): bool {
    return (bool)q('SELECT 1 FROM message_requests WHERE sender_id=? AND recipient_id=?',[$sender,$recipient])->fetchColumn();
}

// open: the peer follows the viewer, so messages land directly.
// inbound: the peer already asked to message the viewer; replies are direct.
// request: no relationship yet; the peer decides before reading anything.
function message_peer_state(int $viewer,int $peer): string {
    if ($viewer===$peer) return 'self';
    if ((bool)q('SELECT 1 FROM user_follows WHERE follower_id=? AND followed_id=?',[$peer,$viewer])->fetchColumn()) return 'open';
    if (message_pending_request($peer,$viewer)) return 'inbound';
    return 'request';
}

// Returns null on success or one of the MESSAGE_* codes; refusals are only ever
// shown to the sender.
function message_send(int $sender,int $recipient,string $body,string $formToken): ?string {
    $body=trim($body);
    if ($sender===$recipient || $recipient<=0 || mb_strlen($body)<1 || mb_strlen($body)>2000) return MESSAGE_INVALID;
    if (!q('SELECT id FROM users WHERE id=? AND phone_verified_at IS NOT NULL',[$recipient])->fetch()) return MESSAGE_INVALID;
    if (!message_rate_ok()) return MESSAGE_RATE_LIMITED;
    message_record_send();
    if (in_array(message_peer_state($sender,$recipient),['request','inbound'],true)) {
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO message_requests(sender_id,recipient_id) VALUES (?,?)',[$sender,$recipient]);
        else q('INSERT OR IGNORE INTO message_requests(sender_id,recipient_id) VALUES (?,?)',[$sender,$recipient]);
    }
    try {
        q('INSERT INTO direct_messages(sender_id,recipient_id,entity_hash,body) VALUES (?,?,?,?)',[$sender,$recipient,message_entity_hash($recipient,$formToken),$body]);
    } catch (PDOException $e) {
        if ($e->getCode()==='23000') return MESSAGE_DUPLICATE;
        throw $e;
    }
    record_activity_event($sender,'message_sent',['recipient'=>$recipient]);
    return null;
}

function message_accept(int $requester,int $recipient): bool {
    if ($requester===$recipient || !message_pending_request($requester,$recipient)) return false;
    q('DELETE FROM message_requests WHERE sender_id=? AND recipient_id=?',[$requester,$recipient]);
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') q('INSERT IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$requester,$recipient]);
    else q('INSERT OR IGNORE INTO user_follows(follower_id,followed_id) VALUES (?,?)',[$requester,$recipient]);
    return true;
}

function messages_inbox(int $userId): array {
    $rows=q('SELECT id,sender_id,recipient_id,body,created_at,read_at FROM direct_messages WHERE recipient_id=? OR sender_id=? ORDER BY id DESC LIMIT 400',[$userId,$userId])->fetchAll();
    $threads=[];
    foreach ($rows as $row) {
        $peer=(int)$row['sender_id']===$userId ? (int)$row['recipient_id'] : (int)$row['sender_id'];
        if (!isset($threads[$peer])) $threads[$peer]=['peer'=>$peer,'last'=>$row,'unread'=>0,'count'=>0];
        $threads[$peer]['count']++;
        if ((int)$row['recipient_id']===$userId && $row['read_at']===null) $threads[$peer]['unread']++;
    }
    $names=[];
    if ($threads) {
        $ids=implode(',',array_map('intval',array_keys($threads)));
        foreach (q('SELECT id,name FROM users WHERE id IN ('.$ids.')')->fetchAll() as $user) $names[(int)$user['id']]=trim((string)$user['name']);
    }
    $list=[];
    foreach ($threads as $thread) {
        $list[]=['peer'=>$thread['peer'],'peer_name'=>($names[$thread['peer']] ?? '')!=='' ? $names[$thread['peer']] : 'عضو Noventix','last'=>(string)$thread['last']['body'],'at'=>(string)$thread['last']['created_at'],'unread'=>$thread['unread'],'count'=>$thread['count']];
    }
    return $list;
}

function messages_thread(int $userId,int $peer,int $limit=60): array {
    return q('SELECT id,sender_id,body,created_at,read_at FROM direct_messages WHERE (recipient_id=? AND sender_id=?) OR (sender_id=? AND recipient_id=?) ORDER BY id DESC LIMIT '.(int)$limit,[$userId,$peer,$userId,$peer]);
}

function messages_mark_read(int $userId,int $peer): void {
    q('UPDATE direct_messages SET read_at=? WHERE recipient_id=? AND sender_id=? AND read_at IS NULL',[gmdate('Y-m-d H:i:s'),$userId,$peer]);
}

function messages_unread(int $userId): int {
    return (int)q('SELECT COUNT(*) FROM direct_messages WHERE recipient_id=? AND read_at IS NULL',[$userId])->fetchColumn();
}

function message_requests_for(int $userId): array {
    return q('SELECT r.sender_id,r.created_at,u.name FROM message_requests r INNER JOIN users u ON u.id=r.sender_id WHERE r.recipient_id=? ORDER BY r.id DESC LIMIT 50',[$userId])->fetchAll();
}

function message_compose(int $recipientId,string $label='ارسال پیام'): void {
    form_start('messages/send','message-compose');
    echo '<input type="hidden" name="recipient" value="'.$recipientId.'"><input type="hidden" name="form_token" value="'.h(message_token()).'"><label>پیام<textarea name="body" required minlength="1" maxlength="2000" rows="3" placeholder="یک پیام کوتاه و محترمانه بنویسید"></textarea></label><button class="btn btn-small btn-primary">'.h($label).'</button></form>';
}
