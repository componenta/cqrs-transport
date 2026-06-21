<?php

declare(strict_types=1);

namespace Componenta\CQRS\Transport;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            TransportMiddleware::class => \Componenta\CQRS\Command\Factory\TransportMiddlewareFactory::class,
        ];
    }
}
