<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Command\Transport\TransportSendException;
use Componenta\CQRS\Transport\Attribute\Async;

function transportTestMetadata(?Async $async): CommandMetadataProviderInterface
{
    return new class ($async) implements CommandMetadataProviderInterface {
        public function __construct(private readonly ?Async $async) {}

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Async::class ? $this->async : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
        }
    };
}

it('routes asynchronous commands and serializes allowlisted operation context', function (): void {
    $transport = new class implements TransportInterface {
        public ?Envelope $sent = null;
        public int $delay = -1;

        public function send(Envelope $envelope, int $delay = 0): Envelope
        {
            $this->sent = $envelope;
            $this->delay = $delay;

            return $envelope;
        }

        public function get(): ?Envelope { return null; }
        public function ack(Envelope $envelope): void {}
        public function reject(Envelope $envelope): void {}
    };

    $registry = new class ($transport) implements TransportRegistryInterface {
        public string $requested = '';

        public function __construct(private readonly TransportInterface $transport) {}

        public function get(string $name): TransportInterface
        {
            $this->requested = $name;

            return $this->transport;
        }

        public function has(string $name): bool
        {
            return $name === 'queue';
        }
    };

    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return 'payload'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };

    $handler = new class implements OperationHandlerInterface {
        public int $calls = 0;

        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;

            return $operation;
        }
    };

    $command = new stdClass();
    $result = (new TransportMiddleware(
        $registry,
        $serializer,
        transportTestMetadata(new Async('queue', 15)),
        new JsonOperationContextSerializer(['tenant']),
    ))->execute(
        Operation::create($command, ['tenant' => 'main', 'local_only' => 'ignored']),
        $handler,
    );

    expect($handler->calls)->toBe(0)
        ->and($registry->requested)->toBe('queue')
        ->and($transport->delay)->toBe(15)
        ->and($transport->sent?->commandClass)->toBe($command::class)
        ->and($transport->sent?->payload)->toBe('payload')
        ->and($transport->sent?->contextPayload)->toBe('{"tenant":"main"}')
        ->and($result->attributes[TransportMiddleware::ATTR_EXECUTION_MODE])
        ->toBe(ExecutionMode::ASYNC);
});

it('rejects invalid asynchronous transport declarations', function (Closure $create): void {
    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'empty transport' => [fn() => new Async('  ')],
    'negative delay' => [fn() => new Async(delay: -1)],
]);

it('adds synchronous execution mode only after downstream returns', function (): void {
    $registry = new class implements TransportRegistryInterface {
        public function get(string $name): TransportInterface
        {
            throw new RuntimeException('A synchronous command must not resolve a transport.');
        }

        public function has(string $name): bool { return false; }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string
        {
            throw new RuntimeException('A synchronous command must not be serialized.');
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            throw new RuntimeException('Not used.');
        }
    };
    $handler = new class implements OperationHandlerInterface {
        public mixed $observedMode = 'not-called';

        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->observedMode = $operation->attributes[
                TransportMiddleware::ATTR_EXECUTION_MODE
            ] ?? null;

            return $operation;
        }
    };

    $result = (new TransportMiddleware(
        $registry,
        $serializer,
        transportTestMetadata(null),
        new JsonOperationContextSerializer(),
    ))->execute(
        Operation::create(new stdClass()),
        $handler,
    );

    expect($handler->observedMode)->toBeNull()
        ->and($result->attributes[TransportMiddleware::ATTR_EXECUTION_MODE])
        ->toBe(ExecutionMode::SYNC);
});

it('turns ambiguous producer send failures into non-retryable transport failures', function (): void {
    $transport = new class implements TransportInterface {
        public function send(Envelope $envelope, int $delay = 0): Envelope
        {
            throw new RuntimeException('connection lost after write');
        }

        public function get(): ?Envelope { return null; }
        public function ack(Envelope $envelope): void {}
        public function reject(Envelope $envelope): void {}
    };
    $registry = new class ($transport) implements TransportRegistryInterface {
        public function __construct(private readonly TransportInterface $transport) {}
        public function get(string $name): TransportInterface { return $this->transport; }
        public function has(string $name): bool { return true; }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };
    $handler = new class implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface { return $operation; }
    };

    expect(fn() => (new TransportMiddleware(
        $registry,
        $serializer,
        transportTestMetadata(new Async()),
        new JsonOperationContextSerializer(),
    ))->execute(Operation::create(new stdClass()), $handler))
        ->toThrow(TransportSendException::class, 'connection lost after write');
});
