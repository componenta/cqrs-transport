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

final readonly class JsonFloatCommand
{
    public function __construct(public float $value) {}
}

final readonly class JsonMixedNumericCommand
{
    public function __construct(public mixed $value) {}
}

final class JsonNumericMutatingCommand
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = (float) $value;
    }
}

final class JsonHydrationProbeCommand
{
    public static int $constructions = 0;

    public function __construct(public array $data)
    {
        ++self::$constructions;
    }
}

#[AllowDynamicProperties]
final class JsonDynamicStateCommand
{
    public function __construct(public int $id) {}
}

#[AllowDynamicProperties]
final class JsonDynamicHydrationCommand
{
    public function __construct(public int $id)
    {
        $this->runtimeState = 'created';
    }
}

class JsonInheritedPrivateStateBase
{
    private string $secret = 'hidden';
}

final class JsonInheritedPrivateStateCommand extends JsonInheritedPrivateStateBase
{
    public function __construct(public int $id) {}
}

it('advertises broad command support for composite fallback use', function (): void {
    $serializer = new JsonCommandSerializer();

    expect($serializer)->toBeInstanceOf(CommandSerializerSupportInterface::class)
        ->and($serializer->supportsCommand(new JsonNestedArrayCommand([])))->toBeTrue()
        ->and($serializer->supportsCommand(JsonNestedArrayCommand::class))->toBeTrue();
});

it('preserves float wire types exactly including nested values', function (): void {
    $serializer = new JsonCommandSerializer();
    $payload = $serializer->serialize(new JsonMixedNumericCommand([
        'top' => 1.0,
        'negative_zero' => -0.0,
    ]));
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, JsonMixedNumericCommand::class);

    expect($decoded['data']['value']['top'])->toBeFloat()->toBe(1.0)
        ->and($decoded['data']['value']['negative_zero'])->toBeFloat()
        ->and(bin2hex(pack('E', $decoded['data']['value']['negative_zero'])))
        ->toBe(bin2hex(pack('E', -0.0)))
        ->and($restored->value['top'])->toBeFloat()->toBe(1.0)
        ->and(bin2hex(pack('E', $restored->value['negative_zero'])))
        ->toBe(bin2hex(pack('E', -0.0)));
});

it('rejects a JSON integer for a float constructor field', function (): void {
    $payload = json_encode([
        '__componenta_cqrs' => JsonCommandSerializer::FORMAT_VERSION,
        'data' => ['value' => 1],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => (new JsonCommandSerializer())->deserialize($payload, JsonFloatCommand::class))
        ->toThrow(TransportException::class, 'must match float; int given');
});

it('rejects numeric type mutation for mixed constructor-backed state', function (): void {
    $payload = json_encode([
        '__componenta_cqrs' => JsonCommandSerializer::FORMAT_VERSION,
        'data' => ['value' => 1],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => (new JsonCommandSerializer())->deserialize(
        $payload,
        JsonNumericMutatingCommand::class,
    ))->toThrow(TransportException::class, 'changed constructor-backed field "value"');
});

it('rejects reconstruction that changes constructor-backed state', function (): void {
    $serializer = new JsonCommandSerializer();
    $payload = $serializer->serialize(new JsonNonIdempotentCommand(1));

    expect(fn() => $serializer->deserialize($payload, JsonNonIdempotentCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field "id"');
});

it('rejects dynamic command state instead of silently dropping it', function (): void {
    $command = new JsonDynamicStateCommand(1);
    $command->runtimeState = 'producer-only';

    expect(fn() => (new JsonCommandSerializer())->serialize($command))
        ->toThrow(TransportException::class, 'unsupported dynamic property(s): runtimeState');
});

it('rejects dynamic state created during command reconstruction', function (): void {
    $payload = json_encode([
        '__componenta_cqrs' => JsonCommandSerializer::FORMAT_VERSION,
        'data' => ['id' => 1],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => (new JsonCommandSerializer())->deserialize(
        $payload,
        JsonDynamicHydrationCommand::class,
    ))->toThrow(TransportException::class, 'unsupported dynamic property(s): runtimeState');
});

it('rejects inherited private command state on serialization and reconstruction', function (): void {
    $serializer = new JsonCommandSerializer();

    expect(fn() => $serializer->serialize(new JsonInheritedPrivateStateCommand(1)))
        ->toThrow(TransportException::class, 'inherited private property')
        ->and(fn() => $serializer->deserialize(
            json_encode([
                '__componenta_cqrs' => JsonCommandSerializer::FORMAT_VERSION,
                'data' => ['id' => 1],
            ], JSON_THROW_ON_ERROR),
            JsonInheritedPrivateStateCommand::class,
        ))->toThrow(TransportException::class, 'inherited private property');
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
