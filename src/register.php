<?php

declare(strict_types=1);

\fiber\register(static fn() => new \FiberInterop\Amp\Adapter(), 'fiber-interop/amp');
\FiberInterop\Amp\ActivatingDriver::install();
