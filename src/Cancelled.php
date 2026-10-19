<?php

declare(strict_types=1);

namespace FiberInterop\Amp;

/**
 * Thrown by await() when an Amp\Future failed with an Amp\CancelledException that was not our timeout.
 *
 * Exists only until Amp\CancelledException itself implements fiber\CancelledException.
 */
final class Cancelled extends \RuntimeException implements \fiber\CancelledException
{
}
