<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportException;

final readonly class JsonPointerCommand
{
    public function __construct(public array $data)
    {
    }
}

it('rejects command array pointer loss without changing the original command', function (bool $nested, int $steps): void {
    $values = ['first' => 'a', 'second' => 'b'];
    for ($step = 0; $step < $steps; ++$step) {
        next($values);
    }
    $command = new JsonPointerCommand($nested ? ['items' => $values] : $values);

    expect(fn () => (new JsonCommandSerializer())->serialize($command))
        ->toThrow(TransportException::class, 'JSON encoding changed command state');
    $unchanged = $nested ? $command->data['items'] : $command->data;
    expect(key($unchanged))->toBe($steps === 1 ? 'second' : null)
        ->and(current($unchanged))->toBe($steps === 1 ? 'b' : false);
})->with([false, true])->with([1, 2]);

final class JsonPointerChangingConstructorCommand
{
    public function __construct(public array $data)
    {
        next($this->data);
    }
}

it('rejects reconstruction that changes only the command array pointer', function (): void {
    $payload = '{"__componenta_cqrs":1,"data":{"data":["a","b"]}}';

    expect(fn () => (new JsonCommandSerializer())->deserialize($payload, JsonPointerChangingConstructorCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field');
});


it('preserves portable command array pointers', function (array $values, int|string|null $key, mixed $value): void {
    $serializer = new JsonCommandSerializer();
    $restored = $serializer->deserialize($serializer->serialize(new JsonPointerCommand($values)), JsonPointerCommand::class);

    expect($restored)->toBeInstanceOf(JsonPointerCommand::class);
    expect(key($restored->data))->toBe($key)
        ->and(current($restored->data))->toBe($value);
})->with([
    'empty array' => [[], null, false],
    'zero key with false' => [[false], 0, false],
    'negative key with null' => [[-5 => null], -5, null],
    'string key' => [['first' => 'a', 'second' => 'b'], 'first', 'a'],
]);
