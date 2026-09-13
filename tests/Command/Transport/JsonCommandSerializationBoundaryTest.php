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

#[Async]
final readonly class JsonSerializationPrivateConstructorCommand
{
    private function __construct(public int $id)
    {
    }

    public static function create(): self
    {
        return new self(7);
    }
}

#[Async]
final readonly class JsonSerializationConvertedStateCommand
{
    public string $id;

    public function __construct(int $id)
    {
        $this->id = (string) $id;
    }
}

it('rejects unrestorable constructor state before sending JSON commands', function (Closure $command, string $error): void {
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
    $operation = Operation::create($command());

    expect(fn () => $middleware->execute($operation, $handler))
        ->toThrow(TransportException::class, $error);
})->with([
    'private constructor' => [
        static fn () => JsonSerializationPrivateConstructorCommand::create(),
        'must be instantiable',
    ],
    'stored value incompatible with constructor' => [
        static fn () => new JsonSerializationConvertedStateCommand(7),
        'must match int; string given',
    ],
]);


final class JsonSerializationConstructionProbe
{
    public static int $constructions = 0;

    public function __construct(public int $id, public float $amount = 1.0)
    {
        ++self::$constructions;
    }
}

it('serializes supported state without constructing the command again', function (): void {
    JsonSerializationConstructionProbe::$constructions = 0;
    $command = new JsonSerializationConstructionProbe(7);
    $serializer = new JsonCommandSerializer();

    $payload = $serializer->serialize($command);

    expect(JsonSerializationConstructionProbe::$constructions)->toBe(1)
        ->and(json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['__componenta_cqrs' => 1, 'data' => ['id' => 7, 'amount' => 1.0]]);

    $restored = $serializer->deserialize($payload, JsonSerializationConstructionProbe::class);

    expect($restored)->toBeInstanceOf(JsonSerializationConstructionProbe::class)
        ->and($restored->id)->toBe(7)
        ->and($restored->amount)->toBe(1.0)
        ->and(JsonSerializationConstructionProbe::$constructions)->toBe(2);
});
