<?php

declare(strict_types=1);

namespace Componenta\CQRS\Transport\Attribute;

use InvalidArgumentException;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Async
{
    public function __construct(
        public string $transport = 'default',
        public int $delay = 0,
    ) {
        if (trim($this->transport) === '') {
            throw new InvalidArgumentException('Async transport name cannot be empty or whitespace.');
        }

        if ($this->delay < 0) {
            throw new InvalidArgumentException('Async transport delay must be non-negative.');
        }
    }
}
