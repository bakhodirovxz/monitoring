<?php
/**
 * HCP Monitor — front controller.
 * Barcha sahifalar va API endpointlari shu yerda yo'naltiriladi.
 *
 * Ishga tushirish (dev):  php -S 0.0.0.0:8000 index.php
 * Production: Apache + .htaccess (URL rewriting -> index.php).
 */

// Warning/Deprecated xabarlari JSON/HTML javobni buzmasligi uchun ekranga
// chiqarmaymiz (xatolar log'ga yoziladi). Faqat jiddiy xatolar ko'rinadi.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

// PHP dev-server uchun: mavjud statik fayllarni to'g'ridan-to'g'ri uzatish.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, 'index.php')) {
        return false;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

// ── JORIY FOYDALANUVCHI ───────────────────────────────────────────────────
function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = auth_current_user($_COOKIE['access_token'] ?? null);
    }
    return $u;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        json_error(401, 'Avtorizatsiya kerak');
    }
    return $u;
}

function require_superadmin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'superadmin') {
        json_error(403, 'Ruxsat yo\'q');
    }
    return $u;
}

// ── ROUTER ─────────────────────────────────────────────────────────────
$LOCAL_TZ = tz_offset_seconds();

// Marshrutni topish: aniq mosliklar + regex (id'lar uchun)
$route = "$method $path";

// ── SAHIFALAR ─────────────────────────────────────────────────────────────
if ($route === 'GET /') {
    redirect(current_user() ? '/dashboard' : '/login');
}

if ($route === 'GET /login') {
    echo render('login.php', ['error' => null]);
    exit;
}

if ($route === 'POST /login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $user = db_one("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1", [$username]);
    if (!$user || !auth_verify_password($password, $user['password_hash'])) {
        echo render('login.php', ['error' => 'Login yoki parol xato']);
        exit;
    }
    $token = auth_create_token(['sub' => $user['username']]);
    setcookie('access_token', $token, [
        'expires'  => time() + 3600 * 8,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    redirect('/dashboard');
}

if ($route === 'GET /logout') {
    setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/']);
    redirect('/login');
}

if ($route === 'GET /dashboard') {
    $user = current_user();
    if (!$user) redirect('/login');
    $branches = scoped_branches($user);
    echo render('dashboard.php', ['user' => $user, 'branches' => $branches]);
    exit;
}

if ($route === 'GET /reports') {
    $user = current_user();
    if (!$user) redirect('/login');
    $branches = scoped_branches($user);
    echo render('reports.php', ['user' => $user, 'branches' => $branches]);
    exit;
}

if ($route === 'GET /manage') {
    $user = current_user();
    if (!$user) redirect('/login');
    page_manage($user);
    exit;
}

if ($route === 'GET /admin') {
    $user = current_user();
    if (!$user) redirect('/login');
    if ($user['role'] !== 'superadmin') redirect('/dashboard');
    page_admin($user);
    exit;
}

if ($method === 'GET' && preg_match('#^/camera/(\d+)$#', $path, $m)) {
    $user = current_user();
    if (!$user) redirect('/login');
    page_camera($user, (int) $m[1]);
    exit;
}

// ── API: STATUS ────────────────────────────────────────────────────────
if ($route === 'GET /api/cameras') {
    api_cameras();
}
if ($route === 'GET /api/stats') {
    api_stats();
}

// ── API: EVENTS ────────────────────────────────────────────────────────
if ($route === 'GET /api/events') {
    api_events();
}

// ── API: ADMIN — BRANCHES ─────────────────────────────────────────────────
if ($route === 'POST /api/branches') {
    api_create_branch();
}
if ($method === 'DELETE' && preg_match('#^/api/branches/(\d+)$#', $path, $m)) {
    api_delete_branch((int) $m[1]);
}
if ($method === 'GET' && preg_match('#^/api/branches/(\d+)/nvrs$#', $path, $m)) {
    api_branch_nvrs((int) $m[1]);
}

// ── API: ADMIN — USERS ─────────────────────────────────────────────────
if ($route === 'POST /api/users') {
    api_create_user();
}
if ($method === 'PUT' && preg_match('#^/api/users/(\d+)$#', $path, $m)) {
    api_update_user((int) $m[1]);
}
if ($method === 'DELETE' && preg_match('#^/api/users/(\d+)$#', $path, $m)) {
    api_delete_user((int) $m[1]);
}

// ── API: BRANCH-ADMIN MANAGED USERS ───────────────────────────────────────
if ($route === 'POST /api/manage/users') {
    api_create_managed_user();
}
if ($method === 'PUT' && preg_match('#^/api/manage/users/(\d+)$#', $path, $m)) {
    api_update_managed_user((int) $m[1]);
}
if ($method === 'DELETE' && preg_match('#^/api/manage/users/(\d+)$#', $path, $m)) {
    api_delete_managed_user((int) $m[1]);
}

// ── API: NVR ─────────────────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/nvrs/(\d+)/assign$#', $path, $m)) {
    api_assign_nvr((int) $m[1]);
}
if ($method === 'PUT' && preg_match('#^/api/nvrs/(\d+)$#', $path, $m)) {
    api_update_nvr((int) $m[1]);
}
if ($route === 'POST /api/sync-nvrs') {
    api_sync_nvrs();
}

// ── API: CAMERA ─────────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/api/cameras/(\d+)$#', $path, $m)) {
    api_camera_detail((int) $m[1]);
}
if ($method === 'GET' && preg_match('#^/api/cameras/(\d+)/daily-stats$#', $path, $m)) {
    api_camera_daily_stats((int) $m[1]);
}
if ($method === 'PUT' && preg_match('#^/api/cameras/(\d+)$#', $path, $m)) {
    api_update_camera((int) $m[1]);
}
if ($method === 'GET' && preg_match('#^/api/cameras/(\d+)/snapshot$#', $path, $m)) {
    api_camera_snapshot((int) $m[1]);
}
if ($method === 'POST' && preg_match('#^/api/cameras/(\d+)/snapshot/refresh$#', $path, $m)) {
    api_camera_snapshot_refresh((int) $m[1]);
}

// ── API: EXPORT ─────────────────────────────────────────────────────────
if ($route === 'GET /api/export/csv') {
    api_export_csv();
}
if ($route === 'GET /api/export/excel') {
    api_export_excel();
}

// ── 404 ─────────────────────────────────────────────────────────────────
http_response_code(404);
echo "404 Not Found";
exit;


// ═══════════════════════════════════════════════════════════════════════
//  HANDLER FUNKSIYALARI
// ═══════════════════════════════════════════════════════════════════════

/** Foydalanuvchi ko'ra oladigan filiallar. */
function scoped_branches(array $user): array
{
    $ids = user_branch_ids($user);
    if ($ids === null) {
        return db_all("SELECT * FROM branches ORDER BY name");
    }
    if (!$ids) {
        return [];
    }
    [$ph, $vals] = db_in($ids);
    return db_all("SELECT * FROM branches WHERE id IN ($ph) ORDER BY name", $vals);
}

/** Kamera uchun "offline_since" — eng so'nggi ochiq offline event boshlanishi (UTC). */
function offline_since_map(array $camIds): array
{
    if (!$camIds) {
        return [];
    }
    [$ph, $vals] = db_in($camIds);
    $rows = db_all(
        "SELECT camera_id, MAX(started_at) AS started_at
           FROM camera_events
          WHERE event_type = 'offline' AND ended_at IS NULL
            AND camera_id IN ($ph)
          GROUP BY camera_id",
        $vals
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['camera_id']] = $r['started_at'];
    }
    return $map;
}

/** Kamera qatorini API formatiga aylantirish. */
function cam_row(array $r, ?string $offlineSinceUtc): array
{
    return [
        'id'            => (int) $r['id'],
        'name'          => $r['name'],
        'ip'            => $r['channel_ip'],
        'status'        => (int) $r['current_status'],
        'offline_since' => ($r['current_status'] == 2 && $offlineSinceUtc) ? local_iso($offlineSinceUtc) : null,
        'nvr_id'        => $r['nvr_id'] !== null ? (int) $r['nvr_id'] : null,
        'nvr_name'      => $r['nvr_name'] ?? '',
        'nvr_ip'        => $r['nvr_ip'] ?? '',
        'branch'        => $r['branch_name'] ?? 'Tayinlanmagan',
        'branch_id'     => isset($r['branch_id']) && $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
    ];
}

/** Event qatorini API formatiga aylantirish. */
function ev_row(array $r): array
{
    return [
        'id'           => (int) $r['id'],
        'camera_id'    => (int) $r['camera_id'],
        'camera_name'  => $r['camera_name'],
        'camera_ip'    => $r['camera_ip'],
        'branch'       => $r['branch_name'] ?? 'Tayinlanmagan',
        'nvr_name'     => $r['nvr_name'] ?? '',
        'event_type'   => $r['event_type'],
        'started_at'   => local_iso($r['started_at']),
        'ended_at'     => local_iso($r['ended_at']),
        'duration_sec' => $r['duration_sec'] !== null ? (float) $r['duration_sec'] : null,
    ];
}

// ── /api/cameras ──────────────────────────────────────────────────────────
function api_cameras(): void
{
    $user = require_user();
    [$where, $params] = cam_scope($user);

    if (!empty($_GET['branch_id'])) {
        $where[]  = "n.branch_id = ?";
        $params[] = (int) $_GET['branch_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[]  = "c.current_status = ?";
        $params[] = (int) $_GET['status'];
    }

    $sql = "SELECT c.*, n.id AS nvr_id, n.name AS nvr_name, n.ip AS nvr_ip,
                   b.id AS branch_id, b.name AS branch_name
              FROM cameras c
              LEFT JOIN nvrs n     ON c.nvr_id = n.id
              LEFT JOIN branches b ON n.branch_id = b.id";
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY c.name";

    $rows   = db_all($sql, $params);
    $camIds = array_map(fn($r) => (int) $r['id'], $rows);
    $offMap = offline_since_map($camIds);

    $cameras = [];
    foreach ($rows as $r) {
        $cameras[] = cam_row($r, $offMap[(int) $r['id']] ?? null);
    }
    json_response(['cameras' => $cameras]);
}

// ── /api/stats ─────────────────────────────────────────────────────────
function api_stats(): void
{
    $user = require_user();
    [$where, $params] = cam_scope($user);

    $sql = "SELECT c.current_status
              FROM cameras c
              LEFT JOIN nvrs n ON c.nvr_id = n.id";
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $rows = db_all($sql, $params);

    $total = count($rows);
    $online = $offline = $unknown = 0;
    foreach ($rows as $r) {
        $s = (int) $r['current_status'];
        if ($s === 1) $online++;
        elseif ($s === 2) $offline++;
        else $unknown++;
    }

    $last = db_val("SELECT MAX(started_at) FROM camera_events");
    json_response([
        'total'       => $total,
        'online'      => $online,
        'offline'     => $offline,
        'unknown'     => $unknown,
        'last_update' => $last ? local_iso($last) : null,
    ]);
}

// ── /api/events ─────────────────────────────────────────────────────────
function api_events(): void
{
    $user = require_user();
    [$scopeWhere, $scopeParams] = cam_scope($user);

    $where  = $scopeWhere;
    $params = $scopeParams;

    if (!empty($_GET['branch_id'])) { $where[] = "n.branch_id = ?"; $params[] = (int) $_GET['branch_id']; }
    if (!empty($_GET['nvr_id']))    { $where[] = "n.id = ?";        $params[] = (int) $_GET['nvr_id']; }
    if (!empty($_GET['camera_id'])) { $where[] = "ce.camera_id = ?";$params[] = (int) $_GET['camera_id']; }
    if (!empty($_GET['event_type'])){ $where[] = "ce.event_type = ?";$params[] = $_GET['event_type']; }
    if (!empty($_GET['search']))    { $where[] = "c.name LIKE ?";   $params[] = '%' . $_GET['search'] . '%'; }

    [$dWhere, $dParams] = date_filter_utc('ce.started_at', $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
    $where  = array_merge($where, $dWhere);
    $params = array_merge($params, $dParams);

    $from = "FROM camera_events ce
               JOIN cameras c       ON ce.camera_id = c.id
               LEFT JOIN nvrs n     ON c.nvr_id = n.id
               LEFT JOIN branches b ON n.branch_id = b.id";
    $whereSql = $where ? (" WHERE " . implode(' AND ', $where)) : '';

    // Agregat statistika (barcha mos qatorlar bo'yicha)
    $total     = (int) db_val("SELECT COUNT(*) $from $whereSql", $params);
    $totalOff  = (int) db_val("SELECT COUNT(*) $from $whereSql" . ($whereSql ? " AND" : " WHERE") . " ce.event_type='offline'", $params);
    $totalOn   = $total - $totalOff;

    $durWhere  = $whereSql ? "$whereSql AND ce.event_type='offline' AND ce.duration_sec IS NOT NULL"
                           : "WHERE ce.event_type='offline' AND ce.duration_sec IS NOT NULL";
    $totalDur  = (float) (db_val("SELECT COALESCE(SUM(ce.duration_sec),0) $from $durWhere", $params) ?? 0);
    $avgDur    = db_val("SELECT AVG(ce.duration_sec) $from $durWhere", $params);
    $avgDur    = $avgDur !== null ? (float) $avgDur : null;

    $uniqueCams = (int) db_val("SELECT COUNT(DISTINCT c.id) $from $whereSql", $params);

    // Sahifalangan natija
    $limit  = isset($_GET['limit'])  ? max(1, (int) $_GET['limit'])  : 100;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    // Saralash — faqat ruxsat etilgan ustunlar (SQL inyeksiyadan himoya)
    $sortMap = [
        'camera_name'  => 'c.name',
        'camera_ip'    => 'c.channel_ip',
        'branch'       => 'b.name',
        'nvr_name'     => 'n.name',
        'event_type'   => 'ce.event_type',
        'started_at'   => 'ce.started_at',
        'ended_at'     => 'ce.ended_at',
        'duration_sec' => 'ce.duration_sec',
    ];
    $sortCol = $sortMap[$_GET['sort'] ?? ''] ?? 'ce.started_at';
    $sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $rows = db_all(
        "SELECT ce.*, c.id AS camera_id, c.name AS camera_name, c.channel_ip AS camera_ip,
                n.name AS nvr_name, b.name AS branch_name
         $from $whereSql
         ORDER BY $sortCol $sortDir
         LIMIT $limit OFFSET $offset",
        $params
    );

    json_response([
        'total'              => $total,
        'total_offline'      => $totalOff,
        'total_online'       => $totalOn,
        'total_duration_sec' => $totalDur,
        'avg_duration_sec'   => $avgDur,
        'unique_cameras'     => $uniqueCams,
        'events'             => array_map('ev_row', $rows),
    ]);
}

// ── BRANCHES ──────────────────────────────────────────────────────────────
function api_create_branch(): void
{
    require_superadmin();
    $data = json_body();
    $name = trim($data['name'] ?? '');
    if ($name === '') json_error(400, 'Nom kerak');
    if (db_one("SELECT id FROM branches WHERE name = ?", [$name])) json_error(400, 'Bu nom allaqachon mavjud');
    $id = db_insert("INSERT INTO branches (name) VALUES (?)", [$name]);
    json_response(['id' => $id, 'name' => $name]);
}

function api_delete_branch(int $bid): void
{
    require_superadmin();
    if (!db_one("SELECT id FROM branches WHERE id = ?", [$bid])) json_error(404);
    db_exec("DELETE FROM branches WHERE id = ?", [$bid]);
    json_response(['ok' => true]);
}

function api_branch_nvrs(int $bid): void
{
    require_user();
    $nvrs = db_all("SELECT id, name, hik_code, ip FROM nvrs WHERE branch_id = ? ORDER BY name", [$bid]);
    $out = array_map(fn($n) => [
        'id'   => (int) $n['id'],
        'name' => $n['name'] !== '' ? $n['name'] : $n['hik_code'],
        'ip'   => $n['ip'],
    ], $nvrs);
    json_response(['nvrs' => $out]);
}

// ── USERS (superadmin) ─────────────────────────────────────────────────
function api_create_user(): void
{
    require_superadmin();
    $data = json_body();
    if (empty($data['username']) || empty($data['password'])) json_error(400, 'Login va parol kerak');
    if (db_one("SELECT id FROM users WHERE username = ?", [$data['username']])) json_error(400, 'Bu login allaqachon mavjud');

    $uid = db_insert(
        "INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)",
        [$data['username'], auth_hash_password($data['password']),
         $data['full_name'] ?? '', $data['role'] ?? 'branch_admin']
    );
    foreach (($data['branch_ids'] ?? []) as $bid) {
        if (db_one("SELECT id FROM branches WHERE id = ?", [(int) $bid])) {
            db_exec("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)", [$uid, (int) $bid]);
        }
    }
    json_response(['id' => $uid, 'username' => $data['username']]);
}

function api_update_user(int $uid): void
{
    require_superadmin();
    $data = json_body();
    $u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) json_error(404);

    foreach (['full_name', 'role', 'is_active'] as $f) {
        if (array_key_exists($f, $data)) {
            $val = $f === 'is_active' ? (int) (bool) $data[$f] : $data[$f];
            db_exec("UPDATE users SET $f = ? WHERE id = ?", [$val, $uid]);
        }
    }
    if (!empty($data['password'])) {
        db_exec("UPDATE users SET password_hash = ? WHERE id = ?", [auth_hash_password($data['password']), $uid]);
    }
    if (array_key_exists('branch_ids', $data)) {
        db_exec("DELETE FROM user_branches WHERE user_id = ?", [$uid]);
        foreach ($data['branch_ids'] as $bid) {
            if (db_one("SELECT id FROM branches WHERE id = ?", [(int) $bid])) {
                db_exec("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)", [$uid, (int) $bid]);
            }
        }
    }
    json_response(['ok' => true]);
}

function api_delete_user(int $uid): void
{
    $cur = require_superadmin();
    if ((int) $cur['id'] === $uid) json_error(400, 'O\'zingizni o\'chira olmaysiz');
    $u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) json_error(404);
    if ($u['role'] === 'superadmin') json_error(400, 'Superadminni o\'chirish mumkin emas');
    db_exec("DELETE FROM users WHERE id = ?", [$uid]);
    json_response(['ok' => true]);
}

// ── MANAGED USERS (branch_admin) ──────────────────────────────────────────
function api_create_managed_user(): void
{
    $cur = require_user();
    $ids = user_branch_ids($cur);
    if (!$ids) json_error(403, 'Faqat filial adminlari foydalanuvchi yarata oladi');
    $data = json_body();
    if (empty($data['username']) || empty($data['password'])) json_error(400, 'Login va parol kerak');
    if (db_one("SELECT id FROM users WHERE username = ?", [$data['username']])) json_error(400, 'Bu login allaqachon mavjud');

    $branchIds = array_values(array_filter(
        array_map('intval', $data['branch_ids'] ?? []),
        fn($b) => in_array($b, $ids, true)
    ));
    if (!$branchIds) $branchIds = [$ids[0]];

    $uid = db_insert(
        "INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'branch_admin')",
        [$data['username'], auth_hash_password($data['password']), $data['full_name'] ?? '']
    );
    foreach ($branchIds as $bid) {
        db_exec("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)", [$uid, $bid]);
    }
    foreach (($data['nvr_ids'] ?? []) as $nid) {
        $nvr = db_one("SELECT branch_id FROM nvrs WHERE id = ?", [(int) $nid]);
        if ($nvr && in_array((int) $nvr['branch_id'], $ids, true)) {
            db_exec("INSERT INTO user_nvrs (user_id, nvr_id) VALUES (?, ?)", [$uid, (int) $nid]);
        }
    }
    json_response(['id' => $uid, 'username' => $data['username']]);
}

function api_update_managed_user(int $uid): void
{
    $cur = require_user();
    $curIds = user_branch_ids($cur);
    if (!$curIds) json_error(403);
    $u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) json_error(404);

    $userBids = array_map(fn($r) => (int) $r['branch_id'],
        db_all("SELECT branch_id FROM user_branches WHERE user_id = ?", [$uid]));
    if (!array_intersect($userBids, $curIds)) json_error(403);

    $data = json_body();
    foreach (['full_name', 'is_active'] as $f) {
        if (array_key_exists($f, $data)) {
            $val = $f === 'is_active' ? (int) (bool) $data[$f] : $data[$f];
            db_exec("UPDATE users SET $f = ? WHERE id = ?", [$val, $uid]);
        }
    }
    if (!empty($data['password'])) {
        db_exec("UPDATE users SET password_hash = ? WHERE id = ?", [auth_hash_password($data['password']), $uid]);
    }
    if (array_key_exists('branch_ids', $data)) {
        db_exec("DELETE FROM user_branches WHERE user_id = ?", [$uid]);
        foreach ($data['branch_ids'] as $bid) {
            $bid = (int) $bid;
            if (in_array($bid, $curIds, true) && db_one("SELECT id FROM branches WHERE id = ?", [$bid])) {
                db_exec("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)", [$uid, $bid]);
            }
        }
    }
    if (array_key_exists('nvr_ids', $data)) {
        db_exec("DELETE FROM user_nvrs WHERE user_id = ?", [$uid]);
        foreach ($data['nvr_ids'] as $nid) {
            $nvr = db_one("SELECT branch_id FROM nvrs WHERE id = ?", [(int) $nid]);
            if ($nvr && in_array((int) $nvr['branch_id'], $curIds, true)) {
                db_exec("INSERT INTO user_nvrs (user_id, nvr_id) VALUES (?, ?)", [$uid, (int) $nid]);
            }
        }
    }
    json_response(['ok' => true]);
}

function api_delete_managed_user(int $uid): void
{
    $cur = require_user();
    if ((int) $cur['id'] === $uid) json_error(400, 'O\'zingizni o\'chira olmaysiz');
    $curIds = user_branch_ids($cur);
    if (!$curIds) json_error(403);
    $u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) json_error(404);

    $userBids = array_map(fn($r) => (int) $r['branch_id'],
        db_all("SELECT branch_id FROM user_branches WHERE user_id = ?", [$uid]));
    if (!array_intersect($userBids, $curIds)) json_error(403);
    if ($u['role'] === 'superadmin') json_error(400, 'Superadminni o\'chirish mumkin emas');
    db_exec("DELETE FROM users WHERE id = ?", [$uid]);
    json_response(['ok' => true]);
}

// ── NVR ─────────────────────────────────────────────────────────────────
function api_assign_nvr(int $nid): void
{
    require_superadmin();
    $data = json_body();
    if (!db_one("SELECT id FROM nvrs WHERE id = ?", [$nid])) json_error(404);
    $bid = isset($data['branch_id']) && $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
    db_exec("UPDATE nvrs SET branch_id = ? WHERE id = ?", [$bid, $nid]);
    json_response(['ok' => true]);
}

function api_update_nvr(int $nid): void
{
    $user = require_user();
    $nvr  = db_one("SELECT * FROM nvrs WHERE id = ?", [$nid]);
    if (!$nvr) json_error(404);
    $ids = user_branch_ids($user);
    if ($ids !== null && !in_array((int) $nvr['branch_id'], $ids, true)) json_error(403);
    $data = json_body();
    if (array_key_exists('name', $data)) {
        db_exec("UPDATE nvrs SET name = ?, name_overridden = 1 WHERE id = ?", [$data['name'], $nid]);
    }
    json_response(['ok' => true]);
}

function api_sync_nvrs(): void
{
    require_superadmin();
    require_once __DIR__ . '/hik.php';
    hik_sync_nvrs();
    $count = (int) db_val("SELECT COUNT(*) FROM nvrs");
    json_response(['ok' => true, 'count' => $count]);
}

// ── CAMERA DETAIL ─────────────────────────────────────────────────────────
function scoped_camera(array $user, int $camId): ?array
{
    [$where, $params] = cam_scope($user);
    $where[]  = "c.id = ?";
    $params[] = $camId;
    $sql = "SELECT c.*, n.id AS nvr_id, n.name AS nvr_name, n.ip AS nvr_ip, n.hik_code AS nvr_hik_code,
                   b.id AS branch_id, b.name AS branch_name
              FROM cameras c
              LEFT JOIN nvrs n     ON c.nvr_id = n.id
              LEFT JOIN branches b ON n.branch_id = b.id
             WHERE " . implode(' AND ', $where) . " LIMIT 1";
    return db_one($sql, $params);
}

function api_camera_detail(int $camId): void
{
    $user = require_user();
    $r = scoped_camera($user, $camId);
    if (!$r) json_error(404);

    $offline = db_one("SELECT MAX(started_at) AS s FROM camera_events
                        WHERE camera_id = ? AND event_type='offline' AND ended_at IS NULL", [$camId]);
    $offlineSince = $offline['s'] ?? null;

    $offCount = (int) db_val("SELECT COUNT(*) FROM camera_events WHERE camera_id = ? AND event_type='offline'", [$camId]);

    $lastRows = db_all(
        "SELECT ce.*, c.id AS camera_id, c.name AS camera_name, c.channel_ip AS camera_ip,
                n.name AS nvr_name, b.name AS branch_name
           FROM camera_events ce
           JOIN cameras c       ON ce.camera_id = c.id
           LEFT JOIN nvrs n     ON c.nvr_id = n.id
           LEFT JOIN branches b ON n.branch_id = b.id
          WHERE ce.camera_id = ?
          ORDER BY ce.started_at DESC LIMIT 10",
        [$camId]
    );

    $base = cam_row($r, $offlineSince);
    json_response(array_merge($base, [
        'hik_code'           => $r['hik_code'],
        'last_status_change' => local_iso($r['last_status_change']),
        'offline_count'      => $offCount,
        'last_events'        => array_map('ev_row', $lastRows),
    ]));
}

function api_camera_daily_stats(int $camId): void
{
    $user = require_user();
    if (!scoped_camera($user, $camId)) json_error(404);
    $rows = db_all(
        "SELECT DATE(started_at) AS day, SUM(duration_sec) AS total_sec, COUNT(*) AS cnt
           FROM camera_events
          WHERE camera_id = ? AND event_type='offline'
          GROUP BY DATE(started_at) ORDER BY day",
        [$camId]
    );
    $daily = array_map(fn($r) => [
        'day'       => $r['day'],
        'total_sec' => (float) ($r['total_sec'] ?? 0),
        'count'     => (int) $r['cnt'],
    ], $rows);
    json_response(['daily' => $daily]);
}

function api_update_camera(int $camId): void
{
    $user = require_user();
    $cam  = db_one("SELECT * FROM cameras WHERE id = ?", [$camId]);
    if (!$cam) json_error(404);
    $ids = user_branch_ids($user);
    if ($ids !== null) {
        $nvr = db_one("SELECT branch_id FROM nvrs WHERE id = ?", [$cam['nvr_id']]);
        if (!$nvr || !in_array((int) $nvr['branch_id'], $ids, true)) json_error(403);
    }
    $data = json_body();
    if (array_key_exists('name', $data)) {
        db_exec("UPDATE cameras SET name = ?, name_overridden = 1 WHERE id = ?", [$data['name'], $camId]);
    }
    json_response(['ok' => true]);
}

// ── SNAPSHOT (snap_dir() va hik_capture_snapshot() hik.php da) ──────────────
function api_camera_snapshot(int $camId): void
{
    require_user();
    if (!db_one("SELECT id FROM cameras WHERE id = ?", [$camId])) json_error(404);
    require_once __DIR__ . '/hik.php';
    $path = snap_dir() . "/$camId.jpg";
    if (is_file($path)) {
        json_response(['has_snapshot' => true, 'url' => "/static/snapshots/$camId.jpg?v=" . filemtime($path)]);
    }
    json_response(['has_snapshot' => false, 'url' => null]);
}

function api_camera_snapshot_refresh(int $camId): void
{
    require_user();
    require_once __DIR__ . '/hik.php';
    $cam = db_one("SELECT * FROM cameras WHERE id = ?", [$camId]);
    if (!$cam) json_error(404);

    // Capture 20-30s olishi mumkin — PHP skript vaqtini oshiramiz (curl 60s + zaxira).
    set_time_limit(75);

    $res = hik_capture_snapshot($camId, $cam['hik_code'], 1);
    if (!$res['ok']) {
        $status = str_contains($res['error'], 'timeout') ? 504 : 502;
        json_error($status, $res['error'] . '. Qaytadan urinib ko\'ring.');
    }
    $path = snap_dir() . "/$camId.jpg";
    json_response(['ok' => true, 'url' => "/static/snapshots/$camId.jpg?v=" . filemtime($path)]);
}

// ── EXPORT ────────────────────────────────────────────────────────────────
function export_rows(array $user): array
{
    [$where, $params] = cam_scope($user);
    if (!empty($_GET['branch_id'])) { $where[] = "n.branch_id = ?"; $params[] = (int) $_GET['branch_id']; }
    if (!empty($_GET['nvr_id']))    { $where[] = "n.id = ?";        $params[] = (int) $_GET['nvr_id']; }
    [$dWhere, $dParams] = date_filter_utc('ce.started_at', $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
    $where  = array_merge($where, $dWhere);
    $params = array_merge($params, $dParams);

    $whereSql = $where ? (" WHERE " . implode(' AND ', $where)) : '';
    return db_all(
        "SELECT ce.*, c.name AS camera_name, c.channel_ip AS camera_ip,
                n.name AS nvr_name, b.name AS branch_name
           FROM camera_events ce
           JOIN cameras c       ON ce.camera_id = c.id
           LEFT JOIN nvrs n     ON c.nvr_id = n.id
           LEFT JOIN branches b ON n.branch_id = b.id
         $whereSql
         ORDER BY ce.started_at DESC",
        $params
    );
}

function api_export_csv(): void
{
    $user = require_user();
    $rows = export_rows($user);
    $fname = 'hisobot_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=$fname");

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel uchun)
    fputcsv($out, ['Kamera', 'IP', 'Filial', 'NVR', 'Hodisa', 'Boshlanish', 'Tugash', 'Davomiyligi (daq.)']);
    foreach ($rows as $r) {
        $dur = $r['duration_sec'] ? number_format($r['duration_sec'] / 60, 1) : '-';
        fputcsv($out, [
            $r['camera_name'], $r['camera_ip'],
            $r['branch_name'] ?? '-',
            $r['nvr_name'] ?? '-',
            $r['event_type'] === 'offline' ? 'Offline' : 'Online',
            local_str($r['started_at']),
            local_str($r['ended_at']),
            $dur,
        ]);
    }
    fclose($out);
    exit;
}

/**
 * Excel eksport — tashqi kutubxonasiz SpreadsheetML (XML) .xls fayli.
 * Excel/LibreOffice ochadi; UTF-8 va ustun kengligi qo'llab-quvvatlanadi.
 */
function api_export_excel(): void
{
    $user = require_user();
    $rows = export_rows($user);
    $fname = 'hisobot_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header("Content-Disposition: attachment; filename=$fname");

    $headers = ['Kamera', 'IP', 'Filial', 'NVR', 'Hodisa', 'Boshlanish', 'Tugash', 'Davomiyligi (daq.)'];

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
                     xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Styles>
            <Style ss:ID="hdr"><Font ss:Bold="1" ss:Color="#FFFFFF"/>
              <Interior ss:Color="#1a5276" ss:Pattern="Solid"/>
              <Alignment ss:Horizontal="Center"/></Style>
          </Styles>' . "\n";
    echo '<Worksheet ss:Name="Hisobot"><Table>' . "\n";

    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . e($h) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($rows as $r) {
        $dur = $r['duration_sec'] ? round($r['duration_sec'] / 60, 1) : '';
        $cells = [
            ['String', $r['camera_name']],
            ['String', $r['camera_ip']],
            ['String', $r['branch_name'] ?? '-'],
            ['String', $r['nvr_name'] ?? '-'],
            ['String', $r['event_type'] === 'offline' ? 'Offline' : 'Online'],
            ['String', local_str($r['started_at'])],
            ['String', local_str($r['ended_at'])],
            [$dur === '' ? 'String' : 'Number', $dur === '' ? '-' : $dur],
        ];
        echo '<Row>';
        foreach ($cells as [$type, $val]) {
            echo "<Cell><Data ss:Type=\"$type\">" . e($val) . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}

// ── SAHIFA: MANAGE ──────────────────────────────────────────────────────
function page_manage(array $user): void
{
    $ids = user_branch_ids($user);
    if ($ids !== null) {
        if (!$ids) {
            $branches = $nvrs = $managedUsers = [];
        } else {
            [$ph, $vals] = db_in($ids);
            $branches = db_all("SELECT * FROM branches WHERE id IN ($ph)", $vals);
            $nvrs     = db_all("SELECT * FROM nvrs WHERE branch_id IN ($ph) ORDER BY name", $vals);
            $managedUsers = db_all(
                "SELECT DISTINCT u.* FROM users u
                   JOIN user_branches ub ON u.id = ub.user_id
                  WHERE ub.branch_id IN ($ph) AND u.role != 'superadmin'",
                $vals
            );
        }
    } else {
        $branches = db_all("SELECT * FROM branches");
        $nvrs     = db_all("SELECT * FROM nvrs ORDER BY name");
        $managedUsers = [];
    }

    // Har bir managed user uchun filiallar va NVRlarni yuklash
    foreach ($managedUsers as &$mu) {
        $mu['branches'] = db_all(
            "SELECT b.* FROM branches b JOIN user_branches ub ON b.id = ub.branch_id WHERE ub.user_id = ?",
            [$mu['id']]
        );
        $mu['nvrs'] = db_all(
            "SELECT n.* FROM nvrs n JOIN user_nvrs un ON n.id = un.nvr_id WHERE un.user_id = ?",
            [$mu['id']]
        );
    }
    unset($mu);

    echo render('manage.php', [
        'user' => $user, 'branches' => $branches,
        'nvrs' => $nvrs, 'managed_users' => $managedUsers,
    ]);
}

// ── SAHIFA: ADMIN ─────────────────────────────────────────────────────────
function page_admin(array $user): void
{
    $branches = db_all("SELECT * FROM branches ORDER BY name");
    foreach ($branches as &$b) {
        $b['nvr_count'] = (int) db_val("SELECT COUNT(*) FROM nvrs WHERE branch_id = ?", [$b['id']]);
    }
    unset($b);

    $users = db_all("SELECT * FROM users ORDER BY id");
    foreach ($users as &$u) {
        $u['branches'] = db_all(
            "SELECT b.* FROM branches b JOIN user_branches ub ON b.id = ub.branch_id WHERE ub.user_id = ?",
            [$u['id']]
        );
        $u['branch_ids'] = array_map(fn($r) => (int) $r['id'], $u['branches']);
    }
    unset($u);

    $nvrs = db_all("SELECT * FROM nvrs ORDER BY name");

    echo render('admin.php', [
        'user' => $user, 'branches' => $branches, 'users' => $users, 'nvrs' => $nvrs,
    ]);
}

// ── SAHIFA: CAMERA ─────────────────────────────────────────────────────────
function page_camera(array $user, int $camId): void
{
    $r = scoped_camera($user, $camId);
    if (!$r) {
        http_response_code(404);
        echo "404 — kamera topilmadi";
        exit;
    }
    $branches = scoped_branches($user);

    $camera = [
        'id'         => (int) $r['id'],
        'name'       => $r['name'],
        'channel_ip' => $r['channel_ip'],
        'hik_code'   => $r['hik_code'],
    ];
    $nvr    = $r['nvr_id'] !== null ? ['name' => $r['nvr_name'], 'hik_code' => $r['nvr_hik_code']] : null;
    $branch = $r['branch_id'] !== null ? ['name' => $r['branch_name']] : null;

    echo render('camera_detail.php', [
        'user' => $user, 'camera' => $camera, 'nvr' => $nvr, 'branch' => $branch, 'branches' => $branches,
    ]);
}
