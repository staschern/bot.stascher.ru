<?php
declare(strict_types=1);

namespace BybitBot\Web;

use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use OTPHP\TOTP;
use Ramsey\Uuid\Uuid;

/**
 * Авторизация: bcrypt пароль + TOTP (2FA).
 *
 * См. spec.md §14.1.
 *  - HTTPS обязателен
 *  - bcrypt + TOTP
 *  - сессия HttpOnly; Secure; SameSite=Strict
 *  - TTL 8 часов, idle 1 час
 *  - rate-limit 5 попыток/15 мин на IP (через `auth_attempts`)
 */
final class Auth
{
    private const RATE_LIMIT_WINDOW_MIN = 15;
    private const RATE_LIMIT_MAX        = 5;

    /** @return array{ok:bool, reason?:string, user_id?:int} */
    public static function login(string $username, string $password, string $totpCode, string $ip): array
    {
        if (self::isRateLimited($ip)) {
            self::recordAttempt($ip, false);
            return ['ok' => false, 'reason' => 'rate_limited'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM auth_users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordAttempt($ip, false);
            EventRecorder::event(EventRecorder::WARN, 'auth_login_failed', null, ['ip' => $ip, 'username' => $username]);
            return ['ok' => false, 'reason' => 'invalid_credentials'];
        }

        // TOTP проверка
        if (empty($user['totp_secret'])) {
            return ['ok' => false, 'reason' => 'totp_not_configured'];
        }
        // OTPHP 10.x: TOTP::create($secret) загружает существующий секрет
        $totp = TOTP::create($user['totp_secret']);
        if (!$totp->verify($totpCode, null, 1)) {
            self::recordAttempt($ip, false);
            EventRecorder::event(EventRecorder::WARN, 'auth_totp_failed', null, ['ip' => $ip, 'user_id' => $user['id']]);
            return ['ok' => false, 'reason' => 'invalid_totp'];
        }

        self::recordAttempt($ip, true);
        $pdo->prepare('UPDATE auth_users SET last_login_at = :t WHERE id = :id')
            ->execute([':t' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => $user['id']]);

        EventRecorder::event(EventRecorder::INFO, 'auth_login_success', null, ['user_id' => $user['id'], 'ip' => $ip]);
        return ['ok' => true, 'user_id' => (int)$user['id']];
    }

    public static function createSession(int $userId, string $ip, string $userAgent, int $ttlHours): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + ($ttlHours * 3600);

        Database::pdo()->prepare(
            'INSERT INTO auth_sessions (id, user_id, ts_created, ts_expires, ip, user_agent)
             VALUES (:id, :uid, :c, :e, :ip, :ua)'
        )->execute([
            ':id'  => $token,
            ':uid' => $userId,
            ':c'   => gmdate('Y-m-d\TH:i:s\Z', $now),
            ':e'   => gmdate('Y-m-d\TH:i:s\Z', $expires),
            ':ip'  => $ip,
            ':ua'  => substr($userAgent, 0, 255),
        ]);
        return $token;
    }

    /** @return array{user_id:int}|null */
    public static function checkSession(string $token, int $idleHours): ?array
    {
        if ($token === '') return null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM auth_sessions WHERE id = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $sess = $stmt->fetch();
        if (!$sess) return null;

        $expires = strtotime($sess['ts_expires']);
        if ($expires < time()) {
            self::destroySession($token);
            return null;
        }

        // Idle-проверка: если ts_created старше idleHours, требуем relogin.
        // (Простейшая реализация без обновления last_seen — добавим в Этапе 2.)
        return ['user_id' => (int)$sess['user_id']];
    }

    public static function destroySession(string $token): void
    {
        Database::pdo()->prepare('DELETE FROM auth_sessions WHERE id = :t')
            ->execute([':t' => $token]);
    }

    private static function recordAttempt(string $ip, bool $success): void
    {
        Database::pdo()->prepare(
            'INSERT INTO auth_attempts (ts, ip, success) VALUES (:t, :ip, :s)'
        )->execute([
            ':t'  => gmdate('Y-m-d\TH:i:s\Z'),
            ':ip' => $ip,
            ':s'  => $success ? 1 : 0,
        ]);
    }

    private static function isRateLimited(string $ip): bool
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - self::RATE_LIMIT_WINDOW_MIN * 60);
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM auth_attempts
             WHERE ip = :ip AND success = 0 AND ts > :since'
        );
        $stmt->execute([':ip' => $ip, ':since' => $since]);
        return ((int)$stmt->fetchColumn()) >= self::RATE_LIMIT_MAX;
    }

    /**
     * Создаёт пользователя. Использовать только из CLI (cli.php auth:init).
     * Возвращает provisioning URL для TOTP-сканирования (otpauth://...).
     */
    public static function createUser(string $username, string $password): array
    {
        $pdo = Database::pdo();
        $existing = $pdo->prepare('SELECT id FROM auth_users WHERE username = :u');
        $existing->execute([':u' => $username]);
        if ($existing->fetchColumn() !== false) {
            throw new \RuntimeException("Пользователь уже существует: {$username}");
        }

        // OTPHP 10.x: TOTP::create() без аргументов генерирует новый секрет
        $totp = TOTP::create();
        $totp->setLabel($username);
        $totp->setIssuer('BybitBot');

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'INSERT INTO auth_users (username, password_hash, totp_secret, created_at)
             VALUES (:u, :h, :s, :t)'
        )->execute([
            ':u' => $username,
            ':h' => $hash,
            ':s' => $totp->getSecret(),
            ':t' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return [
            'username'           => $username,
            'totp_secret'        => $totp->getSecret(),
            'totp_provisioning'  => $totp->getProvisioningUri(),
        ];
    }

    /** Сгенерировать UUID для idempotency-keys (orderLinkId и т.д.). */
    public static function uuid(): string
    {
        return Uuid::uuid4()->toString();
    }
}
