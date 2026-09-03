<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Import;

use Illuminate\Database\Eloquent\Model;

class RemoteEvent extends Model
{
    protected $connection = 'warehouse';

    protected $table = 'events';

    public $timestamps = false;

    protected $guarded = [];
}
