<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Import\RemoteEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    usingFixtures('Import');

    Schema::create('imported_events', function (Blueprint $table): void {
        $table->unsignedBigInteger('id');
        $table->string('name');
        $table->timestamp('happened_at');
        $table->unique(['id']);
    });

    RemoteEvent::query()->insert([
        ['id' => 1, 'name' => 'signup', 'happened_at' => '2026-01-10 09:00:00'],
        ['id' => 2, 'name' => 'signup', 'happened_at' => '2026-01-11 09:00:00'],
        ['id' => 3, 'name' => 'purchase', 'happened_at' => '2026-01-12 09:00:00'],
    ]);
});

it('copies rows from another connection onto the model connection', function () {
    // Arrange
    expect(DB::table('imported_events')->count())->toBe(0);

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('imported_events')->count())->toBe(3)
        ->and(DB::table('imported_events')->where('id', 3)->value('name'))->toBe('purchase');
});

it('reads only past the high water mark on a later run', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();
    DB::table('imported_events')->where('id', 1)->delete();
    RemoteEvent::query()->insert([['id' => 4, 'name' => 'refund', 'happened_at' => '2026-01-13 09:00:00']]);

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('imported_events')->pluck('id')->all())->toBe([2, 3, 4]);
});

it('reads everything again under a full refresh', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();
    DB::table('imported_events')->where('id', 1)->delete();

    // Act
    $this->artisan('analytics:sync', ['--full-refresh' => true])->assertSuccessful();

    // Assert
    expect(DB::table('imported_events')->pluck('id')->all())->toBe([1, 2, 3]);
});

it('replaces a restated row instead of doubling it', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();
    DB::connection('warehouse')->table('events')->where('id', 2)->update(['name' => 'renamed']);

    // Act
    $this->artisan('analytics:sync', ['--full-refresh' => true])->assertSuccessful();

    // Assert
    expect(DB::table('imported_events')->count())->toBe(3)
        ->and(DB::table('imported_events')->where('id', 2)->value('name'))->toBe('renamed');
});

it('refuses to import into a table that was never migrated', function () {
    // Arrange
    Schema::drop('imported_events');

    // Act
    $exitCode = Artisan::call('analytics:sync');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('never creates its target')
        ->and($output)->toContain('Write a migration');
});

it('refuses an import whose target has no unique index to replace on', function () {
    // Arrange
    Schema::drop('imported_events');
    Schema::create('imported_events', function (Blueprint $table): void {
        $table->unsignedBigInteger('id');
        $table->string('name');
        $table->timestamp('happened_at');
    });

    // Act
    $exitCode = Artisan::call('analytics:sync');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('no unique index');
});

it('resolves an import even though it crosses a connection', function () {
    // Arrange
    $nodes = app(Resolver::class)->resolve();

    // Act
    $names = array_map(static fn ($node): string => $node->name(), $nodes);

    // Assert
    expect($names)->toContain('ImportedEvents');
});
