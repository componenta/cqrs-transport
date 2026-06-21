<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Attribute\Async;
use Componenta\CQRS\Command\Metadata\CommandAttributeProviderInterface;
use Componenta\CQRS\Command\Metadata\ReflectionCommandAttributeProvider;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;

/**
 * Sends commands marked with #[Async] to transport.
 *
 * Commands without #[Async] are executed synchronously.
 * Commands with #[Async] are serialized and sent to the configured transport.
 *
 * @example
 * ```php
 * // Command with async attribute
 * #[Async(transport: 'emails', delay: 60)]
 * final readonly class SendWelcomeEmailCommand
 * {
 *     public function __construct(public int $userId) {}
 * }
 *
 * // Dispatch - sends to transport
 * $operation = $bus->dispatch(new SendWelcomeEmailCommand($userId));
 *
 * // Check execution mode
 * if ($operation->attributes[TransportMiddleware::ATTR_EXECUTION_MODE] === ExecutionMode::ASYNC) {
 *     // Command queued for async processing
 * }
 * ```
 */
final readonly class TransportMiddleware implements MiddlewareInterface
{
    /**
     * Attribute key for execution mode.
     */
    public const string ATTR_EXECUTION_MODE = '__execution_mode';

    private CommandAttributeProviderInterface $attributes;

    public function __construct(
        private TransportRegistryInterface $transports,
        private CommandSerializerInterface $serializer,
        ?CommandAttributeProviderInterface $attributes = null,
    ) {
        $this->attributes = $attributes ?? new ReflectionCommandAttributeProvider();
    }

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        $mode = $operation->attributes[self::ATTR_EXECUTION_MODE] ?? null;

        // Worker sets Sync - execute immediately
        if ($mode === ExecutionMode::SYNC) {
            return $handler->handle($operation);
        }

        $async = $this->attributes->async($operation->command);

        if ($async === null) {
            return $handler->handle($operation)
                ->withAttribute(self::ATTR_EXECUTION_MODE, ExecutionMode::SYNC);
        }

        $transport = $this->transports->get($async->transport);

        $transport->send(
            new Envelope(
                operationId: $operation->id->toString(),
                commandClass: $operation->command::class,
                payload: $this->serializer->serialize($operation->command),
            ),
            delay: $async->delay,
        );

        return $operation->withAttribute(self::ATTR_EXECUTION_MODE, ExecutionMode::ASYNC);
    }
}
