# Componenta CQRS Transport

Async transport middleware, transport contracts, JSON command serializer, registry, and worker for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

```bash
composer require componenta/cqrs-transport
```

Register the provider after the core CQRS provider. The package deliberately does not choose transports or a serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, and register at least the named transports the application uses.

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Transport\ConfigProvider(),

A minimal application provider may select the bundled implementations:

```php
protected function getAliases(): array
{
    return [
        Componenta\CQRS\Command\Transport\CommandSerializerInterface::class
            => Componenta\CQRS\Command\Transport\JsonCommandSerializer::class,
        Componenta\CQRS\Command\Transport\TransportRegistryInterface::class
            => Componenta\CQRS\Command\Transport\TransportRegistry::class,
    ];
}
```

The registry still needs configured `TransportInterface` instances. The package provider registers `Async` in `ConfigKey::COMMAND_METADATA_ATTRIBUTES`; `componenta/cqrs-app` therefore compiles it without a transport-specific compiler.
];
```

The package provides:

- `Componenta\CQRS\Command\Middleware\TransportMiddleware`
- `Componenta\CQRS\Command\Transport\TransportInterface`
- `Componenta\CQRS\Command\Transport\TransportRegistryInterface`
- `Componenta\CQRS\Command\Transport\CommandSerializerInterface`
- `Componenta\CQRS\Command\Transport\CommandWorker`

For a Cycle Database transport, install `componenta/cqrs-transport-cycle`. For the Symfony Console worker command, install `componenta/cqrs-transport-console`.