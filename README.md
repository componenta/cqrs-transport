# Componenta CQRS Transport

Async transport middleware, transport contracts, JSON command serializer, registry, and worker for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

```bash
composer require componenta/cqrs-transport
```

Register the provider after the core CQRS provider. The package deliberately does not choose transports or a serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, and register the named transports the application uses.

The package provides `TransportMiddleware`, `TransportInterface`, `TransportRegistryInterface`, `CommandSerializerInterface`, and `CommandWorker`.

## Worker deserialization boundary

The normal `CommandWorker` constructor is fail-closed and requires a complete `CommandMetadataProviderInterface`. The envelope-selected class is checked before `class_exists()` and before serializer hydration:

```php
$worker = new CommandWorker(
    bus: $commandBus,
    serializer: $serializer,
    transport: $transport,
    commands: $compiledCommandMetadata,
);
```

For an integrity-protected transport whose producers are all trusted, unrestricted behavior must be selected explicitly:

```php
$worker = CommandWorker::unsafe($commandBus, $serializer, $transport);
```

Do not use the unsafe factory for queues writable by a less-trusted actor. A compiled metadata provider avoids reflection and autoload work while checking the allowlist.

The worker sets `ExecutionMode::SYNC` before dispatch and exposes the producer ID as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`. It does not skip policy automatically. Put policy before transport to authorize before enqueue, or provide a trusted worker policy context.

Transport outside `SequentialMiddleware` can publish a nested async command before the parent transaction commits. Use an outbox for durable cross-system delivery.

For Cycle Database transport install `componenta/cqrs-transport-cycle`; for the Symfony Console worker install `componenta/cqrs-transport-console`.
