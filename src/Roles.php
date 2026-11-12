<?php

declare(strict_types=1);

namespace App;

/**
 * Hand-written agreement lists per role, loaded from `config/roles.php`.
 *
 * A role describes working hours (for quiet hours and question time), first-day
 * logistics for the welcome message, and the list of agreements («договорённости»)
 * the company commits to for a newcomer in that role.
 */
final class Roles
{
    /**
     * @param array<string,array<string,mixed>> $roles Role definitions keyed by role key.
     */
    private function __construct(private array $roles)
    {
    }

    /**
     * Loads role definitions from a PHP file that returns an array.
     *
     * @param string $path Path to `config/roles.php`.
     */
    public static function load(string $path): self
    {
        return new self(require $path);
    }

    /**
     * Returns one role definition.
     *
     * @param string $key Role key, e.g. `warehouse_picker`.
     * @return array<string,mixed>
     * @throws \InvalidArgumentException When the role is not configured.
     */
    public function get(string $key): array
    {
        return $this->roles[$key] ?? throw new \InvalidArgumentException("Unknown role: $key");
    }

    /**
     * Lists configured role keys in file order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->roles);
    }

    /**
     * Finds a role by its human title as written in a hire task («Должность: …»).
     * Comparison is case-insensitive and ignores surrounding spaces.
     *
     * @return string|null Role key, or null when no role has that title.
     */
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

    /**
     * Returns one agreement definition of a role.
     *
     * @return array<string,mixed> Keys: key, day, question, task_title, done_text, promise, owner.
     * @throws \InvalidArgumentException When the role or agreement is unknown.
     */
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
