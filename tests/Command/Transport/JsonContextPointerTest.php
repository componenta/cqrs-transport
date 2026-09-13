<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializationException;

it('rejects transport context array pointer loss without changing the source operation', function (bool $nested, int $steps): void {
    $values = ['first' => 'a', 'second' => 'b'];
    for ($step = 0; $step < $steps; ++$step) {
        next($values);
    }
    $operation = Operation::create(new stdClass(), ['data' => $nested ? ['items' => $values] : $values]);
    $serializer = new JsonOperationContextSerializer(['data']);

    expect(fn () => $serializer->serialize($operation))
        ->toThrow(OperationContextSerializationException::class, 'JSON encoding changed operation transport context');
    $unchanged = $nested ? $operation->attributes['data']['items'] : $operation->attributes['data'];
    expect(key($unchanged))->toBe($steps === 1 ? 'second' : null)
        ->and(current($unchanged))->toBe($steps === 1 ? 'b' : false);
})->with([false, true])->with([1, 2]);


it('preserves portable context array pointers', function (array $values, int|string|null $key, mixed $value): void {
    $serializer = new JsonOperationContextSerializer(['data']);
    $operation = Operation::create(new stdClass(), ['data' => $values]);
    $restored = $serializer->deserialize($serializer->serialize($operation));

    expect(key($restored['data']))->toBe($key)
        ->and(current($restored['data']))->toBe($value);
})->with([
    'empty array' => [[], null, false],
    'zero key with false' => [[false], 0, false],
    'negative key with null' => [[-5 => null], -5, null],
    'string key' => [['first' => 'a', 'second' => 'b'], 'first', 'a'],
]);

it('ignores nonportable array pointers outside the transport allowlist', function (): void {
    $values = ['a', 'b'];
    next($values);
    $operation = Operation::create(new stdClass(), ['local' => $values]);

    expect((new JsonOperationContextSerializer())->serialize($operation))->toBe('{}');
});
