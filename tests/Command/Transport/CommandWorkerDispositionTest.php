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
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;

final class WorkerDispositionTestBus implements CommandBusInterface
{
    public function __construct(private readonly ?Throwable $failure = null) {}

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
    public function serialize(object $command): string { return '{}'; }
    public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
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

    public function send(Envelope $envelope, int $delay = 0): Envelope { return $envelope; }

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

function workerDispositionMap(string ...$commands): CqrsMapProviderInterface
{
    $known = [];

    foreach ($commands as $command) {
        $known[$command] = true;
    }

    return new class ($known) implements CqrsMapProviderInterface {
        /** @param array<string, true> $known */
        public function __construct(private readonly array $known) {}

        public function map(): CqrsMap
        {
            return new CqrsMap(knownCommands: $this->known);
        }
    };
}

function workerDispositionWorker(
    WorkerDispositionTestBus $bus,
    CommandSerializerInterface $serializer,
    WorkerDispositionTestTransport $transport,
    ?CqrsMapProviderInterface $commands = null,
): CommandWorker {
    return new CommandWorker(
        $bus,
        $serializer,
        new JsonOperationContextSerializer(),
        $transport,
        $commands ?? workerDispositionMap(stdClass::class),
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

    expect(fn() => $worker->processOne())
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
        workerDispositionMap(DateTimeImmutable::class),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('rejects a command outside the configured map before deserialization', function (): void {
    $transport = new WorkerDispositionTestTransport(workerDispositionEnvelope());
    $serializer = new class implements CommandSerializerInterface {
        public int $calls = 0;
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { ++$this->calls; return new stdClass(); }
    };
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        $serializer,
        $transport,
        workerDispositionMap(),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($serializer->calls)->toBe(0)
        ->and($transport->acks)->toBe(0)
        ->and($transport->rejects)->toBe(1);
});

it('rejects a negative worker sleep interval before polling transport', function (): void {
    $worker = workerDispositionWorker(
        new WorkerDispositionTestBus(),
        new WorkerDispositionTestSerializer(),
        new WorkerDispositionTestTransport(null),
    );

    expect(fn() => $worker->run(-1))->toThrow(InvalidArgumentException::class);
});
