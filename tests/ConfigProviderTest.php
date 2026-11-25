<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\DI\ContainerFactory;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\CQRS\Transport\ConfigProvider;

#[Async('queue')]
final readonly class TransportProviderCommand
{
    public function __construct(public int $id)
    {
    }
}

it('wires transport and serializers without any map registration', function (): void {
    $transport = new class () implements TransportInterface {
        public ?Envelope $sent = null;
        public function send(Envelope $envelope, int $delay = 0): Envelope
        {
            return $this->sent = $envelope;
        }
        public function get(): ?Envelope
        {
            return null;
        }
        public function ack(Envelope $envelope): void
        {
        }
        public function reject(Envelope $envelope): void
        {
        }
    };
    $registry = new TransportRegistry();
    $registry->register('queue', $transport);
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new \Componenta\CQRS\ConfigProvider(),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => [
            TransportRegistryInterface::class => $registry,
            CommandSerializerInterface::class => new JsonCommandSerializer(),
        ]]],
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $terminal = new class () implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            throw new RuntimeException('Must send to transport.');
        }
    };
    $result = $container->get(TransportMiddleware::class)->execute(Operation::create(new TransportProviderCommand(7)), $terminal);
    expect($result->attributes[TransportMiddleware::ATTR_EXECUTION_MODE])->toBe(ExecutionMode::ASYNC)
        ->and(json_decode($transport->sent->payload, true))->toBe(['__componenta_cqrs' => 1, 'data' => ['id' => 7]])
        ->and($transport->sent->contextPayload)->toBe('{}');
});


it('rejects each incorrectly configured transport factory dependency', function (string $service): void {
    $factory = new \Componenta\CQRS\Command\Factory\TransportMiddlewareFactory();
    $services = [
        TransportRegistryInterface::class => new TransportRegistry(),
        CommandSerializerInterface::class => new JsonCommandSerializer(),
        \Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface::class
            => new \Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider(),
        \Componenta\CQRS\Command\Transport\OperationContextSerializerInterface::class
            => new \Componenta\CQRS\Command\Transport\JsonOperationContextSerializer(),
    ];
    $services[$service] = new stdClass();
    $container = $this->createStub(\Psr\Container\ContainerInterface::class);
    $container->method('get')->willReturnCallback(static fn (string $id): mixed => $services[$id]);
    expect(fn () => $factory($container))->toThrow(LogicException::class, $service);
})->with([
    TransportRegistryInterface::class,
    CommandSerializerInterface::class,
    \Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface::class,
    \Componenta\CQRS\Command\Transport\OperationContextSerializerInterface::class,
]);
