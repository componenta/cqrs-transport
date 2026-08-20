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
        /** @var list<CommandSerializerInterface&CommandSerializerSupportInterface> $ordered */
        $ordered = [];

        foreach ($serializers as $index => $serializer) {
            if (!$serializer instanceof CommandSerializerInterface
                || !$serializer instanceof CommandSerializerSupportInterface
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Command serializer at key "%s" must implement both %s and %s; got %s.',
                    (string) $index,
                    CommandSerializerInterface::class,
                    CommandSerializerSupportInterface::class,
                    get_debug_type($serializer),
                ));
            }

            $ordered[] = $serializer;
        }

        $this->serializers = $ordered;
    }

    public function supportsCommand(object|string $command): bool
    {
        foreach ($this->serializers as $serializer) {
            if ($this->supports($serializer, $command)) {
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
            if ($this->supports($serializer, $command)) {
                return $serializer;
            }
        }

        throw new TransportException(sprintf(
            'No command serializer supports %s.',
            is_object($command) ? $command::class : $command,
        ));
    }

    /**
     * @param CommandSerializerInterface&CommandSerializerSupportInterface $serializer
     * @param object|class-string $command
     */
    private function supports(
        CommandSerializerInterface&CommandSerializerSupportInterface $serializer,
        object|string $command,
    ): bool {
        $supported = $serializer->supportsCommand($command);

        if (!is_object($command)) {
            return $supported;
        }

        $classSupported = $serializer->supportsCommand($command::class);

        if ($supported !== $classSupported) {
            throw new TransportException(sprintf(
                'Command serializer %s has instance-dependent support for %s; supportsCommand() must return the same result for a command instance and its class.',
                $serializer::class,
                $command::class,
            ));
        }

        return $supported;
    }
}
