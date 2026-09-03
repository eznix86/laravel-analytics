<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Models;

use Carbon\CarbonInterval;
use Eznix86\LaravelAnalytics\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property class-string $model
 * @property string $materialization
 * @property RunStatus $status
 * @property int|null $rows
 * @property int $duration_ms
 * @property string|null $error
 * @property Carbon $synced_at
 */
class AnalyticsRun extends Model
{
    use MassPrunable;

    protected $table = 'analytics_runs';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Runs older than the configured retention window. `newQuery()` rather than
     * `static::query()` so pruning follows the connection this instance is on.
     *
     * @return Builder<AnalyticsRun>
     */
    public function prunable(): Builder
    {
        $retention = config('analytics.retention');

        if (! is_string($retention) || $retention === '') {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        return $this->newQuery()->where(
            'synced_at',
            '<=',
            Carbon::now()->sub(CarbonInterval::make($retention)),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'rows' => 'integer',
            'duration_ms' => 'integer',
            'status' => RunStatus::class,
        ];
    }
}
