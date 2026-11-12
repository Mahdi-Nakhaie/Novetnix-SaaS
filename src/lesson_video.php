<?php
declare(strict_types=1);

// Aparat embed codes carry their hash inside the player URL, so the hash is the
// only part worth storing; the frame URL is rebuilt from it on every render.
const LESSON_APARAT_FRAME = 'https://www.aparat.com/video/video/embed/videohash/%s/vt/frame?titleShow=true&startTime=0';
const LESSON_APARAT_WATCH = 'https://www.aparat.com/v/%s';

function lesson_aparat_hash(?string $value): ?string {
    $value=trim((string)$value);
    if ($value==='') return null;
    if (preg_match('~/videohash/([a-z0-9]+)~i',$value,$match) || preg_match('~aparat\.com/embed/([a-z0-9]+)~i',$value,$match) || preg_match('~aparat\.com/v/([a-z0-9]+)~i',$value,$match)) return strtolower($match[1]);
    if (preg_match('~^[a-z0-9]{4,32}$~i',$value)) return strtolower($value);
    return null;
}

// Only the admin can attach a video; the hash is re-derived so a pasted
// third-party URL can never end up in the frame src.
function lesson_video_save(int $lessonId, string $raw): void {
    $hash=lesson_aparat_hash($raw) ?? '';
    q('UPDATE lessons SET video_url=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',[$hash,$lessonId]);
}

function lesson_aparat_frame(string $hash): string {
    return sprintf(LESSON_APARAT_FRAME,rawurlencode($hash));
}

function lesson_aparat_watch(string $hash): string {
    return sprintf(LESSON_APARAT_WATCH,rawurlencode($hash));
}
