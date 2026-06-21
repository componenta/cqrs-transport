<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Serializes/deserializes commands for transport.
 */
interface CommandSerializerInterface
{
    /**
     * Serializes command to string.
     *
     * @param object $command Command to serialize
     * @return string Serialized command
     */
    public function serialize(object $command): string;

    /**
     * Deserializes command from string.
     *
     * @param string $payload Serialized command
     * @param class-string $commandClass Command FQCN
     * @return object Deserialized command
     */
    public function deserialize(string $payload, string $commandClass): object;
}
