<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportException;

final readonly class JsonRoundTripCommand
{
    /** @param list<string> $tags */
    public function __construct(public int $id, public array $tags = []) {}
}
final class JsonStaticPropertyCommand
{
    public static string $global = 'must-not-leak';
    public function __construct(public int $id) {}
}
final class JsonHookedPropertyCommand
{
    public static int $reads = 0;
    public string $value { get { ++self::$reads; return 'computed'; } }
    public function __construct(string $value) {}
}
final readonly class JsonObjectPropertyCommand
{
    public function __construct(public DateTimeImmutable $at) {}
}
final readonly class JsonPrivateStateCommand
{
    public function __construct(private string $secret) {}
}
final class JsonVariadicCommand
{
    /** @var list<string> */ public array $values;
    public function __construct(string ...$values) { $this->values = $values; }
}
final readonly class JsonStrictTypesCommand
{
    public function __construct(public int $id, public float $ratio, public string|bool|null $label) {}
}
final readonly class JsonNullableNamedTypesCommand
{
    public function __construct(public ?string $label, public ?DateTimeImmutable $at) {}
}
final readonly class JsonLiteralTypeCommand
{
    public function __construct(public true $enabled, public false $disabled) {}
}
final class JsonCallableParameterCommand
{
    public mixed $callback;
    public function __construct(callable $callback) { $this->callback = $callback; }
}
final class JsonCallableUnionCommand
{
    public mixed $callback;
    public function __construct(callable|string $callback) { $this->callback = $callback; }
}
final class JsonClosureParameterCommand
{
    public mixed $callback;
    public function __construct(?Closure $callback) { $this->callback = $callback; }
}
final readonly class JsonStringCapabilityNameCommand
{
    public function __construct(public string $callback) {}
}
final class JsonCallableTarget
{
    public static function run(): void {}
}

it('round-trips supported constructor-backed JSON values with a versioned envelope', function (): void {
    $serializer = new JsonCommandSerializer();
    $command = new JsonRoundTripCommand(7, ['one', 'two']);
    $payload = $serializer->serialize($command);
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $roundTrip = $serializer->deserialize($payload, JsonRoundTripCommand::class);
    $reordered = $serializer->deserialize('{"data":{"id":9,"tags":[]},"__componenta_cqrs":1}', JsonRoundTripCommand::class);
    $legacy = $serializer->deserialize('{"id":8,"tags":["legacy"]}', JsonRoundTripCommand::class);

    expect($decoded)->toBe(['__componenta_cqrs' => 1, 'data' => ['id' => 7, 'tags' => ['one', 'two']]])
        ->and($roundTrip)->toEqual($command)
        ->and($reordered)->toEqual(new JsonRoundTripCommand(9))
        ->and($legacy)->toEqual(new JsonRoundTripCommand(8, ['legacy']));
});

it('ignores public static properties instead of leaking them into payloads', function (): void {
    $payload = (new JsonCommandSerializer())->serialize(new JsonStaticPropertyCommand(9));
    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data'])->toBe(['id' => 9]);
});

it('rejects hooked properties without invoking their getters', function (): void {
    JsonHookedPropertyCommand::$reads = 0;
    expect(fn () => (new JsonCommandSerializer())->serialize(new JsonHookedPropertyCommand('input')))
        ->toThrow(TransportException::class, 'hooked or virtual property')
        ->and(JsonHookedPropertyCommand::$reads)->toBe(0);
});

it('validates decoded fields with strict PHP type semantics before reflection instantiation', function (): void {
    $serializer = new JsonCommandSerializer();
    $command = $serializer->deserialize('{"id":12,"ratio":3,"label":null}', JsonStrictTypesCommand::class);
    $literal = $serializer->deserialize('{"enabled":true,"disabled":false}', JsonLiteralTypeCommand::class);
    expect($command)->toEqual(new JsonStrictTypesCommand(12, 3.0, null))
        ->and($literal)->toEqual(new JsonLiteralTypeCommand(true, false));
});

it('accepts null for nullable named scalar and class constructor types', function (): void {
    $command = (new JsonCommandSerializer())->deserialize('{"label":null,"at":null}', JsonNullableNamedTypesCommand::class);
    expect($command)->toEqual(new JsonNullableNamedTypesCommand(null, null));
});

it('rejects scalar coercion, invalid union members, and non-nullable null', function (string $payload, string $message): void {
    expect(fn () => (new JsonCommandSerializer())->deserialize($payload, JsonStrictTypesCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    ['{"id":"12","ratio":3,"label":null}', 'must match int; string given'],
    ['{"id":12,"ratio":3,"label":1}', 'must match string|bool|null; int given'],
    ['{"id":12,"ratio":true,"label":null}', 'must match float; bool given'],
    ['{"id":null,"ratio":3,"label":null}', 'must match int; null given'],
]);

it('rejects executable capability types before inspecting transported values', function (Closure $operation): void {
    expect($operation)->toThrow(
        TransportException::class,
        'does not support executable callable constructor parameter',
    );
})->with([
    'serialize callable' => [
        fn () => (new JsonCommandSerializer())->serialize(
            new JsonCallableParameterCommand('strlen'),
        ),
    ],
    'callable string' => [
        fn () => (new JsonCommandSerializer())->deserialize(
            '{"callback":"system"}',
            JsonCallableParameterCommand::class,
        ),
    ],
    'callable array' => [
        fn () => (new JsonCommandSerializer())->deserialize(
            json_encode([
                'callback' => [JsonCallableTarget::class, 'run'],
            ], JSON_THROW_ON_ERROR),
            JsonCallableParameterCommand::class,
        ),
    ],
    'union containing callable' => [
        fn () => (new JsonCommandSerializer())->deserialize(
            '{"callback":"safe"}',
            JsonCallableUnionCommand::class,
        ),
    ],
    'nullable Closure' => [
        fn () => (new JsonCommandSerializer())->deserialize(
            '{"callback":null}',
            JsonClosureParameterCommand::class,
        ),
    ],
]);

it('keeps executable-looking text as ordinary data for string fields', function (): void {
    $command = (new JsonCommandSerializer())->deserialize(
        '{"callback":"system"}',
        JsonStringCapabilityNameCommand::class,
    );

    expect($command)->toEqual(new JsonStringCapabilityNameCommand('system'));
});

it('fails fast for unsupported command shapes and unknown payload fields', function (Closure $operation, string $message): void {
    expect($operation)->toThrow(TransportException::class, $message);
})->with([
    [fn () => (new JsonCommandSerializer())->serialize(new JsonObjectPropertyCommand(new DateTimeImmutable())), 'configure a custom serializer'],
    [fn () => (new JsonCommandSerializer())->serialize(new JsonPrivateStateCommand('secret')), 'to be public'],
    [fn () => (new JsonCommandSerializer())->serialize(new JsonVariadicCommand('a', 'b')), 'variadic or by-reference'],
    [fn () => (new JsonCommandSerializer())->deserialize('{"id":1,"tags":[],"static":"leak"}', JsonRoundTripCommand::class), 'unknown field'],
]);
