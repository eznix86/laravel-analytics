<div align="center">
    <h1>Analytics for Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/v/eznix86/laravel-analytics.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/php-v/eznix86/laravel-analytics.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://badge.laravel.cloud/badge/eznix86/laravel-analytics?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/eznix86/laravel-analytics/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/eznix86/laravel-analytics/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/eznix86/laravel-analytics"><img src="https://img.shields.io/packagist/dt/eznix86/laravel-analytics.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Define analytics tables as Eloquent models, build them in dependency order, and query them with Eloquent.

Laravel Analytics brings the dbt model workflow to Laravel. Define each transformation as a model, reference other models as dependencies, and let the package determine the build order.

Everything stays inside your Laravel application and runs against the database connections you already use.

## Why Laravel Analytics?

Analytics logic often gets duplicated across dashboards, reports, jobs, and ad hoc queries. Laravel Analytics gives that logic a home.

* **Define business logic once.** Each analytics model is a reusable query that downstream models can reference. Change it once and rebuild the models that depend on it.
* **Build dependencies automatically.** Referencing another analytics model creates an edge in the dependency graph. Models are built in the correct order.
* **Rebuild safely.** Tables are built before they are swapped into place, so a failed build does not leave readers with a partially rebuilt relation.
* **Stay inside Laravel.** Models live in `app/Analytics` and can be queried with ordinary Eloquent.
* **Use your existing databases.** PostgreSQL, MySQL, MariaDB, and SQLite are supported.
* **Scale beyond full rebuilds.** Incremental models, microbatches, snapshots, and imports handle larger and more complex workloads.
* **Test your data.** Declare expectations about your analytics models and run them from Artisan or your test suite.

No Python. No `profiles.yml`. No separate analytics project. No second database configuration.

## Quick Start

Create an analytics model:

```bash
php artisan make:analytics Revenue
```

Define the model:

```php
<?php

namespace App\Analytics;

use App\Models\Order;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Query;
use Illuminate\Database\Eloquent\Model;

class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->per('customer_id')
            ->measure('total', 'sum(amount)');
    }
}
```

Build it:

```bash
php artisan analytics:sync
```

Query it:

```php
Revenue::query()
    ->where('total', '>', 1000)
    ->get();
```

The result is a real database relation that Laravel can query like any other Eloquent model.

## Installation

```bash
composer require eznix86/laravel-analytics
```

Publish and run the migration used to record sync history:

```bash
php artisan vendor:publish --tag="laravel-analytics-migrations"
php artisan migrate
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag="laravel-analytics-config"
```

## Defining a Model

Create a model with:

```bash
php artisan make:analytics Revenue
```

Analytics models live in `app/Analytics`.

They are ordinary Eloquent models that:

1. Implement `AnalyticsModel`
2. Use the `Analytics` trait
3. Define a `computes()` method

The `computes()` method can return a `Query`, a Laravel query builder, or raw SQL.

For most models, start with `Query`:

```php
<?php

namespace App\Analytics;

use App\Models\Order;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Query;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->where('status', '<>', 'cancelled')
            ->per(
                'customer_id',
                date_trunc('month', 'placed_at')->as('month')
            )
            ->measure('total', 'sum(amount)');
    }
}
```

`date_trunc()` is one of the [expressions](#expressions) that translate themselves for the active database driver. These are namespaced functions, so import them with `use function`.

### Dependencies

Passing a model class to `from()` or `join()` records a dependency in the build graph:

```php
$this->from(StgOrder::class); // analytics model: dependency
$this->from(Order::class);    // Eloquent model: source
```

Because your Eloquent models already describe where source data lives, there is no separate source manifest to maintain.

Inside raw SQL, use `ref()` to reference another model:

```php
->whereRaw(
    'id in (select order_id from '.$this->ref(Refund::class).')'
)
```

The referenced model becomes part of the dependency graph and `ref()` resolves to the correct relation name during compilation.

## Writing the Query

The fluent query API is designed around dimensions, measures, and dependencies:

```php
public function computes(): Query
{
    return $this->from(AdjustedJournalEntries::class, 'j')
        ->join(
            StgAccount::class,
            'a',
            'a.account_id',
            'j.account_id'
        )
        ->per(
            'a.account_code',
            'a.account_name',
            'a.account_type'
        )
        ->measure('total_debit', 'sum(j.debit)')
        ->measure('balance', 'sum(j.adjusted_amount)');
}
```

### Dimensions

Use `per()` for dimensions.

A dimension is selected and grouped automatically:

```php
->per('customer_id', 'country')
```

This keeps the selected columns and `group by` clause in sync.

### Measures

Use `measure()` for aggregates:

```php
->measure('total', 'sum(amount)')
->measure('orders', 'count(*)')
```

A measure is selected but not grouped.

### Grain

Use `grain()` when you want to group by an expression without returning that expression:

```php
->grain(date_trunc('day', 't.created_at'))
->measure('created_at', 'min(t.created_at)')
->measure('total', 'count(*)')
```

This is useful when the grouping expression and the output column need to be different.

Grouped queries intentionally do not expose `select()`. Plain columns belong in `per()`, while aggregates belong in `measure()`.

This prevents queries that behave differently across database engines. PostgreSQL and MySQL reject a selected column that is neither grouped nor aggregated, while SQLite can return an arbitrary value.

### Ungrouped Queries

Use `select()` for ungrouped models.

This is also where window functions belong:

```php
return $this->from(TransByStoreDay::class, 'd')
    ->select(
        'd.created_at_day',
        'd.store_id',
        'd.total_transactions',
        raw(
            'sum(d.total_transactions) over (
                partition by d.store_id
                order by d.created_at_day
                rows between 29 preceding and current row
            )'
        )->as('transactions_30_day')
    );
```

The rest of the query API includes:

* `join()`
* `leftJoin()`
* `on()`
* `where()`
* `whereRaw()`
* `orderBy()`
* `limit()`
* `pipe()`

`where()` binds values for you, while `whereRaw()` is available when you need SQL expressions.

### Immutable Queries

A `Query` is immutable. Every method returns a new query.

This makes it safe to share a base query between multiple analytics models without one model modifying another model's definition.

## Materializations

The return type of `computes()` determines how an analytics model is materialized.

| `computes()` returns | Stored as       | Configuration                             |
| -------------------- | --------------- | ----------------------------------------- |
| `Query`              | Table           | Default                                   |
| `ViewQuery`          | View            | `->view()`                                |
| `EphemeralQuery`     | CTE             | `->ephemeral()`                           |
| `IncrementalQuery`   | Table           | `->incremental(replacing:, since:)`       |
| `MicrobatchQuery`    | Table           | `->microbatch($eventTime, $size, begin:)` |
| `SnapshotQuery`      | Versioned table | `->snapshot(trackedBy:, whenChanged:)`    |
| `ImportQuery`        | Table           | `->import(replacing:, appendOnly:)`       |

Materialization-specific configuration is available only on the corresponding query type.

For example, you cannot call `since()` on a normal table query or `whenChanged()` on an incremental query. Invalid combinations fail at compile time instead of being silently ignored at runtime.

### Tables

A normal `Query` creates a table from the model's result.

The table is rebuilt on every sync.

### Views

Use `view()` when you want the model stored as a database view:

```php
->view()
```

The query is recomputed by the database when the view is read.

### Ephemeral Models

Use `ephemeral()` for intermediate models that should not be materialized:

```php
->ephemeral()
```

An ephemeral model is inlined as a CTE into models that reference it.

This is useful for breaking complicated SQL into smaller, reusable models without creating additional tables.

Ephemeral models cannot be queried directly.

## Incremental Models

A normal table is rebuilt from scratch on every sync.

Incremental models allow you to process only new or changed data:

```php
public function computes(): IncrementalQuery
{
    return $this->from(Event::class)
        ->per(
            date_trunc('day', 'happened_at')->as('day'),
            'name'
        )
        ->measure('total', 'count(*)')
        ->incremental(
            replacing: ['day', 'name'],
            since: 'day'
        );
}
```

Without `replacing:`, new rows are appended.

With `replacing:`, rows matching the specified key are replaced. This is useful when an existing time period can be restated.

The delete and insert happen in one transaction, so readers never see the batch missing.

### Incremental Boundaries

An append-only model uses `>` when comparing against the high water mark, so the boundary row is not processed twice.

A model with a replace key uses `>=`, allowing the boundary period to be rebuilt.

When `since()` references a dimension, the comparison is made against the dimension expression rather than its select alias.

### Custom Incremental Logic

Use `whenIncremental()` when `since()` is not sufficient:

```php
->whenIncremental(
    fn (IncrementalQuery $query): IncrementalQuery =>
        $query->whereRaw(
            'id > (select max(id) from '.$this->getTable().')'
        )
)
```

This is the fluent equivalent of dbt's `is_incremental()` block.

### Incremental Strategies

Override `incrementalStrategy()` when you need a specific strategy.

For example, an immutable event log can explicitly use append mode:

```php
IncrementalStrategy::Append
```

### Schema Changes

When an incremental model's columns change, the default behavior is to stop the sync rather than insert incompatible data.

Use `onSchemaChange()` to select another strategy:

* `Ignore` inserts only columns shared by the model and existing relation.
* `AppendNewColumns` adds columns introduced by the model.
* `SyncAllColumns` also removes columns that no longer exist in the model.

### Full Refresh

Rebuild incremental models from scratch with:

```bash
php artisan analytics:sync --full-refresh
```

### Window Functions

Window functions are rejected in incremental models by default.

A window function can depend on rows outside the incremental filter. As a result, the first rows in a batch can receive incorrect values.

Instead:

* Make the model a regular table so the entire window is recomputed.
* Move the window function into a downstream table model.
* Override `allowsWindowFunctions()` only when you have explicitly handled the boundary behavior.

### Late-Arriving Data

Rows that arrive behind the high water mark are not automatically picked up.

Use `whenIncremental()` to widen the incremental window, or use a microbatch model with a lookback period.

## Microbatches

Incremental models process data from a high water mark.

Microbatch models divide processing into time slices and rebuild each slice completely.

Each batch is independent and idempotent, making individual batches safe to rerun:

```php
public function computes(): MicrobatchQuery
{
    return $this->from(Transaction::class, 't')
        ->grain(date_trunc('day', 't.created_at'))
        ->measure('created_at', 'min(t.created_at)')
        ->measure('total', 'count(*)')
        ->microbatch(
            'created_at',
            BatchSize::Month,
            begin: '2026-01-01',
            lookback: 1
        );
}
```

The batch window is applied to the event time column automatically.

The window is half-open, so adjacent batches do not overlap or leave gaps.

For raw SQL models, use `$this->batchWindow()` to apply the batch predicate yourself.

### Lookback

The first run builds every batch from `begin:` to the current time.

Later runs rebuild the newest stored batch plus the configured number of previous batches.

This allows late-arriving records to be included without rebuilding the entire history.

### Batch Size and Output Grain

Batch size and output grain are independent.

For example, monthly batches can produce daily rows:

```php
->grain(date_trunc('day', 't.created_at'))
->microbatch('created_at', BatchSize::Month, begin: '2026-01-01')
```

### Backfilling

Backfill an explicit time range with:

```bash
php artisan analytics:sync DailyTransactions --only \
    --event-time-start=2026-01-01 \
    --event-time-end=2026-01-31
```

### Event Time

The event time column must contain a full timestamp.

For example, select:

```php
->measure('created_at', 'min(created_at)')
```

rather than a truncated date.

This matters because the batch boundaries are timestamp values.

## Snapshots

A regular analytics table represents the current state of the source.

A snapshot keeps the history of those changes.

```php
class StoreHistory extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): SnapshotQuery
    {
        return $this->from(Store::class)
            ->select(
                'store_id',
                'sqft',
                'country',
                'region',
                'is_active'
            )
            ->snapshot(
                trackedBy: ['store_id'],
                whenChanged: ['sqft', 'region', 'is_active']
            );
    }
}
```

Each version contains:

* `valid_from`
* `valid_to`

For example:

```text
store=1 sqft=800  region=Indian Ocean
valid_from=2026-09-03 10:38:47
valid_to=open

store=2 sqft=3000 region=London
valid_from=2026-09-03 10:38:47
valid_to=2026-09-03 10:41:02

store=2 sqft=6000 region=Manchester
valid_from=2026-09-03 10:41:02
valid_to=open
```

`trackedBy:` identifies a source record.

`whenChanged:` determines which columns are monitored for changes.

If `whenChanged:` is empty, all non-key columns are tracked.

Comparisons are null-safe, so transitions between `NULL` and a value are detected.

### Full Refresh

`--full-refresh` does not destroy snapshot history.

Snapshot history cannot be reconstructed from the current source state, so snapshots remain intact during a full refresh.

## Importing Between Connections

A database query cannot normally join tables from two separate Laravel connections.

Import models provide a way to copy rows from one connection to another.

Create the target table first:

```php
Schema::create('imported_events', function (Blueprint $table): void {
    $table->unsignedBigInteger('id');
    $table->string('name');
    $table->timestamp('happened_at');

    $table->unique(['id']);
});
```

Then define the import:

```php
class ImportedEvents extends Model implements AnalyticsModel
{
    use Analytics;

    protected $connection = 'pgsql';

    public function computes(): ImportQuery
    {
        return $this->from(Event::class)
            ->select('id', 'name', 'happened_at')
            ->import(
                replacing: ['id'],
                appendOnly: 'id',
                chunk: 1000
            );
    }
}
```

Rows are read in chunks and upserted into the target table.

With `replacing:`, rerunning the import replaces existing rows instead of creating duplicates.

With `appendOnly:`, only rows beyond the highest stored value are read.

### Append-Only Imports

`appendOnly:` is an assumption about the source.

It is appropriate for sources that only gain rows, such as immutable event logs.

It does not detect:

* Rows deleted from the source
* Rows updated below the current high water mark

For mutable sources, omit `appendOnly:` so the source is read in full and rows are upserted.

To detect both updates and deletes, periodically run a full refresh:

```bash
php artisan analytics:sync ImportedEvents --only --full-refresh
```

### Import Requirements

Imports require:

* An existing target table
* A unique index for the replace key
* A replace key so repeated runs do not duplicate rows
* A plain Eloquent source

An analytics model on another connection cannot be used directly as the source of an import.

Instead, import the underlying source table or build the analytics model on the target connection.

### Memory Usage

Rows are streamed in chunks instead of loading the entire source into PHP memory.

For MySQL, the import disables result buffering so memory usage remains tied to the configured chunk size rather than the total size of the source.

### What Imports Do Not Do

Imports are designed to move rows between database connections already configured in Laravel.

They do not provide:

* API ingestion
* File ingestion
* Change data capture
* External source connectors

## Indexes

Tables created from analytics models do not inherit indexes from their source tables.

Declare the indexes required by your queries:

```php
public function indexes(): array
{
    return [
        ['customer_id', 'month'],
        ['month'],
    ];
}
```

Indexes are recreated when the model is synced.

## Freshness

Declare how long a model can remain fresh:

```php
public function freshness(): ?string
{
    return '25 hours';
}
```

Then inspect its status:

```php
Revenue::lastSyncedAt();
Revenue::isStale();
```

`lastSyncedAt()` returns the timestamp of the last successful build.

## Data Expectations

Analytics models can declare expectations about their output:

```php
public function expectations(): array
{
    return [
        Expectation::unique('created_at_day', 'store_id'),
        Expectation::notNull('store_id'),
        Expectation::acceptedValues(
            'movement',
            ['new', 'churn', 'expansion', 'contraction', 'flat']
        ),
        Expectation::expression(
            'conversion > 0 and conversion <= 1'
        ),
        Expectation::relationship(
            'store_id',
            Store::class,
            'store_id'
        ),
    ];
}
```

Run expectations with:

```bash
php artisan analytics:test
```

Example output:

```text
TrialBalance
  account_code is unique ................................................. PASS
  every row satisfies total_debit >= 0 and total_credit >= 0 .............. FAIL 1 rows

ERROR 1 of 2 expectations failed.
```

The command returns exit code `1` when an expectation fails, making it suitable for CI and scheduled jobs.

You can also run expectations from your test suite:

```php
expect(
    app(Runner::class)->failures(new TrialBalance)
)->toBeEmpty();
```

## Read-Only Models

Analytics models are read-only.

They are rebuilt during sync, so writes throw instead of being silently overwritten by a later sync.

## Artisan Commands

### Inspect the dependency graph

```bash
php artisan analytics:graph
```

Shows the dependency graph and build order, grouped by connection.

### Compile SQL

```bash
php artisan analytics:compile Revenue
```

Compiles the model's SQL without executing it.

### Sync Everything

```bash
php artisan analytics:sync
```

Builds all analytics models in dependency order.

### Sync a Model

```bash
php artisan analytics:sync Revenue
```

Builds `Revenue` and its dependencies.

### Sync Only a Model

```bash
php artisan analytics:sync Revenue --only
```

Builds only the specified model.

### Sync One Connection

```bash
php artisan analytics:sync --connection=warehouse
```

Builds models on the specified connection.

### Parallel Builds

```bash
php artisan analytics:sync --parallel
```

Or specify the number of workers:

```bash
php artisan analytics:sync --parallel=8
```

Models in the same dependency wave can be built concurrently.

Each worker runs as a separate Artisan process, so parallel execution is most useful when models take enough time to make the additional Laravel boot cost worthwhile.

### Machine-Readable Output

```bash
php artisan analytics:sync --porcelain
```

Outputs:

```text
name<TAB>rows<TAB>milliseconds
```

This format is intended for scripts and the parallel sync coordinator.

### Resume a Failed Run

```bash
php artisan analytics:sync --continue
```

A sync records the status of each model.

If a run fails, `--continue` skips models that already succeeded and rebuilds the remaining models.

Example:

```text
ERROR Flaky failed to build.

SQLSTATE[42P01]: relation "late_feed" does not exist

INFO Fix it, then resume with:
php artisan analytics:sync --continue
```

A resumed run keeps the same run ID, allowing the complete operation to remain represented as one coherent run.

### Full Refresh

```bash
php artisan analytics:sync --full-refresh
```

Rebuilds incremental models from scratch.

### Backfill a Time Range

```bash
php artisan analytics:sync \
    --event-time-start=2026-01-01 \
    --event-time-end=2026-01-31
```

### Test Expectations

```bash
php artisan analytics:test
php artisan analytics:test TrialBalance
```

### Prune Sync History

```bash
php artisan analytics:prune
```

## Scheduling

Laravel Analytics works with Laravel's scheduler:

```php
// routes/console.php

Schedule::command('analytics:sync')->dailyAt('03:00');
Schedule::command('analytics:test')->dailyAt('03:30');
Schedule::command('analytics:prune')->weekly();
```

## Run History

Every sync records its run and model statuses in `analytics_runs`.

Configure how long run history should be retained:

```php
// config/analytics.php

'retention' => env('ANALYTICS_RETENTION', '1 year'),
```

Set the value to `null` to retain history indefinitely.

You can also use Laravel's model pruning:

```bash
php artisan model:prune \
    --model="Eznix86\LaravelAnalytics\Models\AnalyticsRun"
```

The dedicated `analytics:prune` command handles every connection used by your analytics models.

## Connections

Analytics models use Eloquent's `$connection` property.

If `$connection` is not set, Laravel Analytics uses:

```php
config('analytics.connection')
```

Every model in a dependency chain must use the same Laravel connection.

This includes the plain Eloquent models used as sources because the database cannot generally join tables from two separate Laravel connections in one query.

Different dependency chains can use different connections:

```text
pgsql
  StgOrder
  MonthlyOrders
  CustomerRevenue

warehouse
  StgEvent
  DailyEvents
```

Build a specific connection with:

```bash
php artisan analytics:sync --connection=warehouse
```

### Cross-Database References

When the database engine supports references between databases or schemas, you can qualify the table instead of creating another Laravel connection.

For example:

```php
class Region extends Model
{
    protected $connection = 'mysql';

    protected $table = 'reference_data.regions';
}
```

This remains a single Laravel connection.

MySQL and SQL Server can resolve `database.table` references on the same server.

PostgreSQL can resolve `schema.table` references within the same database, but PostgreSQL does not support cross-database joins.

When data genuinely resides on another Laravel connection, use an [import model](#importing-between-connections).

## Cross-Driver SQL

Laravel Analytics provides consistent orchestration across PostgreSQL, MySQL, MariaDB, and SQLite.

This includes:

* Dependency resolution
* Build order
* DDL
* Atomic swaps
* Index rebuilding
* Freshness tracking

SQL expressions inside your models remain database-specific unless you use the provided expression helpers.

```php
use function Eznix86\LaravelAnalytics\{
    cast,
    date_add,
    date_diff,
    date_spine,
    date_trunc,
    raw,
    string_agg
};
```

For example:

```php
date_trunc('month', 'created_at')->as('month');
```

The expression is compiled for the active database driver.

Expressions can be used anywhere a column can be used:

```php
->per(date_trunc('month', 'created_at')->as('month'))
->measure('names', string_agg('name', ', '))
```

They can also be nested:

```php
raw(
    '%s / nullif(%s, 0)',
    cast('total', 'decimal(18,4)'),
    'customers'
);
```

### Raw Expressions

`raw()` uses `%s` placeholders for rendered operands:

```php
raw(
    '%s / nullif(%s, 0)',
    cast('total', 'decimal(18,4)'),
    'customers'
)
```

The number of placeholders must match the number of operands.

With no operands, the SQL is passed through unchanged:

```php
raw("name like '%sale%'")
```

## Expressions in Raw SQL Models

A raw SQL model cannot contain an expression object directly.

Use the corresponding model methods instead:

```php
$this->dateTrunc('month', 'created_at');
$this->dateAdd('day', 7, 'created_at');
$this->dateDiff('day', 'start', 'end');
$this->dateSpine('month', "'2026-01-01'", 'current_date');
$this->stringAgg('name', ', ');
$this->castAs('debit', 'bigint');
```

These methods use the model's database connection to render the appropriate SQL.

Use `$this->render()` when a shared expression needs to be converted to SQL:

```php
$this->render($expression);
```

This allows shared metrics to remain driver-aware without requiring them to know which database grammar will compile them.

## Registering a Driver

Additional database drivers can be registered through the grammar manager:

```php
app(GrammarManager::class)->extend(
    'clickhouse',
    ClickHouseGrammar::class
);
```

## Sharing Metrics

Shared metrics are a convention for keeping repeated SQL definitions in one place.

For example:

```php
final class Metrics
{
    public static function totalTransactions(
        string $alias = 't'
    ): string {
        return "count(distinct {$alias}.transaction_id)";
    }

    public static function transPerCust(
        string $alias = 't'
    ): Expression {
        return raw(
            '%s / nullif(%s, 0)',
            cast(
                self::totalTransactions($alias),
                'decimal(18,4)'
            ),
            "count(distinct {$alias}.customer_id)"
        );
    }
}
```

Use the metric from an analytics model:

```php
->measure(
    'trans_per_cust',
    Metrics::transPerCust()
)
```

### Pass the Source Alias

Shared metrics should accept the source alias as an argument.

Avoid hard-coding an alias such as `t` unless every caller is required to use it.

```php
Metrics::transPerCust('orders')
```

This prevents a shared metric from silently referring to the wrong table alias.

### When to Share a Metric

A shared metric is useful when:

* The metric appears in multiple models.
* The calculation is complex.
* Two copies of the calculation could drift apart.
* The metric contains database-specific expressions.

For a metric that is used once or is already obvious from the query, keeping it inline may be clearer.

Shared metrics can return an `Expression` when they need driver-aware SQL. The active connection determines how the expression is compiled.

## Raw SQL and Query Builders

The fluent API is the recommended starting point, but `computes()` can also return raw SQL.

Raw SQL is useful for queries that do not have a convenient fluent representation, such as complex `CASE` expressions or lateral joins.

```php
public function materialization(): Materialization
{
    return Materialization::Ephemeral;
}

public function computes(): string
{
    return 'select store_id, '
        ."case when country in ('MU', 'ZA') "
        ."then 'AFRICA' else 'OTHER' end as country_group "
        .'from '.$this->ref(Store::class);
}
```

Raw SQL models declare materialization and other applicable configuration through methods such as:

* `materialization()`
* `uniqueKey()`
* `eventTime()`
* `batchSize()`
* `begin()`
* `checkColumns()`

Incremental raw SQL models can use `isIncremental()`.

Microbatch raw SQL models can use `batchWindow()`.

### Query Builders

Laravel query builders are supported too:

```php
public function computes(): string|Builder
{
    return DB::table($this->ref(Order::class))
        ->select('customer_id', 'amount')
        ->where('status', '<>', 'cancelled');
}
```

Fluent models, raw SQL models, and query builder models can reference one another because dependencies resolve to relation names.

## Changelog

See [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Thank you for considering contributing to Laravel Analytics.

Please review the [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review the [security policy](.github/SECURITY.md) for information about reporting security vulnerabilities.

## Credits

* [Bruno Bernard](https://github.com/eznix86)
* [All Contributors](../../contributors)

## License

Laravel Analytics is open-sourced software licensed under the [MIT license](LICENSE.md).

