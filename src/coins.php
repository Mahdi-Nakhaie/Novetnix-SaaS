<?php
declare(strict_types=1);

const COIN_WELCOME_BONUS = 100;
const COIN_HINT_COST = 15;

function coin_balance(int $userId): int {
    coin_ensure_welcome($userId);
    return (int)q('SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE user_id=?',[$userId])->fetchColumn();
}
// The welcome bonus is granted once per account, lazily, so accounts created before
// the coin system existed still receive it on their first visit.
function coin_ensure_welcome(int $userId): void {
    if (q("SELECT 1 FROM coin_ledger WHERE user_id=? AND reason='welcome' LIMIT 1",[$userId])->fetchColumn()) return;
    $pdo=db();
    $pdo->beginTransaction();
    try {
        if (!q("SELECT 1 FROM coin_ledger WHERE user_id=? AND reason='welcome' LIMIT 1",[$userId])->fetchColumn()) {
            q("INSERT INTO coin_ledger(user_id,amount,reason,reference) VALUES (?,?,'welcome','first-login')",[$userId,COIN_WELCOME_BONUS]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function coin_grant(int $userId,int $amount,string $reason,?string $reference=null): void {
    if ($amount<=0) throw new InvalidArgumentException('Coin grants must be positive.');
    q('INSERT INTO coin_ledger(user_id,amount,reason,reference) VALUES (?,?,?,?)',[$userId,$amount,$reason,$reference]);
}
// Returns false when the balance is too low; the caller decides how to report it.
function coin_spend(int $userId,int $amount,string $reason,?string $reference=null): bool {
    if ($amount<=0) throw new InvalidArgumentException('Coin spends must be positive.');
    $pdo=db();
    $pdo->beginTransaction();
    try {
        ws_lock_user($userId);
        $balance=(int)q('SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE user_id=?',[$userId])->fetchColumn();
        if ($balance<$amount) { $pdo->commit(); return false; }
        q('INSERT INTO coin_ledger(user_id,amount,reason,reference) VALUES (?,?,?,?)',[$userId,-$amount,$reason,$reference]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function coin_history(int $userId,int $limit=20): array {
    return q('SELECT amount,reason,reference,created_at FROM coin_ledger WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(100,$limit)),[$userId])->fetchAll();
}
function coin_reason_label(string $reason): string {
    return ['welcome'=>'هدیهٔ خوش‌آمدگویی','project'=>'پاداش تکمیل پروژه','hint'=>'بازکردن راهنمایی'][$reason] ?? $reason;
}
// Coins scale with the XP a project is worth, so harder challenges pay more.
function coin_reward_for(array $definition): int {
    return max(10,(int)round(((int)($definition['xp'] ?? 0))/2));
}
function hint_unlocked(int $userId,string $key,int $index): bool {
    return (bool)q('SELECT 1 FROM hint_unlocks WHERE user_id=? AND learning_key=? AND hint_index=?',[$userId,$key,$index])->fetchColumn();
}
function hint_unlock(int $userId,string $key,int $index,int $cost): bool {
    if (hint_unlocked($userId,$key,$index)) return true;
    $pdo=db();
    $pdo->beginTransaction();
    try {
        ws_lock_user($userId);
        if (hint_unlocked($userId,$key,$index)) { $pdo->commit(); return true; }
        $balance=(int)q('SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE user_id=?',[$userId])->fetchColumn();
        if ($balance<$cost) { $pdo->commit(); return false; }
        q('INSERT INTO coin_ledger(user_id,amount,reason,reference) VALUES (?,?,?,?)',[$userId,-$cost,'hint',$key.'#'.$index]);
        q('INSERT INTO hint_unlocks(user_id,learning_key,hint_index,cost) VALUES (?,?,?,?)',[$userId,$key,$index,$cost]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
