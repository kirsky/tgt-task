<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @param array<string,string> $values */
    private function __construct(private array $values)
    {
    }

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

    /** @param array<string,string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null) {
            throw new \RuntimeException("Missing config value: $key");
        }

        return $v;
    }
}
