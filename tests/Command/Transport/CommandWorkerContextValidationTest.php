<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Map\CommandMetadataDescriptor;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Transport\Attribute\Async;

function contextValidationWorker(array $dispatchAttributes = []): CommandWorker
{
    $bus = new class implements CommandBusInterface {
        public function dispatch(object $command, array $attributes = []): OperationInterface
        {
            return Operation::create($command, $attributes);
        }
    };
    $serializer = new readonly class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };
    $context = new readonly class implements OperationContextSerializerInterface {
        public function serialize(OperationInterface $operation): string { return '{}'; }
        public function deserialize(string $payload): array { return []; }
    };
    $transport = new readonly class implements TransportInterface {
        public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }
        public function get(): ?Envelope { return null; }
        public function ack(Envelope $envelope): void {}
        public function reject(Envelope $envelope): void {}
    };
    $commands = new readonly class implements CqrsMapProviderInterface {
        public function map(): CqrsMap { return CqrsMap::empty(); }
    };

    return new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: $context,
        transport: $transport,
        transportName: 'default',
        commands: $commands,
        dispatchAttributes: $dispatchAttributes,
    );
}

it('rejects invalid trusted worker dispatch attribute names at construction', function (array $attributes): void {
    expect(fn() => contextValidationWorker($attributes))
        ->toThrow(InvalidArgumentException::class, 'attribute');
})->with([
    'empty string' => [['' => true]],
    'whitespace' => [['   ' => true]],
    'integer key' => [[0 => true]],
]);

it('rejects non-string keys returned by a custom operation context serializer', function (): void {
    $envelope = new Envelope(
        operationId: 'operation-id',
        commandClass: stdClass::class,
        payload: '{}',
        receiptHandle: 'receipt-id',
    );

    $bus = new class implements CommandBusInterface {
        public int $calls = 0;

        public function dispatch(object $command, array $attributes = []): OperationInterface
        {
            ++$this->calls;

            return Operation::create($command, $attributes);
        }
    };

    $serializer = new readonly class implements CommandSerializerInterface {
        public function serialize(object $command): string
        {
            return '{}';
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            return new stdClass();
        }
    };

    $context = new readonly class implements OperationContextSerializerInterface {
        public function serialize(OperationInterface $operation): string
        {
            return '{}';
        }

        public function deserialize(string $payload): array
        {
            return [0 => 'invalid'];
        }
    };

    $transport = new class ($envelope) implements TransportInterface {
        public int $acks = 0;
        public int $rejects = 0;

        public function __construct(private ?Envelope $envelope) {}

        public function send(Envelope $envelope, int $delay = 0): Envelope
        {
            return $envelope;
        }

        public function get(): ?Envelope
        {
            $envelope = $this->envelope;
            $this->envelope = null;

            return $envelope;
        }

        public function ack(Envelope $envelope): void
        {
            ++$this->acks;
        }

        public function reject(Envelope $envelope): void
        {
            ++$this->rejects;
        }
    };

    $commands = new readonly class implements CqrsMapProviderInterface {
        public function map(): CqrsMap
        {
            return new CqrsMap(commandMetadata: [
                stdClass::class => [
                    Async::class => new CommandMetadataDescriptor(
                        Async::class,
                        [],
                    ),
                ],
            ]);
        }
    };

    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: $context,
        transport: $transport,
        transportName: 'default',
        commands: $commands,
    );

    expect($worker->processOne())->toBeTrue()
        ->and($bus->calls)->toBe(0)
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});
