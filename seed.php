<?php
/**
 * Filiallarni avtomatik yaratib, NVRlarni IP (ikkinchi oktet) bo'yicha biriktiradi.
 * Default superadmin foydalanuvchini ham yaratadi (admin / admin123).
 *
 * Ishlatish:  php seed.php
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hik.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Faqat CLI orqali ishlatiladi";
    exit;
}

// ── FILIAL → IP (ikkinchi oktet diapazoni) ─────────────────────────────────
$BRANCH_MAP = [
    ["O'rta chirchiq", range(11, 15)],   // 10.11–15.x.x
    ['Chirchiq',        range(21, 26)],   // 10.21–26.x.x
    ['Qodiriya',        range(31, 35)],   // 10.31–35.x.x
    ['Toshkent',        range(41, 44)],   // 10.41–44.x.x
    ["Quyi bo'zsuv",    range(51, 56)],   // 10.51–56.x.x
    ['Farxod',          range(61, 69)],   // 10.61–69.x.x
    ['Samarqand',       range(71, 78)],   // 10.71–78.x.x
    ['Xisorak',         [81]],            // 10.81.x.x
    ["To'palang",       range(91, 99)],   // 10.91–99.x.x
    ['Andijon',         range(111, 114)], // 10.111–114.x.x
    ['Shaxrixon',       range(121, 124)], // 10.121–124.x.x
    ['Norin',           range(131, 135)], // 10.131–135.x.x
    ['Qamchiq',         [141]],           // 10.141.x.x
    ['Oxangaron',       [151]],           // 10.151.x.x
];

function second_octet(string $ip): ?int
{
    $parts = explode('.', trim($ip));
    return count($parts) >= 2 && is_numeric($parts[1]) ? (int) $parts[1] : null;
}

function find_branch_id(string $ip, array $branchMap, array $nameToId): ?int
{
    $octet = second_octet($ip);
    if ($octet === null) {
        return null;
    }
    foreach ($branchMap as [$name, $octets]) {
        if (in_array($octet, $octets, true)) {
            return $nameToId[$name] ?? null;
        }
    }
    return null;
}

echo str_repeat('=', 50) . "\n";

// 0. Default superadmin
if ((int) db_val("SELECT COUNT(*) FROM users") === 0) {
    db_insert(
        "INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, 'Super Admin', 'superadmin')",
        ['admin', auth_hash_password('admin123')]
    );
    echo "0. Default superadmin yaratildi:  admin / admin123\n";
}

// 1. Filiallar
echo "1. Filiallar yaratilmoqda...\n";
$nameToId = [];
foreach ($BRANCH_MAP as [$name, $octets]) {
    $existing = db_one("SELECT id FROM branches WHERE name = ?", [$name]);
    if ($existing) {
        $nameToId[$name] = (int) $existing['id'];
        echo "   · {$name} — allaqachon mavjud\n";
    } else {
        $nameToId[$name] = db_insert("INSERT INTO branches (name) VALUES (?)", [$name]);
        echo "   ✓ {$name} — yaratildi\n";
    }
}
echo "   Jami: " . count($nameToId) . " ta filial\n";

// 2. HikCentral dan NVR sinxronlash
echo "\n2. HikCentral dan NVRlar yuklanmoqda...\n";
hik_sync_nvrs();
$nvrs = db_all("SELECT * FROM nvrs");
echo "   " . count($nvrs) . " ta NVR topildi\n";

// 3. NVRlarni filiallarga biriktirish
echo "\n3. NVRlar filiallarga biriktirilmoqda...\n";
$matched   = 0;
$unmatched = [];
foreach ($nvrs as $nvr) {
    $bid = find_branch_id($nvr['ip'], $BRANCH_MAP, $nameToId);
    if ($bid) {
        db_exec("UPDATE nvrs SET branch_id = ? WHERE id = ?", [$bid, $nvr['id']]);
        $matched++;
        $bname = array_search($bid, $nameToId, true);
        echo "   ✓ " . ($nvr['name'] ?: $nvr['hik_code']) . "  ({$nvr['ip']})  →  {$bname}\n";
    } else {
        db_exec("UPDATE nvrs SET branch_id = NULL WHERE id = ?", [$nvr['id']]);
        $unmatched[] = ($nvr['name'] ?: $nvr['hik_code']) . " ({$nvr['ip']})";
    }
}

echo "\n   Biriktirildi: {$matched} ta NVR\n";
if ($unmatched) {
    echo "   Topilmadi (" . count($unmatched) . " ta):\n";
    foreach ($unmatched as $u) {
        echo "     - {$u}\n";
    }
}

// 4. Natija
echo "\n" . str_repeat('=', 50) . "\nNATIJA:\n";
foreach (db_all("SELECT * FROM branches ORDER BY name") as $b) {
    $cnt = (int) db_val("SELECT COUNT(*) FROM nvrs WHERE branch_id = ?", [$b['id']]);
    echo "  {$b['name']}: {$cnt} ta NVR\n";
}

echo "\nMuvaffaqiyatli yakunlandi!\n";
echo "Endi web serverni ishga tushiring:  php -S 0.0.0.0:8000 index.php\n";
echo "Va poller'ni:  php poller.php\n";
