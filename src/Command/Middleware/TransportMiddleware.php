<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Command\Transport\TransportSendException;
use Componenta\CQRS\Transport\Attribute\Async;
use Throwable;

/** Sends commands marked with #[Async] to transport. */
final readonly class TransportMiddleware implements MiddlewareInterface
{
    public const string ATTR_EXECUTION_MODE = '__execution_mode';

    public function __construct(
        private TransportRegistryInterface $transports,
        private CommandSerializerInterface $serializer,
        private CommandMetadataProviderInterface $metadata,
        private OperationContextSerializerInterface $contextSerializer,
    ) {
    }

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        $mode = $operation->attributes[self::ATTR_EXECUTION_MODE] ?? null;

        if ($mode === ExecutionMode::SYNC) {
            return $handler->handle($operation);
        }

        $async = $this->metadata->get($operation->command, Async::class);

        if ($async === null) {
            return $handler->handle($operation)
                ->withAttribute(self::ATTR_EXECUTION_MODE, ExecutionMode::SYNC);
        }

        $envelope = new Envelope(
            operationId: $operation->id->toString(),
            commandClass: $operation->command::class,
            payload: $this->serializer->serialize($operation->command),
            contextPayload: $this->contextSerializer->serialize($operation),
        );

        try {
            $this->transports->get($async->transport)->send(
                $envelope,
                delay: $async->delay,
            );
        } catch (Throwable $exception) {
            throw new TransportSendException($envelope, $exception);
        }

        return $operation->withAttribute(self::ATTR_EXECUTION_MODE, ExecutionMode::ASYNC);
    }
}
