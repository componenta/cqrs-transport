<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Map\CommandMetadataDescriptor;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Transport\Attribute\Async;

function workerTestMapForTransport(string $transport, string ...$commands): CqrsMapProviderInterface
{
    $metadata = [];

    foreach ($commands as $command) {
        $metadata[$command][Async::class] = new CommandMetadataDescriptor(
            Async::class,
            ['transport' => $transport],
        );
    }

    return new class ($metadata) implements CqrsMapProviderInterface {
        /** @param array<string, array<class-string, CommandMetadataDescriptor>> $metadata */
        public function __construct(private readonly array $metadata) {}

        public function map(): CqrsMap
        {
            return new CqrsMap(commandMetadata: $this->metadata);
        }
    };
}

it('re-dispatches transported commands with restored operation context and worker attributes', function (): void {
    $command = new stdClass();
    $envelope = new Envelope(
        'operation-id',
        stdClass::class,
        '{}',
        'receipt-id',
        '{"tenant":"transport","trace":"abc"}',
    );
    $bus = new class implements CommandBusInterface {
        public object $command;
        /** @var array<string, mixed> */
        public array $attributes = [];

        public function dispatch(object $command, array $attributes = []): OperationInterface
        {
            $this->command = $command;
            $this->attributes = $attributes;

            return Operation::create($command, $attributes);
        }
    };
    $serializer = new class ($command) implements CommandSerializerInterface {
        public function __construct(private readonly object $command) {}
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return $this->command; }
    };
    $transport = new class ($envelope) implements TransportInterface {
        public int $acks = 0;
        public int $rejects = 0;

        public function __construct(private ?Envelope $envelope) {}
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { $value = $this->envelope; $this->envelope = null; return $value; }
        public function ack(Envelope $envelope): void { ++$this->acks; }
        public function reject(Envelope $envelope): void { ++$this->rejects; }
    };

    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: new JsonOperationContextSerializer(['tenant', 'trace']),
        transport: $transport,
        transportName: 'default',
        commands: workerTestMapForTransport('default', stdClass::class),
        dispatchAttributes: ['tenant' => 'worker'],
    );

    expect($worker->processOne())->toBeTrue()
        ->and($bus->command)->toBe($command)
        ->and($bus->attributes)->toBe([
            'tenant' => 'worker',
            'trace' => 'abc',
            CommandWorker::ATTR_ORIGINAL_OPERATION_ID => 'operation-id',
            TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
        ])
        ->and($transport->acks)->toBe(1)
        ->and($transport->rejects)->toBe(0);
});

it('rejects a command routed to a different transport before hydration', function (): void {
    $envelope = new Envelope('operation-id', stdClass::class, '{}', 'receipt-id');
    $bus = new class implements CommandBusInterface {
        public int $calls = 0;
        public function dispatch(object $command, array $attributes = []): OperationInterface { ++$this->calls; return Operation::create($command, $attributes); }
    };
    $serializer = new class implements CommandSerializerInterface {
        public int $calls = 0;
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { ++$this->calls; return new stdClass(); }
    };
    $transport = new class ($envelope) implements TransportInterface {
        public int $acks = 0;
        public int $rejects = 0;
        public function __construct(private ?Envelope $envelope) {}
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { $value = $this->envelope; $this->envelope = null; return $value; }
        public function ack(Envelope $envelope): void { ++$this->acks; }
        public function reject(Envelope $envelope): void { ++$this->rejects; }
    };

    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: new JsonOperationContextSerializer(),
        transport: $transport,
        transportName: 'emails',
        commands: workerTestMapForTransport('payments', stdClass::class),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($serializer->calls)->toBe(0)
        ->and($bus->calls)->toBe(0)
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('keeps sync execution mode authoritative over worker dispatch attributes', function (): void {
    $envelope = new Envelope('operation-id', stdClass::class, '{}', 'receipt-id');
    $bus = new class implements CommandBusInterface {
        /** @var array<string, mixed> */
        public array $attributes = [];

        public function dispatch(object $command, array $attributes = []): OperationInterface
        {
            $this->attributes = $attributes;

            return Operation::create($command, $attributes);
        }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };
    $transport = new class ($envelope) implements TransportInterface {
        public function __construct(private ?Envelope $envelope) {}
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { $value = $this->envelope; $this->envelope = null; return $value; }
        public function ack(Envelope $envelope): void {}
        public function reject(Envelope $envelope): void {}
    };
    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: new JsonOperationContextSerializer(),
        transport: $transport,
        transportName: 'default',
        commands: workerTestMapForTransport('default', stdClass::class),
        dispatchAttributes: [
            'tenant' => 'main',
            TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::ASYNC,
        ],
    );

    $worker->processOne();

    expect($bus->attributes)->toBe([
        'tenant' => 'main',
        TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
        CommandWorker::ATTR_ORIGINAL_OPERATION_ID => 'operation-id',
    ]);
});

it('rejects reserved attributes returned by a custom context serializer', function (): void {
    $envelope = new Envelope('operation-id', stdClass::class, '{}', 'receipt-id', '{}');
    $bus = new class implements CommandBusInterface {
        public int $calls = 0;
        public function dispatch(object $command, array $attributes = []): OperationInterface { ++$this->calls; return Operation::create($command, $attributes); }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };
    $context = new class implements OperationContextSerializerInterface {
        public function serialize(OperationInterface $operation): string { return '{}'; }
        public function deserialize(string $payload): array { return ['__execution_mode' => ExecutionMode::ASYNC]; }
    };
    $transport = new class ($envelope) implements TransportInterface {
        public int $acks = 0;
        public int $rejects = 0;
        public function __construct(private ?Envelope $envelope) {}
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { $value = $this->envelope; $this->envelope = null; return $value; }
        public function ack(Envelope $envelope): void { ++$this->acks; }
        public function reject(Envelope $envelope): void { ++$this->rejects; }
    };
    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: $context,
        transport: $transport,
        transportName: 'default',
        commands: workerTestMapForTransport('default', stdClass::class),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($bus->calls)->toBe(0)
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('rejects empty worker transport names at construction', function (): void {
    $transport = new class implements TransportInterface {
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { return null; }
        public function ack(Envelope $envelope): void {}
        public function reject(Envelope $envelope): void {}
    };
    $bus = new class implements CommandBusInterface {
        public function dispatch(object $command, array $attributes = []): OperationInterface { return Operation::create($command, $attributes); }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };

    expect(fn() => new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: new JsonOperationContextSerializer(),
        transport: $transport,
        transportName: '   ',
        commands: workerTestMapForTransport('default', stdClass::class),
    ))->toThrow(InvalidArgumentException::class, 'transport name');
});
