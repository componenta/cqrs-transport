<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;

/**
 * Explicit opt-out from the worker command allowlist.
 *
 * Use only when the transport is integrity-protected and every producer is trusted.
 */
final readonly class UnsafeCommandMetadataProvider implements CommandMetadataProviderInterface
{
    public function get(object|string $command, string $attribute): ?object
    {
        return null;
    }

    public function isKnown(object|string $command): bool
    {
        return true;
    }
}
