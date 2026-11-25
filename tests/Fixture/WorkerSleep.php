<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/** Test-only probe; all unrelated calls retain native sleep semantics. */
final class WorkerSleepProbe
{
    public static ?\Closure $sleep = null;
}

function sleep(int $seconds): int|false
{
    if (WorkerSleepProbe::$sleep !== null) {
        (WorkerSleepProbe::$sleep)($seconds);
        return 0;
    }

    return \sleep($seconds);
}
