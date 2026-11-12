<?php

declare(strict_types=1);

namespace App;

/**
 * Lazy service factory: builds each service once per process (web request, webhook
 * call or cron tick). Tests replace services through {@see Container::set()}.
 */
final class Container
{
    /** Repository root. */
    public const ROOT = __DIR__ . '/..';

    /** @var array<string,mixed> */
    private array $services = [];

    /**
     * @param Config     $cfg   Loaded settings.
     * @param Clock|null $clock Overrides the system clock (tests).
     * @param \PDO|null  $db    Overrides the SQLite connection (tests).
     */
    public function __construct(private Config $cfg, ?Clock $clock = null, ?\PDO $db = null)
    {
        if ($clock) {
            $this->services['clock'] = $clock;
        }
        if ($db) {
            $this->services['db'] = $db;
        }
    }

    /**
     * Creates the container for real runs (web, webhook, cron) using the `.env`
     * one level above the repository, or the file named in `APP_ENV_FILE`.
     */
    public static function boot(): self
    {
        return new self(Config::load(self::envPath()));
    }

    /**
     * Path of the secrets file: `APP_ENV_FILE` if set, otherwise `.env` in the folder
     * that contains the repository (kept outside git on purpose).
     */
    public static function envPath(): string
    {
        return getenv('APP_ENV_FILE') ?: dirname(__DIR__, 2) . '/.env';
    }

    /**
     * Replaces a service, e.g. a fake Bitrix24 gateway or a fake Telegram bot in tests.
     *
     * @param string $id Service id (method name, e.g. `tasks`).
     */
    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    /**
     * Returns the cached service or builds it once with the factory.
     *
     * @template T
     * @param callable():T $factory
     * @return T
     */
    private function once(string $id, callable $factory): mixed
    {
        return $this->services[$id] ??= $factory();
    }

    /** Application settings. */
    public function config(): Config
    {
        return $this->cfg;
    }

    /** Time source (Europe/Minsk). */
    public function clock(): Clock
    {
        return $this->once('clock', fn () => new SystemClock());
    }

    /** SQLite connection; path from `DB_PATH`, default `var/app.sqlite`. */
    public function db(): \PDO
    {
        return $this->once('db', fn () => Db::open($this->cfg->get('DB_PATH', self::ROOT . '/var/app.sqlite')));
    }

    /** Key/value flags store. */
    public function kv(): Kv
    {
        return $this->once('kv', fn () => new Kv($this->db()));
    }

    /** Agreement lists per role from `config/roles.php`. */
    public function roles(): Roles
    {
        return $this->once('roles', fn () => Roles::load(self::ROOT . '/config/roles.php'));
    }

    /**
     * Bitrix24 portal map: owner role → user id, owner titles, creator id.
     *
     * @return array<string,mixed>
     */
    public function portal(): array
    {
        return $this->once('portal', fn () => require self::ROOT . '/config/portal.php');
    }

    /** Date math and quiet-hours rules. */
    public function schedule(): Schedule
    {
        return $this->once('schedule', fn () => new Schedule());
    }

    /** Bitrix24 REST client over the inbound webhook from `BITRIX_WEBHOOK_URL`. */
    public function bitrix(): Bitrix\BitrixClient
    {
        return $this->once('bitrix', fn () => new Bitrix\BitrixClient($this->cfg->require('BITRIX_WEBHOOK_URL'), $this->kv()));
    }

    /** Gateway to Bitrix24 tasks (replaced by a fake in tests). */
    public function tasks(): Bitrix\Tasks
    {
        return $this->once('tasks', fn () => new Bitrix\BitrixTasks($this->bitrix()));
    }

    /** Hire processing: «Приём» task → newcomer, owner tasks, invite. */
    public function hire(): Hire\HireService
    {
        return $this->once('hire', fn () => new Hire\HireService(
            $this->db(), $this->tasks(), $this->roles(), $this->schedule(), $this->portal(),
            $this->clock(), $this->appUrl(), $this->botUsername(),
        ));
    }

    /** Applies newcomers' answers to local state and Bitrix24. */
    public function answers(): Bot\AnswerService
    {
        return $this->once('answers', fn () => new Bot\AnswerService(
            $this->db(), $this->tasks(), $this->roles(), $this->portal(), $this->clock(), $this->appUrl(),
        ));
    }

    /** Telegram bot client (replaced by `Nutgram::fake()` in tests). */
    public function bot(): \SergiX44\Nutgram\Nutgram
    {
        return $this->once('bot', fn () => new \SergiX44\Nutgram\Nutgram($this->cfg->require('TELEGRAM_BOT_TOKEN')));
    }

    /** The newcomer-facing conversation (questions, answers, notes). */
    public function conversation(): Bot\Conversation
    {
        return $this->once('conversation', fn () => new Bot\Conversation(
            $this->db(), $this->bot(), $this->answers(), $this->roles(), $this->schedule(), $this->clock(),
        ));
    }

    /** Bitrix24 polling: new hires, closed tasks, resolved disputes. */
    public function poller(): Sync\Poller
    {
        return $this->once('poller', fn () => new Sync\Poller($this->db(), $this->tasks(), $this->hire(), $this->kv(), $this->clock()));
    }

    /** Time-driven sending of questions, reminders and retries. */
    public function dispatcher(): Sync\Dispatcher
    {
        return $this->once('dispatcher', fn () => new Sync\Dispatcher(
            $this->db(), $this->conversation(), $this->answers(), $this->roles(), $this->schedule(), $this->clock(),
        ));
    }

    /** Public URL of the HR panel, without trailing slash. */
    public function appUrl(): string
    {
        return rtrim($this->cfg->get('APP_URL', 'https://novichok.indigit.info'), '/');
    }

    /** Telegram bot username used in invite links. */
    public function botUsername(): string
    {
        return $this->cfg->get('TELEGRAM_BOT_USERNAME', 'novichok_promises_bot');
    }
}
