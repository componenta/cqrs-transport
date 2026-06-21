<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use ReflectionClass;
use ReflectionProperty;
use JsonException;

/**
 * JSON-based command serializer.
 *
 * Serializes public properties to JSON. Deserializes via constructor.
 */
final readonly class JsonCommandSerializer implements CommandSerializerInterface
{
    public function serialize(object $command): string
    {
        $reflection = new ReflectionClass($command);
        $data = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($command)) {
                $data[$property->getName()] = $property->getValue($command);
            }
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException("Failed to serialize command: {$e->getMessage()}", 0, $e);
        }
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException("Failed to deserialize command: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($data)) {
            throw new TransportException('Invalid payload: expected JSON object');
        }

        $reflection = new ReflectionClass($commandClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $data)) {
                $args[] = $data[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new TransportException("Missing required parameter '{$name}' for {$commandClass}");
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}
