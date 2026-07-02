<?php
/**
 * Kameralar uchun snapshot olib diskка saqlaydi (static/snapshots/<id>.jpg).
 *
 * Strategiya: BIR MARTA barcha kameralarni yuklab olish. Keyin avtomatik
 * yangilanish yo'q — foydalanuvchi kamera profilida "Yangilash" tugmasini bosib,
 * faqat o'sha bitta kamerani qayta oladi.
 *
 * Ishlatish:
 *   php snapshot.php             — snapshoti YO'Q kameralarni oladi (default, takror xavfsiz)
 *   php snapshot.php --all       — barcha kameralarni majburan qayta oladi
 *   php snapshot.php --online    — faqat online (status=1) kameralar
 *   php snapshot.php --limit=N   — bitta ishga tushishda eng ko'pi N ta kamera
 *
 * 1795 kamera × ~20s — to'liq yuklab olish UZOQ davom etadi. Katta partiyada
 * --limit bilan bo'lib-bo'lib ishga tushirish mumkin (har safar qolganini oladi,
 * chunki default rejimda mavjud snapshotlar o'tkazib yuboriladi).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hik.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Faqat CLI orqali ishlatiladi";
    exit;
}

set_time_limit(0);

// ── Argumentlar ─────────────────────────────────────────────────────────
$opts = getopt('', ['all', 'online', 'limit::']);
$forceAll   = isset($opts['all']);
$onlyOnline = isset($opts['online']);
$limit      = isset($opts['limit']) ? (int) $opts['limit'] : 0;

// ── Kameralar ro'yxati ──────────────────────────────────────────────────
$sql = "SELECT id, hik_code, name, current_status FROM cameras";
if ($onlyOnline) {
    $sql .= " WHERE current_status = 1";
}
$sql .= " ORDER BY id";
$cams = db_all($sql);

if (!$cams) {
    echo "Kamera topilmadi. Avval poller'ni ishga tushiring.\n";
    exit(0);
}

$dir = snap_dir();

$done = $failed = $skipped = 0;
$processed = 0;

echo "Snapshot olish boshlandi (" . count($cams) . " kameradan tanlanyapti)...\n";

foreach ($cams as $cam) {
    $camId = (int) $cam['id'];
    $path  = "$dir/{$camId}.jpg";

    // Default rejim: snapshoti bor kameralarni o'tkazib yuboramiz (bir martalik).
    if (!$forceAll && is_file($path)) {
        $skipped++;
        continue;
    }

    if ($limit > 0 && $processed >= $limit) {
        break;
    }
    $processed++;

    $res = hik_capture_snapshot($camId, $cam['hik_code'], 1);
    if ($res['ok']) {
        $done++;
        echo "  ✓ id={$camId} {$cam['name']}\n";
    } else {
        $failed++;
        echo "  ✗ id={$camId} {$cam['name']} — {$res['error']}\n";
    }
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "Yuklandi: {$done} | Xato: {$failed} | O'tkazib yuborildi (mavjud): {$skipped}\n";
if ($failed > 0) {
    echo "Xatolar HikCentral beqarorligidan — qayta ishga tushirsangiz qolganini oladi.\n";
}
