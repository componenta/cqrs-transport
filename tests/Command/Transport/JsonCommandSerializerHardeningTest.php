<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\CommandSerializerSupportInterface;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportException;

final class JsonNonIdempotentCommand
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id + 1;
    }
}

final readonly class JsonNestedArrayCommand
{
    public function __construct(public array $data) {}
}

final class JsonHydrationProbeCommand
{
    public static int $constructions = 0;

    public function __construct(public array $data)
    {
        ++self::$constructions;
    }
}

it('advertises broad command support for composite fallback use', function (): void {
    $serializer = new JsonCommandSerializer();

    expect($serializer)->toBeInstanceOf(CommandSerializerSupportInterface::class)
        ->and($serializer->supportsCommand(new JsonNestedArrayCommand([])))->toBeTrue()
        ->and($serializer->supportsCommand(JsonNestedArrayCommand::class))->toBeTrue();
});

it('rejects reconstruction that changes constructor-backed state', function (): void {
    $serializer = new JsonCommandSerializer();
    $payload = $serializer->serialize(new JsonNonIdempotentCommand(1));

    expect(fn() => $serializer->deserialize($payload, JsonNonIdempotentCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field "id"');
});

it('rejects recursive arrays before unbounded recursion can exhaust the process', function (): void {
    $recursive = [];
    $recursive['self'] = &$recursive;
    $serializer = new JsonCommandSerializer();

    expect(fn() => $serializer->serialize(new JsonNestedArrayCommand($recursive)))
        ->toThrow(TransportException::class, 'maximum JSON nesting depth');
});

it('rejects excessively deep arrays deterministically', function (): void {
    $nested = 'leaf';

    for ($i = 0; $i < 70; ++$i) {
        $nested = [$nested];
    }

    $serializer = new JsonCommandSerializer();

    expect(fn() => $serializer->serialize(new JsonNestedArrayCommand($nested)))
        ->toThrow(TransportException::class, 'maximum JSON nesting depth');
});

it('rejects excessive payload nesting before command construction', function (): void {
    $nested = 'leaf';

    for ($i = 0; $i < 70; ++$i) {
        $nested = [$nested];
    }

    JsonHydrationProbeCommand::$constructions = 0;
    $payload = json_encode([
        '__componenta_cqrs' => JsonCommandSerializer::FORMAT_VERSION,
        'data' => ['data' => $nested],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => (new JsonCommandSerializer())->deserialize(
        $payload,
        JsonHydrationProbeCommand::class,
    ))->toThrow(TransportException::class, 'maximum JSON nesting depth')
        ->and(JsonHydrationProbeCommand::$constructions)->toBe(0);
});
