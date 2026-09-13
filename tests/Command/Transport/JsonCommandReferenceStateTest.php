<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBus;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Transport\Attribute\Async;

#[Async]
final readonly class JsonReferenceArrayCommand
{
    public function __construct(public array $data)
    {
    }
}

it('rejects aliased array elements before a JSON command can be sent or executed', function (): void {
    $value = 1;
    $command = new JsonReferenceArrayCommand(['nested' => ['first' => &$value, 'second' => &$value]]);
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->never())->method('send');
    $registry = new TransportRegistry();
    $registry->register('default', $transport);
    $terminal = new class () implements OperationHandlerInterface {
        public int $calls = 0;

        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;
            return $operation;
        }
    };
    $bus = new CommandBus($terminal, [new TransportMiddleware(
        $registry,
        new JsonCommandSerializer(),
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    )]);

    expect(fn () => $bus->dispatch($command))
        ->toThrow(TransportException::class, 'PHP reference');
    expect($terminal->calls)->toBe(0);
    $value = 2;
    expect($command->data)->toBe(['nested' => ['first' => 2, 'second' => 2]]);
});


final class JsonReferencePropertyCommand
{
    public function __construct(public int $first, public int $second)
    {
    }
}

it('rejects references between command properties before extracting their values', function (): void {
    $command = new JsonReferencePropertyCommand(1, 1);
    $command->second = &$command->first;

    expect(fn () => (new JsonCommandSerializer())->serialize($command))
        ->toThrow(TransportException::class, 'PHP reference');
    $command->first = 2;
    expect($command->second)->toBe(2);
});


final class JsonHydratedReferencePropertiesCommand
{
    public function __construct(public int $first, public int $second)
    {
        $this->second = &$this->first;
    }
}

final class JsonHydratedReferenceArrayCommand
{
    public function __construct(public array $data)
    {
        $this->data['second'] = &$this->data['first'];
    }
}

it('rejects command reconstruction that introduces PHP references', function (string $class, array $data): void {
    $payload = json_encode(['__componenta_cqrs' => 1, 'data' => $data], JSON_THROW_ON_ERROR);

    expect(fn () => (new JsonCommandSerializer())->deserialize($payload, $class))
        ->toThrow(TransportException::class, 'PHP reference');
})->with([
    'between properties' => [JsonHydratedReferencePropertiesCommand::class, ['first' => 1, 'second' => 1]],
    'inside an array' => [JsonHydratedReferenceArrayCommand::class, ['data' => ['first' => 1, 'second' => 1]]],
]);


it('preserves ordinary equal values without turning them into references', function (): void {
    $serializer = new JsonCommandSerializer();
    $sharedValue = ['value' => 1];
    $command = new JsonReferenceArrayCommand(['first' => $sharedValue, 'second' => $sharedValue]);
    $restored = $serializer->deserialize($serializer->serialize($command), $command::class);
    expect($restored->data)->toBe(['first' => ['value' => 1], 'second' => ['value' => 1]]);
    $data = $restored->data;
    $data['first']['value'] = 2;
    expect($data['second']['value'])->toBe(1);

    $properties = new JsonReferencePropertyCommand(1, 1);
    $restored = $serializer->deserialize($serializer->serialize($properties), $properties::class);
    $restored->first = 2;
    expect($restored->second)->toBe(1);
});
