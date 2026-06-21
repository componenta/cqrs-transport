# Componenta CQRS Transport

Async transport middleware, transport contracts, JSON command serializer, registry, and worker for `componenta/cqrs` commands marked with `#[Async]`.

```bash
composer require componenta/cqrs-transport
```

Register the provider and configure `TransportRegistryInterface` and `CommandSerializerInterface` in the container.

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Transport\ConfigProvider(),
];
```

The package provides:

- `Componenta\CQRS\Command\Middleware\TransportMiddleware`
- `Componenta\CQRS\Command\Transport\TransportInterface`
- `Componenta\CQRS\Command\Transport\TransportRegistryInterface`
- `Componenta\CQRS\Command\Transport\CommandSerializerInterface`
- `Componenta\CQRS\Command\Transport\CommandWorker`

For a Cycle Database transport, install `componenta/cqrs-transport-cycle`. For the Symfony Console worker command, install `componenta/cqrs-transport-console`.