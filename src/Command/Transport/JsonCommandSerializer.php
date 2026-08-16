<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use JsonException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/** JSON serializer for public stored constructor-backed command state. */
final readonly class JsonCommandSerializer implements CommandSerializerInterface
{
    public const int FORMAT_VERSION = 1;

    private const string FORMAT_KEY = '__componenta_cqrs';
    private const string DATA_KEY = 'data';

    public function serialize(object $command): string
    {
        $reflection = new ReflectionClass($command);
        $data = $this->extractConstructorData($reflection, $command);

        try {
            return json_encode([
                self::FORMAT_KEY => self::FORMAT_VERSION,
                self::DATA_KEY => $data,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TransportException(
                "Failed to serialize command: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TransportException(
                "Failed to deserialize command: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $data = $this->payloadData($decoded);

        if (!class_exists($commandClass)) {
            throw new TransportException("Command class '{$commandClass}' does not exist.");
        }

        $reflection = new ReflectionClass($commandClass);
        if (!$reflection->isInstantiable()) {
            throw new TransportException("Command class '{$commandClass}' must be instantiable.");
        }

        $parameters = $this->constructorParameters($reflection);
        $this->assertSupportedProperties($reflection, $parameters);

        if ($parameters === []) {
            if ($data !== []) {
                throw new TransportException("Command '{$commandClass}' has no constructor parameters, but its payload contains fields.");
            }

            return $this->instantiate($reflection, []);
        }

        $arguments = [];
        $remaining = $data;

        foreach ($parameters as $name => $parameter) {
            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                $this->assertParameterType($value, $parameter, $commandClass);
                $arguments[] = $value;
                unset($remaining[$name]);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new TransportException("Missing required parameter '{$name}' for {$commandClass}.");
        }

        if ($remaining !== []) {
            throw new TransportException(sprintf(
                'Payload for %s contains unknown field(s): %s.',
                $commandClass,
                implode(', ', array_keys($remaining)),
            ));
        }

        return $this->instantiate($reflection, $arguments);
    }

    /** @param ReflectionClass<object> $reflection @return array<string, mixed> */
    private function extractConstructorData(ReflectionClass $reflection, object $command): array
    {
        $parameters = $this->constructorParameters($reflection);
        $properties = $this->assertSupportedProperties($reflection, $parameters);
        $data = [];

        foreach ($properties as $name => $property) {
            if (!$property->isInitialized($command)) {
                throw new TransportException("Command property '{$name}' is not initialized.");
            }

            try {
                $value = $property->getValue($command);
            } catch (Throwable $exception) {
                throw new TransportException(
                    "Cannot read command property '{$name}': {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            $this->assertJsonValue($value, $name);
            $data[$name] = $value;
        }

        return $data;
    }

    /** @param ReflectionClass<object> $reflection @return array<string, ReflectionParameter> */
    private function constructorParameters(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new TransportException(sprintf(
                    'Default JSON serialization does not support variadic or by-reference constructor parameter "%s" on %s.',
                    $parameter->getName(),
                    $reflection->getName(),
                ));
            }

            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, ReflectionParameter> $parameters
     * @return array<string, ReflectionProperty>
     */
    private function assertSupportedProperties(ReflectionClass $reflection, array $parameters): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            if (!array_key_exists($name, $parameters)) {
                throw new TransportException(sprintf(
                    'Command property "%s::$%s" is not represented by a constructor parameter.',
                    $reflection->getName(),
                    $name,
                ));
            }

            if (!$property->isPublic()) {
                throw new TransportException(sprintf(
                    'Default JSON serialization requires constructor-backed property "%s::$%s" to be public.',
                    $reflection->getName(),
                    $name,
                ));
            }

            if ($property->isVirtual() || $property->getHooks() !== []) {
                throw new TransportException(sprintf(
                    'Default JSON serialization does not support hooked or virtual property "%s::$%s".',
                    $reflection->getName(),
                    $name,
                ));
            }

            $properties[$name] = $property;
        }

        foreach (array_keys($parameters) as $name) {
            if (!isset($properties[$name])) {
                throw new TransportException(sprintf(
                    'Constructor parameter "%s::$%s" must have a matching public stored property for default JSON serialization.',
                    $reflection->getName(),
                    $name,
                ));
            }
        }

        return $properties;
    }

    private function assertJsonValue(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new TransportException("Command field '{$path}' must contain a finite float.");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertJsonValue($item, $path . '.' . $key);
            }

            return;
        }

        throw new TransportException(sprintf(
            'Command field "%s" contains unsupported value of type %s; configure a custom serializer.',
            $path,
            get_debug_type($value),
        ));
    }

    /** @return array<string, mixed> */
    private function payloadData(mixed $decoded): array
    {
        $decoded = $this->jsonObject($decoded, 'Invalid payload: expected a JSON object.');

        if (!array_key_exists(self::FORMAT_KEY, $decoded)) {
            return $decoded;
        }

        if (($decoded[self::FORMAT_KEY] ?? null) !== self::FORMAT_VERSION) {
            throw new TransportException(sprintf(
                'Unsupported command payload version "%s".',
                is_scalar($decoded[self::FORMAT_KEY] ?? null)
                    ? (string) $decoded[self::FORMAT_KEY]
                    : get_debug_type($decoded[self::FORMAT_KEY] ?? null),
            ));
        }

        if (count($decoded) !== 2 || !array_key_exists(self::DATA_KEY, $decoded)) {
            throw new TransportException('Invalid versioned command payload envelope.');
        }

        return $this->jsonObject(
            $decoded[self::DATA_KEY],
            'Invalid versioned command payload envelope.',
        );
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $error): array
    {
        if (!is_array($value)) {
            throw new TransportException($error);
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new TransportException($error);
            }

            $object[$key] = $item;
        }

        return $object;
    }

    private function assertParameterType(mixed $value, ReflectionParameter $parameter, string $commandClass): void
    {
        $type = $parameter->getType();

        if ($type === null || $this->matchesType($value, $type)) {
            return;
        }

        throw new TransportException(sprintf(
            'Payload field "%s" for %s must match %s; %s given.',
            $parameter->getName(),
            $commandClass,
            (string) $type,
            get_debug_type($value),
        ));
    }

    private function matchesType(mixed $value, ReflectionType $type): bool
    {
        if ($value === null && $type->allowsNull()) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->matchesType($value, $member)) {
                    return true;
                }
            }
            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!$this->matchesType($value, $member)) {
                    return false;
                }
            }
            return true;
        }

        assert($type instanceof ReflectionNamedType);

        return match ($type->getName()) {
            'mixed' => true,
            'null' => $value === null,
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            default => is_object($value) && is_a($value, $type->getName()),
        };
    }

    /** @param ReflectionClass<object> $reflection @param list<mixed> $arguments */
    private function instantiate(ReflectionClass $reflection, array $arguments): object
    {
        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable $exception) {
            throw new TransportException(sprintf(
                'Failed to instantiate command %s: %s',
                $reflection->getName(),
                $exception->getMessage(),
            ), previous: $exception);
        }
    }
}
