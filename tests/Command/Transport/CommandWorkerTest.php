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
use Componenta\CQRS\Command\Transport\TransportDispositionException;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;

it('re-dispatches transported commands with worker attributes', function (): void {
    $command = new stdClass();
    $envelope = new Envelope(
        operationId: 'operation-id',
        commandClass: stdClass::class,
        payload: '{}',
        receiptHandle: 'receipt-id',
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

        public function serialize(object $command): string
        {
            return '{}';
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            return $this->command;
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
            $this->acks++;
        }

        public function reject(Envelope $envelope): void
        {
            $this->rejects++;
        }
    };

    $worker = new CommandWorker($bus, $serializer, $transport);

    expect($worker->processOne())->toBeTrue()
        ->and($bus->command)->toBe($command)
        ->and($bus->attributes)->toMatchArray([
            CommandWorker::ATTR_ORIGINAL_OPERATION_ID => 'operation-id',
            TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
        ])
        ->and($transport->acks)->toBe(1)
        ->and($transport->rejects)->toBe(0);
});

it('merges custom worker dispatch attributes and keeps sync execution mode authoritative', function (): void {
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
        public function serialize(object $command): string
        {
            return '{}';
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            return new stdClass();
        }
    };

    $transport = new class ($envelope) implements TransportInterface {
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

        public function ack(Envelope $envelope): void {}

        public function reject(Envelope $envelope): void {}
    };

    $worker = new CommandWorker($bus, $serializer, $transport, dispatchAttributes: [
        'tenant' => 'main',
        TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::ASYNC,
    ]);

    $worker->processOne();

    expect($bus->attributes)->toBe([
        'tenant' => 'main',
        TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
        CommandWorker::ATTR_ORIGINAL_OPERATION_ID => 'operation-id',
    ]);
});


