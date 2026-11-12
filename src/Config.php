<?php

declare(strict_types=1);

namespace App;

/**
 * Read-only application settings loaded from a dotenv-style file.
 *
 * Secrets (Bitrix24 webhook, Telegram token, panel password) live in a file outside
 * the repository; this class only reads it and never writes or prints values.
 */
final class Config
{
    /**
     * @param array<string,string> $values Raw key/value pairs as read from the file.
     */
    private function __construct(private array $values)
    {
    }

    /**
     * Parses a `KEY=value` file. Blank lines and `#` comments are skipped,
     * surrounding quotes are stripped. A missing file yields an empty config.
     *
     * @param string $envPath Absolute path to the `.env` file.
     */
    public static function load(string $envPath): self
    {
        $values = [];
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $values[trim($k)] = trim(trim($v), "\"'");
            }
        }

        return new self($values);
    }

    /**
     * Builds a config from an in-memory array (used by tests).
     *
     * @param array<string,string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Returns a value, treating empty strings as absent.
     *
     * @param string      $key     Setting name, e.g. `APP_URL`.
     * @param string|null $default Returned when the key is missing or empty.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    /**
     * Returns a value that must be present.
     *
     * @throws \RuntimeException When the key is missing or empty.
     */
    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null) {
            throw new \RuntimeException("Missing config value: $key");
        }

        return $v;
    }
}
