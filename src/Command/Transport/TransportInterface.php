<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Transport abstraction for async command delivery.
 *
 * Implementations:
 * - DatabaseTransport: uses auto-increment ID as receiptHandle
 * - RabbitMQ: uses delivery_tag (int)
 * - Redis Streams: uses message ID (string)
 * - AWS SQS: uses receipt_handle (string)
 */
interface TransportInterface
{
    /**
     * Sends envelope to transport.
     *
     * @param Envelope $envelope Command envelope
     * @param int $delay Delay in seconds (best effort, may not be supported)
     * @return Envelope Envelope with receiptHandle
     *
     * @throws TransportException If send fails
     */
    public function send(Envelope $envelope, int $delay = 0): Envelope;

    /**
     * Gets next available envelope from transport.
     *
     * @return Envelope|null Envelope with receiptHandle or null if empty
     */
    public function get(): ?Envelope;

    /**
     * Acknowledges successful processing.
     *
     * @param Envelope $envelope Envelope with receiptHandle from get()
     */
    public function ack(Envelope $envelope): void;

    /**
     * Rejects envelope (moves to failed/dead letter).
     *
     * @param Envelope $envelope Envelope with receiptHandle from get()
     */
    public function reject(Envelope $envelope): void;
}
