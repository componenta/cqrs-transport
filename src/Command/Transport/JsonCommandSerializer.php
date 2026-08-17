<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Closure;
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
final readonly class JsonCommandSerializer implements CommandSerializerInterface, CommandSerializerSupportInterface
{
    public const int FORMAT_VERSION = 1;

    private const int MAX_NESTING_DEPTH = 64;
    private const string FORMAT_KEY = '__componenta_cqrs';
    private const string DATA_KEY = 'data';

    public function supportsCommand(object|string $command): bool
    {
        return true;
    }

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
        $properties = $this->assertSupportedProperties($reflection, $parameters);

        $unknownFields = array_values(array_diff(array_keys($data), array_keys($parameters)));
        if ($unknownFields !== []) {
            throw new TransportException(sprintf(
                'Payload for %s contains unknown field(s): %s.',
                $commandClass,
                implode(', ', $unknownFields),
            ));
        }

        if ($parameters === []) {
            return $this->instantiate($reflection, []);
        }

        /** @var list<mixed> $arguments */
        $arguments = [];
        /** @var array<string, mixed> $expectedState */
        $expectedState = [];

        foreach ($parameters as $name => $parameter) {
            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                $this->assertJsonValue($value, $name);
                $this->assertParameterType($value, $parameter, $commandClass);
                $arguments[] = $value;
                $expectedState[$name] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $value = $parameter->getDefaultValue();
                $this->assertJsonValue($value, $name);
                $arguments[] = $value;
                $expectedState[$name] = $value;
                continue;
            }

            throw new TransportException("Missing required parameter '{$name}' for {$commandClass}.");
        }

        $command = $this->instantiate($reflection, $arguments);
        $this->assertRoundTripState($command, $expectedState, $properties, $commandClass);

        return $command;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, mixed>
     */
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

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, ReflectionParameter>
     */
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

            $this->assertSupportedParameterType($parameter, $reflection);
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertSupportedParameterType(
        ReflectionParameter $parameter,
        ReflectionClass $reflection,
    ): void {
        $type = $parameter->getType();

        if ($type === null || !self::containsExecutableType($type)) {
            return;
        }

        throw new TransportException(sprintf(
            'Default JSON serialization does not support executable callable constructor parameter "%s" on %s; configure a trusted custom serializer.',
            $parameter->getName(),
            $reflection->getName(),
        ));
    }

    private static function containsExecutableType(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::containsExecutableType($member)) {
                    return true;
                }
            }

            return false;
        }

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return $type->getName() === 'callable';
        }

        return is_a($type->getName(), Closure::class, true);
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

    private function assertJsonValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new TransportException(sprintf(
                'Command field "%s" exceeds the maximum JSON nesting depth of %d.',
                $path,
                self::MAX_NESTING_DEPTH,
            ));
        }

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
                $this->assertJsonValue($item, $path . '.' . $key, $depth + 1);
            }

            return;
        }

        throw new TransportException(sprintf(
            'Command field "%s" contains unsupported value of type %s; configure a custom serializer.',
            $path,
            get_debug_type($value),
        ));
    }

    /**
     * @param array<string, mixed> $expectedState
     * @param array<string, ReflectionProperty> $properties
     */
    private function assertRoundTripState(
        object $command,
        array $expectedState,
        array $properties,
        string $commandClass,
    ): void {
        foreach ($expectedState as $name => $expected) {
            $property = $properties[$name];

            if (!$property->isInitialized($command)) {
                throw new TransportException(sprintf(
                    'Restored command %s left constructor-backed field "%s" uninitialized.',
                    $commandClass,
                    $name,
                ));
            }

            try {
                $actual = $property->getValue($command);
            } catch (Throwable $exception) {
                throw new TransportException(
                    sprintf('Cannot read restored command field "%s": %s', $name, $exception->getMessage()),
                    previous: $exception,
                );
            }

            if (!$this->valuesEquivalent($expected, $actual)) {
                throw new TransportException(sprintf(
                    'Restored command %s changed constructor-backed field "%s" during reconstruction.',
                    $commandClass,
                    $name,
                ));
            }
        }
    }

    private function valuesEquivalent(mixed $expected, mixed $actual, int $depth = 0): bool
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return false;
        }

        if (is_int($expected) && is_float($actual)) {
            return (float) $expected === $actual;
        }

        if (is_float($expected) && is_int($actual)) {
            return $expected === (float) $actual;
        }

        if (is_array($expected) && is_array($actual)) {
            if (array_keys($expected) !== array_keys($actual)) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (!$this->valuesEquivalent($value, $actual[$key], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }

    /** @return array<string, mixed> */
    private function payloadData(mixed $decoded): array
    {
        $decoded = $this->jsonObject($decoded, 'Invalid payload: expected a JSON object.');

        if (!array_key_exists(self::FORMAT_KEY, $decoded)) {
            throw new TransportException('Command payload must use the versioned envelope.');
        }

        $version = $decoded[self::FORMAT_KEY];
        if ($version !== self::FORMAT_VERSION) {
            throw new TransportException(sprintf(
                'Unsupported command payload version "%s".',
                is_scalar($version) ? (string) $version : get_debug_type($version),
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
            'callable' => false,
            default => is_object($value) && is_a($value, $type->getName()),
        };
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<mixed> $arguments
     */
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
