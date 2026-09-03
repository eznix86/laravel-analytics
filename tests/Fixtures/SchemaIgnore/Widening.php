<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\SchemaIgnore;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\SchemaChange;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Widening extends Model implements AnalyticsModel
{
    /**
     * Flipped by the test to simulate the model gaining or losing a column.
     */
    public static string $columns = 'id, customer_id';

    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function onSchemaChange(): SchemaChange
    {
        return SchemaChange::Ignore;
    }

    public function computes(): string
    {
        $sql = 'select '.static::$columns.' from '.$this->ref(Order::class);

        if ($this->isIncremental()) {
            $sql .= ' where id > (select max(id) from '.$this->getTable().')';
        }

        return $sql;
    }
}
