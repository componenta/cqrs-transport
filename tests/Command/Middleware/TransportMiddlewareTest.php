<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Transport\Attribute\Async;

it('uses the generic metadata provider to route asynchronous commands', function (): void {
    $transport = new class implements TransportInterface {
        public ?Envelope $sent = null;
        public int $delay = -1;

        public function send(Envelope $envelope, int $delay = 0): Envelope
        {
            $this->sent = $envelope;
            $this->delay = $delay;

            return $envelope;
        }

        public function get(): ?Envelope
        {
            return null;
        }

        public function ack(Envelope $envelope): void {}

        public function reject(Envelope $envelope): void {}
    };

    $registry = new class ($transport) implements TransportRegistryInterface {
        public string $requested = '';

        public function __construct(private readonly TransportInterface $transport) {}

        public function get(string $name): TransportInterface
        {
            $this->requested = $name;

            return $this->transport;
        }

        public function has(string $name): bool
        {
            return $name === 'queue';
        }
    };

    $serializer = new class implements CommandSerializerInterface {
        public function serialize(object $command): string
        {
            return 'payload';
        }

        public function deserialize(string $payload, string $commandClass): object
        {
            return new stdClass();
        }
    };

    $metadata = new class implements CommandMetadataProviderInterface {
        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Async::class ? new Async('queue', 15) : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
        }
    };

    $handler = new class implements OperationHandlerInterface {
        public int $calls = 0;

        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->calls++;

            return $operation;
        }
    };

    $command = new stdClass();
    $result = (new TransportMiddleware($registry, $serializer, $metadata))->execute(
        Operation::create($command),
        $handler,
    );

    expect($handler->calls)->toBe(0)
        ->and($registry->requested)->toBe('queue')
        ->and($transport->delay)->toBe(15)
        ->and($transport->sent?->commandClass)->toBe($command::class)
        ->and($transport->sent?->payload)->toBe('payload')
        ->and($result->attributes[TransportMiddleware::ATTR_EXECUTION_MODE])
        ->toBe(ExecutionMode::ASYNC);
});
