<?php

/**
 * Rebuilds the five example newcomers relative to today. Nightly cron:
 *   0 3 * * * cd <repo> && php bin/reset-examples.php
 */

declare(strict_types=1);

use App\Container;
use App\Demo\Examples;

require dirname(__DIR__) . '/vendor/autoload.php';

$c = Container::boot();
Examples::reset($c->db(), $c->roles(), $c->schedule(), $c->clock());
echo "examples reset\n";
