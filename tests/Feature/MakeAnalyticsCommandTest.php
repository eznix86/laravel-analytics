<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->generated = sys_get_temp_dir().'/laravel-analytics-'.Str::random(8);

    config()->set('analytics.path', $this->generated);
    config()->set('analytics.namespace', 'Playground\\Analytics');
});

afterEach(function (): void {
    File::deleteDirectory($this->generated);
});

it('generates an analytics model at the configured path and namespace', function () {
    // Arrange, Act
    $this->artisan('make:analytics', ['name' => 'Churn'])->assertSuccessful();

    // Assert
    expect(File::get($this->generated.'/Churn.php'))
        ->toContain('namespace Playground\\Analytics;')
        ->toContain('class Churn extends Model')
        ->toContain('use Analytics;')
        ->toContain('public function computes(): Query')
        ->toContain('->per(')
        ->toContain('->measure(');
});

it('generates into a nested namespace that mirrors the directory', function () {
    // Arrange, Act
    $this->artisan('make:analytics', ['name' => 'Staging/Orders'])->assertSuccessful();

    // Assert
    expect(File::get($this->generated.'/Staging/Orders.php'))
        ->toContain('namespace Playground\\Analytics\\Staging;');
});
