<?php
/**
 * HikCentral OpenAPI (Artemis) va Web (ISAPI) bilan ishlash.
 * index.php (snapshot) va poller.php tomonidan ishlatiladi.
 *
 * Eslatma: PHP 8.0+ da curl_close() hech narsa qilmaydi (8.5'da deprecated),
 * shuning uchun ishlatilmaydi — CurlHandle obyekti avtomatik tozalanadi.
 */

require_once __DIR__ . '/helpers.php';

function _hik_cfg(): array
{
    return cfg()['hik'];
}

// ── OPENAPI IMZO ───────────────────────────────────────────────────────
function _hik_headers(string $path): array
{
    $h = _hik_cfg();
    $s = "POST\n*/*\napplication/json\nx-ca-key:{$h['app_key']}\n{$path}";
    $sig = base64_encode(hash_hmac('sha256', $s, $h['secret_key'], true));
    return [
        'Content-Type: application/json',
        'Accept: */*',
        "x-ca-key: {$h['app_key']}",
        "x-ca-signature: {$sig}",
        'x-ca-signature-headers: x-ca-key',
        'userId: ' . $h['hcp_user'],
    ];
}

/**
 * Artemis OpenAPI POST so'rovi. Muvaffaqiyatda dekod qilingan massiv, aks holda null.
 * $timeout — javob kutish vaqti (snapshot/capture sekin, shuning uchun oshiriladi).
 */
function hik(string $path, array $body = [], int $timeout = 30): ?array
{
    $h   = _hik_cfg();
    $url = "{$h['host']}:{$h['port']}{$path}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => _hik_headers($path),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);

    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// ── HCP WEB LOGIN (ISAPI) ───────────────────────────────────────────────
function _web_headers(): array
{
    $h = _hik_cfg();
    return [
        'Accept: application/xml, text/xml, */*;',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:151.0) Gecko/20100101 Firefox/151.0',
        'Referer: ' . $h['host'] . '/',
        'Origin: ' . $h['host'],
    ];
}

/** HikCentral web sessiyasiga kirib SID cookie qaytaradi (bo'sh = muvaffaqiyatsiz). */
function hcp_login(): string
{
    $h = _hik_cfg();

    // 1) CRYPTO kalitini olish
    $ch = curl_init($h['host'] . '/ISAPI/Bumblebee/Platform/V0/Security/Crypto?MT=GET');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => _web_headers(),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        return '';
    }
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headers = substr($resp, 0, $hdrSize);
    $bodyStr = substr($resp, $hdrSize);

    $cryptoCookie = _extract_cookie($headers, 'CRYPTO');
    $j            = json_decode($bodyStr, true);
    $cryptoKey    = $j['ResponseStatus']['Data']['CryptoResponse']['CryptoKey'] ?? '';
    if (!$cryptoCookie || !$cryptoKey) {
        return '';
    }

    // 2) Parolni public key bilan shifrlash (RSA PKCS#1 v1.5)
    //    HikCentral PKCS#1 (RSAPublicKey) formatida kalit beradi; PHP openssl uni
    //    to'g'ridan-to'g'ri qabul qilmaydi — SPKI (PUBLIC KEY) ga o'rab beramiz.
    $pub = _rsa_public_from_pkcs1(base64_decode($cryptoKey));
    if ($pub === false) {
        return '';
    }
    $enc = '';
    if (!openssl_public_encrypt($h['hcp_pass'], $enc, $pub, OPENSSL_PKCS1_PADDING)) {
        return '';
    }
    $encPw = base64_encode($enc);

    // 3) Login
    $loginBody = json_encode([
        'LoginRequest' => [
            'UserName'      => $h['hcp_user'],
            'Password'      => $encPw,
            'LoginAddress'  => preg_replace('#^https?://#', '', $h['host']),
            'LoginModel'    => 1,
            'IsRSMWebLogin' => 0,
        ],
    ]);

    $ch = curl_init($h['host'] . '/ISAPI/Bumblebee/Platform/V0/Login?CT=0&MT=POST');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $loginBody,
        CURLOPT_HTTPHEADER     => array_merge(_web_headers(), [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            "Cookie: CRYPTO={$cryptoCookie}",
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        return '';
    }
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headers = substr($resp, 0, $hdrSize);
    $bodyStr = substr($resp, $hdrSize);
    $j       = json_decode($bodyStr, true);
    $ec      = $j['ResponseStatus']['ErrorCode'] ?? -1;
    $sid     = _extract_cookie($headers, 'SID');

    return ($ec === 0 && $sid) ? $sid : '';
}

/**
 * PKCS#1 (RSAPublicKey) DER kalitini SPKI (SubjectPublicKeyInfo) PEM ga o'rab,
 * openssl public key obyektini qaytaradi. Xato bo'lsa false.
 *
 * HikCentral CryptoKey'ni PKCS#1 DER (base64) sifatida beradi; PHP'ning openssl'i
 * SPKI ("PUBLIC KEY") kutadi, shuning uchun standart AlgorithmIdentifier qo'shamiz.
 */
function _rsa_public_from_pkcs1(string $pkcs1Der)
{
    $derLen = function (int $n): string {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = '';
        while ($n > 0) {
            $b = chr($n & 0xff) . $b;
            $n >>= 8;
        }
        return chr(0x80 | strlen($b)) . $b;
    };

    // AlgorithmIdentifier: SEQUENCE { OID rsaEncryption, NULL }
    $algoId = hex2bin('300d06092a864886f70d0101010500');
    // BIT STRING (0 padding) o'ralgan PKCS#1 kalit
    $bitStr = "\x03" . $derLen(strlen($pkcs1Der) + 1) . "\x00" . $pkcs1Der;
    // SEQUENCE { algoId, bitStr }
    $spki = "\x30" . $derLen(strlen($algoId) + strlen($bitStr)) . $algoId . $bitStr;

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** Cookie qiymatini Set-Cookie sarlavhalaridan ajratib oladi. */
function _extract_cookie(string $headers, string $name): string
{
    if (preg_match('/Set-Cookie:\s*' . preg_quote($name, '/') . '=([^;\s]+)/i', $headers, $m)) {
        return $m[1];
    }
    return '';
}

// ── ISAPI KANAL IP'lari ──────────────────────────────────────────────────
/** SID orqali kamera kanal IP'larini oladi: [hik_code => ip]. */
function hik_fetch_cam_ips(string $sid): array
{
    if (!$sid) {
        return [];
    }
    $h      = _hik_cfg();
    $url    = $h['host'] . '/ISAPI/Bumblebee/ResourceMaintain/V0/StatusMonitor/CameraElements?MT=GET';
    $result = [];
    $page   = 1;

    while (true) {
        $body = json_encode([
            'CameraElementStatusRequest' => [
                'PageSize'  => 200,
                'PageIndex' => $page,
                'SearchCriteria' => ['AreaID' => -1, 'SiteID' => 0, 'Alias' => '', 'DepthTraversal' => 0],
                'StatusType' => -1,
                'Sort'       => ['SortField' => -1, 'SortType' => false],
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                "Cookie: SID={$sid}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($resp === false || $code !== 200) {
            break;
        }
        $j     = json_decode($resp, true);
        $cl    = $j['ResponseStatus']['Data']['CameraElementStatusList'] ?? [];
        $cams  = $cl['CameraElementStatus'] ?? [];
        $total = $cl['TotalNum'] ?? 0;

        foreach ($cams as $c) {
            $cid = (string) ($c['ID'] ?? '');
            $ip  = $c['AddressChannel'] ?? '';
            if ($cid !== '' && $ip !== '') {
                $result[$cid] = $ip;
            }
        }
        if (!$cams || count($cams) < 200 || ($total > 0 && count($result) >= $total)) {
            break;
        }
        $page++;
    }
    return $result;
}

// ── OPENAPI MA'LUMOTLARI ──────────────────────────────────────────────────
/** NVR ro'yxati: [hik_code => ['name'=>, 'ip'=>]]. */
function hik_fetch_nvrs(): array
{
    $nvrs = [];
    $page = 1;
    while (true) {
        $d = hik('/artemis/api/resource/v1/encodeDevice/encodeDeviceList',
                 ['pageNo' => $page, 'pageSize' => 500]);
        if (!$d || (string) ($d['code'] ?? '') !== '0') {
            break;
        }
        $list = $d['data']['list'] ?? [];
        foreach ($list as $item) {
            $nvrs[$item['encodeDevIndexCode']] = [
                'name' => $item['encodeDevName'] ?? '',
                'ip'   => $item['encodeDevIp'] ?? '',
            ];
        }
        if (count($nvrs) >= ($d['data']['total'] ?? 0) || !$list) {
            break;
        }
        $page++;
    }
    return $nvrs;
}

/** Hududlar (regions): [indexCode => name]. */
function hik_fetch_regions(): array
{
    $regions = [];
    $page = 1;
    while (true) {
        $d = hik('/artemis/api/resource/v1/regions', ['pageNo' => $page, 'pageSize' => 500]);
        if (!$d || (string) ($d['code'] ?? '') !== '0') {
            break;
        }
        $list = $d['data']['list'] ?? [];
        foreach ($list as $r) {
            $regions[$r['indexCode']] = $r['name'] ?? '';
        }
        if (count($regions) >= ($d['data']['total'] ?? 0) || !$list) {
            break;
        }
        $page++;
    }
    return $regions;
}

/**
 * Kameralar ro'yxati: [hik_code => [...info...]].
 * $camIps — ISAPI dan olingan kanal IP'lari.
 */
function hik_fetch_cameras(array $nvrs, array $regions, array $camIps): array
{
    $h       = _hik_cfg();
    $cameras = [];
    $page    = 1;
    while (true) {
        $d = hik('/artemis/api/resource/v1/camera/advance/cameraList',
                 ['pageNo' => $page, 'pageSize' => $h['page_size'], 'bRecordSetting' => 0]);
        if (!$d || (string) ($d['code'] ?? '') !== '0') {
            break;
        }
        $batch = $d['data']['list'] ?? [];
        $total = $d['data']['total'] ?? 0;
        foreach ($batch as $c) {
            $idx     = $c['cameraIndexCode'] ?? '';
            $nvrCode = $c['encodeDevIndexCode'] ?? '';
            $nvrInfo = $nvrs[$nvrCode] ?? [];
            $cameras[$idx] = [
                'name'      => $c['cameraName'] ?? $idx,
                'status'    => $c['status'] ?? 0,
                'nvr_code'  => $nvrCode,
                'nvrName'   => $nvrInfo['name'] ?? '',
                'nvrIp'     => $nvrInfo['ip'] ?? '',
                'area'      => $regions[$c['regionIndexCode'] ?? ''] ?? '',
                'channelIp' => $camIps[$idx] ?? '',
            ];
        }
        if (count($cameras) >= $total || !$batch) {
            break;
        }
        $page++;
    }
    return $cameras;
}

// ── DB SYNC ────────────────────────────────────────────────────────────
/** NVRlarni HikCentral dan bazaga upsert qiladi. */
function hik_sync_nvrs(): void
{
    $raw = hik_fetch_nvrs();
    foreach ($raw as $code => $info) {
        $nvr = db_one("SELECT id, name_overridden FROM nvrs WHERE hik_code = ?", [$code]);
        if ($nvr) {
            if (!$nvr['name_overridden']) {
                db_exec("UPDATE nvrs SET name = ?, ip = ? WHERE id = ?",
                        [$info['name'], $info['ip'], $nvr['id']]);
            } else {
                db_exec("UPDATE nvrs SET ip = ? WHERE id = ?", [$info['ip'], $nvr['id']]);
            }
        } else {
            db_insert("INSERT INTO nvrs (hik_code, name, ip) VALUES (?, ?, ?)",
                      [$code, $info['name'], $info['ip']]);
        }
    }
}

/** Kameralarni HikCentral dan bazaga upsert qiladi. */
function hik_sync_cameras(array $camerasRaw): void
{
    foreach ($camerasRaw as $code => $info) {
        $nvr = db_one("SELECT id FROM nvrs WHERE hik_code = ?", [$info['nvr_code']]);
        $nvrId = $nvr ? (int) $nvr['id'] : null;

        $cam = db_one("SELECT id, name_overridden FROM cameras WHERE hik_code = ?", [$code]);
        if ($cam) {
            if (!$cam['name_overridden']) {
                db_exec("UPDATE cameras SET name = ?, channel_ip = ?, nvr_id = COALESCE(?, nvr_id) WHERE id = ?",
                        [$info['name'], $info['channelIp'], $nvrId, $cam['id']]);
            } else {
                db_exec("UPDATE cameras SET channel_ip = ?, nvr_id = COALESCE(?, nvr_id) WHERE id = ?",
                        [$info['channelIp'], $nvrId, $cam['id']]);
            }
        } else {
            db_insert("INSERT INTO cameras (hik_code, name, channel_ip, nvr_id) VALUES (?, ?, ?, ?)",
                      [$code, $info['name'], $info['channelIp'], $nvrId]);
        }
    }
}

// ── SNAPSHOT ─────────────────────────────────────────────────────────────
/** Snapshot fayllari katalogi (yo'q bo'lsa yaratiladi). */
function snap_dir(): string
{
    $dir = __DIR__ . '/static/snapshots';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Bitta kamera uchun HikCentral'dan snapshot olib diskка saqlaydi.
 * Qaytaradi: ['ok'=>bool, 'error'=>string].
 * $retries — beqaror HikCentral uchun qayta urinishlar soni.
 */
function hik_capture_snapshot(int $camId, string $hikCode, int $retries = 1): array
{
    $result = null;
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $result = hik('/artemis/api/video/v1/camera/capture', ['cameraIndexCode' => $hikCode], 60);

        if ($result && (string) ($result['code'] ?? '') === '0') {
            $data = $result['data'] ?? '';
            if ($data === '') {
                return ['ok' => false, 'error' => "Bo'sh rasm"];
            }
            if (str_contains($data, ',')) {
                $data = explode(',', $data, 2)[1];
            }
            $img = base64_decode($data);
            if ($img === false || $img === '') {
                return ['ok' => false, 'error' => 'Rasm dekodlanmadi'];
            }
            file_put_contents(snap_dir() . "/{$camId}.jpg", $img);
            return ['ok' => true, 'error' => ''];
        }

        // Muvaffaqiyatsiz — qayta urinishdan oldin biroz kutamiz
        if ($attempt < $retries) {
            usleep(500000); // 0.5s
        }
    }

    $err = $result === null ? 'HikCentral javob bermadi (timeout)' : ($result['msg'] ?? 'Noma\'lum xato');
    return ['ok' => false, 'error' => $err];
}

// ── TELEGRAM ─────────────────────────────────────────────────────────────
function tg_send(string $text): void
{
    $t = cfg()['telegram'];
    if (!$t['token'] || !$t['chat_id']) {
        return;
    }
    $ch = curl_init("https://api.telegram.org/bot{$t['token']}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'    => $t['chat_id'],
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
}
