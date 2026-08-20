<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Transport\Attribute\Async;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/** Processes commands from one named transport. */
final class CommandWorker
{
    public const string ATTR_ORIGINAL_OPERATION_ID = '__original_operation_id';

    private bool $shouldStop = false;
    private readonly LoggerInterface $logger;

    /** @var array<string, mixed> */
    private readonly array $dispatchAttributes;

    /**
     * The worker is fail-closed: envelope-selected command classes must be
     * explicitly declared async for this exact transport name in the active
     * CQRS map before class loading or deserialization is attempted.
     *
     * @param array<string, mixed> $dispatchAttributes Trusted attributes added by the worker.
     */
    public function __construct(
        private readonly CommandBusInterface $bus,
        private readonly CommandSerializerInterface $serializer,
        private readonly OperationContextSerializerInterface $contextSerializer,
        private readonly TransportInterface $transport,
        private readonly string $transportName,
        private readonly CqrsMapProviderInterface $commands,
        ?LoggerInterface $logger = null,
        array $dispatchAttributes = [],
    ) {
        if (trim($this->transportName) === '') {
            throw new InvalidArgumentException('Worker transport name cannot be empty or whitespace.');
        }

        foreach ($dispatchAttributes as $attribute => $_) {
            if (!is_string($attribute)) {
                throw new InvalidArgumentException('Worker dispatch attribute names must be strings.');
            }
        }

        $this->logger = $logger ?? new NullLogger();
        $this->dispatchAttributes = $dispatchAttributes;
    }

    /** @phpstan-impure */
    public function processOne(): bool
    {
        $envelope = $this->transport->get();

        if ($envelope === null) {
            return false;
        }

        try {
            $descriptor = $this->commands->map()->commandMetadata(
                $envelope->commandClass,
                Async::class,
            );

            if ($descriptor === null) {
                throw new TransportException(sprintf(
                    'Transported command class "%s" is not declared async in the configured CQRS command map.',
                    $envelope->commandClass,
                ));
            }

            try {
                $async = new Async(...$descriptor->arguments);
            } catch (Throwable $exception) {
                throw new TransportException(sprintf(
                    'Invalid Async metadata for transported command class "%s": %s',
                    $envelope->commandClass,
                    $exception->getMessage(),
                ), previous: $exception);
            }

            if ($async->transport !== $this->transportName) {
                throw new TransportException(sprintf(
                    'Transported command class "%s" is declared for transport "%s", not worker transport "%s".',
                    $envelope->commandClass,
                    $async->transport,
                    $this->transportName,
                ));
            }

            if (!class_exists($envelope->commandClass)) {
                throw new TransportException(
                    "Transported command class '{$envelope->commandClass}' does not exist.",
                );
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

            $context = $this->contextSerializer->deserialize($envelope->contextPayload);

            foreach ($context as $attribute => $_) {
                if (!is_string($attribute)) {
                    throw new TransportException(
                        'Transported operation context attribute names must be strings.',
                    );
                }

                if (str_starts_with($attribute, '__')) {
                    throw new TransportException(sprintf(
                        'Transported operation context contains reserved runtime attribute "%s".',
                        $attribute,
                    ));
                }
            }

            $this->bus->dispatch($command, [
                ...$context,
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
