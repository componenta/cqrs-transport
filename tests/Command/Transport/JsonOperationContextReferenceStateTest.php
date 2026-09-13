<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBus;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializationException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Transport\Attribute\Async;

#[Async]
final readonly class JsonContextReferenceCommand
{
}

it('rejects transportable context references before sending or executing a command', function (Closure $attributes): void {
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
        new JsonOperationContextSerializer(['data', 'first', 'second']),
    )]);

    expect(fn () => $bus->dispatch(new JsonContextReferenceCommand(), $attributes()))
        ->toThrow(OperationContextSerializationException::class, 'PHP reference');
    expect($terminal->calls)->toBe(0);
})->with([
    'nested array' => [static function (): array {
        $value = 1;
        return ['data' => ['nested' => ['first' => &$value, 'second' => &$value]]];
    }],
    'between attributes' => [static function (): array {
        $value = 1;
        return ['first' => &$value, 'second' => &$value];
    }],
]);


it('preserves ordinary context values and ignores references outside the allowlist', function (): void {
    $local = 1;
    $sharedValue = ['value' => 1];
    $operation = Operation::create(new JsonContextReferenceCommand(), [
        'data' => ['first' => $sharedValue, 'second' => $sharedValue],
        'local_first' => &$local,
        'local_second' => &$local,
    ]);
    $serializer = new JsonOperationContextSerializer(['data']);
    $restored = $serializer->deserialize($serializer->serialize($operation));

    expect($restored)->toBe(['data' => ['first' => ['value' => 1], 'second' => ['value' => 1]]]);
    $restored['data']['first']['value'] = 2;
    expect($restored['data']['second']['value'])->toBe(1);
    $local = 3;
    expect($operation->attributes['local_first'])->toBe(3)
        ->and($operation->attributes['local_second'])->toBe(3);
});
