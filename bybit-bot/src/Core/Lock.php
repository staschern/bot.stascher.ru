<?php
declare(strict_types=1);

namespace BybitBot\Core;

use RuntimeException;

/**
 * Файловый лок (flock) для cron-скриптов.
 *
 * Предотвращает повторный вход того же скрипта, если предыдущий запуск ещё не завершился
 * (например, минутный скрипт работает > 60 секунд).
 *
 * См. spec.md §2.2 — все cron-скрипты защищены flock.
 *
 * Использование:
 *   $lock = new Lock(__DIR__ . '/../data/locks/cron_minute.lock');
 *   if (!$lock->acquire()) { exit(0); }  // другой запуск уже идёт
 *   // ... работа ...
 *   $lock->release();  // или просто выйти из скрипта — лок освободится при close
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    /** @var bool */
    private $acquired = false;

    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог для лока: {$dir}");
        }
    }

    public function acquire(bool $blocking = false): bool
    {
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл лока: {$this->path}");
        }

        $flags = LOCK_EX | ($blocking ? 0 : LOCK_NB);
        $wouldBlock = 0;
        if (!flock($handle, $flags, $wouldBlock)) {
            fclose($handle);
            return false;
        }

        // Записать PID — полезно для диагностики "кто держит лок".
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid() . "\n");
        fflush($handle);

        $this->handle   = $handle;
        $this->acquired = true;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            if ($this->acquired) {
                flock($this->handle, LOCK_UN);
            }
            fclose($this->handle);
            $this->handle = null;
        }
        $this->acquired = false;
    }

    public function __destruct()
    {
        $this->release();
    }
}
