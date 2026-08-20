<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\TransportMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\CQRS\Transport\ConfigProvider;

it('registers transport middleware, operation context serializer, and command metadata', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config[DependencyConfigKey::DEPENDENCIES];

    expect($dependencies[DependencyConfigKey::FACTORIES])->toMatchArray([
        TransportMiddleware::class => TransportMiddlewareFactory::class,
    ])->and($dependencies[DependencyConfigKey::ALIASES])->toMatchArray([
        OperationContextSerializerInterface::class => JsonOperationContextSerializer::class,
    ])->and($dependencies[DependencyConfigKey::INVOKABLES])->toContain(
        JsonOperationContextSerializer::class,
    )->and($config[ConfigKey::COMMAND_METADATA_ATTRIBUTES])->toBe([
        Async::class,
    ]);
});
