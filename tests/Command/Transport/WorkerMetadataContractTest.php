<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Transport\Attribute\Async;

#[Async('queue')]
final readonly class WorkerMetadataCommand
{
    public function __construct(public int $id)
    {
    }
}
final readonly class WorkerSynchronousCommand
{
    public function __construct(public int $id)
    {
    }
}

it('uses command attributes to admit deliveries without any handler map', function (
    string $commandClass,
    string $workerTransport,
    string $payload,
    bool $accepted,
): void {
    $envelope = new Envelope('operation', $commandClass, $payload, 'receipt');
    $transport = $this->createMock(TransportInterface::class);
    $transport->method('get')->willReturn($envelope);
    $transport->expects($accepted ? $this->once() : $this->never())->method('ack')->with($envelope);
    $transport->expects($accepted ? $this->never() : $this->once())->method('reject')->with($envelope);
    $bus = new class () implements CommandBusInterface {
        public array $commands = [];
        public function dispatch(object $command, array $attributes = []): OperationInterface
        {
            $this->commands[] = $command;
            return Operation::create($command, $attributes);
        }
    };
    $worker = new CommandWorker(
        bus: $bus,
        serializer: new JsonCommandSerializer(),
        contextSerializer: new JsonOperationContextSerializer(),
        transport: $transport,
        transportName: $workerTransport,
    );

    expect($worker->processOne())->toBeTrue();
    expect($bus->commands)->toEqual($accepted ? [new WorkerMetadataCommand(7)] : []);
})->with([
    'async command absent from maps' => [WorkerMetadataCommand::class, 'queue', '{"__componenta_cqrs":1,"data":{"id":7}}', true],
    'wrong transport' => [WorkerMetadataCommand::class, 'other', '{"__componenta_cqrs":1,"data":{"id":7}}', false],
    'synchronous command' => [WorkerSynchronousCommand::class, 'queue', '{"__componenta_cqrs":1,"data":{"id":7}}', false],
    'serializer rejects the payload' => [WorkerMetadataCommand::class, 'queue', '{"__componenta_cqrs":1,"data":{"id":"7"}}', false],
]);

it('propagates serialization failures without sending or executing the command', function (): void {
    $failure = new RuntimeException('unsupported command state');
    $serializer = $this->createStub(CommandSerializerInterface::class);
    $serializer->method('serialize')->willThrowException($failure);
    $registry = $this->createMock(TransportRegistryInterface::class);
    $registry->expects($this->never())->method('get');
    $handler = $this->createMock(OperationHandlerInterface::class);
    $handler->expects($this->never())->method('handle');
    $middleware = new TransportMiddleware(
        $registry,
        $serializer,
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    );

    expect(fn () => $middleware->execute(Operation::create(new WorkerMetadataCommand(7)), $handler))
        ->toThrow($failure);
});
