<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Thrown when requested transport is not registered.
 */
final class TransportNotFoundException extends TransportException
{
    public static function forName(string $name): self
    {
        return new self("Transport '{$name}' is not registered");
    }
}
