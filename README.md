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

Selection must be stable for a command class. Serialization can pass an object, while deserialization has only the class name; therefore `supportsCommand($instance)` and `supportsCommand($instance::class)` must return the same result. The composite verifies that invariant while serializing and rejects instance-dependent ownership. A support predicate must be deterministic and side-effect free.

If one command class can carry several domain actor/value variants, a custom serializer cannot claim only selected instances of that class. It must own the whole command class and handle its supported wire variants itself, or the command types must be separated.

`JsonCommandSerializer` implements the support interface and deliberately reports support for every command type, so it is normally the final fallback.

### Default JSON wire contract

The default serializer uses one current versioned wire envelope:

```json
{
  "__componenta_cqrs": 1,
  "data": {
    "id": 42
  }
}
```

Unversioned payloads are rejected. The serializer does not keep a legacy compatibility path.

The default JSON serializer accepts public stored constructor-backed state containing null, booleans, integers, finite floats, strings, and arrays of the same values. It rejects executable callable/Closure capability types, arbitrary objects, private state, hooked/virtual properties, dynamic properties, variadic/by-reference constructor parameters, excessive array nesting, unknown fields, and reconstructed commands whose constructor changes serialized state.

Dynamic runtime state is rejected both before serialization and after reconstruction. A command therefore cannot silently lose `#[AllowDynamicProperties]` state that is outside the declared constructor-backed wire contract.

Incoming field shape, type, and nesting are validated before command construction. Invalid or excessively deep payloads therefore cannot execute a command constructor before being rejected. Richer command formats belong in a specialized serializer ordered before the JSON fallback.

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

The worker sets `ExecutionMode::SYNC` before dispatch and exposes the producer ID as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`. It does not know about policy-specific skip attributes. Put policy before transport to authorize before enqueue, or provide a trusted worker policy context deliberately.

Transport outside `SequentialMiddleware` can publish a nested async command before the parent transaction commits. Use an outbox for durable cross-system delivery.

For Cycle Database transport install `componenta/cqrs-transport-cycle`; for the Symfony Console worker install `componenta/cqrs-transport-console`.
