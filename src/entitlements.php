<?php
declare(strict_types=1);

// Central entitlement layer. Every capability check goes through here so a
// plan's limits live in exactly one place (PLANS in src/data.php) and cannot
// drift between the pricing pages and the server-side guards.

function entitlement_plan(?array $user): string {
    if (!$user || empty($user['id'])) return 'free';
    return plan_for($user);
}
function entitlement_rank(string $plan): int {
    return (int)(PLANS[$plan]['rank'] ?? 0);
}
function entitlement_at_least(string $plan, string $required): bool {
    return entitlement_rank($plan) >= entitlement_rank($required);
}
// Quotas are plan-scoped and read straight from configuration.
function entitlement_quota(string $plan, string $key): int {
    return (int)(PLANS[$plan]['quotas'][$key] ?? 0);
}
function entitlement_workspace(string $plan, string $capability): mixed {
    return PLANS[$plan]['workspace'][$capability] ?? false;
}
function entitlement_sla_hours(string $plan): int {
    return (int)(PLANS[$plan]['sla_hours'] ?? 0);
}

// --- Courses -------------------------------------------------------------
// A paid course purchase is independent of a subscription: the purchase row is
// the entitlement and is never removed when a subscription lapses.
function entitlement_course_tier(string $slug): string {
    return COURSE_TIERS[$slug] ?? 'titanium';
}
function entitlement_course_purchased(?array $user, string $slug): bool {
    if (!$user || empty($user['id'])) return false;
    return (bool)q('SELECT 1 FROM course_purchases WHERE user_id=? AND course_slug=?',[(int)$user['id'],$slug])->fetchColumn();
}
function entitlement_can_course(?array $user, string $slug): bool {
    if (!$user || empty($user['id'])) return false;
    if (entitlement_course_purchased($user,$slug)) return true;
    if (!COMMERCE_ENABLED) return false;
    return entitlement_at_least(entitlement_plan($user), entitlement_course_tier($slug));
}

// --- Projects ------------------------------------------------------------
// The required tier is stored per project by the administrator; the mapping in
// data.php is only the default used when publishing a project.
function entitlement_project_tier(int $catalogIndex): ?string {
    static $cache=[];
    if (array_key_exists($catalogIndex,$cache)) return $cache[$catalogIndex];
    $tier=q('SELECT min_plan FROM project_access WHERE catalog_index=?',[$catalogIndex])->fetchColumn();
    if (!$tier) $tier=PROJECT_TIERS[$catalogIndex] ?? 'titanium';
    return $cache[$catalogIndex]=is_string($tier) && isset(PLANS[$tier]) ? $tier : 'titanium';
}
function entitlement_can_project(?array $user, int $catalogIndex): bool {
    $tier=entitlement_project_tier($catalogIndex);
    if ($tier==='free') return true;
    if (!$user || empty($user['id'])) return false;
    return entitlement_at_least(entitlement_plan($user), $tier);
}

// --- Workspace capabilities ---------------------------------------------
// A feature is only reported as available when both the plan grants it and the
// runtime actually supports it; never advertise an unimplemented capability.
function entitlement_workspace_capabilities(?array $user): array {
    $plan=entitlement_plan($user);
    return [
        'editor'=>entitlement_workspace($plan,'editor'),
        'terminal'=>(bool)entitlement_workspace($plan,'terminal') && ws_runner_ready(),
        'execution'=>(bool)entitlement_workspace($plan,'execution') && ws_runner_ready(),
        'history'=>(bool)entitlement_workspace($plan,'history'),
        'projects'=>entitlement_quota($plan,'projects'),
    ];
}

// --- Glossary ------------------------------------------------------------
function entitlement_can_glossary_specialized(?array $user): bool {
    return entitlement_at_least(entitlement_plan($user),'bronze');
}

// --- The دریچه feature ---------------------------------------------------
// Silver and above; the threshold is configuration, not a hardcoded name.
const DARYCHEH_MIN_PLAN = 'silver';
function entitlement_can_darycheh(?array $user): bool {
    if (!$user || empty($user['id'])) return false;
    return entitlement_at_least(entitlement_plan($user),DARYCHEH_MIN_PLAN);
}

// --- Support SLA ---------------------------------------------------------
// Only a first-response target is promised; there is no resolution target.
function entitlement_sla_deadline(string $plan,string $createdAtUtc): ?string {
    $hours=entitlement_sla_hours($plan);
    if ($hours<=0) return null;
    $start=new DateTimeImmutable($createdAtUtc,new DateTimeZone('UTC'));
    $remaining=$hours;
    while ($remaining>0) {
        $start=$start->modify('+1 hour');
        $weekday=(int)$start->format('N');
        if ($weekday<=5) $remaining--;
    }
    return $start->format('Y-m-d H:i:s');
}
function entitlement_sla_state(string $plan,string $createdAtUtc,?string $firstResponseUtc): string {
    $deadline=entitlement_sla_deadline($plan,$createdAtUtc);
    if ($deadline===null) return 'none';
    if ($firstResponseUtc!==null) return $firstResponseUtc<=$deadline ? 'met' : 'breached';
    return gmdate('Y-m-d H:i:s')>$deadline ? 'breached' : 'pending';
}

// --- AI features ---------------------------------------------------------
// A feature may only be enabled once its backend exists; none are live today.
function entitlement_ai_features(): array {
    return [
        'learning-assistant'=>['available'=>false,'label'=>'دستیار یادگیری'],
        'code-review'=>['available'=>false,'label'=>'بازبینی کد'],
        'roadmap'=>['available'=>false,'label'=>'تولید نقشهٔ راه'],
        'skill-gap'=>['available'=>false,'label'=>'تحلیل شکاف مهارت'],
    ];
}
function entitlement_ai_available(string $feature): bool {
    return (bool)(entitlement_ai_features()[$feature]['available'] ?? false);
}
