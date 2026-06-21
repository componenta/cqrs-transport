<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Registry for named transports.
 */
interface TransportRegistryInterface
{
    /**
     * Gets transport by name.
     *
     * @param string $name Transport name
     * @return TransportInterface
     *
     * @throws TransportNotFoundException If transport is not registered
     */
    public function get(string $name): TransportInterface;

    /**
     * Checks if transport is registered.
     *
     * @param string $name Transport name
     */
    public function has(string $name): bool;
}
