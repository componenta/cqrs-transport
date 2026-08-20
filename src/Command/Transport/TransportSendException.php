<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Throwable;

/**
 * Represents an ambiguous producer-side send failure.
 *
 * It intentionally does not implement RetryableExceptionInterface: retrying a
 * generic transport send after an ambiguous failure can duplicate a message.
 */
final class TransportSendException extends TransportException
{
    public function __construct(Envelope $envelope, Throwable $previous)
    {
        parent::__construct(
            sprintf(
                'Failed to send command "%s" for operation "%s": %s',
                $envelope->commandClass,
                $envelope->operationId,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
