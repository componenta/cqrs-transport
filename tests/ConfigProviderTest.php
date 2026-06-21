<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\TransportMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Transport\ConfigProvider;

it('registers transport middleware factory', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        TransportMiddleware::class => TransportMiddlewareFactory::class,
    ]);
});
