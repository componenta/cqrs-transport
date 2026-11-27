# Componenta CQRS Transport

Async transport middleware, transport contracts, command serializers, operation-context serialization, registry, and worker for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Transport\Attribute\Async]`.

## Compatibility

Version 5.1 supports CQRS 4 or 5 and Config 3 or 4, with the existing PHP 8.4
runtime floor. The update expands dependency compatibility without changing
transport, middleware, envelope or serializer APIs.

Transport metadata, serialization and worker dispatch remain runtime operations.
The package has no App build dependency. CQRS App's prepared registration map can
be used by the producer and worker while preserving retry, policy, locking and
transaction middleware behavior.

```bash
composer require componenta/cqrs-transport
```

Register `Componenta\CQRS\Transport\ConfigProvider` after the core CQRS provider. The package does not choose a concrete transport or command serializer for the application: bind `TransportRegistryInterface` and `CommandSerializerInterface`, then register the named transports used by the application.

The provider registers `TransportMiddleware` as a service. The middleware reads `Async` through `CommandMetadataProviderInterface`. The application chooses middleware composition and ordering. Add it explicitly where async routing is required:

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

`JsonCommandSerializer` checks constructor accessibility and the compatibility of stored fields with constructor parameter types before sending, without invoking the constructor.

`JsonCommandSerializer` accepts public stored constructor-backed state containing null, booleans, integers, finite floats, strings, and recursively JSON-safe arrays. It rejects executable callable/Closure capabilities, arbitrary objects, private or inherited private state, internal PHP classes or ancestors other than `stdClass`, hooked/virtual properties, dynamic properties, PHP references in properties or array elements, variadic/by-reference constructor parameters, unknown fields, excessive nesting, and reconstructed commands whose constructor changes serialized state.

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

Arrays must preserve their internal pointer and next integer append index through JSON. JSON restores nonempty arrays with the pointer at the first element; other pointer positions are rejected, including the past-end state. This also applies to nested arrays. For example, deleting the last numeric element can leave hidden append state that JSON cannot represent. Both command and context serializers reject this state before sending; command reconstruction is checked as well. Validation uses copies and leaves the original arrays unchanged. Use a custom serializer when this state is meaningful, or explicitly normalize arrays in application code when that is the intended behaviour.

Only listed attributes cross the async boundary. Attribute names obey the same string-key contract as `Operation`; numeric-string names that PHP would convert to integer array keys are rejected. Values must be JSON-safe scalars/arrays; arbitrary objects, non-finite floats, and PHP references actually present in transported attributes or nested arrays are rejected. The serializer observes the operation's exposed attributes, not the caller's original array before an operation factory snapshots it. The serializer preserves exact integer/float wire types and signed zero, rejects integer tokens outside the PHP integer range, and fails closed when PHP JSON precision would alter context data.

Attribute names beginning with `__` are reserved for trusted runtime state and cannot be allowlisted. The worker independently rejects reserved attributes even when a custom context serializer is used. The default serializer has an empty allowlist, so application attributes remain process-local unless deliberately declared transportable.

## Worker hydration and queue boundary

`CommandWorker` asks the configured serializer to restore the envelope's requested command type. The serializer owns supported formats, supported command state and deserialization errors. The worker then verifies the returned object's type and reads `#[Async]` through `CommandMetadataProviderInterface`.

A command is dispatched only when its attribute names the worker's transport. A missing attribute or a different transport rejects the delivery after deserialization. Handler-map membership is unrelated to this decision.

```php
$worker = new CommandWorker(
    bus: $commandBus,
    serializer: $commandSerializer,
    contextSerializer: $operationContextSerializer,
    transport: $transport,
    transportName: 'payments',
);
```

The default metadata provider is `ReflectionCommandMetadataProvider`; a custom provider can be passed as `metadata:`. The producer middleware reads the same attribute contract. No registration of metadata classes or prepared handler map is needed.

Trusted worker dispatch attributes are shallow snapshots taken at construction; top-level PHP references are detached. Nested objects and nested references are not deep-copied.

An error restoring the command, checking its type or transport, restoring context, or dispatching prevents acknowledgement and follows the worker's rejection policy. A serialization failure on the producer prevents sending and is propagated to its caller.

`CommandWorker::run()` polls until `stop()` is requested. A stop request does not interrupt an active delivery; acknowledgement or rejection still finishes. When a stop is requested during an empty poll, the loop exits without another idle sleep.

The serializer is the boundary for constructing supported command objects. The worker's subsequent transport check does not authenticate payload fields or undo constructor effects. Integrity protection for queued messages belongs to the transport/storage boundary.

After successful deserialization and transport validation the worker merges attributes with this precedence:

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
