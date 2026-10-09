<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'Aurelle\\')) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) require $file;
    }
});
date_default_timezone_set('UTC');
function env(string $name, string $default = ''): string { return getenv($name) !== false ? getenv($name) : $default; }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int|string $value): string { return '$' . number_format((int)$value / 100, 0); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf(): string { return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">'; }
function flash(string $text): void { $_SESSION['flash'] = $text; }
if (PHP_SAPI !== 'cli') {
    $sessionDirectory=ROOT.'/storage/sessions';
    if (!is_dir($sessionDirectory)) mkdir($sessionDirectory,0700,true);
    session_save_path($sessionDirectory);
    session_name('aurelle_session');
    session_set_cookie_params(['httponly'=>true, 'secure'=>str_starts_with(env('APP_URL'), 'https://'), 'samesite'=>'Lax', 'path'=>'/']);
    ini_set('session.use_strict_mode', '1');
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' https://images.unsplash.com data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self' " . env('WS_URL', 'ws://127.0.0.1:8081') . "; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
