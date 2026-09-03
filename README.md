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

This brings dbt's way of working to Laravel. dbt, short for data build tool, is the standard way data
teams turn raw tables into analytics tables: you write one SELECT per model, and the tool works out
what depends on what and builds them in order. This package does the same job in PHP, against the
database connections your application already has.

What it is for:

- **Write a definition once.** A model is a SELECT that other models reference. Change it in one
  place and the change reaches everything downstream on the next sync, so business logic is not
  copied into six dashboards.
- **Rebuild without fear.** A sync is safe to rerun. New data is always assembled first and put in
  place in one step, so a failed or repeated run does not leave readers with a half built table.
- **Keep it inside the app.** Models live in `app/Analytics` and are read back with ordinary
  Eloquent. PostgreSQL, MySQL, MariaDB and SQLite are supported, and independent chains can each sit
  on their own connection.

```php
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
            ->per('customer_id', date_trunc('month', 'placed_at')->as('month'))
            ->measure('total', 'sum(amount)');
    }
}
```

`date_trunc()` is one of the [expressions](#expressions) that translate themselves per driver. They
are namespaced functions, so import them with `use function`.

`computes()` returns a `Query`, a query builder, or a string of raw SQL. Start with the query: it
writes the `group by` for you and cannot forget a dependency. The other two are fully supported and
are the right answer for a `case when` ladder or a window function. See
[Raw SQL and query builders](#raw-sql-and-query-builders).

### Dependencies

Passing a model class to `from()` or `join()` is what records the edge in the build graph:

```php
$this->from(StgOrder::class)     // another analytics model: a graph edge
$this->from(Order::class)        // a plain Eloquent model: a source, a leaf
```

Because your Eloquent models already declare where raw data lives, there is no source manifest to maintain.

Inside a raw fragment, `ref()` does the same job and returns the relation name to select from:

```php
->whereRaw('id in (select order_id from '.$this->ref(Refund::class).')')
```

## Writing the query

```php
public function computes(): Query
{
    return $this->from(AdjustedJournalEntries::class, 'j')
        ->join(StgAccount::class, 'a', 'a.account_id', 'j.account_id')
        ->per('a.account_code', 'a.account_name', 'a.account_type')
        ->measure('total_debit', 'sum(j.debit)')
        ->measure('balance', 'sum(j.adjusted_amount)');
}
```

`per()` declares a dimension: it is selected *and* grouped by, so the expression is written once
instead of once per clause. `measure()` declares an aggregate, which is selected and not grouped.
`grain()` groups by an expression that is never selected, for a rollup whose output columns differ
from what it is grouped by:

```php
->grain(date_trunc('day', 't.created_at'))
->measure('created_at', 'min(t.created_at)')
->measure('total', 'count(*)')
```

A grouped query refuses `select()`. A column that is neither grouped nor aggregated is rejected by
PostgreSQL and MySQL and silently given an arbitrary row's value by SQLite, so the API does not
offer the combination. Plain columns go in `per()`, aggregates in `measure()`.

Ungrouped models use `select()`, which is also where a window function goes:

```php
return $this->from(TransByStoreDay::class, 'd')
    ->select('d.created_at_day', 'd.store_id', 'd.total_transactions',
        raw('sum(d.total_transactions) over (partition by d.store_id '
            .'order by d.created_at_day rows between 29 preceding and current row)')->as('transactions_30_day'));
```

The rest of the surface: `join()` / `leftJoin()` with `on()` for a second condition, `where()` (which
binds its value) and `whereRaw()`, `orderBy()`, `limit()`, and `pipe()` for a shared fragment.

A `Query` is immutable: every method returns a new query, so a shared base can be handed to several
models without one of them altering it.

## Materializations

The declared return type of `computes()` is what says how a model is persisted, and it carries the
configuration that materialization needs:

| `computes()` returns | Stored | Declared with |
| --- | --- | --- |
| `Query` | every row | the default |
| `ViewQuery` | definition only, recomputed per read | `->view()` |
| `EphemeralQuery` | nothing, inlined as a CTE | `->ephemeral()` |
| `IncrementalQuery` | every row, topped up | `->incremental(replacing:, since:)` |
| `MicrobatchQuery` | every row, rebuilt one time slice at a time | `->microbatch($eventTime, $size, begin:)` |
| `SnapshotQuery` | one row per version, `valid_from` / `valid_to` | `->snapshot(trackedBy:, whenChanged:)` |
| `ImportQuery` | rows copied from another connection | `->import(replacing:, appendOnly:)` |

A setting from another materialization is not on the query at all. You cannot call `since()` on a
table query, or `whenChanged()` on an incremental one. A wrong combination fails to compile instead
of being ignored at run time.

An ephemeral model is never built. It is inlined as a CTE into every model that references it, which
makes splitting long SQL into small layered models free. It cannot be queried directly.

A model that returns raw SQL declares `materialization()` as a method instead. Its `computes()` is
never called just to read configuration, because raw SQL can depend on things that only exist during
a real build.

### Incremental models

A table is rebuilt from scratch on every sync. An incremental model appends instead, which matters once a full rebuild stops finishing in the window you have.

`since()` writes the filter for you:

```php
public function computes(): IncrementalQuery
{
    return $this->from(Event::class)
        ->per(date_trunc('day', 'happened_at')->as('day'), 'name')
        ->measure('total', 'count(*)')
        ->incremental(replacing: ['day', 'name'], since: 'day');
}
```

Without `replacing:` the new rows are appended. With it, rows matching that key are deleted first, so a restated day replaces itself instead of doubling. The delete and insert run in one transaction, so a reader never sees the batch missing.

The comparison depends on the strategy. A model that appends uses `>`, so the boundary row is not
counted twice. A model with a replace key uses `>=`, so a row restated in the boundary period is
rebuilt.

If the column is a dimension, `since()` compares its expression, not its alias. No driver accepts a
select alias in a `where`.

Nothing is added on the build that creates the relation, or under `--full-refresh`.

Anything `since()` cannot express goes in `whenIncremental()`, which is the fluent form of dbt's `is_incremental()` block:

```php
->whenIncremental(fn (IncrementalQuery $query): IncrementalQuery => $query->whereRaw(
    'id > (select max(id) from '.$this->getTable().')',
))
```

Override `incrementalStrategy()` to force `IncrementalStrategy::Append` even with a replace key, which is right for an immutable log that has a natural id.

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

Late-arriving rows are yours to handle, as they are in dbt. A row that lands behind the high water mark is not picked up, so either widen the window with `whenIncremental()`, or use a microbatch model, which rebuilds whole time slices and has a lookback for exactly this.

### Microbatch

An incremental model tops up from a high water mark. A microbatch model splits the run into time slices instead, rebuilding each one whole. Every batch is independent and idempotent, so rerunning one is always safe:

```php
public function computes(): MicrobatchQuery
{
    return $this->from(Transaction::class, 't')
        ->grain(date_trunc('day', 't.created_at'))
        ->measure('created_at', 'min(t.created_at)')
        ->measure('total', 'count(*)')
        ->microbatch('created_at', BatchSize::Month, begin: '2026-01-01', lookback: 1);
}
```

The window of the batch being built is applied from the event time column, as bound values, with no filter written in the model. It is half open, so consecutive batches neither overlap nor leave a gap. In a raw SQL model, write it yourself with `$this->batchWindow()`.

The first run builds every batch from `begin:` to now. Later runs rebuild the newest stored batch plus `lookback:` batches behind it, which is how a late arriving row still lands. Each batch deletes its own window and inserts the rebuild, in one transaction.

Batch size and output grain are independent: monthly batches producing daily rows is fine, and often what you want.

Backfill an explicit range without touching anything else:

```bash
php artisan analytics:sync DailyTransactions --only \
    --event-time-start=2026-01-01 --event-time-end=2026-01-31
```

**The event time column must hold a full timestamp.** SQLite's date functions return ten characters, which sort before a nineteen character window bound and would never match. Select `min(created_at)` rather than a truncated date.

### Snapshots

A table shows the source as it is now. A snapshot records how it got there, one row per version, with `valid_from` and `valid_to`:

```php
class StoreHistory extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): SnapshotQuery
    {
        return $this->from(Store::class)
            ->select('store_id', 'sqft', 'country', 'region', 'is_active')
            ->snapshot(trackedBy: ['store_id'], whenChanged: ['sqft', 'region', 'is_active']);
    }
}
```

Each sync closes the open version of every row whose watched columns changed and opens a new one. Rows that did not change are left alone; rows the source has never had get their first version.

```
store=1 sqft=800   region=Indian Ocean  valid_from=2026-09-03 10:38:47  valid_to=open
store=2 sqft=3000  region=London        valid_from=2026-09-03 10:38:47  valid_to=2026-09-03 10:41:02
store=2 sqft=6000  region=Manchester    valid_from=2026-09-03 10:41:02  valid_to=open
```

`whenChanged:` narrows what counts as a change; empty watches every non-key column. Comparison is null safe, so a column going to or from null opens a version.

The reported row count is versions opened, not the size of the table. `trackedBy:` is required. Without it nothing identifies which row a version belongs to, and the resolver says so.

**A full refresh leaves snapshots alone**, because their history cannot be recomputed from the source. Aiming `--full-refresh` at a snapshot by name fails outright rather than quietly destroying it.

### Importing from another connection

A model can only read from one connection, because no database engine can join two in a single
statement. An import model is how you get the other side's data onto the connection you need it on.

Write a migration for the target table first, so you choose the column types:

```php
Schema::create('imported_events', function (Blueprint $table): void {
    $table->unsignedBigInteger('id');
    $table->string('name');
    $table->timestamp('happened_at');

    $table->unique(['id']);
});
```

Then the model reads from the source and lands on its own connection:

```php
class ImportedEvents extends Model implements AnalyticsModel
{
    use Analytics;

    protected $connection = 'pgsql';        // where the rows land

    public function computes(): ImportQuery
    {
        return $this->from(Event::class)    // Event lives on the warehouse connection
            ->select('id', 'name', 'happened_at')
            ->import(replacing: ['id'], appendOnly: 'id', chunk: 1000);
    }
}
```

The rows are read in chunks and upserted, so a rerun replaces rather than doubles. `appendOnly:` reads
only past the highest value already stored, and `--full-refresh` empties the table and reads
everything again. Downstream models on `pgsql` then reference `ImportedEvents` like any other model.

Rows are streamed rather than loaded. MySQL buffers a whole result set in PHP memory unless told not
to, so an import turns that off for the read: one million rows peaked at 77 MB instead of 241 MB, at
the same speed. Memory is the size of a chunk, not the size of the source.

**`appendOnly:` is a claim about the source, not a setting.** It reads only past the highest value
already stored, so a row deleted at the source stays in the copy, and a row updated below that value
is never picked up. Neither produces an error, which is why the parameter is named after the
assumption you are making:

- leave `appendOnly:` off, and every run reads the whole source and upserts it, which catches updates
  but still not deletes
- schedule a periodic `analytics:sync ImportedEvents --only --full-refresh`, which empties the table
  and reads everything again, which catches both
- use `appendOnly:` for a source that only ever gains rows, such as an event log

Four things it refuses, each with the fix in the message:

- a target table that does not exist, because an import never creates one
- a target with no unique index on the replace key, since the upsert would insert duplicates instead
- an import with no replace key, since a second run would double the rows
- a source that is an analytics model on another connection, rather than a plain Eloquent source

That last one keeps the two connections independent graphs. Import the table the other model is built
from, or build it on this connection instead.

The package does not do the rest of ingestion. There is no API reader, no file loader and no change
capture. An import moves rows between connections your application already has.

## Indexes

`create table as select` produces a table with no indexes, so declare the ones you need and they are rebuilt on every sync:

```php
public function indexes(): array
{
    return [['customer_id', 'month'], ['month']];
}
```

## Freshness

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

## Data expectations

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

## Analytics models are read-only

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

`analytics:graph` groups models into waves. Everything inside a wave depends only on earlier waves, so `--parallel` builds a wave at once, starting the next model as soon as a worker frees up. Each worker is a separate `artisan` process with its own connection, so it costs a Laravel boot per model. That is worth it when models take seconds, not milliseconds:

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

`analytics:prune` walks every connection your analytics models use, skipping any that has no run log. The model uses Laravel's `MassPrunable`, so `php artisan model:prune --model="Eznix86\LaravelAnalytics\Models\AnalyticsRun"` works too, but it only prunes one connection, which is why the dedicated command exists. Set `retention` to `null` to keep history forever.

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

When the data is genuinely on another connection, copy it over with an import model.

## Cross-driver SQL

The package guarantees cross-driver *orchestration*: DDL, atomic swaps, index rebuilds, build order
and freshness work the same on PostgreSQL, MySQL/MariaDB and SQLite. The SQL inside `computes()` is
yours.

Where a dialect difference is unavoidable, use an expression. Expressions carry no driver of their
own and resolve one when they are compiled, so the same object becomes `date_trunc` on PostgreSQL,
`date_format` on MySQL and `strftime` on SQLite:

```php
use function Eznix86\LaravelAnalytics\{cast, date_add, date_diff, date_spine, date_trunc, raw, string_agg};

date_trunc('month', 'created_at')->as('month');
cast('debit', 'bigint');                    // 'signed' on MySQL, 'integer' on SQLite
raw('%s / nullif(%s, 0)', cast('total', 'decimal(18,4)'), 'customers');
```

Expressions nest, so an expression can be an argument to another one, and they go anywhere a column
does: `per()`, `measure()`, `select()`, or a query builder:

```php
->per(date_trunc('month', 'created_at')->as('month'))
->measure('names', string_agg('name', ', '))

DB::table($this->ref(Order::class))->select(date_trunc('month', 'created_at'));
```

`raw()` substitutes `%s` placeholders with rendered operands, and refuses to render when the number
of placeholders and operands disagree. With no operands the fragment is passed through untouched, so
`raw("name like '%sale%'")` means what it says.

### Inside a raw SQL model

A string model cannot hold an expression object, so the same helpers exist as methods that return a
string, using the model's own connection:

```php
$this->dateTrunc('month', 'created_at');
$this->dateAdd('day', 7, 'created_at');
$this->dateDiff('day', 'start', 'end');
$this->dateSpine('month', "'2026-01-01'", 'current_date');
$this->stringAgg('name', ', ');
$this->castAs('debit', 'bigint');
```

`$this->render()` turns an expression into a string the same way, which is how a shared metric can
return an expression and still be used in raw SQL.

### Registering a driver

```php
app(GrammarManager::class)->extend('clickhouse', ClickHouseGrammar::class);
```

## Sharing a metric

This is a convention, not a feature. A metric is a static method that returns a SQL fragment. Write
the arithmetic once, and every rollup that uses it gives the same answer.

```php
final class Metrics
{
    public static function totalTransactions(string $alias = 't'): string
    {
        return "count(distinct {$alias}.transaction_id)";
    }

    public static function transPerCust(string $alias = 't'): Expression
    {
        return raw(
            '%s / nullif(%s, 0)',
            cast(self::totalTransactions($alias), 'decimal(18,4)'),
            "count(distinct {$alias}.customer_id)",
        );
    }
}
```

```php
// one rollup by brand and month, another by store and day, the same definition
->measure('trans_per_cust', Metrics::transPerCust())
```

**Take the alias as a parameter.** A fragment like `count(distinct t.transaction_id)` silently
requires every caller to alias its source `t`; a caller that aliases something else gets a wrong
number with no error.

**Worth it when** the metric appears in two or more models and its definition is not obvious: a
ratio, a filtered count, anything where two copies could drift apart unnoticed. A metric used once,
or one that reads as plainly as its name, is clearer inlined.

The package adds one thing here. A metric that needs a driver-aware fragment returns an
`Expression` instead of a string, so it never has to be handed a `Grammar`. The connection is decided
where the metric is used, not where it is written.

The joins those rollups share belong in one wide model they all read from. That resolves the join
graph once at build time; the package does not plan joins per query the way a semantic layer such as
Cube or dbt's MetricFlow does, so pick the dimension combinations you want and materialize them.

## Raw SQL and query builders

`computes()` also accepts a string of raw SQL, which is the right answer for a `case when` ladder, a
lateral join, or anything else with no fluent form:

```php
public function materialization(): Materialization
{
    return Materialization::Ephemeral;
}

public function computes(): string
{
    return 'select store_id, '
        ."case when country in ('MU', 'ZA') then 'AFRICA' else 'OTHER' end as country_group "
        .'from '.$this->ref(Store::class);
}
```

A raw SQL model declares `materialization()` and, where they apply, `uniqueKey()`, `eventTime()`,
`batchSize()`, `begin()` and `checkColumns()` as methods, and writes its own incremental filter with
`isIncremental()` and its own batch predicate with `batchWindow()`.

A query builder works too, and is portable for free:

```php
public function computes(): string|Builder
{
    return DB::table($this->ref(Order::class))
        ->select('customer_id', 'amount')
        ->where('status', '<>', 'cancelled');
}
```

The two forms mix per model: a fluent model can `ref()` a raw one and the other way round, because
both resolve to a relation name.

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
