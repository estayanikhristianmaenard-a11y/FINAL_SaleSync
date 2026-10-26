<?php
declare(strict_types=1);

namespace SaleSync\Core;

final class Session
{
    public static function start(array $config): void
    {
        // A shared host may already have started the agreed session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $directory = $config['session_directory'] ?? dirname($config['database']) . '/sessions';
            if (!is_dir($directory)) { mkdir($directory, 0700, true); }
            session_save_path($directory);
            session_name($config['session_name'] ?? 'salesync');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => (bool) $config['secure_cookies'],
                'samesite' => 'Lax',
                'path' => $config['session_cookie_path'] ?? (parse_url($config['app_url'], PHP_URL_PATH) ?: '/'),
            ]);
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
        if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 7200) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_active'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf'], $token)) {
            throw new ApiError(419, 'Your session changed. Refresh the session and try again.');
        }
    }

    public static function login(int $userId, int $authVersion = 0): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_version'] = $authVersion;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'last_active' => time()];
        session_regenerate_id(true);
    }
}
