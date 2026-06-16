<?php
function requisicaoHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto !== '' && in_array('https', array_map('trim', explode(',', $proto)), true)) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

function ambienteLocal(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function forcarHttps(): void
{
    if (requisicaoHttps() || ambienteLocal() || headers_sent()) {
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    if ($host !== '') {
        header('Location: https://' . $host . $uri, true, 302);
        exit;
    }
}

function aplicarCabecalhosSeguros(bool $privado = true): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), geolocation=(self), microphone=()');

    if (requisicaoHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if ($privado) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

function iniciarSessaoSegura(bool $privado = true): void
{
    forcarHttps();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => requisicaoHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', requisicaoHttps(), true);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    aplicarCabecalhosSeguros($privado);
}
