<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerSupportInterface;
use Componenta\CQRS\Command\Transport\CompositeCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportException;

final readonly class CompositeSpecialCommand
{
    public function __construct(public int $id) {}
}

final readonly class CompositeOtherCommand
{
    public function __construct(public int $id) {}
}

final class CompositeRecordingSerializer implements CommandSerializerInterface, CommandSerializerSupportInterface
{
    /** @var list<object|string> */
    public array $supportChecks = [];

    /** @var list<object> */
    public array $serialized = [];

    /** @var list<array{string, string}> */
    public array $deserialized = [];

    public function __construct(
        private readonly string $supportedClass,
        private readonly string $payload,
        private readonly object $result,
    ) {}

    public function supportsCommand(object|string $command): bool
    {
        $this->supportChecks[] = $command;
        $class = is_object($command) ? $command::class : $command;

        return $class === $this->supportedClass;
    }

    public function serialize(object $command): string
    {
        $this->serialized[] = $command;

        return $this->payload;
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        $this->deserialized[] = [$payload, $commandClass];

        return $this->result;
    }
}

it('delegates serialization to the first supporting serializer', function (): void {
    $command = new CompositeSpecialCommand(1);
    $first = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'first',
        new CompositeSpecialCommand(10),
    );
    $second = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'second',
        new CompositeSpecialCommand(20),
    );
    $composite = new CompositeCommandSerializer([$first, $second]);

    expect($composite->serialize($command))->toBe('first')
        ->and($first->serialized)->toBe([$command])
        ->and($second->serialized)->toBe([]);
});

it('preserves iterable order independently of iterable keys', function (): void {
    $first = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'first',
        new CompositeSpecialCommand(10),
    );
    $second = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'second',
        new CompositeSpecialCommand(20),
    );
    $serializers = (static function () use ($first, $second): Generator {
        yield 'preferred' => $first;
        yield 'later' => $second;
    })();
    $composite = new CompositeCommandSerializer($serializers);

    expect($composite->serialize(new CompositeSpecialCommand(1)))->toBe('first')
        ->and($first->serialized)->toHaveCount(1)
        ->and($second->serialized)->toBe([]);
});

it('uses class-based support selection before deserialization', function (): void {
    $result = new CompositeSpecialCommand(10);
    $serializer = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'payload',
        $result,
    );
    $composite = new CompositeCommandSerializer([$serializer]);

    expect($composite->deserialize('wire', CompositeSpecialCommand::class))->toBe($result)
        ->and($serializer->deserialized)->toBe([
            ['wire', CompositeSpecialCommand::class],
        ]);
});

it('rejects instance-dependent support before serialization can choose a different owner than deserialization', function (bool $instanceSupport): void {
    $unstable = new class($instanceSupport) implements CommandSerializerInterface, CommandSerializerSupportInterface {
        public function __construct(private readonly bool $instanceSupport) {}

        public function supportsCommand(object|string $command): bool
        {
            return is_object($command) ? $this->instanceSupport : !$this->instanceSupport;
        }

        public function serialize(object $command): string
        {
            return 'unstable';
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            return new CompositeSpecialCommand(1);
        }
    };
    $composite = new CompositeCommandSerializer([$unstable, new JsonCommandSerializer()]);

    expect(fn() => $composite->serialize(new CompositeSpecialCommand(1)))
        ->toThrow(TransportException::class, 'instance-dependent support');
})->with([
    'instance true, class false' => [true],
    'instance false, class true' => [false],
]);

it('does not fall through after a supporting serializer fails', function (): void {
    $failing = new class implements CommandSerializerInterface, CommandSerializerSupportInterface {
        public function supportsCommand(object|string $command): bool
        {
            return true;
        }

        public function serialize(object $command): string
        {
            throw new TransportException('owned failure');
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            throw new TransportException('owned failure');
        }
    };
    $fallback = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'fallback',
        new CompositeSpecialCommand(1),
    );
    $composite = new CompositeCommandSerializer([$failing, $fallback]);

    expect(fn() => $composite->serialize(new CompositeSpecialCommand(1)))
        ->toThrow(TransportException::class, 'owned failure')
        ->and($fallback->serialized)->toBe([]);

    expect(fn() => $composite->deserialize('wire', CompositeSpecialCommand::class))
        ->toThrow(TransportException::class, 'owned failure')
        ->and($fallback->deserialized)->toBe([]);
});

it('throws when no serializer supports the command', function (): void {
    $serializer = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'special',
        new CompositeSpecialCommand(1),
    );
    $composite = new CompositeCommandSerializer([$serializer]);

    expect(fn() => $composite->serialize(new CompositeOtherCommand(2)))
        ->toThrow(TransportException::class, 'No command serializer supports');
});

it('uses JsonCommandSerializer as a broad fallback when it is ordered last', function (): void {
    $special = new CompositeRecordingSerializer(
        CompositeSpecialCommand::class,
        'special',
        new CompositeSpecialCommand(1),
    );
    $composite = new CompositeCommandSerializer([$special, new JsonCommandSerializer()]);
    $other = new CompositeOtherCommand(7);

    $payload = $composite->serialize($other);
    $restored = $composite->deserialize($payload, CompositeOtherCommand::class);

    expect($restored)->toEqual($other);
});
