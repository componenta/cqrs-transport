<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Transport\Attribute\Async;

class JsonNativeStorageBase extends SplObjectStorage
{
}

#[Async]
final class JsonNativeStorageCommand extends JsonNativeStorageBase
{
    public static int $constructions = 0;

    public function __construct(public int $id)
    {
        ++self::$constructions;
    }
}

it('rejects inherited native state before sending a JSON command', function (): void {
    JsonNativeStorageCommand::$constructions = 0;
    $command = new JsonNativeStorageCommand(7);
    $command[new stdClass()] = 'required-item';
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->never())->method('send');
    $handler = $this->createMock(OperationHandlerInterface::class);
    $handler->expects($this->never())->method('handle');
    $registry = new TransportRegistry();
    $registry->register('default', $transport);
    $middleware = new TransportMiddleware(
        $registry,
        new JsonCommandSerializer(),
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    );

    expect(fn () => $middleware->execute(Operation::create($command), $handler))
        ->toThrow(TransportException::class, 'SplObjectStorage');
    expect($command)->toHaveCount(1)
        ->and(JsonNativeStorageCommand::$constructions)->toBe(1);
});

it('rejects JSON reconstruction of native state before invoking the constructor', function (string $class, array $data): void {
    JsonNativeStorageCommand::$constructions = 0;
    $payload = json_encode(['__componenta_cqrs' => 1, 'data' => $data], JSON_THROW_ON_ERROR);

    expect(fn () => (new JsonCommandSerializer())->deserialize($payload, $class))
        ->toThrow(TransportException::class, 'SplObjectStorage');
    expect(JsonNativeStorageCommand::$constructions)->toBe(0);
})->with([
    'internal class' => [SplObjectStorage::class, []],
    'indirect internal ancestor' => [JsonNativeStorageCommand::class, ['id' => 7]],
]);

class JsonStoredStateBase extends stdClass
{
    public function __construct(public int $id)
    {
    }
}

final class JsonStoredStateChild extends JsonStoredStateBase
{
}

it('preserves public constructor state inherited through a stdClass ancestor', function (): void {
    $serializer = new JsonCommandSerializer();
    $payload = $serializer->serialize(new JsonStoredStateChild(7));
    $restored = $serializer->deserialize($payload, JsonStoredStateChild::class);

    expect($restored)->toBeInstanceOf(JsonStoredStateChild::class)
        ->and($restored->id)->toBe(7);
});
