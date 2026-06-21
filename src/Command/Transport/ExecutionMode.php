<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Command execution mode.
 */
enum ExecutionMode
{
    /**
     * Command executed synchronously, result available immediately.
     */
    case SYNC;

    /**
     * Command sent to transport for async processing.
     */
    case ASYNC;
}
