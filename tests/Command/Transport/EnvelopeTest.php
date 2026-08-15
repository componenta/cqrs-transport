<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\Envelope;

it('rejects empty transport envelope identifiers', function (Closure $create): void {
    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'operation ID' => [fn() => new Envelope('  ', stdClass::class, '{}')],
    'command class' => [fn() => new Envelope('operation-id', '  ', '{}')],
    'receipt handle' => [fn() => new Envelope('operation-id', stdClass::class, '{}', '')],
]);
