<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Middleware\EventMiddleware;
use Componenta\CQRS\Command\Middleware\MiddlewareOrder;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Middleware\TransactionMiddleware;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;

it('declares the async transport boundary before execution-only middleware', function (): void {
    $attributes = (new ReflectionClass(TransportMiddleware::class))
        ->getAttributes(MiddlewareOrder::class);

    expect($attributes)->toHaveCount(1);

    /** @var MiddlewareOrder $order */
    $order = $attributes[0]->newInstance();

    expect($order->after)->toBe([
        Componenta\CQRS\Command\Middleware\PolicyMiddleware::class,
    ])->and($order->before)->toBe([
        EventMiddleware::class,
        ResourceLockMiddleware::class,
        RetryMiddleware::class,
        TransactionMiddleware::class,
    ]);
});
