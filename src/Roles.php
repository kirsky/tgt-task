<?php

declare(strict_types=1);

namespace App;

final class Roles
{
    /** @param array<string,array<string,mixed>> $roles */
    private function __construct(private array $roles)
    {
    }

    public static function load(string $path): self
    {
        return new self(require $path);
    }

    /** @return array<string,mixed> */
    public function get(string $key): array
    {
        return $this->roles[$key] ?? throw new \InvalidArgumentException("Unknown role: $key");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->roles);
    }

    public function findByTitle(string $title): ?string
    {
        $needle = mb_strtolower(trim($title));
        foreach ($this->roles as $key => $role) {
            if (mb_strtolower($role['title']) === $needle) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function agreement(string $roleKey, string $agreementKey): array
    {
        foreach ($this->get($roleKey)['agreements'] as $a) {
            if ($a['key'] === $agreementKey) {
                return $a;
            }
        }
        throw new \InvalidArgumentException("Unknown agreement $roleKey.$agreementKey");
    }
}
