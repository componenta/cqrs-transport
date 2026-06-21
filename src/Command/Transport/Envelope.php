<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Serialized command for transport.
 */
final readonly class Envelope
{
    /**
     * @param string $operationId Operation ID for tracing
     * @param string $commandClass Command FQCN
     * @param string $payload Serialized command
     * @param string|int|null $receiptHandle Transport-specific ID for ack/reject
     */
    public function __construct(
        public string $operationId,
        public string $commandClass,
        public string $payload,
        public string|int|null $receiptHandle = null,
    ) {}

    /**
     * Returns new envelope with receipt handle.
     */
    public function withReceiptHandle(string|int $receiptHandle): self
    {
        return new self(
            $this->operationId,
            $this->commandClass,
            $this->payload,
            $receiptHandle,
        );
    }
}
