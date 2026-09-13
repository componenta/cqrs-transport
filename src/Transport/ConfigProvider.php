<?php

declare(strict_types=1);

namespace Componenta\CQRS\Transport;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getInvokables(): array
    {
        return [
            OperationContextSerializerInterface::class => JsonOperationContextSerializer::class,
        ];
    }

    protected function getFactories(): array
    {
        return [
            TransportMiddleware::class => \Componenta\CQRS\Command\Factory\TransportMiddlewareFactory::class,
        ];
    }
}
