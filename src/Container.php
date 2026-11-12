<?php

declare(strict_types=1);

namespace App;

/**
 * Lazy service factory, one instance per process. Tests override services via set().
 */
final class Container
{
    public const ROOT = __DIR__ . '/..';

    /** @var array<string,mixed> */
    private array $services = [];

    public function __construct(private Config $cfg, ?Clock $clock = null, ?\PDO $db = null)
    {
        if ($clock) {
            $this->services['clock'] = $clock;
        }
        if ($db) {
            $this->services['db'] = $db;
        }
    }

    public static function boot(): self
    {
        $env = getenv('APP_ENV_FILE') ?: dirname(self::ROOT) . '/.env';

        return new self(Config::load($env));
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    /** @template T  @param callable():T $factory  @return T */
    private function once(string $id, callable $factory): mixed
    {
        return $this->services[$id] ??= $factory();
    }

    public function config(): Config
    {
        return $this->cfg;
    }

    public function clock(): Clock
    {
        return $this->once('clock', fn () => new SystemClock());
    }

    public function db(): \PDO
    {
        return $this->once('db', fn () => Db::open($this->cfg->get('DB_PATH', self::ROOT . '/var/app.sqlite')));
    }

    public function kv(): Kv
    {
        return $this->once('kv', fn () => new Kv($this->db()));
    }

    public function roles(): Roles
    {
        return $this->once('roles', fn () => Roles::load(self::ROOT . '/config/roles.php'));
    }

    /** @return array<string,mixed> */
    public function portal(): array
    {
        return $this->once('portal', fn () => require self::ROOT . '/config/portal.php');
    }

    public function schedule(): Schedule
    {
        return $this->once('schedule', fn () => new Schedule());
    }

    public function appUrl(): string
    {
        return rtrim($this->cfg->get('APP_URL', 'https://novichok.indigit.info'), '/');
    }

    public function botUsername(): string
    {
        return $this->cfg->get('TELEGRAM_BOT_USERNAME', 'novichok_promises_bot');
    }
}
