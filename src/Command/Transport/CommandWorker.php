<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/** Processes commands from transport. */
final class CommandWorker
{
    public const string ATTR_ORIGINAL_OPERATION_ID = '__original_operation_id';

    private bool $shouldStop = false;
    private readonly LoggerInterface $logger;

    /** @var array<string, mixed> */
    private readonly array $dispatchAttributes;

    /**
     * The safe constructor is fail-closed: a complete command metadata provider
     * is required before any envelope-selected class can be deserialized.
     *
     * @param array<string, mixed> $dispatchAttributes Extra attributes passed to command re-dispatch.
     */
    public function __construct(
        private readonly CommandBusInterface $bus,
        private readonly CommandSerializerInterface $serializer,
        private readonly TransportInterface $transport,
        private readonly CommandMetadataProviderInterface $commands,
        ?LoggerInterface $logger = null,
        array $dispatchAttributes = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->dispatchAttributes = $dispatchAttributes;
    }

    /**
     * Explicitly disables command-class allowlisting.
     *
     * @param array<string, mixed> $dispatchAttributes
     */
    public static function unsafe(
        CommandBusInterface $bus,
        CommandSerializerInterface $serializer,
        TransportInterface $transport,
        ?LoggerInterface $logger = null,
        array $dispatchAttributes = [],
    ): self {
        return new self(
            $bus,
            $serializer,
            $transport,
            new UnsafeCommandMetadataProvider(),
            $logger,
            $dispatchAttributes,
        );
    }

    /** @phpstan-impure */
    public function processOne(): bool
    {
        $envelope = $this->transport->get();

        if ($envelope === null) {
            return false;
        }

        try {
            if (!$this->commands->isKnown($envelope->commandClass)) {
                throw new TransportException(sprintf(
                    'Transported command class "%s" is not present in the configured CQRS command map.',
                    $envelope->commandClass,
                ));
            }

            if (!class_exists($envelope->commandClass)) {
                throw new TransportException("Transported command class '{$envelope->commandClass}' does not exist.");
            }

            $commandClass = $envelope->commandClass;
            $command = $this->serializer->deserialize(
                $envelope->payload,
                $commandClass,
            );

            if (!$command instanceof $commandClass) {
                throw new TransportException(sprintf(
                    'Deserializer returned %s for transported command class %s.',
                    get_debug_type($command),
                    $commandClass,
                ));
            }

            $this->bus->dispatch($command, [
                ...$this->dispatchAttributes,
                self::ATTR_ORIGINAL_OPERATION_ID => $envelope->operationId,
                TransportMiddleware::ATTR_EXECUTION_MODE => ExecutionMode::SYNC,
            ]);
        } catch (Throwable $processingFailure) {
            try {
                $this->transport->reject($envelope);
            } catch (Throwable $dispositionFailure) {
                $this->safeLog('error', 'Command rejection failed', [
                    'operation_id' => $envelope->operationId,
                    'command' => $envelope->commandClass,
                    'processing_exception' => $processingFailure->getMessage(),
                    'disposition_exception' => $dispositionFailure->getMessage(),
                ]);

                throw new TransportDispositionException(
                    $processingFailure,
                    $dispositionFailure,
                );
            }

            $this->safeLog('error', 'Command failed', [
                'operation_id' => $envelope->operationId,
                'command' => $envelope->commandClass,
                'exception' => $processingFailure->getMessage(),
            ]);

            return true;
        }

        try {
            $this->transport->ack($envelope);
        } catch (Throwable $ackFailure) {
            $this->safeLog('error', 'Command acknowledgement failed', [
                'operation_id' => $envelope->operationId,
                'command' => $envelope->commandClass,
                'exception' => $ackFailure->getMessage(),
            ]);

            throw new TransportException(sprintf(
                'Command "%s" was processed, but acknowledgement failed: %s',
                $envelope->operationId,
                $ackFailure->getMessage(),
            ), previous: $ackFailure);
        }

        $this->safeLog('info', 'Command processed', [
            'operation_id' => $envelope->operationId,
            'command' => $envelope->commandClass,
        ]);

        return true;
    }

    /** @param array<string, mixed> $context */
    private function safeLog(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Logging must not alter delivery disposition.
        }
    }

    public function run(int $sleep = 1): void
    {
        $this->shouldStop = false;

        if ($sleep < 0) {
            throw new InvalidArgumentException(
                'Worker sleep interval must be non-negative.',
            );
        }

        while (!$this->shouldStop) {
            if (!$this->processOne()) {
                sleep($sleep);
            }
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }
}
