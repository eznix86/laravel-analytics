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

The package brings dbt's way of working to Laravel. Each model is one SELECT, the package resolves what depends on what, and `analytics:sync` builds them in order on the application's own database connections. There is no Python and no separate profile to configure.

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

Generate with `php artisan make:analytics Revenue`. The stub is a fluent `Query`; replace the example source model with your own. Models live in `app/Analytics`, extend `Illuminate\Database\Eloquent\Model`, implement `Eznix86\LaravelAnalytics\Contracts\AnalyticsModel`, and use `Eznix86\LaravelAnalytics\Concerns\Analytics`.

```php
use Eznix86\LaravelAnalytics\Query;

use function Eznix86\LaravelAnalytics\date_trunc;

class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function indexes(): array
    {
        return [['customer_id']];
    }

    public function computes(): Query
    {
        return $this->from(StgOrder::class)
            ->where('status', '<>', 'cancelled')
            ->per('customer_id', date_trunc('month', 'placed_at')->as('month'))
            ->measure('total', 'sum(amount)');
    }
}
```

- `computes(): string|Builder|Query` is the only required method. Prefer the fluent `Query`.
- `$this->from(Model::class, 'alias')` starts the query and records the dependency. Pass a plain Eloquent model for a raw table, another analytics model for a derived one. `$this->ref()` does the same inside a raw fragment.
- `per()` declares a dimension, selected and grouped in one statement. `measure()` declares an aggregate. `grain()` groups by an expression that is not selected. The `group by` is derived, and `select()` is refused on a grouped query.
- `select()` is for ungrouped models, and is where a window function goes, wrapped in `raw()`.
- `join()` and `leftJoin()` take the model class and one condition. Chain `on()` for a second.
- `where()` binds its value. `whereRaw()` takes a fragment. Also `orderBy()`, `limit()`, `pipe()`.
- `indexes()` must declare any index the model needs, because `create table as select` produces none.
- `freshness()` returns a window such as `'25 hours'` and enables `Model::isStale()`.
- `expectations()` declares data assertions checked by `analytics:test`.

### 3. Choose the materialization

The declared return type of `computes()` says how the model is persisted. Set it with the matching call at the end of the chain.

| Return type | Materialization | Call |
| --- | --- | --- |
| `Query` | table | the default |
| `ViewQuery` | view | `->view()` |
| `EphemeralQuery` | ephemeral, inlined as a CTE | `->ephemeral()` |
| `IncrementalQuery` | incremental | `->incremental(replacing:, since:)` |
| `MicrobatchQuery` | microbatch | `->microbatch($eventTime, $size, begin:, lookback:)` |
| `SnapshotQuery` | snapshot | `->snapshot(trackedBy:, whenChanged:)` |
| `ImportQuery` | import from another connection | `->import(replacing:, appendOnly:, chunk:)` |

```php
public function computes(): IncrementalQuery
{
    return $this->from(Event::class)
        ->per(date_trunc('day', 'happened_at')->as('day'), 'name')
        ->measure('total', 'count(*)')
        ->incremental(replacing: ['day', 'name'], since: 'day');
}
```

- Do not also override `materialization()`, `uniqueKey()`, `eventTime()`, `batchSize()`, `begin()`, `lookback()` or `checkColumns()`. They are read off the query.
- `since('column')` adds the high water mark filter. It uses `>` when the model appends and `>=` when a replace key makes it replace. If the column is a dimension it compares the expression, not the alias.
- `whenIncremental(fn (IncrementalQuery $q) => ...)` covers anything `since()` cannot express.
- A microbatch model needs no filter. The batch window is applied from its event time column.
- Neither filter is added on the first build or under `--full-refresh`.
- Set `onSchemaChange()` when an incremental model's columns will drift.
- `appendOnly:` on an import is a claim that the source only ever gains rows. It reads past the highest value already stored, so a deleted row stays in the copy and an update below that value is missed. Drop it to catch updates, or schedule `--full-refresh` to catch both.
- An import copies rows from the connection its source lives on onto the model's own connection, which is the only model allowed to cross a connection. Write a migration for the target table first, with a unique index on the replace key. The import never creates the table, so the column types stay the ones you chose. Its source must be a plain Eloquent model, not an analytics model on the other connection.
- A model that returns raw SQL keeps `materialization()` and the rest as methods, and writes its own filter with `isIncremental()` and its own batch predicate with `$this->batchWindow()`.
- Write raw SQL as a heredoc opened with `<<<SQL`, not `<<<'SQL'`, and interpolate `ref()` in place:

```php
public function computes(): string
{
    return <<<SQL
        select store_id, sqft
        from {$this->ref(Store::class)}
        where is_active
    SQL;
}
```

Never build raw SQL with `.` concatenation or `sprintf`. Concatenation loses the SQL layout and a missing trailing space breaks on one driver only. `sprintf` treats `%` as a format specifier, so an ordinary `like '%sale%'` throws `ValueError: Missing padding character`. The same applies to a `whereRaw()` fragment inside a fluent query.

### 4. Build and schedule

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

### 5. Declare expectations

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

### 6. Read through Eloquent

```php
Revenue::query()->where('total', '>', 1000)->get();
Revenue::lastSyncedAt();
Revenue::isStale();
```

### 7. Handle dialect differences

Use an expression rather than one database's function name. Expressions carry no driver and resolve one when they compile, so the same object becomes `date_trunc` on PostgreSQL, `date_format` on MySQL and `strftime` on SQLite.

```php
use function Eznix86\LaravelAnalytics\{cast, date_add, date_diff, date_spine, date_trunc, raw, string_agg};

->per(date_trunc('month', 'created_at')->as('month'))
->measure('ratio', raw('%s / nullif(%s, 0)', cast('total', 'decimal(18,4)'), 'customers'))
```

- Expressions nest, and `->as()` gives one an alias.
- `raw()` fills `%s` placeholders with rendered operands. With no operands the fragment is left alone.
- Inside a raw SQL model use the string helpers instead: `$this->dateTrunc`, `dateAdd`, `dateDiff`, `dateSpine`, `stringAgg`, `castAs`. `$this->render($expression)` turns an expression into a string the same way.
- Register an unsupported driver with `app(GrammarManager::class)->extend('clickhouse', ClickHouseGrammar::class)`.

### 8. Share a metric across rollups

A metric is a static method returning a SQL fragment, so the arithmetic is written once.

```php
final class Metrics
{
    public static function transPerCust(string $alias = 't'): Expression
    {
        return raw(
            '%s / nullif(%s, 0)',
            cast("count(distinct {$alias}.transaction_id)", 'decimal(18,4)'),
            "count(distinct {$alias}.customer_id)",
        );
    }
}

->measure('trans_per_cust', Metrics::transPerCust())
```

Take the alias as a parameter. A fragment that hardcodes `t.` forces every caller to alias its source `t`, and a caller that aliases something else gets a wrong number with no error. Only do this when the metric is used in two or more models and its definition is not obvious.

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
- building raw SQL with `.` concatenation or `sprintf`; use a heredoc and interpolate `ref()`
- calling `computes()` directly on a raw SQL model; use `compile()` so refs and CTEs resolve
- referencing a model on another connection; import the source onto this one first with an import model
- omitting `indexes()` on a large table and losing index coverage after every rebuild
- rerunning a whole failed sync from scratch instead of `analytics:sync --continue`
- using `--parallel` for a graph of fast models; each worker pays a framework boot
- putting a window function in an incremental model; the boundary row silently gets a wrong value, and the package refuses it
- forgetting `replacing:` on an incremental model whose rows can be restated, which duplicates them instead of replacing
- pointing `--full-refresh` at a snapshot; its history cannot be recomputed and the command refuses
- giving a microbatch model an event time column that holds a date rather than a full timestamp; on SQLite it will never match the batch window
