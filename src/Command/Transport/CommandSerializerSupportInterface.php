<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Declares whether a command serializer owns a command type.
 *
 * The support decision must be stable for a command class: passing an instance
 * and passing that instance's class name must produce the same result. A
 * composite sees an object while serializing but has only the class name while
 * deserializing, so support must not depend on mutable or instance-specific
 * command state.
 */
interface CommandSerializerSupportInterface
{
    /** @param object|class-string $command Command instance or class name. */
    public function supportsCommand(object|string $command): bool;
}
