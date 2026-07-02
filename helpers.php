<?php
/**
 * Umumiy yordamchilar: vaqt zonasi, date filter, template render,
 * foydalanuvchi ko'rish doirasi (branch/NVR scoping), JSON javoblar.
 *
 * Baza UTC vaqtni saqlaydi; ko'rsatishda mahalliy zona (config: tz_offset_hours).
 */

require_once __DIR__ . '/db.php';

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function tz_offset_seconds(): int
{
    return (int) cfg()['tz_offset_hours'] * 3600;
}

// ── VAQT ─────────────────────────────────────────────────────────────────
/** UTC vaqt (string) -> mahalliy ISO ("Y-m-d\TH:i:s"), yoki null. */
function local_iso(?string $utc): ?string
{
    if (!$utc) {
        return null;
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d\TH:i:s', $ts + tz_offset_seconds());
}

/** UTC vaqt -> mahalliy "d.m.Y H:i:s", yoki "-". */
function local_str(?string $utc): string
{
    if (!$utc) {
        return '-';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return '-';
    }
    return date('d.m.Y H:i:s', $ts + tz_offset_seconds());
}

/** Hozirgi UTC vaqt MySQL DATETIME formatida. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Mahalliy sana chegaralarini UTC ga aylantirib WHERE shartlariga qo'shadi.
 * $col — ustun nomi (masalan "ce.started_at").
 * Qaytaradi: [where_qismi (string), params (array)] — birlashtirish uchun.
 */
function date_filter_utc(string $col, ?string $date_from, ?string $date_to): array
{
    $where  = [];
    $params = [];
    $off    = tz_offset_seconds();

    if ($date_from) {
        $ts = strtotime($date_from);
        if ($ts !== false) {
            $where[]  = "$col >= ?";
            $params[] = gmdate('Y-m-d H:i:s', $ts - $off);
        }
    }
    if ($date_to) {
        $ts = strtotime($date_to);
        if ($ts !== false) {
            // +1 kun (chegaragacha), keyin UTC ga
            $where[]  = "$col < ?";
            $params[] = gmdate('Y-m-d H:i:s', $ts + 86400 - $off);
        }
    }
    return [$where, $params];
}

/** Davomiylikni (soniya) o'qiladigan matnga. */
function fmt_dur(?float $sec): string
{
    if (!$sec) {
        return '-';
    }
    $s = (int) round($sec);
    if ($s < 60) {
        return "$s son.";
    }
    $m = intdiv($s, 60);
    $r = $s % 60;
    if ($m < 60) {
        return "$m daq. $r son.";
    }
    $h  = intdiv($m, 60);
    $m2 = $m % 60;
    return "$h soat $m2 daq.";
}

// ── FOYDALANUVCHI KO'RISH DOIRASI ─────────────────────────────────────────
/** Superadmin -> null (cheklovsiz). Aks holda foydalanuvchi filiallari id'lari. */
function user_branch_ids(array $user): ?array
{
    if ($user['role'] === 'superadmin') {
        return null;
    }
    $rows = db_all("SELECT branch_id FROM user_branches WHERE user_id = ?", [$user['id']]);
    return array_map(fn($r) => (int) $r['branch_id'], $rows);
}

/** Superadmin -> null. Aks holda biriktirilgan NVR id'lari (yo'q bo'lsa null). */
function user_nvr_ids(array $user): ?array
{
    if ($user['role'] === 'superadmin') {
        return null;
    }
    $rows = db_all("SELECT nvr_id FROM user_nvrs WHERE user_id = ?", [$user['id']]);
    $ids  = array_map(fn($r) => (int) $r['nvr_id'], $rows);
    return $ids ?: null;
}

/**
 * Kameralar so'roviga ko'rish doirasi shartini qo'shadi.
 * Qaytaradi: [where (string array), params (array)].
 * NVR cheklovi bo'lsa nvr bo'yicha, aks holda branch bo'yicha.
 */
function cam_scope(array $user): array
{
    $where  = [];
    $params = [];
    $nvrIds = user_nvr_ids($user);
    $brIds  = user_branch_ids($user);

    if ($nvrIds !== null) {
        [$ph, $vals] = db_in($nvrIds);
        $where[]  = "c.nvr_id IN ($ph)";
        $params   = array_merge($params, $vals);
    } elseif ($brIds !== null) {
        [$ph, $vals] = db_in($brIds);
        $where[]  = "n.branch_id IN ($ph)";
        $params   = array_merge($params, $vals);
    }
    return [$where, $params];
}

// ── TEMPLATE RENDER ───────────────────────────────────────────────────────
/**
 * PHP template render qiladi. $vars — template ichida o'zgaruvchi sifatida.
 * Layout: templates/base.php ichida $__content sifatida ishlatiladi.
 */
function render(string $tpl, array $vars = []): string
{
    $vars['__current_path'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    extract($vars, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/templates/' . $tpl;
    return ob_get_clean();
}

/** HTML-escape (template ichida e() deb ishlatiladi). */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// ── JSON JAVOBLAR ─────────────────────────────────────────────────────────
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $detail = ''): void
{
    json_response(['detail' => $detail], $status);
}

/** So'rov tanasini JSON sifatida o'qiydi. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    $d   = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function redirect(string $to, int $status = 302): void
{
    http_response_code($status);
    header("Location: $to");
    exit;
}
