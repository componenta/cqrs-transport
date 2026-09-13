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
final readonly class JsonContextAppendStateCommand
{
}

it('rejects lost array append state in transport context before sending or executing', function (array $values, int $removed, int $next): void {
    unset($values[$removed]);
    $attributes = ['data' => ['items' => $values]];
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
        new JsonOperationContextSerializer(['data']),
    )]);

    expect(fn () => $bus->dispatch(new JsonContextAppendStateCommand(), $attributes))
        ->toThrow(OperationContextSerializationException::class, 'JSON encoding changed operation transport context');
    expect($terminal->calls)->toBe(0);
    $unchanged = $attributes['data']['items'];
    $unchanged[] = 'next';
    expect(array_key_last($unchanged))->toBe($next);
})->with([
    'removed tail' => [['kept', 'removed'], 1, 2],
    'emptied array' => [['removed'], 0, 1],
    'negative indices' => [[-5 => 'kept', -4 => 'removed'], -4, -3],
    'removed maximum index' => [[0 => 'kept', PHP_INT_MAX => 'removed'], PHP_INT_MAX, PHP_INT_MAX],
]);

it('preserves representable array append behaviour in transport context', function (array $values, int $next): void {
    $serializer = new JsonOperationContextSerializer(['data']);
    $operation = Operation::create(new JsonContextAppendStateCommand(), ['data' => ['items' => $values]]);
    $restored = $serializer->deserialize($serializer->serialize($operation));
    expect($restored)->toBe(['data' => ['items' => $values]]);
    $original = $operation->attributes['data']['items'];
    $received = $restored['data']['items'];
    $original[] = 'next';
    $received[] = 'next';

    expect(array_key_last($original))->toBe($next)
        ->and(array_key_last($received))->toBe($next);
})->with([
    'list' => [['kept'], 1],
    'sparse array' => [[2 => 'kept', 8 => 'last'], 9],
    'negative index' => [[-5 => 'kept'], -4],
]);

it('preserves an occupied maximum index in transport context', function (): void {
    $serializer = new JsonOperationContextSerializer(['data']);
    $operation = Operation::create(new JsonContextAppendStateCommand(), ['data' => [PHP_INT_MAX => 'kept']]);
    $restored = $serializer->deserialize($serializer->serialize($operation));
    expect($restored)->toBe(['data' => [PHP_INT_MAX => 'kept']]);
    expect(static function () use ($restored): void {
        $values = $restored['data'];
        $values[] = 'next';
    })->toThrow(Error::class, 'Cannot add element');
});

it('ignores lossy append state outside the transport context allowlist', function (): void {
    $values = ['removed'];
    unset($values[0]);
    $operation = Operation::create(new JsonContextAppendStateCommand(), ['local' => $values]);
    $serializer = new JsonOperationContextSerializer();

    expect($serializer->serialize($operation))->toBe('{}')
        ->and($serializer->deserialize('{}'))->toBe([]);
});
