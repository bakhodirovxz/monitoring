<?php
/**
 * Autentifikatsiya: JWT (HS256) + bcrypt.
 *
 *   auth_hash_password($plain)            -> bcrypt hash
 *   auth_verify_password($plain, $hash)   -> bool
 *   auth_create_token(['sub'=>$username]) -> JWT string
 *   auth_current_user($token)             -> user qatori yoki null
 */

require_once __DIR__ . '/db.php';

function _auth_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = (require __DIR__ . '/config.php')['auth'];
    }
    return $cfg;
}

// ── PAROL ──────────────────────────────────────────────────────────────
function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function auth_verify_password(string $plain, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    return password_verify($plain, $hash);
}

// ── JWT (HS256) ─────────────────────────────────────────────────────────
function _b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function auth_create_token(array $data): string
{
    $cfg = _auth_cfg();

    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = $data;
    $payload['exp'] = time() + $cfg['token_ttl'] * 3600;

    $segments = [
        _b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES)),
        _b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signing = implode('.', $segments);
    $sig     = hash_hmac('sha256', $signing, $cfg['secret_key'], true);
    $segments[] = _b64url_encode($sig);

    return implode('.', $segments);
}

function auth_decode_token(?string $token): ?array
{
    if (!$token) {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h64, $p64, $s64] = $parts;

    $cfg      = _auth_cfg();
    $expected = _b64url_encode(hash_hmac('sha256', "$h64.$p64", $cfg['secret_key'], true));
    if (!hash_equals($expected, $s64)) {
        return null;
    }

    $payload = json_decode(_b64url_decode($p64), true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
        return null;
    }
    return $payload;
}

// ── JORIY FOYDALANUVCHI ─────────────────────────────────────────────────
function auth_current_user(?string $token): ?array
{
    $payload = auth_decode_token($token);
    if (!$payload || empty($payload['sub'])) {
        return null;
    }
    return db_one(
        "SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1",
        [$payload['sub']]
    );
}
