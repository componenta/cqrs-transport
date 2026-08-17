# Componenta CQRS Transport

Async transport middleware, transport contracts, JSON command serializers, registry, and worker for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

```bash
composer require componenta/cqrs-transport
```

Register the provider after the core CQRS provider. The package deliberately does not choose transports or a serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, and register the named transports the application uses.

## Command serializers

`CommandSerializerInterface` remains the wire conversion contract:

```php
interface CommandSerializerInterface
{
    public function serialize(object $command): string;

    public function deserialize(string $payload, string $commandClass): object;
}
```

Serializers that participate in automatic selection additionally implement:

```php
interface CommandSerializerSupportInterface
{
    public function supportsCommand(object|string $command): bool;
}
```

`CompositeCommandSerializer` accepts an ordered iterable of objects implementing both interfaces. The first serializer whose `supportsCommand()` returns `true` owns the operation. A failure from that serializer is final; the composite never treats a serialization or validation exception as a reason to try the next serializer.

```php
$serializer = new CompositeCommandSerializer([
    $applicationSpecificSerializer,
    $specializedSerializer,
    new JsonCommandSerializer(), // broad fallback belongs last
]);
```

Selection is symmetric: serialization checks the command object and deserialization checks the command class before an instance exists. `JsonCommandSerializer` implements the support interface and deliberately reports support for every command type, so it is normally the final fallback.

The default JSON serializer accepts public stored constructor-backed state containing null, booleans, integers, finite floats, strings, and arrays of the same values. It rejects executable callable/Closure capability types, arbitrary objects, private state, hooked/virtual properties, variadic/by-reference constructor parameters, excessive array nesting, and reconstructed commands whose constructor changes the serialized state. These checks keep the default transport path deterministic and fail closed; richer command formats belong in a specialized serializer ordered before it.

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

Do not use the unsafe factory for queues writable by a less-trusted actor. The class allowlist restricts which command types can be hydrated; it does not authenticate individual payload fields. Any transported business data or actor reference is a reference supplied by the producer, not an authentication credential. If untrusted parties can modify queued messages, integrity protection must cover the complete envelope/payload rather than one selected field.

The worker sets `ExecutionMode::SYNC` before dispatch and exposes the producer ID as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`. It does not skip policy automatically. Put policy before transport to authorize before enqueue, or provide a trusted worker policy context.

Transport outside `SequentialMiddleware` can publish a nested async command before the parent transaction commits. Use an outbox for durable cross-system delivery.

For Cycle Database transport install `componenta/cqrs-transport-cycle`; for the Symfony Console worker install `componenta/cqrs-transport-console`.
