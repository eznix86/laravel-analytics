---
name: laravel-analytics-development
description: >
  Configure and apply the Laravel Analytics package in Laravel applications.
license: MIT
metadata:
  author: Bruno Bernard
---

# Laravel Analytics

Use this skill when a Laravel application needs analytics tables that are derived from other tables, rebuilt on a schedule, and read through Eloquent.

## Primary Goal

- apply the `eznix86/laravel-analytics` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project with `eznix86/laravel-analytics` installed
- publish and run the sync history migration if `analytics_runs` does not exist:
  `php artisan vendor:publish --tag="laravel-analytics-migrations" && php artisan migrate`
- publish config only when defaults need changing:
  `php artisan vendor:publish --tag="laravel-analytics-config"`

### 2. Define analytics models

Generate with `php artisan make:analytics Revenue`. Models live in `app/Analytics`, extend `Illuminate\Database\Eloquent\Model`, implement `Eznix86\LaravelAnalytics\Contracts\AnalyticsModel`, and use `Eznix86\LaravelAnalytics\Concerns\Analytics`.

```php
class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function indexes(): array
    {
        return [['customer_id']];
    }

    public function computes(): string
    {
        return 'select customer_id, sum(amount) as total from '
            .$this->ref(StgOrder::class)
            .' group by customer_id';
    }
}
```

- `computes(): string|Builder` is the only required method
- `$this->ref(Model::class)` returns the relation to select from and records the dependency; pass a plain Eloquent model for raw tables, another analytics model for derived ones
- `materialization()` returns `Materialization::Table` (default), `View`, `Incremental`, `Microbatch`, `Snapshot`, or `Ephemeral`
- `indexes()` must declare any index the model needs, because `create table as select` produces none
- `freshness()` returns a window such as `'25 hours'` and enables `Model::isStale()`
- `expectations()` declares data assertions checked by `analytics:test`
- for `Incremental`, guard the filter with `isIncremental()`, declare `uniqueKey()` when rows can be restated, and set `onSchemaChange()` if the columns will drift
- for `Snapshot`, declare `uniqueKey()` and optionally `checkColumns()`; each sync closes changed versions and opens new ones
- for `Microbatch`, declare `eventTime()`, `batchSize()` and `begin()`, and place `$this->batchWindow()` in the model's where clause

### 3. Build and schedule

```bash
php artisan analytics:graph                        # build order, grouped by connection
php artisan analytics:compile Revenue              # compiled SQL, nothing executed
php artisan analytics:sync                         # build everything, in order
php artisan analytics:sync Revenue                 # Revenue plus its dependencies
php artisan analytics:sync Revenue --only          # Revenue alone
php artisan analytics:sync --connection=warehouse  # one connection
php artisan analytics:sync --parallel              # build each wave concurrently
php artisan analytics:sync --continue              # resume after a failure
php artisan analytics:test                         # check declared expectations
php artisan analytics:sync --full-refresh          # rebuild incremental models from scratch
php artisan analytics:prune                        # drop sync history past the retention window
```

```php
// routes/console.php
Schedule::command('analytics:sync')->dailyAt('03:00');
```

### 4. Declare expectations

```php
public function expectations(): array
{
    return [
        Expectation::unique('created_at_day', 'store_id'),
        Expectation::notNull('store_id'),
        Expectation::acceptedValues('status', ['open', 'closed']),
        Expectation::expression('conversion > 0 and conversion <= 1'),
        Expectation::relationship('store_id', Store::class, 'store_id'),
    ];
}
```

`analytics:test` exits 1 when any expectation fails, so schedule it after `analytics:sync`. From a test suite, assert `app(Runner::class)->failures(new Model)` is empty.

### 5. Read through Eloquent

```php
Revenue::query()->where('total', '>', 1000)->get();
Revenue::lastSyncedAt();
Revenue::isStale();
```

### 6. Handle dialect differences

`computes()` returns a string, a query builder, or a fluent `Eznix86\LaravelAnalytics\Query` started with `$this->from(Model::class, 'alias')`. On the fluent query, `per()` declares a dimension that is selected and grouped in one statement, `measure()` an aggregate, and `grain()` a grouping key that is not selected; the `group by` is derived, and `select()` is refused on a grouped query. Use `join()`/`leftJoin()` with `on()` for a further condition, `where()` for a bound value, `whereRaw()` for a fragment, and `raw()` for a window function. Passing the model class to `from()`/`join()` registers the dependency, so `ref()` is only needed inside raw fragments and string models.

On an incremental model, `since('column')` adds the high water mark filter — `>` when the model appends, `>=` when a unique key makes it replace — and resolves a dimension alias back to its expression; `whenIncremental(fn (Query $q) => ...)` covers anything else. A microbatch model needs no filter: the batch window is applied from `eventTime()`. Neither is added on the first build or under `--full-refresh`.

Use the driver-aware helpers inside `computes()` rather than hard-coding one database's functions: `$this->dateTrunc`, `dateAdd`, `dateDiff`, `dateSpine`, `stringAgg`, `castAs`. Outside a model — in a shared metric class, or in a query builder — use the expression form of the same helpers (`Eznix86\LaravelAnalytics\{date_trunc, date_add, date_diff, date_spine, string_agg, cast, raw}`), which resolves the driver when it is compiled instead of being handed one. Expressions nest, carry an `->as()` alias, and render inside a string model with `$this->render($expression)`. Register an unsupported driver with `app(GrammarManager::class)->extend('clickhouse', ClickHouseGrammar::class)`.

## Rules, References, and Templates

Read before executing:

- `config/analytics.php` for `connection`, `path`, `namespace`, `prefix`, and `schema`
- every model in one dependency chain, including plain Eloquent sources, must share a connection; the resolver fails with an explanation otherwise

## Examples

- Layer a mart on staging: a `View` model that cleans a raw table, an `Ephemeral` model that names a reusable aggregation, and a `Table` model that the dashboard queries.
- Split long SQL: extract a CTE into its own `Ephemeral` model so it costs no storage and no extra build step.
- Detect stale data: give the model a `freshness()` window and check `Model::isStale()` before rendering a report.

## Anti-patterns

- writing to an analytics model; they are rebuilt from scratch on every sync and writes throw
- querying an `Ephemeral` model directly; it has no relation and is inlined into its consumers
- calling `computes()` directly; use `compile()` so refs and CTEs resolve
- referencing a model on another connection; replicate the source first
- omitting `indexes()` on a large table and losing index coverage after every rebuild
- rerunning a whole failed sync from scratch instead of `analytics:sync --continue`
- using `--parallel` for a graph of fast models; each worker pays a framework boot
- putting a window function in an incremental model; the boundary row silently gets a wrong value, and the package refuses it
- forgetting `uniqueKey()` on an incremental model whose rows can be restated, which duplicates them instead of replacing
- pointing `--full-refresh` at a snapshot; its history cannot be recomputed and the command refuses
- giving a microbatch model an event time column that holds a date rather than a full timestamp; on SQLite it will never match the batch window
