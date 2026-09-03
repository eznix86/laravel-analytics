<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

use Eznix86\LaravelAnalytics\Exceptions\UnsupportedDriver;

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
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_keys($this->grammars);
    }
}
