<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

use Eznix86\LaravelAnalytics\Exceptions\UnsupportedDriver;
use Illuminate\Database\Grammar as QueryGrammar;
use Illuminate\Database\Query\Grammars\MariaDbGrammar as MariaDbQueryGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar as MySqlQueryGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar as PostgresQueryGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar as SQLiteQueryGrammar;

class GrammarManager
{
    /**
     * @var array<string, class-string<Grammar>>
     */
    protected array $grammars = [
        'pgsql' => PostgresGrammar::class,
        'mysql' => MySqlGrammar::class,
        'mariadb' => MySqlGrammar::class,
        'sqlite' => SQLiteGrammar::class,
    ];

    /**
     * @var array<string, Grammar>
     */
    protected array $resolved = [];

    /**
     * @param  class-string<Grammar>  $grammar
     */
    public function extend(string $driver, string $grammar): void
    {
        $this->grammars[$driver] = $grammar;

        unset($this->resolved[$driver]);
    }

    public function for(string $driver): Grammar
    {
        if (! isset($this->grammars[$driver])) {
            throw UnsupportedDriver::for($driver, array_keys($this->grammars));
        }

        return $this->resolved[$driver] ??= new $this->grammars[$driver];
    }

    /**
     * Laravel's own grammar is the only driver signal an expression gets when it is
     * compiled inside a query builder.
     */
    public function fromQueryGrammar(QueryGrammar $grammar): Grammar
    {
        return $this->for(match (true) {
            $grammar instanceof MariaDbQueryGrammar => 'mariadb',
            $grammar instanceof MySqlQueryGrammar => 'mysql',
            $grammar instanceof PostgresQueryGrammar => 'pgsql',
            $grammar instanceof SQLiteQueryGrammar => 'sqlite',
            default => throw UnsupportedDriver::forQueryGrammar($grammar::class, $this->drivers()),
        });
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_keys($this->grammars);
    }
}
