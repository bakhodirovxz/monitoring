<?php
/**
 * HCP Monitor — konfiguratsiya namunasi.
 * Nusxa oling:  cp config.example.php config.php
 * va o'zingizning qiymatlaringizni kiriting.
 */

return [
    // ── MySQL ──────────────────────────────────────────────────────────
    'db' => [
        'host'    => 'localhost',
        'port'    => '3306',
        'name'    => 'hcp_monitor',
        'user'    => 'hcp_user',
        'pass'    => 'your_password_here',
        'charset' => 'utf8mb4',
    ],

    // ── Auth (JWT) ─────────────────────────────────────────────────────
    'auth' => [
        // MUHIM: uzun, tasodifiy maxfiy kalit qo'ying (masalan: openssl rand -hex 32)
        'secret_key' => 'change-me-to-a-long-random-secret',
        'token_ttl'  => 8, // soat
    ],

    // ── Mahalliy vaqt zonasi (UTC+5) ───────────────────────────────────
    'tz_offset_hours' => 5,

    // ── HikCentral OpenAPI / Web ───────────────────────────────────────
    'hik' => [
        'host'       => 'https://10.0.0.1',
        'port'       => 443,
        'app_key'    => 'your_app_key',
        'secret_key' => 'your_secret_key',
        'hcp_user'   => 'admin',
        'hcp_pass'   => 'your_hcp_password',
        'poll_interval' => 60,   // soniya
        'page_size'     => 200,
        'isapi_refresh' => 1200, // soniya
    ],

    // ── Telegram ───────────────────────────────────────────────────────
    'telegram' => [
        'token'   => 'your_telegram_bot_token',
        'chat_id' => 'your_chat_id',
    ],
];
