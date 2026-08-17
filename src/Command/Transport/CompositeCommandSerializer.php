<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use InvalidArgumentException;

/**
 * Delegates command serialization to the first serializer that supports the
 * command type.
 *
 * Serializer order is significant. Broad fallbacks such as
 * {@see JsonCommandSerializer} belong after specialized serializers.
 */
final readonly class CompositeCommandSerializer implements CommandSerializerInterface, CommandSerializerSupportInterface
{
    /** @var list<CommandSerializerInterface&CommandSerializerSupportInterface> */
    private array $serializers;

    /**
     * @param iterable<CommandSerializerInterface&CommandSerializerSupportInterface> $serializers
     */
    public function __construct(iterable $serializers)
    {
        $validated = [];

        foreach ($serializers as $serializer) {
            if (!$serializer instanceof CommandSerializerInterface
                || !$serializer instanceof CommandSerializerSupportInterface
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Composite serializer entries must implement both %s and %s; %s given.',
                    CommandSerializerInterface::class,
                    CommandSerializerSupportInterface::class,
                    get_debug_type($serializer),
                ));
            }

            $validated[] = $serializer;
        }

        $this->serializers = $validated;
    }

    public function supportsCommand(object|string $command): bool
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->supportsCommand($command)) {
                return true;
            }
        }

        return false;
    }

    public function serialize(object $command): string
    {
        return $this->serializerFor($command)->serialize($command);
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        return $this->serializerFor($commandClass)->deserialize($payload, $commandClass);
    }

    /**
     * @param object|class-string $command
     * @return CommandSerializerInterface&CommandSerializerSupportInterface
     */
    private function serializerFor(object|string $command): CommandSerializerInterface&CommandSerializerSupportInterface
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->supportsCommand($command)) {
                return $serializer;
            }
        }

        throw new TransportException(sprintf(
            'No command serializer supports %s.',
            is_object($command) ? $command::class : $command,
        ));
    }
}
