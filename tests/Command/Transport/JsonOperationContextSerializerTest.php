<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\OperationContextSerializationException;

it('round-trips only explicitly allowlisted operation attributes', function (): void {
    $serializer = new JsonOperationContextSerializer(['tenant', 'trace']);
    $operation = Operation::create(new stdClass(), [
        'tenant' => 'main',
        'trace' => ['id' => 123, 'sampled' => true],
        'local_only' => new stdClass(),
    ]);

    $payload = $serializer->serialize($operation);

    expect($payload)->toBe('{"tenant":"main","trace":{"id":123,"sampled":true}}')
        ->and($serializer->deserialize($payload))->toBe([
            'tenant' => 'main',
            'trace' => ['id' => 123, 'sampled' => true],
        ]);
});

it('preserves exact numeric JSON types including signed zero', function (): void {
    $serializer = new JsonOperationContextSerializer(['values']);
    $payload = $serializer->serialize(Operation::create(new stdClass(), [
        'values' => [1, 1.0, -0.0],
    ]));
    $values = $serializer->deserialize($payload)['values'];

    expect($values[0])->toBeInt()->toBe(1)
        ->and($values[1])->toBeFloat()->toBe(1.0)
        ->and($values[2])->toBeFloat()
        ->and(pack('E', $values[2]))->toBe(pack('E', -0.0));
});

it('fails closed when PHP JSON precision would change context state', function (): void {
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '3');

    try {
        $serializer = new JsonOperationContextSerializer(['value']);

        expect(fn() => $serializer->serialize(Operation::create(
            new stdClass(),
            ['value' => 1.2345678901234567],
        )))->toThrow(
            OperationContextSerializationException::class,
            'JSON encoding changed operation transport context',
        );
    } finally {
        if ($previous !== false) {
            ini_set('serialize_precision', $previous);
        }
    }
});

it('rejects reserved runtime attributes in the allowlist', function (): void {
    expect(fn() => new JsonOperationContextSerializer(['__execution_mode']))
        ->toThrow(InvalidArgumentException::class, 'reserved');
});

it('rejects non-string allowlist entries', function (): void {
    expect(fn() => new JsonOperationContextSerializer([123]))
        ->toThrow(InvalidArgumentException::class, 'non-empty string');
});

it('rejects non-allowlisted attributes from incoming payloads', function (): void {
    $serializer = new JsonOperationContextSerializer(['tenant']);

    expect(fn() => $serializer->deserialize('{"tenant":"main","admin":true}'))
        ->toThrow(OperationContextSerializationException::class, 'non-allowlisted');
});

it('rejects executable or object values instead of implicitly serializing them', function (): void {
    $serializer = new JsonOperationContextSerializer(['value']);

    expect(fn() => $serializer->serialize(
        Operation::create(new stdClass(), ['value' => new stdClass()]),
    ))->toThrow(OperationContextSerializationException::class, 'unsupported value');
});

it('rejects non-finite floating point values', function (float $value): void {
    $serializer = new JsonOperationContextSerializer(['value']);

    expect(fn() => $serializer->serialize(
        Operation::create(new stdClass(), ['value' => $value]),
    ))->toThrow(OperationContextSerializationException::class, 'non-finite');
})->with([NAN, INF, -INF]);
