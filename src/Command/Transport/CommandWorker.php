<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Throwable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;

/**
 * Processes commands from transport.
 *
 * @example
 * ```php
 * $worker = new CommandWorker($bus, $serializer, $transport, $logger);
 *
 * // Process single command
 * $worker->processOne();
 *
 * // Run continuously
 * $worker->run(sleep: 5);
 *
 * // Graceful shutdown (from signal handler)
 * $worker->stop();
 * ```
 */
final class CommandWorker
{
    public const string ATTR_SKIP_POLICY = '__skip_policy';

    private bool $shouldStop = false;
    private readonly LoggerInterface $logger;

    /** @var array<string, mixed> */
    private readonly array $dispatchAttributes;

    /**
     * @param array<string, mixed> $dispatchAttributes Extra attributes passed to command re-dispatch.
     */
    public function __construct(
        private readonly CommandBusInterface $bus,
        private readonly CommandSerializerInterface $serializer,
        private readonly TransportInterface $transport,
        ?LoggerInterface $logger = null,
        array $dispatchAttributes = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->dispatchAttributes = [self::ATTR_SKIP_POLICY => true, ...$dispatchAttributes];
    }

    /**
     * Processes single command from transport.
     *
     * @return bool True if command was processed, false if transport is empty
     */
    public function processOne(): bool
    {
        $envelope = $this->transport->get();

        if ($envelope === null) {
            return false;
        }

        try {
            $command = $this->serializer->deserialize(
                $envelope->payload,
                $envelope->commandClass,
            );
            $this->bus->dispatch($command, [
                ...$this->dispatchAttributes,
                TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
            ]);

            $this->transport->ack($envelope);

            $this->logger->info('Command processed', [
                'operation_id' => $envelope->operationId,
                'command' => $envelope->commandClass,
            ]);
        } catch (Throwable $e) {
            $this->transport->reject($envelope);

            $this->logger->error('Command failed', [
                'operation_id' => $envelope->operationId,
                'command' => $envelope->commandClass,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Runs worker loop until stopped.
     *
     * @param int $sleep Seconds to sleep when transport is empty
     */
    public function run(int $sleep = 1): void
    {
        $this->shouldStop = false;

        while (!$this->shouldStop) {
            if (!$this->processOne()) {
                sleep($sleep);
            }
        }
    }

    /**
     * Signals worker to stop after current command.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }
}


