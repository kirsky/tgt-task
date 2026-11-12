<?php

/**
 * Demo helper: creates a «Приём» task in Bitrix24 and processes it.
 *
 *   php bin/hire.php "Петров Алексей" warehouse_picker 2026-11-17
 *
 * Prints the newcomer id, the personal bot link and the panel card URL.
 */

declare(strict_types=1);

use App\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

[$_, $name, $role, $start] = $argv + [null, null, null, null];
if (!$name || !$role || !$start) {
    fwrite(STDERR, "usage: php bin/hire.php \"Фамилия Имя\" <warehouse_picker|store_seller> <YYYY-MM-DD>\n");
    exit(1);
}
$c = Container::boot();
$id = $c->demo()->hire($name, $role, $start);
$code = $c->db()->query("SELECT invite_code FROM newcomers WHERE id = $id")->fetchColumn();
echo "newcomer: $id\nlink: {$c->hire()->inviteLink((string) $code)}\ncard: {$c->appUrl()}/n/$id\n";
