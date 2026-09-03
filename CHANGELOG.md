# Release Notes

## [Unreleased](https://github.com/eznix86/laravel-analytics/compare/v0.1.0...1.x)

### Added

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

### Changed

- Renamed the package from `eznix86/laravel-dbt` to `eznix86/laravel-analytics`, and the namespace from `Eznix86\LaravelDBT` to `Eznix86\LaravelAnalytics`.

## [v0.1.0](https://github.com/eznix86/laravel-analytics/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
