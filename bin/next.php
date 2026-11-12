<?php

/**
 * Demo helper: sends the next day's questions to a newcomer right now.
 *
 *   php bin/next.php <newcomer id>
 */

declare(strict_types=1);

use App\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "usage: php bin/next.php <newcomer id>\n");
    exit(1);
}
Container::boot()->demo()->nextBatch($id);
echo "sent\n";
