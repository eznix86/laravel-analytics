<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Import;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\ImportQuery;
use Illuminate\Database\Eloquent\Model;

class ImportedEvents extends Model implements AnalyticsModel
{
    use Analytics;

    protected $table = 'imported_events';

    public function computes(): ImportQuery
    {
        return $this->from(RemoteEvent::class)
            ->select('id', 'name', 'happened_at')
            ->import(replacing: ['id'], since: 'id', chunk: 2);
    }
}
