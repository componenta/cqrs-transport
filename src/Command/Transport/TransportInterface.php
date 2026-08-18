<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/** Transport abstraction for asynchronous command delivery. */
interface TransportInterface
{
    /**
     * Sends an envelope to the transport.
     *
     * @param int $delay Delay in seconds (best effort, may not be supported).
     *
     * @throws TransportException If sending fails.
     */
    public function send(Envelope $envelope, int $delay = 0): Envelope;

    /** Returns the next available envelope, or null when the transport is empty. */
    public function get(): ?Envelope;

    /** Acknowledges successful processing of an envelope returned by {@see self::get()}. */
    public function ack(Envelope $envelope): void;

    /** Rejects an envelope returned by {@see self::get()}. */
    public function reject(Envelope $envelope): void;
}
