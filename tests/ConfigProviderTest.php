<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\TransportMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\CQRS\Transport\ConfigProvider;

it('registers its middleware and command metadata attribute', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        TransportMiddleware::class => TransportMiddlewareFactory::class,
    ])->and($config[ConfigKey::COMMAND_METADATA_ATTRIBUTES])->toBe([
        Async::class,
    ]);
});
