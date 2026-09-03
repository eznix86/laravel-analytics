# Release Notes

## [Unreleased](https://github.com/eznix86/laravel-analytics/compare/v0.1.0...1.x)

### Added

- A fluent `Query` accepted by `computes()`, which derives `group by` from `per()` and `grain()`, binds `where()` values, registers dependencies from the model class passed to `from()` and `join()`, and refuses `select()` on a grouped query. Immutable: every method returns a new query.
- `Analytics` trait and `AnalyticsModel` contract for defining analytics models as Eloquent models.
- `ref()` dependency resolution that distinguishes analytics models from plain Eloquent sources.
- `table`, `view` and `ephemeral` materializations, with ephemeral models inlined as CTEs.
- `analytics:graph`, `analytics:compile`, `analytics:sync` and `make:analytics` commands.
- Dependency ordering with cycle detection and cross-connection validation.
- Per-driver grammars for PostgreSQL, MySQL/MariaDB and SQLite, with `dateTrunc`, `dateAdd`, `dateDiff`, `dateSpine`, `stringAgg` and `cast` helpers, extensible through `GrammarManager`.
- Index rebuilding and an atomic relation swap on every table sync.
- Sync history in `analytics_runs`, backing `lastSyncedAt()` and `isStale()`.
- Concurrent builds with `analytics:sync --parallel`, scheduling each dependency wave across worker processes.
- `analytics:sync --continue`, resuming the last run and skipping models that already succeeded, across every connection.
- `analytics:sync --porcelain` for machine readable output.
- `Materialization::Incremental` with `isIncremental()`, `uniqueKey()` and `analytics:sync --full-refresh`; appends by default, deletes and replaces when a unique key is declared.
- `Materialization::Microbatch`, rebuilding one time slice at a time with `eventTime()`, `batchSize()`, `lookback()` and `begin()`, plus `--event-time-start` / `--event-time-end` for backfills.
- `Materialization::Snapshot`, recording one row per version with `valid_from` / `valid_to`, watched columns chosen by `checkColumns()` and compared null safely.
- `onSchemaChange()` reconciling an incremental relation whose columns drifted: `Fail` (default), `Ignore`, `AppendNewColumns`, `SyncAllColumns`.
- `incrementalStrategy()` choosing between `Append` and `DeleteInsert` explicitly, with the replace wrapped in a transaction.
- A guard refusing an incremental model whose SQL contains a window function, unless `allowsWindowFunctions()` says otherwise.
- `analytics:prune` and a `retention` window, removing old sync history across every connection; `AnalyticsRun` uses Laravel's `MassPrunable`.
- Data expectations declared with `expectations()` and checked by `analytics:test`: `unique`, `notNull`, `acceptedValues`, `expression` and `relationship`.
- Expressions that resolve their driver when compiled rather than being handed one: `date_trunc()`, `date_add()`, `date_diff()`, `date_spine()`, `string_agg()`, `cast()` and `raw()` under `Eznix86\LaravelAnalytics`. They nest, take an `->as()` alias, compile inside a query builder, and render inside a string model with `$this->render()`, so a shared metric no longer takes a `Grammar` argument.

### Changed

- `analytics:test` reports a model whose relation has not been built yet and exits non-zero, instead of surfacing the driver's `relation does not exist` error. Views count as built, which `hasTable()` alone does not report on any driver.
- Renamed the package from `eznix86/laravel-dbt` to `eznix86/laravel-analytics`, and the namespace from `Eznix86\LaravelDBT` to `Eznix86\LaravelAnalytics`.

## [v0.1.0](https://github.com/eznix86/laravel-analytics/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
