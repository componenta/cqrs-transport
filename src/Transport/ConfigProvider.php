<?php

declare(strict_types=1);

namespace Componenta\CQRS\Transport;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Transport\Attribute\Async;

final class ConfigProvider extends BaseConfigProvider
{
    /**
     * @return array<string, list<class-string>>
     */
    protected function getConfig(): array
    {
        return [
            ConfigKey::COMMAND_METADATA_ATTRIBUTES => [Async::class],
        ];
    }

    protected function getFactories(): array
    {
        return [
            TransportMiddleware::class => \Componenta\CQRS\Command\Factory\TransportMiddlewareFactory::class,
        ];
    }
}
