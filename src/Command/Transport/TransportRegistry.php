<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

/**
 * Simple transport registry implementation.
 */
final class TransportRegistry implements TransportRegistryInterface
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    /**
     * Registers transport with given name.
     *
     * @param string $name Transport name
     * @param TransportInterface $transport Transport instance
     */
    public function register(string $name, TransportInterface $transport): void
    {
        $this->transports[$name] = $transport;
    }

    public function get(string $name): TransportInterface
    {
        if (!$this->has($name)) {
            throw TransportNotFoundException::forName($name);
        }

        return $this->transports[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->transports[$name]);
    }
}
