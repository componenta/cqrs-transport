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
];
```

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

The package provides:

- `Componenta\CQRS\Command\Middleware\TransportMiddleware`
- `Componenta\CQRS\Command\Transport\TransportInterface`
- `Componenta\CQRS\Command\Transport\TransportRegistryInterface`
- `Componenta\CQRS\Command\Transport\CommandSerializerInterface`

For a direct non-async dispatch, `ExecutionMode::SYNC` is attached to the returned
operation after downstream middleware completes; inner middleware does not see
that marker. A worker sets `SYNC` before dispatch and also exposes the producer
ID as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`.

The worker no longer skips policy automatically. Put policy before transport to
authorize before enqueue, or explicitly provide a trusted worker policy context.
If `CommandMetadataProviderInterface` is passed to `CommandWorker`, an unknown
command class is rejected before deserialization and constructor hydration. A
compiled metadata provider also makes this check before autoload; a reflection
fallback may autoload while evaluating `isKnown()`. The console integration

Transport outside `SequentialMiddleware` can publish a nested async command
before the parent transaction commits. Middleware order is not a generic
DB/queue atomicity guarantee; use an outbox for durable cross-system delivery.

- `Componenta\CQRS\Command\Transport\CommandWorker`

For a Cycle Database transport, install `componenta/cqrs-transport-cycle`. For the Symfony Console worker command, install `componenta/cqrs-transport-console`.