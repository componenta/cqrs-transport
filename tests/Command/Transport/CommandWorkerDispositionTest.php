<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportDispositionException;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;

final class WorkerDispositionTestBus implements CommandBusInterface
{
    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function dispatch(object $command, array $attributes = []): OperationInterface
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return Operation::create($command, $attributes);
    }
}

final readonly class WorkerDispositionTestSerializer implements CommandSerializerInterface
{
    public function serialize(object $command): string
    {
        return '{}';
    }
    public function deserialize(string $payload, string $commandClass): object
    {
        return new stdClass();
    }
}

final class WorkerDispositionTestTransport implements TransportInterface
{
    public int $acks = 0;
    public int $rejects = 0;

    public function __construct(
        private ?Envelope $envelope,
        private readonly ?Throwable $ackFailure = null,
        private readonly ?Throwable $rejectFailure = null,
    ) {
    }

    public function send(Envelope $envelope, int $delay = 0): Envelope
    {
        return $envelope;
    }

    public function get(): ?Envelope
    {
        $value = $this->envelope;
        $this->envelope = null;

        return $value;
    }

    public function ack(Envelope $envelope): void
    {
        ++$this->acks;

        if ($this->ackFailure !== null) {
            throw $this->ackFailure;
        }
    }

    public function reject(Envelope $envelope): void
    {
        ++$this->rejects;

        if ($this->rejectFailure !== null) {
            throw $this->rejectFailure;
        }
    }
}

function workerDispositionEnvelope(string $commandClass = stdClass::class): Envelope
{
    return new Envelope('operation-id', $commandClass, '{}', 'receipt-id');
}

function workerDispositionMetadata(string ...$commands): CommandMetadataProviderInterface
{
    return new readonly class ($commands) implements CommandMetadataProviderInterface {
        public function __construct(private array $commands)
        {
        }
        public function get(object|string $command, string $attribute): ?object
        {
            return in_array(is_object($command) ? $command::class : $command, $this->commands, true)
                ? new Async() : null;
        }
    };
}

function workerDispositionWorker(
    WorkerDispositionTestBus $bus,
    CommandSerializerInterface $serializer,
    WorkerDispositionTestTransport $transport,
    ?CommandMetadataProviderInterface $commands = null,
): CommandWorker {
    return new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        contextSerializer: new JsonOperationContextSerializer(),
        transport: $transport,
        transportName: 'default',
        metadata: $commands ?? workerDispositionMetadata(stdClass::class),
    );
}

it('does not reject a successfully handled command when acknowledgement fails', function (): void {
    $transport = new WorkerDispositionTestTransport(
        workerDispositionEnvelope(),
        ackFailure: new RuntimeException('ack unavailable'),
    );
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        new WorkerDispositionTestSerializer(),
        $transport,
    );

    expect(fn () => $worker->processOne())
        ->toThrow(TransportException::class, 'acknowledgement failed')
        ->and($transport->acks)->toBe(1)
        ->and($transport->rejects)->toBe(0);
});

it('preserves processing and reject failures when disposition fails', function (): void {
    $processing = new RuntimeException('handler failed');
    $disposition = new RuntimeException('reject unavailable');
    $transport = new WorkerDispositionTestTransport(
        workerDispositionEnvelope(),
        rejectFailure: $disposition,
    );
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus($processing),
        new WorkerDispositionTestSerializer(),
        $transport,
    );

    try {
        $worker->processOne();
        test()->fail('Expected disposition failure.');
    } catch (TransportDispositionException $exception) {
        expect($exception->processingFailure)->toBe($processing)
            ->and($exception->dispositionFailure)->toBe($disposition)
            ->and($exception->getPrevious())->toBe($processing)
            ->and($transport->rejects)->toBe(1);
    }
});

it('rejects an envelope when a custom deserializer returns the wrong command type', function (): void {
    $transport = new WorkerDispositionTestTransport(
        workerDispositionEnvelope(DateTimeImmutable::class),
    );
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        new WorkerDispositionTestSerializer(),
        $transport,
        workerDispositionMetadata(DateTimeImmutable::class),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('rejects a command without async metadata after deserialization', function (): void {
    $transport = new WorkerDispositionTestTransport(workerDispositionEnvelope());
    $serializer = new class () implements CommandSerializerInterface {
        public int $calls = 0;
        public function serialize(object $command): string
        {
            return '{}';
        }
        public function deserialize(string $payload, string $commandClass): object
        {
            ++$this->calls;
            return new stdClass();
        }
    };
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        $serializer,
        $transport,
        workerDispositionMetadata(),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($serializer->calls)->toBe(1)
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('rejects a negative worker sleep interval before polling transport', function (): void {
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        new WorkerDispositionTestSerializer(),
        new WorkerDispositionTestTransport(null),
    );

    expect(fn () => $worker->run(-1))->toThrow(InvalidArgumentException::class);
});
