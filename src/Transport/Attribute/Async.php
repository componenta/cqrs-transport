<?php

declare(strict_types=1);

namespace Componenta\CQRS\Transport\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Async
{
    public function __construct(
        public string $transport = 'default',
        public int $delay = 0,
    ) {}
}
