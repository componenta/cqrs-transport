<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Throwable;

final class TransportDispositionException extends TransportException
{
    public function __construct(
        public readonly Throwable $processingFailure,
        public readonly Throwable $dispositionFailure,
    ) {
        parent::__construct(
            sprintf(
                'Command processing failed with "%s" and reject disposition also failed with "%s".',
                $processingFailure->getMessage(),
                $dispositionFailure->getMessage(),
            ),
            previous: $processingFailure,
        );
    }
}
