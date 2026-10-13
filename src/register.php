<?php

declare(strict_types=1);

\async\register(static fn() => new \FiberInterop\Amp\Adapter(), 'fiber-interop/amp');
