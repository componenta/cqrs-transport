<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandAttributeProviderInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Psr\Container\ContainerInterface;

final class TransportMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): TransportMiddleware
    {
        return new TransportMiddleware(
            transports: $container->get(TransportRegistryInterface::class),
            serializer: $container->get(CommandSerializerInterface::class),
            attributes: $container->has(CommandAttributeProviderInterface::class)
                ? $container->get(CommandAttributeProviderInterface::class)
                : null,
        );
    }
}
