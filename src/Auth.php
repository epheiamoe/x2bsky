<?php

declare(strict_types=1);

namespace X2BSky;

class Auth
{
    private const SESSION_NAME = 'x2bsky_session';
    private const COOKIE_NAME = 'x2bsky_remember';
    private const COOKIE_EXPIRY = 86400 * 30;
    private const PASSWORD_HASH_FILE = '/data/.password_hash';

    private static ?string $passwordHash = null;

    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            if (isset($_COOKIE[self::COOKIE_NAME])) {
                $token = $_COOKIE[self::COOKIE_NAME];
                if (self::validateRememberToken($token)) {
                    $_SESSION['authenticated'] = true;
                    return true;
                }
                self::clearRememberCookie();
            }
            return false;
        }

        return true;
    }

    private static function getPasswordHash(): string
    {
        if (self::$passwordHash !== null) {
            return self::$passwordHash;
        }

        $hashFile = dirname(__DIR__) . self::PASSWORD_HASH_FILE;

        if (file_exists($hashFile)) {
            $contents = @file_get_contents($hashFile);
            if ($contents !== false) {
                self::$passwordHash = trim($contents);
                return self::$passwordHash;
            }
            Logger::error('Password hash file exists but is not readable', ['file' => $hashFile]);
            self::$passwordHash = '';
            return self::$passwordHash;
        }

        $fallback = Config::get('ADMIN_PASSWORD', 'x2bsky_admin');
        self::$passwordHash = password_hash($fallback, PASSWORD_DEFAULT);
        return self::$passwordHash;
    }

    public static function isPasswordFileBroken(): bool
    {
        $hashFile = dirname(__DIR__) . self::PASSWORD_HASH_FILE;
        return file_exists($hashFile) && !is_readable($hashFile);
    }

    public static function setPassword(string $password): bool
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $hashFile = dirname(__DIR__) . self::PASSWORD_HASH_FILE;

        if (file_put_contents($hashFile, $hash) !== false) {
            chmod($hashFile, 0600);
            self::$passwordHash = $hash;
            return true;
        }

        return false;
    }

    public static function verifyPassword(string $password): bool
    {
        $hash = self::getPasswordHash();
        return password_verify($password, $hash);
    }

    public static function login(string $password): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!self::verifyPassword($password)) {
            return false;
        }

        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        self::regenerateSession();

        return true;
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::clearRememberCookie();
    }

    public static function remember(string $password): bool
    {
        if (!self::verifyPassword($password)) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $expiry = time() + self::COOKIE_EXPIRY;

        $hashedToken = hash('sha256', $token);

        $tokenData = json_encode([
            'token' => $hashedToken,
            'expiry' => $expiry,
        ]);

        $tokenFile = dirname(__DIR__) . '/data/.remember_token';
        file_put_contents($tokenFile, $tokenData);
        chmod($tokenFile, 0600);

        setcookie(self::COOKIE_NAME, $token, $expiry, '/', '', true, true);

        return true;
    }

    private static function validateRememberToken(string $token): bool
    {
        $tokenFile = dirname(__DIR__) . '/data/.remember_token';

        if (!file_exists($tokenFile)) {
            return false;
        }

        $tokenData = json_decode(file_get_contents($tokenFile), true);

        if (!$tokenData || !isset($tokenData['expiry'])) {
            return false;
        }

        if (time() > $tokenData['expiry']) {
            @unlink($tokenFile);
            return false;
        }

        $hashedToken = hash('sha256', $token);

        return hash_equals($tokenData['token'] ?? '', $hashedToken);
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            setcookie(self::COOKIE_NAME, '', time() - 42000, '/');
        }
    }

    private static function regenerateSession(): void
    {
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            http_response_code(401);
            header('Location: login.php');
            exit;
        }
    }

    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = $_POST['csrf_token'] ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';
        return $token !== '' && hash_equals($stored, $token);
    }
}
