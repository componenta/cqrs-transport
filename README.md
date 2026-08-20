# Componenta CQRS Transport

Async transport middleware, transport contracts, command serializers, operation-context serialization, registry, and worker for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

`main` is the transport v5 line.

```bash
composer require componenta/cqrs-transport
```

Register `Componenta\CQRS\Transport\ConfigProvider` after the core CQRS provider. The package does not choose a concrete transport or command serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, then register the named transports used by the application.

The provider supplies a safe default `OperationContextSerializerInterface`: `JsonOperationContextSerializer` with an empty allowlist, which transports no application attributes unless the application explicitly opts them in.

## Command serializers

`CommandSerializerInterface` owns only command wire conversion:

```php
interface CommandSerializerInterface
{
    public function serialize(object $command): string;

    public function deserialize(string $payload, string $commandClass): object;
}
```

Serializers that participate in automatic selection additionally implement `CommandSerializerSupportInterface`. `CompositeCommandSerializer` tries support predicates in order; once a serializer claims a command class, any serialization/validation failure from that serializer is final.

```php
$serializer = new CompositeCommandSerializer([
    $applicationSpecificSerializer,
    $specializedSerializer,
    new JsonCommandSerializer(),
]);
```

Selection must be stable between a command instance and its class name because deserialization has only the class name. The composite rejects instance-dependent ownership.

### Default JSON command contract

`JsonCommandSerializer` accepts the versioned wire envelope:

```json
{
  "__componenta_cqrs": 1,
  "data": {
    "id": 42
  }
}
```

It accepts public stored constructor-backed state containing null, booleans, integers, finite floats, strings, and recursively JSON-safe arrays. It rejects executable callable/Closure capabilities, arbitrary objects, private or inherited private state, hooked/virtual properties, dynamic properties, variadic/by-reference constructor parameters, unknown fields, excessive nesting, and reconstructed commands whose constructor changes the serialized state.

Incoming shape and type are validated before command construction. The serializer also verifies its own encoded payload so lossy float conversion fails closed.

## Operation transport context

A command serializer must not serialize the complete `OperationInterface`. Command state and operation runtime state are separate concerns.

Transport v5 therefore uses a dedicated boundary:

```php
interface OperationContextSerializerInterface
{
    public function serialize(OperationInterface $operation): string;

    /** @return array<string, mixed> */
    public function deserialize(string $payload): array;
}
```

The transport envelope carries:

- `operationId` separately for idempotency/correlation;
- `commandClass` and command `payload` through `CommandSerializerInterface`;
- `contextPayload` through `OperationContextSerializerInterface`.

`result`, completion state, and the runtime `Operation` object itself are never serialized. The worker creates its normal execution operation when it re-dispatches the command and exposes the producer ID as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`.

### Safe JSON context

`JsonOperationContextSerializer` uses an explicit attribute allowlist:

```php
$contextSerializer = new JsonOperationContextSerializer([
    'tenant_id',
    'trace_id',
    'locale',
]);
```

Only listed attributes cross the async boundary. Values must be JSON-safe scalars/arrays; arbitrary objects and non-finite floats are rejected.

Attribute names beginning with `__` are reserved for trusted runtime state and cannot be allowlisted. The worker independently rejects reserved attributes even when a custom context serializer is used. This prevents transported data from supplying flags such as `__execution_mode` or other technical bypass state.

The default serializer has an empty allowlist, so existing application attributes remain process-local unless deliberately declared transportable.

## Worker class boundary

`CommandWorker` is fail-closed. The command class selected by an envelope must be present in the active `CqrsMapProviderInterface` map **before** `class_exists()` and before serializer hydration:

```php
$worker = new CommandWorker(
    bus: $commandBus,
    serializer: $commandSerializer,
    contextSerializer: $operationContextSerializer,
    transport: $transport,
    commands: $cqrsMapProvider,
);
```

There is no unrestricted `unsafe()` construction path in v5. A generic reflection metadata provider is not a command-class allowlist.

The class map restricts which command types may be hydrated; it does not authenticate payload fields. If less-trusted actors can modify queued messages, integrity protection must cover the complete transport message at the transport/storage boundary.

After successful deserialization the worker merges attributes with this precedence:

```text
transported allowlisted context
  < trusted worker dispatch attributes
  < __original_operation_id / __execution_mode=SYNC
```

Trusted runtime attributes therefore cannot be overridden by queued data.

## Retry and producer sends

A generic `TransportInterface` does not promise that `send()` is idempotent. A connection can fail after the transport accepted the message but before the producer observed success.

`TransportMiddleware` wraps producer-side send failures in `TransportSendException`, which is intentionally not retryable by default. This prevents generic command retry middleware from blindly duplicating an ambiguous enqueue. A concrete transport may provide stronger idempotency guarantees (for example by operation ID) and may implement its own safe producer retry policy.

## Policy, transactions, and outbox

Authorize before enqueue when queueing itself must be authorized: policy middleware must run outside transport middleware.

Nested `CommandBusInterface::dispatch()` calls are normal reentrant dispatches; the core no longer has `SequentialMiddleware`. If an async message must become visible only after a database transaction commits, use an outbox or another explicit after-commit mechanism. Middleware ordering alone cannot make an external transport atomic with a local database transaction.

For Cycle Database transport install `componenta/cqrs-transport-cycle`; for the Symfony Console worker install `componenta/cqrs-transport-console`.

## Verification

```bash
composer test
composer analyse
```
