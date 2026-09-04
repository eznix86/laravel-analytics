# Release Notes

## [Unreleased](https://github.com/eznix86/laravel-analytics/compare/v0.2.2...HEAD)

## [v0.2.2](https://github.com/eznix86/laravel-analytics/compare/v0.2.1...v0.2.2) - 2026-09-04

<!-- Release notes generated using configuration in .github/release.yml at v0.2.2 -->
### What's Changed

#### Other Changes

* Swap views and MySQL tables atomically by @eznix86 in https://github.com/eznix86/laravel-analytics/pull/4

**Full Changelog**: https://github.com/eznix86/laravel-analytics/compare/v0.2.1...v0.2.2

## [v0.2.1](https://github.com/eznix86/laravel-analytics/compare/v0.2.0...v0.2.1) - 2026-09-03

### Fixed

- `make:analytics` generates a fluent `Query` model instead of raw SQL, matching the API the rest of the package leads with.

## [v0.2.0](https://github.com/eznix86/laravel-analytics/compare/v0.1.0...v0.2.0) - 2026-09-04

### Added

- `ImportQuery` and `Materialization::Import`, copying rows from the connection a source lives on onto the model's own connection, in chunks, upserted on a replace key, with `appendOnly:` reading only past the highest value already stored. Rows are streamed, with MySQL's result buffering turned off for the read, so memory is the size of a chunk rather than the size of the source. The target table is never created, so its column types stay the ones you migrated. Refuses a missing target, a target with no unique index on the replace key, an import with no replace key, and a source that is an analytics model on the other connection.

## [v0.1.0](https://github.com/eznix86/laravel-analytics/releases/tag/v0.1.0) - 2026-09-03

### Added

- `ViewQuery`, `EphemeralQuery`, `IncrementalQuery`, `MicrobatchQuery` and `SnapshotQuery`. The declared return type of `computes()` is the materialization, and each subclass carries the configuration that materialization needs — `replacing`/`since`, event time and batch size, tracked and watched columns — so a fluent model overrides neither `materialization()` nor `uniqueKey()`, `eventTime()`, `batchSize()`, `begin()` or `checkColumns()`, and a setting that belongs to another materialization does not exist on the query at all.
- `since()` and `whenIncremental()` on a fluent query, restricting an incremental run without the model writing the filter twice; `since()` compares with `>` when the model appends and `>=` when it replaces by key, and compares the dimension expression rather than its alias. A microbatch model is narrowed to the batch being built automatically, from `eventTime()`.
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

- A model's compilation context now stays set while its fluent query compiles. It was cleared as soon as `computes()` returned, so `isIncremental()` could not see a `--full-refresh` and a fluent incremental model kept its high water mark filter, rebuilding nothing.
- `analytics:test` reports a model whose relation has not been built yet and exits non-zero, instead of surfacing the driver's `relation does not exist` error. Views count as built, which `hasTable()` alone does not report on any driver.
- Renamed the package from `eznix86/laravel-dbt` to `eznix86/laravel-analytics`, and the namespace from `Eznix86\LaravelDBT` to `Eznix86\LaravelAnalytics`.
