<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Transport\Attribute\Async;

it('does not classify transport lookup failures as ambiguous send failures', function (): void {
    $lookupFailure = new RuntimeException('transport is not configured');
    $registry = new class ($lookupFailure) implements TransportRegistryInterface {
        public function __construct(private readonly Throwable $failure) {}
        public function get(string $name): TransportInterface { throw $this->failure; }
        public function has(string $name): bool { return false; }
    };
    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string { return '{}'; }
        public function deserialize(string $payload, string $commandClass): object { return new stdClass(); }
    };
    $metadata = new class implements CommandMetadataProviderInterface {
        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Async::class ? new Async('missing') : null;
        }

        public function isKnown(object|string $command): bool { return true; }
    };
    $handler = new class implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface { return $operation; }
    };

    try {
        (new TransportMiddleware(
            $registry,
            $serializer,
            $metadata,
            new JsonOperationContextSerializer(),
        ))->execute(Operation::create(new stdClass()), $handler);
        test()->fail('Expected transport registry failure.');
    } catch (Throwable $exception) {
        expect($exception)->toBe($lookupFailure);
    }
});
