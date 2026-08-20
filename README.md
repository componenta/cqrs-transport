# Componenta CQRS Transport

Async transport middleware, transport contracts, command serializers, operation-context serialization, registry, and worker for `componenta/cqrs` v4 commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

`main` is the transport v5 line and requires CQRS v4.

```bash
composer require componenta/cqrs-transport
```

Register `Componenta\CQRS\Transport\ConfigProvider` after the core CQRS provider. The package does not choose a concrete transport or command serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, then register the named transports used by the application.

The provider registers `TransportMiddleware` as a service and contributes `Async` to compiled command metadata, but it does **not** insert transport into the command pipeline. Middleware composition and ordering belong to the application. Add it explicitly where async routing is required:

```php
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\ConfigKey;

return [
    ConfigKey::COMMAND_MIDDLEWARES => [
        TransportMiddleware::class,
    ],
];
```

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

Serializers that participate in automatic selection additionally implement `CommandSerializerSupportInterface`. `CompositeCommandSerializer` tries support predicates in order; once a serializer claims a command class, any serialization or validation failure from that serializer is final. The composite validates every configured serializer at construction time so an invalid iterable fails as configuration rather than later during dispatch.

`JsonCommandSerializer` accepts public stored constructor-backed state containing null, booleans, integers, finite floats, strings, and recursively JSON-safe arrays. It rejects executable callable/Closure capabilities, arbitrary objects, private or inherited private state, hooked/virtual properties, dynamic properties, variadic/by-reference constructor parameters, unknown fields, excessive nesting, and reconstructed commands whose constructor changes serialized state.

Incoming shape and type are validated before command construction. JSON integer and float remain distinct wire types; integer tokens outside the PHP integer range are rejected rather than silently coerced to float. The serializer also verifies its own encoded payload so lossy numeric conversion fails closed.

## Operation transport context

The complete `OperationInterface` is not a wire object. Command state and operation runtime state are separate concerns.

Transport v5 uses a dedicated boundary:

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

`result`, completion state, `Operation::createdAt`, and the runtime `Operation` object itself are never serialized. `createdAt` describes creation of one local operation object; a worker creates a new execution operation when it re-dispatches the command. The producer operation ID remains available as `CommandWorker::ATTR_ORIGINAL_OPERATION_ID`. If end-to-end queue latency is required later, model a dedicated transport timestamp such as `dispatchedAt` rather than reusing the worker operation creation time.

### Safe JSON context

`JsonOperationContextSerializer` uses an explicit attribute allowlist:

```php
$contextSerializer = new JsonOperationContextSerializer([
    'tenant_id',
    'trace_id',
    'locale',
]);
```

Only listed attributes cross the async boundary. Attribute names obey the same string-key contract as `Operation`; numeric-string names that PHP would convert to integer array keys are rejected. Values must be JSON-safe scalars/arrays; arbitrary objects and non-finite floats are rejected. The serializer preserves exact integer/float wire types and signed zero, rejects integer tokens outside the PHP integer range, and fails closed when PHP JSON precision would alter context data.

Attribute names beginning with `__` are reserved for trusted runtime state and cannot be allowlisted. The worker independently rejects reserved attributes even when a custom context serializer is used. The default serializer has an empty allowlist, so application attributes remain process-local unless deliberately declared transportable.

## Worker hydration and queue boundary

`CommandWorker` is fail-closed. Before `class_exists()` and before serializer hydration, the envelope-selected class must have compiled `Async` metadata in the active `CqrsMapProviderInterface` map **for the exact logical transport processed by this worker**.

Being merely present in `knownCommands`, listener metadata, unrelated command metadata, or `Async` metadata for another transport is not sufficient.

```php
$worker = new CommandWorker(
    bus: $commandBus,
    serializer: $commandSerializer,
    contextSerializer: $operationContextSerializer,
    transport: $transport,
    transportName: 'payments',
    commands: $cqrsMapProvider,
);
```

For a command declared as `#[Async('payments')]`, a worker bound to `emails` rejects the envelope before command class loading or deserialization even if the message physically appears in that queue.

There is no unrestricted `unsafe()` construction path in v5. A generic reflection metadata provider is not a command-class allowlist. The standard CQRS v4 metadata provider is strictly map-backed, so producer routing and worker acceptance use the same compiled metadata contract.

The metadata boundary restricts which command types may be hydrated; it does not authenticate payload fields. If less-trusted actors can modify queued messages, integrity protection must cover the complete transport message at the transport/storage boundary.

After successful deserialization the worker merges attributes with this precedence:

```text
transported allowlisted context
  < trusted worker dispatch attributes
  < __original_operation_id / __execution_mode=SYNC
```

Trusted runtime attributes therefore cannot be overridden by queued data.

## Middleware order

Middleware placement is controlled by the application. `TransportMiddleware` does not declare or enforce dependencies on policy, event, lock, retry, transaction, or custom middleware.

A common producer-side topology is:

```text
PolicyMiddleware
  TransportMiddleware
    EventMiddleware
    ResourceLockMiddleware
    RetryMiddleware
    TransactionMiddleware
    handler
```

With this order, authorization happens before enqueue and an async command short-circuits at transport before execution-only middleware. On worker redispatch `ExecutionMode::SYNC` makes transport pass through, so the downstream middleware execute around the actual handler.

Other orders are technically valid and intentionally not rejected. For example, placing policy inside transport means authorization occurs only after worker redispatch, while placing event middleware outside transport means producer-side queueing participates in those lifecycle events. Applications choose the semantics explicitly.

If an async message must become visible only after a separate local database transaction commits, use an outbox or another explicit after-commit mechanism. Middleware ordering cannot make an external transport atomic with an unrelated local transaction.

## Retry and producer sends

A generic `TransportInterface` does not promise that `send()` is idempotent. A connection can fail after the transport accepted the message but before the producer observed success.

`TransportMiddleware` wraps exceptions thrown specifically by `TransportInterface::send()` in `TransportSendException`, which is intentionally not retryable by default. Registry/configuration failures are not mislabeled as ambiguous send failures. If application configuration places generic retry outside transport and explicitly marks transport-send failures retryable, duplicate enqueue risk becomes an application responsibility unless the concrete transport guarantees idempotent send semantics.

For Cycle Database transport install `componenta/cqrs-transport-cycle`; for the Symfony Console worker install `componenta/cqrs-transport-console`.

## Verification

```bash
composer test
composer analyse
```
