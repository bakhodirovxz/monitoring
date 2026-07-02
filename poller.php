<?php
/**
 * HikCentral Professional kamera holati pollери.
 *
 * Ishlatish:
 *   php poller.php           — uzluksiz halqada ishlaydi (systemd/CLI)
 *   php poller.php --once     — bir marta tekshirib chiqadi (cron uchun)
 *
 * cron misoli (har daqiqada):
 *   * * * * * /usr/bin/php /path/to/poller.php --once >> /var/log/hcp_poller.log 2>&1
 *
 * Holat (prev_status, offline_since) bazadan olinadi — xotira talab qilmaydi,
 * shuning uchun --once rejimi cron bilan to'g'ri ishlaydi.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hik.php';

const ISAPI_STATE_FILE = __DIR__ . '/.isapi_state.json';

// ── ISAPI kanal IP'lari (vaqtinchalik keshlangan) ──────────────────────────
function isapi_cam_ips(): array
{
    $h     = cfg()['hik'];
    $state = is_file(ISAPI_STATE_FILE) ? json_decode(file_get_contents(ISAPI_STATE_FILE), true) : [];
    $ts    = $state['ts'] ?? 0;
    $ips   = $state['ips'] ?? [];

    if (time() - $ts > $h['isapi_refresh'] || !$ips) {
        $sid   = hcp_login();
        $fresh = $sid ? hik_fetch_cam_ips($sid) : [];
        if ($fresh) {
            $ips = $fresh;
            file_put_contents(ISAPI_STATE_FILE, json_encode(['ts' => time(), 'ips' => $ips]));
        }
    }
    return $ips;
}

// ── Bitta poll sikli ──────────────────────────────────────────────────────
function poll_once(): void
{
    $camIps      = isapi_cam_ips();
    $nvrsRaw     = hik_fetch_nvrs();
    $regionsRaw  = hik_fetch_regions();
    $camerasRaw  = hik_fetch_cameras($nvrsRaw, $regionsRaw, $camIps);
    if (!$camerasRaw) {
        return;
    }

    $nowUtc = now_utc();

    // NVR + kameralarni bazaga sinxronlash
    hik_sync_nvrs();
    hik_sync_cameras($camerasRaw);

    // Joriy (bazadagi) holatni o'qib olamiz — oldingi holat sifatida
    $dbCams = [];
    foreach (db_all("SELECT id, hik_code, current_status FROM cameras") as $r) {
        $dbCams[$r['hik_code']] = $r;
    }

    foreach ($camerasRaw as $idx => $info) {
        $dbCam = $dbCams[$idx] ?? null;
        if (!$dbCam) {
            continue; // sync'dan keyin bo'lishi kerak, ehtiyot uchun
        }
        $camId = (int) $dbCam['id'];
        $oldSt = (int) $dbCam['current_status'];
        $newSt = (int) $info['status'];

        if ($oldSt === $newSt) {
            continue;
        }

        if ($oldSt !== 2 && $newSt === 2) {
            // ── OFFLINE bo'ldi ──
            db_exec("UPDATE cameras SET current_status = 2, last_status_change = ? WHERE id = ?",
                    [$nowUtc, $camId]);
            db_insert("INSERT INTO camera_events (camera_id, event_type, started_at) VALUES (?, 'offline', ?)",
                      [$camId, $nowUtc]);
            notify_offline($info);
        } elseif ($oldSt === 2 && $newSt !== 2) {
            // ── ONLINE bo'ldi ──
            db_exec("UPDATE cameras SET current_status = ?, last_status_change = ? WHERE id = ?",
                    [$newSt, $nowUtc, $camId]);

            // Ochiq offline eventlarni yopamiz (eng so'nggisida davomiylik)
            $open = db_all(
                "SELECT id, started_at FROM camera_events
                  WHERE camera_id = ? AND event_type='offline' AND ended_at IS NULL
                  ORDER BY started_at DESC",
                [$camId]
            );
            $durSec = null;
            foreach ($open as $i => $ev) {
                if ($i === 0) {
                    $durSec = strtotime($nowUtc . ' UTC') - strtotime($ev['started_at'] . ' UTC');
                    db_exec("UPDATE camera_events SET ended_at = ?, duration_sec = ? WHERE id = ?",
                            [$nowUtc, $durSec, $ev['id']]);
                } else {
                    db_exec("UPDATE camera_events SET ended_at = ? WHERE id = ?", [$nowUtc, $ev['id']]);
                }
            }
            db_insert("INSERT INTO camera_events (camera_id, event_type, started_at) VALUES (?, 'online', ?)",
                      [$camId, $nowUtc]);
            notify_online($info, $durSec);
        } else {
            // Unknown <-> boshqa o'zgarishlar (event'siz holat yangilash)
            db_exec("UPDATE cameras SET current_status = ?, last_status_change = ? WHERE id = ?",
                    [$newSt, $nowUtc, $camId]);
        }
    }

    echo '[' . date('d.m.Y H:i:s') . "] poll tugadi: " . count($camerasRaw) . " ta kamera\n";
}

// ── Telegram xabarlari ──────────────────────────────────────────────────
function _local_ts(): string
{
    return date('d.m.Y H:i:s', time() + tz_offset_seconds());
}

function notify_offline(array $info): void
{
    $ges = $info['area'] ?: $info['nvrName'];
    $ip  = $info['channelIp'] ?: $info['nvrIp'];
    tg_send(
        "📵 <b>Camera Offline</b>\n" .
        "━━━━━━━━━━━━━━━━━━━\n" .
        "📹 <b>Kamera:</b> {$info['name']}\n" .
        "📍 <b>Ges:</b> {$ges}\n" .
        "🌐 <b>IP (Kamera):</b> <code>{$ip}</code>\n" .
        "⏰ <b>Uzilish vaqti:</b> " . _local_ts()
    );
}

function notify_online(array $info, ?float $durSec): void
{
    $ges = $info['area'] ?: $info['nvrName'];
    $ip  = $info['channelIp'] ?: $info['nvrIp'];
    $dur = $durSec ? "\n⏱ <b>Offline turdi:</b> " . fmt_dur($durSec) : '';
    tg_send(
        "✅ <b>Camera Online</b>\n" .
        "━━━━━━━━━━━━━━━━━━━\n" .
        "📹 <b>Kamera:</b> {$info['name']}\n" .
        "📍 <b>Ges:</b> {$ges}\n" .
        "🌐 <b>IP (Kamera):</b> <code>{$ip}</code>\n" .
        "⏰ <b>Online vaqti:</b> " . _local_ts() .
        $dur
    );
}

// ── Dastlabki ishga tushish (faqat loop rejimida) ──────────────────────────
function startup_seed(): void
{
    hik_sync_nvrs();

    $camIps     = isapi_cam_ips();
    $nvrsRaw    = hik_fetch_nvrs();
    $regionsRaw = hik_fetch_regions();
    $camerasRaw = hik_fetch_cameras($nvrsRaw, $regionsRaw, $camIps);
    if (!$camerasRaw) {
        return;
    }

    hik_sync_cameras($camerasRaw);
    $nowUtc = now_utc();

    $totalOff = $totalOn = 0;
    foreach ($camerasRaw as $idx => $info) {
        $cam = db_one("SELECT id FROM cameras WHERE hik_code = ?", [$idx]);
        if (!$cam) continue;
        $camId = (int) $cam['id'];
        $st    = (int) $info['status'];

        db_exec("UPDATE cameras SET current_status = ?, last_status_change = ? WHERE id = ?",
                [$st, $nowUtc, $camId]);

        if ($st === 2) {
            $totalOff++;
            $open = db_all(
                "SELECT id FROM camera_events
                  WHERE camera_id = ? AND event_type='offline' AND ended_at IS NULL
                  ORDER BY started_at ASC",
                [$camId]
            );
            if (count($open) === 0) {
                db_insert("INSERT INTO camera_events (camera_id, event_type, started_at) VALUES (?, 'offline', ?)",
                          [$camId, $nowUtc]);
            } elseif (count($open) > 1) {
                // Eng eski N-1 tasini yopamiz, yagona ochiq qoldiramiz
                $toClose = array_slice($open, 0, -1);
                foreach ($toClose as $ev) {
                    db_exec("UPDATE camera_events SET ended_at = ? WHERE id = ?", [$nowUtc, $ev['id']]);
                }
            }
        } else {
            if ($st === 1) $totalOn++;
            // Online kameralarda ochiq offline event qolsa yopamiz
            db_exec("UPDATE camera_events SET ended_at = ?
                      WHERE camera_id = ? AND event_type='offline' AND ended_at IS NULL",
                    [$nowUtc, $camId]);
        }
    }

    tg_send(
        "✅ <b>HikCentral Monitor ishga tushdi</b>\n" .
        "📹 Jami kamera: <b>" . count($camerasRaw) . "</b> ta\n" .
        "🟢 Online: <b>{$totalOn}</b> ta\n" .
        "🔴 Offline: <b>{$totalOff}</b> ta"
    );
}

// ── ENTRY POINT ─────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Faqat CLI orqali ishlatiladi";
    exit;
}

$once = in_array('--once', $argv, true);

if ($once) {
    try {
        poll_once();
    } catch (Throwable $ex) {
        fwrite(STDERR, "[poll xatosi] " . $ex->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// Uzluksiz halqa
echo "HCP poller ishga tushdi (loop). To'xtatish: Ctrl+C\n";
$interval = (int) cfg()['hik']['poll_interval'];

try {
    startup_seed();
} catch (Throwable $ex) {
    fwrite(STDERR, "[startup xatosi] " . $ex->getMessage() . "\n");
}

while (true) {
    sleep($interval);
    try {
        poll_once();
    } catch (Throwable $ex) {
        fwrite(STDERR, "[poll xatosi] " . $ex->getMessage() . "\n");
    }
}
