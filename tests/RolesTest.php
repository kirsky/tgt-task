<?php

declare(strict_types=1);

namespace Tests;

use App\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function test_two_roles_match_spec(): void
    {
        $roles = Roles::load(self::ROOT . '/config/roles.php');
        $portal = require self::ROOT . '/config/portal.php';

        self::assertSame(['warehouse_picker', 'store_seller'], $roles->keys());
        self::assertSame([1, 1, 1, 3, 3, 7, 30, 60], array_column($roles->get('warehouse_picker')['agreements'], 'day'));
        self::assertSame([1, 1, 3, 7, 30, 60], array_column($roles->get('store_seller')['agreements'], 'day'));

        foreach ($roles->keys() as $key) {
            $role = $roles->get($key);
            foreach (['title', 'department', 'work_start', 'work_end', 'ask_at', 'logistics', 'agreements'] as $field) {
                self::assertArrayHasKey($field, $role, "$key.$field");
            }
            foreach ($role['agreements'] as $a) {
                foreach (['key', 'day', 'question', 'task_title', 'done_text', 'promise', 'owner'] as $field) {
                    self::assertNotEmpty($a[$field] ?? null, "$key.{$a['key']}.$field");
                }
                self::assertArrayHasKey($a['owner'], $portal['owners'], "owner of $key.{$a['key']}");
            }
        }

        self::assertDoesNotMatchRegularExpression('/обещ/iu', (string) file_get_contents(self::ROOT . '/config/roles.php'));
        self::assertSame('warehouse_picker', $roles->findByTitle(' кладовщик-комплектовщик '));
        self::assertNull($roles->findByTitle('Директор'));
    }
}
