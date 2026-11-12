<?php

declare(strict_types=1);

namespace App\Bitrix;

/**
 * The portal cannot be reached or refuses service (network error, HTTP 5xx, rate limit,
 * expired REST subscription). The app switches to read-only mode while this persists.
 */
final class BitrixUnavailable extends \RuntimeException
{
}
