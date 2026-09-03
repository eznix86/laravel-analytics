<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Eznix86\LaravelAnalytics\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

/**
 * Point discovery at one of the fixture directories under tests/Fixtures.
 */
function usingFixtures(string $directory): void
{
    config()->set('analytics.path', __DIR__.'/Fixtures/'.$directory);
    config()->set('analytics.namespace', 'Eznix86\\LaravelAnalytics\\Tests\\Fixtures\\'.$directory);
}

/**
 * @param  list<array<string, mixed>>  $orders
 */
function seedOrders(array $orders): void
{
    Order::query()->insert(array_map(
        static fn (array $order): array => $order + ['placed_on' => '2026-01-10 09:00:00'],
        $orders,
    ));
}
