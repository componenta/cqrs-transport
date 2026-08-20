<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Componenta\CQRS\Command\OperationInterface;

/**
 * Serializes the explicitly transportable context of a command operation.
 *
 * The operation ID and command payload are transported separately. Implementations
 * must not serialize the operation result or other execution lifecycle state.
 */
interface OperationContextSerializerInterface
{
    public function serialize(OperationInterface $operation): string;

    /** @return array<string, mixed> */
    public function deserialize(string $payload): array;
}
