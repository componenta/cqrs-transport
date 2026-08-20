<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use LogicException;
use Psr\Container\ContainerInterface;

final class TransportMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): TransportMiddleware
    {
        $transports = $container->get(TransportRegistryInterface::class);

        if (!$transports instanceof TransportRegistryInterface) {
            throw new LogicException(sprintf(
                'Container entry "%s" must implement %s.',
                TransportRegistryInterface::class,
                TransportRegistryInterface::class,
            ));
        }

        $serializer = $container->get(CommandSerializerInterface::class);

        if (!$serializer instanceof CommandSerializerInterface) {
            throw new LogicException(sprintf(
                'Container entry "%s" must implement %s.',
                CommandSerializerInterface::class,
                CommandSerializerInterface::class,
            ));
        }

        $metadata = $container->get(CommandMetadataProviderInterface::class);

        if (!$metadata instanceof CommandMetadataProviderInterface) {
            throw new LogicException(sprintf(
                'Container entry "%s" must implement %s.',
                CommandMetadataProviderInterface::class,
                CommandMetadataProviderInterface::class,
            ));
        }

        $contextSerializer = $container->get(OperationContextSerializerInterface::class);

        if (!$contextSerializer instanceof OperationContextSerializerInterface) {
            throw new LogicException(sprintf(
                'Container entry "%s" must implement %s.',
                OperationContextSerializerInterface::class,
                OperationContextSerializerInterface::class,
            ));
        }

        return new TransportMiddleware(
            transports: $transports,
            serializer: $serializer,
            metadata: $metadata,
            contextSerializer: $contextSerializer,
        );
    }
}
