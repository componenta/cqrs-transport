<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Declares whether a command serializer owns a command type.
 *
 * The same predicate is used for serialization and deserialization so a
 * composite can select a serializer before a command instance exists.
 */
interface CommandSerializerSupportInterface
{
    /**
     * @param object|class-string $command Command instance or class name.
     */
    public function supportsCommand(object|string $command): bool;
}
