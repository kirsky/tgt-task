<?php

declare(strict_types=1);

namespace App\Bitrix;

/**
 * The portal answered with a business error (bad parameters, no access, unknown method).
 * Not an outage: retrying the same call will not help.
 */
final class BitrixError extends \RuntimeException
{
}
