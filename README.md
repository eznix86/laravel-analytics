<div align="center">
    <h1>Laravel Analytics</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/v/eznix86/laravel-analytics.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/php-v/eznix86/laravel-analytics.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://badge.laravel.cloud/badge/eznix86/laravel-analytics?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/eznix86/laravel-analytics/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/eznix86/laravel-analytics/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/dt/eznix86/laravel-analytics.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Define analytics tables as Eloquent models, build them in dependency order, and query them with Eloquent.

```php
class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select customer_id, sum(amount) as total from '
            .$this->ref(Order::class)
            .' group by customer_id';
    }
}
```

```bash
php artisan analytics:sync
```

```php
Revenue::query()->where('total', '>', 1000)->get();
```

No Python, no `profiles.yml`, no second database configuration. `analytics:sync` builds real relations on your existing Laravel connections; everything after that is ordinary Eloquent.

## Installation

```bash
composer require eznix86/laravel-analytics
```

Publish and run the migration that records sync history:

```bash
php artisan vendor:publish --tag="laravel-analytics-migrations"
php artisan migrate
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag="laravel-analytics-config"
```

## Defining a model

```bash
php artisan make:analytics Revenue
```

Analytics models live in `app/Analytics` and are ordinary Eloquent models that implement `AnalyticsModel` and use the `Analytics` trait. The only required method is `computes()`.

```php
<?php

namespace App\Analytics;

use App\Models\Order;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Illuminate\Database\Eloquent\Model;

class StgOrder extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::View;
    }

    public function computes(): string
    {
        return 'select id, customer_id, amount / 100 as amount from '
            .$this->ref(Order::class)
            ." where status <> 'cancelled'";
    }
}
```

### `ref()`

`ref()` returns the relation name to select from, and records the dependency:

```php
$this->ref(StgOrder::class)   // another analytics model: a graph edge
$this->ref(Order::class)      // a plain Eloquent model: a source, a leaf
```

Because your Eloquent models already declare where raw data lives, there is no source manifest to maintain.

### Materializations

| | Stored | Read speed | Freshness |
| --- | --- | --- | --- |
| `Materialization::Table` (default) | every row | fast | as of the last sync |
| `Materialization::View` | definition only | recomputed per read | always live |
| `Materialization::Incremental` | every row, topped up | fast | as of the last sync |
| `Materialization::Microbatch` | every row, rebuilt one time slice at a time | fast | as of the last sync |
| `Materialization::Snapshot` | one row per version | fast | history, `valid_from` / `valid_to` |
| `Materialization::Ephemeral` | nothing | n/a | n/a |

An ephemeral model is never built. It is inlined as a CTE into every model that references it, which makes splitting long SQL into small layered models free. It cannot be queried directly.

### Incremental models

A table is rebuilt from scratch on every sync. An incremental model appends instead, which matters once a full rebuild stops finishing in the window you have.

You write the filter, the same way dbt does. `isIncremental()` is false on the first build and under `--full-refresh`, so the same method serves both:

```php
public function materialization(): Materialization
{
    return Materialization::Incremental;
}

public function uniqueKey(): array
{
    return ['day', 'name'];
}

public function computes(): string
{
    $sql = 'select '.$this->dateTrunc('day', 'happened_at').' as day, name, count(*) as total from '
        .$this->ref(Event::class);

    if ($this->isIncremental()) {
        $sql .= ' where '.$this->dateTrunc('day', 'happened_at')
            .' >= (select max(day) from '.$this->getTable().')';
    }

    return $sql.' group by '.$this->dateTrunc('day', 'happened_at').', name';
}
```

Without `uniqueKey()` the new rows are appended. With one, rows matching that key are deleted first, so a restated day replaces itself instead of doubling. The delete and insert run in one transaction, so a reader never sees the batch missing.

Override `incrementalStrategy()` to force `IncrementalStrategy::Append` even with a unique key, which is right for an immutable log that has a natural id.

If the model's columns drift from the relation it is appending to, the sync stops rather than inserting a shape that does not match. `onSchemaChange()` picks something else: `Ignore` inserts only the shared columns, `AppendNewColumns` adds what the model gained, `SyncAllColumns` also drops what it lost.

```
$ php artisan analytics:sync

  EventCounts  incremental append ................................ 2 rows  0.00s
  EventStream  incremental append ................................ 2 rows  0.00s
```

The row count is what the run appended, not the size of the table. `analytics:sync --full-refresh` rebuilds every incremental model from scratch.

**A window function in an incremental model is refused.** A window frame reads rows the filter never selects, so the first row of each batch silently gets a wrong value:

```
Running is incremental and its SQL contains a window function.

Fix one of:
  - make it a table, so every run recomputes the whole window
  - move the window function into a downstream table model that reads this one
  - override allowsWindowFunctions() to return true, if you have reasoned about the boundary yourself
```

Late-arriving rows are yours to handle, as they are in dbt: widen the filter with the lookback your data needs.

### Microbatch

An incremental model makes you write the filter and choose the lookback. A microbatch model splits the run into time slices instead, rebuilding each one whole. Every batch is independent and idempotent, so rerunning one is always safe:

```php
public function materialization(): Materialization
{
    return Materialization::Microbatch;
}

public function eventTime(): ?string
{
    return 'created_at';
}

public function batchSize(): BatchSize
{
    return BatchSize::Month;
}

public function begin(): ?string
{
    return '2026-01-01';
}

public function computes(): string
{
    return 'select min(t.created_at) as created_at, count(*) as total from '
        .$this->ref(Transaction::class).' t '
        .'where '.$this->batchWindow()
        .' group by '.$this->dateTrunc('day', 't.created_at');
}
```

`batchWindow()` is the half open predicate for the batch being built, so consecutive batches neither overlap nor leave a gap. Place it in your own `where` clause; nothing is spliced into your SQL.

The first run builds every batch from `begin()` to now. Later runs rebuild the newest stored batch plus `lookback()` behind it, which is how a late arriving row still lands. Each batch deletes its own window and inserts the rebuild, in one transaction.

Batch size and output grain are independent: monthly batches producing daily rows is fine, and often what you want.

Backfill an explicit range without touching anything else:

```bash
php artisan analytics:sync DailyTransactions --only \
    --event-time-start=2026-01-01 --event-time-end=2026-01-31
```

**The event time column must hold a full timestamp.** SQLite's date functions return ten characters, which sort before a nineteen character window bound and would never match — select `min(created_at)` rather than a truncated date.

### Snapshots

A table shows the source as it is now. A snapshot records how it got there, one row per version, with `valid_from` and `valid_to`:

```php
class StoreHistory extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Snapshot;
    }

    public function uniqueKey(): array
    {
        return ['store_id'];
    }

    public function checkColumns(): array
    {
        return ['sqft', 'region', 'is_active'];
    }

    public function computes(): string
    {
        return 'select store_id, sqft, country, region, is_active from '.$this->ref(Store::class);
    }
}
```

Each sync closes the open version of every row whose watched columns changed and opens a new one. Rows that did not change are left alone; rows the source has never had get their first version.

```
store=1 sqft=800   region=Indian Ocean  valid_from=2026-09-03 10:38:47  valid_to=open
store=2 sqft=3000  region=London        valid_from=2026-09-03 10:38:47  valid_to=2026-09-03 10:41:02
store=2 sqft=6000  region=Manchester    valid_from=2026-09-03 10:41:02  valid_to=open
```

`checkColumns()` narrows what counts as a change; empty watches every non-key column. Comparison is null safe, so a column going to or from null opens a version.

The reported row count is versions opened, not the size of the table. `uniqueKey()` is required — without it nothing identifies which row a version belongs to, and the resolver says so.

**A full refresh leaves snapshots alone**, because their history cannot be recomputed from the source. Aiming `--full-refresh` at a snapshot by name fails outright rather than quietly destroying it.

### Indexes

`create table as select` produces a table with no indexes, so declare the ones you need and they are rebuilt on every sync:

```php
public function indexes(): array
{
    return [['customer_id', 'month'], ['month']];
}
```

### Freshness

```php
public function freshness(): ?string
{
    return '25 hours';
}
```

```php
Revenue::lastSyncedAt();   // Carbon|null, successful builds only
Revenue::isStale();        // true once the window has passed
```

### Data expectations

Declare what must be true of the built data, then check it with `analytics:test`:

```php
public function expectations(): array
{
    return [
        Expectation::unique('created_at_day', 'store_id'),
        Expectation::notNull('store_id'),
        Expectation::acceptedValues('movement', ['new', 'churn', 'expansion', 'contraction', 'flat']),
        Expectation::expression('conversion > 0 and conversion <= 1'),
        Expectation::relationship('store_id', Store::class, 'store_id'),
    ];
}
```

```
$ php artisan analytics:test

  TrialBalance
    account_code is unique ................................................. PASS
    every row satisfies total_debit >= 0 and total_credit >= 0 .............. FAIL 1 rows

  ERROR 1 of 2 expectations failed.
```

Exit code is 1 when anything fails, so it drops straight into CI or a scheduler. To assert the same thing from your own test suite, use the runner:

```php
expect(app(Runner::class)->failures(new TrialBalance))->toBeEmpty();
```

### Analytics models are read-only

They are dropped and rebuilt on every sync, so writes throw rather than being silently discarded on the next run.

## Commands

```bash
php artisan analytics:graph                        # build order, grouped by connection
php artisan analytics:compile Revenue              # the SQL, without running it
php artisan analytics:sync                         # build everything, in order
php artisan analytics:sync Revenue                 # Revenue and everything upstream
php artisan analytics:sync Revenue --only          # just Revenue
php artisan analytics:sync --connection=warehouse  # one connection
php artisan analytics:sync --parallel              # build each wave concurrently
php artisan analytics:sync --parallel=8            # with this many workers
php artisan analytics:sync --porcelain             # one tab separated line per model
php artisan analytics:sync --continue              # resume the last run after a failure
php artisan analytics:sync --full-refresh          # rebuild incremental models from scratch
php artisan analytics:sync --event-time-start=2026-01-01 --event-time-end=2026-01-31
php artisan analytics:test                         # check declared data expectations
php artisan analytics:test TrialBalance
php artisan analytics:prune                        # drop sync history past the retention window
```

`analytics:graph` groups models into waves. Everything inside a wave depends only on earlier waves, so `--parallel` builds a wave at once, starting the next model as soon as a worker frees up. Each worker is a separate `artisan` process with its own connection, so it costs a Laravel boot per model — worth it when models take seconds, not milliseconds:

```
16 models, four of them 2s each

  serial       8.51s
  --parallel   3.08s
```

`--porcelain` emits `name<TAB>rows<TAB>milliseconds` on raw output, which is what the parallel parent parses and what you want for scripting.

### Resuming a failed run

A sync stops at the first failure, records it, and tells you how to pick up:

```
 ERROR Flaky failed to build.

SQLSTATE[42P01]: relation "late_feed" does not exist

 INFO Fix it, then resume with: php artisan analytics:sync --continue.
```

`--continue` rebuilds only what has not already succeeded in that run, across every connection:

```
 INFO Resuming run 01M1KB957MQAXD2B940TZ2A23J, skipping 10 models that already succeeded.
```

Every model built by one invocation shares a run id in `analytics_runs`, along with its status and, on failure, the database error. `--continue` reuses that run id, so resuming twice keeps one coherent run. It composes with `--parallel`.

```php
// routes/console.php
Schedule::command('analytics:sync')->dailyAt('03:00');
```

### Pruning the run log

`analytics_runs` grows with every model on every sync, so it has a retention window:

```php
// config/analytics.php
'retention' => env('ANALYTICS_RETENTION', '1 year'),
```

```php
// routes/console.php
Schedule::command('analytics:sync')->dailyAt('03:00');
Schedule::command('analytics:test')->dailyAt('03:30');
Schedule::command('analytics:prune')->weekly();
```

`analytics:prune` walks every connection your analytics models use, skipping any that has no run log. The model uses Laravel's `MassPrunable`, so `php artisan model:prune --model="Eznix86\LaravelAnalytics\Models\AnalyticsRun"` works too — but it only prunes one connection, which is why the dedicated command exists. Set `retention` to `null` to keep history forever.

## Connections

Analytics models use Eloquent's `$connection`, falling back to `config('analytics.connection')`.

Every model in a dependency chain must share one connection, including its plain Eloquent sources: no database engine can join across two connections in a single statement. `analytics:graph` fails with an explanation when a chain crosses a boundary.

Different chains may live on different connections. Each one resolves and builds as an independent graph, and `analytics:sync --connection=warehouse` builds just that one:

```
pgsql .. 3 models
  StgOrder          view       Order
  MonthlyOrders     ephemeral  StgOrder
  CustomerRevenue   table      Customer, MonthlyOrders, StgOrder

warehouse .. 2 models
  StgEvent          ephemeral  Event
  DailyEvents       table      Event, StgEvent
```

A model that leaves its connection unset uses the application default, and is compared against other models by that resolved name.

Where the engine itself can reach across databases, qualify the relation instead of adding a connection. MySQL and SQL Server resolve `database.table` on the same server, and PostgreSQL resolves `schema.table` inside one database:

```php
class Region extends Model
{
    protected $connection = 'mysql';

    protected $table = 'reference_data.regions';
}
```

That stays one Laravel connection, so `ref()` accepts it. PostgreSQL cannot join across two databases at all (`cross-database references are not implemented`); use schemas there.

## Cross-driver SQL

The package guarantees cross-driver *orchestration*: DDL, atomic swaps, index rebuilds, build order and freshness work the same on PostgreSQL, MySQL/MariaDB and SQLite. The SQL inside `computes()` is yours.

Where a dialect difference is unavoidable, use the driver-aware helpers:

```php
$this->dateTrunc('month', 'created_at');
$this->dateAdd('day', 7, 'created_at');
$this->dateDiff('day', 'start', 'end');
$this->dateSpine('month', "'2026-01-01'", 'current_date');
$this->stringAgg('name', ', ');
$this->castAs('debit', 'bigint');   // 'signed' on MySQL, 'integer' on SQLite
```

Register a grammar for a driver the package does not ship:

```php
app(GrammarManager::class)->extend('clickhouse', ClickHouseGrammar::class);
```

## Defining a metric once

`computes()` returns a string, so a metric is just a PHP method. Define it in one place and roll it up along whatever dimensions you need:

```php
final class Metrics
{
    public static function totalTransactions(): string
    {
        return 'count(distinct t.transaction_id)';
    }

    public static function transPerCust(Grammar $grammar): string
    {
        return $grammar->cast(self::totalTransactions(), 'decimal(18,4)')
            .' / nullif(count(distinct t.customer_id), 0)';
    }
}
```

```php
// one rollup by brand and month, another by store and day, same definition
Metrics::transPerCust($this->grammar()).' as trans_per_cust '
```

Metrics that need a driver-aware fragment take the `Grammar` from `$this->grammar()`, which is public on every analytics model.

The joins those rollups share belong in one wide model they all `ref()`. That resolves the join graph once at build time; the package does not plan joins per query the way a semantic layer such as Cube or dbt's MetricFlow does, so pick the dimension combinations you want and materialize them.

## Builder-backed models

`computes()` also accepts a query builder, which is portable for free and reads well for staging models:

```php
public function computes(): string|Builder
{
    return DB::table($this->ref(Order::class))
        ->select('customer_id', 'amount')
        ->where('status', '<>', 'cancelled');
}
```

Window functions and CTEs have no builder form, so aggregation layers are usually plain SQL.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Analytics! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Bruno Bernard](https://github.com/eznix86)
- [All Contributors](../../contributors)

## License

Laravel Analytics is open-sourced software licensed under the [MIT license](LICENSE.md).
