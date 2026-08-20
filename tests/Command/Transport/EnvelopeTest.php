<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\Envelope;

it('rejects empty transport envelope identifiers and context payloads', function (Closure $create): void {
    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'operation ID' => [fn() => new Envelope('  ', stdClass::class, '{}')],
    'command class' => [fn() => new Envelope('operation-id', '  ', '{}')],
    'receipt handle' => [fn() => new Envelope('operation-id', stdClass::class, '{}', '')],
    'operation context' => [fn() => new Envelope('operation-id', stdClass::class, '{}', null, '  ')],
]);

it('preserves operation context when attaching a receipt handle', function (): void {
    $envelope = new Envelope(
        operationId: 'operation-id',
        commandClass: stdClass::class,
        payload: '{}',
        contextPayload: '{"tenant":"main"}',
    );

    $received = $envelope->withReceiptHandle('receipt-id');

    expect($received->contextPayload)->toBe('{"tenant":"main"}')
        ->and($received->receiptHandle)->toBe('receipt-id');
});
