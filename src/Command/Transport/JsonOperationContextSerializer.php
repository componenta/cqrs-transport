<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use Componenta\CQRS\Command\OperationInterface;
use InvalidArgumentException;
use JsonException;

/**
 * JSON serializer for an explicit allowlist of operation attributes.
 *
 * Attribute names beginning with "__" are reserved for trusted runtime state
 * and can never cross the transport boundary through this serializer.
 */
final readonly class JsonOperationContextSerializer implements OperationContextSerializerInterface
{
    private const int MAX_DEPTH = 64;

    /** @var array<string, true> */
    private array $allowedAttributes;

    /** @param array<array-key, mixed> $allowedAttributes */
    public function __construct(array $allowedAttributes = [])
    {
        if (!array_is_list($allowedAttributes)) {
            throw new InvalidArgumentException('Transportable operation attributes must be a list.');
        }

        $allowed = [];

        foreach ($allowedAttributes as $index => $attribute) {
            if (!is_string($attribute) || trim($attribute) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Transportable operation attribute at index %d must be a non-empty string.',
                    $index,
                ));
            }

            if (str_starts_with($attribute, '__')) {
                throw new InvalidArgumentException(sprintf(
                    'Operation attribute "%s" is reserved for trusted runtime state and cannot be transported.',
                    $attribute,
                ));
            }

            if (isset($allowed[$attribute])) {
                throw new InvalidArgumentException(sprintf(
                    'Transportable operation attribute "%s" is duplicated.',
                    $attribute,
                ));
            }

            $allowed[$attribute] = true;
        }

        $this->allowedAttributes = $allowed;
    }

    public function serialize(OperationInterface $operation): string
    {
        $context = [];

        foreach ($this->allowedAttributes as $attribute => $_) {
            if (!array_key_exists($attribute, $operation->attributes)) {
                continue;
            }

            $value = $operation->attributes[$attribute];
            self::assertJsonValue($value, $attribute);
            $context[$attribute] = $value;
        }

        try {
            $payload = json_encode(
                (object) $context,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                self::MAX_DEPTH,
            );
            $decoded = json_decode(
                $payload,
                true,
                self::MAX_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new OperationContextSerializationException(
                'Failed to serialize operation transport context: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!is_array($decoded)
            || (array_is_list($decoded) && $decoded !== [])
            || !self::valuesEquivalent($context, $decoded)
        ) {
            throw new OperationContextSerializationException(
                'JSON encoding changed operation transport context; configure PHP for lossless JSON float serialization or use a custom serializer.',
            );
        }

        return $payload;
    }

    public function deserialize(string $payload): array
    {
        $payload = trim($payload);

        if ($payload === '' || $payload[0] !== '{') {
            throw new OperationContextSerializationException(
                'Operation transport context must be a JSON object.',
            );
        }

        try {
            $context = json_decode(
                $payload,
                true,
                self::MAX_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new OperationContextSerializationException(
                'Failed to deserialize operation transport context: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!is_array($context) || (array_is_list($context) && $context !== [])) {
            throw new OperationContextSerializationException(
                'Operation transport context must be a JSON object.',
            );
        }

        foreach ($context as $attribute => $value) {
            if (!is_string($attribute)
                || str_starts_with($attribute, '__')
                || !isset($this->allowedAttributes[$attribute])
            ) {
                throw new OperationContextSerializationException(sprintf(
                    'Operation transport context contains non-allowlisted attribute "%s".',
                    is_string($attribute) ? $attribute : (string) $attribute,
                ));
            }

            self::assertJsonValue($value, $attribute);
        }

        /** @var array<string, mixed> $context */
        return $context;
    }

    private static function assertJsonValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new OperationContextSerializationException(sprintf(
                'Operation transport context attribute "%s" exceeds maximum nesting depth.',
                $path,
            ));
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new OperationContextSerializationException(sprintf(
                    'Operation transport context attribute "%s" contains a non-finite float.',
                    $path,
                ));
            }

            return;
        }

        if (!is_array($value)) {
            throw new OperationContextSerializationException(sprintf(
                'Operation transport context attribute "%s" contains unsupported value of type %s.',
                $path,
                get_debug_type($value),
            ));
        }

        foreach ($value as $key => $nested) {
            self::assertJsonValue(
                $nested,
                sprintf('%s[%s]', $path, (string) $key),
                $depth + 1,
            );
        }
    }

    private static function valuesEquivalent(mixed $expected, mixed $actual, int $depth = 0): bool
    {
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        if (is_float($expected) || is_float($actual)) {
            return is_float($expected)
                && is_float($actual)
                && pack('E', $expected) === pack('E', $actual);
        }

        if (is_array($expected) && is_array($actual)) {
            if (array_keys($expected) !== array_keys($actual)) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (!self::valuesEquivalent($value, $actual[$key], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }
}
