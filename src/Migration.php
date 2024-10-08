<?php

namespace Fcz\Migrator;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Stringable;
use Laravel\Prompts\Progress;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

abstract class Migration
{
    protected Carbon $start;
    public Cursor $cursor;
    protected int $succeed = 0;
    protected int $skipped = 0;
    protected int $failed = 0;
    protected ?LoggerInterface $logger = null;

    public function __construct()
    {
        $this->start = now();
        $this->cursor = new Cursor(get_class($this));
    }

    /**
     * Destination table name.
     */
    abstract public function table(): string;

    /**
     * Source query builder.
     */
    abstract public function query(): Builder;

    /**
     * Source table key name.
     */
    abstract public function keyName(): string;

    /**
     * Run these migrations first.
     *
     * @return array<static>
     */
    abstract public function dependsOn(): array;

    /**
     * Migrate row.
     *
     * @throws Throwable
     * @internal
     */
    abstract public function migrate(stdClass $row): bool;

    public function title(): Stringable
    {
        return str(class_basename($this))->headline();
    }

    public function before(): void
    {
        //
    }

    public function after(): void
    {
        // DB::getPdo()->query("optimize table {$this->table()}");
        // DB::getPdo()->query("flush table {$this->table()}");
    }

    public function always(?Command $shell): void
    {

    }

    /**
     * Count all rows.
     */
    public function total(): int
    {
        return $this->skipQuery()->count() + $this->leftQuery()->count();
    }

    /**
     * Get rows already migrated.
     */
    public function skipQuery(): Builder
    {
        return $this->query()
            ->where($this->keyName(), '<=', $this->cursor->get());
    }

    /**
     * Get rows left to migrate.
     */
    public function leftQuery(): Builder
    {
        return $this->query()
            ->where($this->keyName(), '>', $this->cursor->get());
    }

    /**
     * Iterate rows through migration.
     */
    protected function each(Closure $closure, int $limit = null): void
    {
        $query = $this->leftQuery()
            ->orderBy($this->keyName());

        if ($limit) {
            $query
                ->limit($limit)
                ->get()
                ->each($closure);
        } else {
            $query
                ->each($closure);
        }
    }

    /**
     * Run migration.
     */
    public function run(int $limit = null, Progress $bar = null): void
    {
        $bar?->start();
        $skip = $this->skipQuery()->count();
        $bar?->advance($skip);
        $limiter = Limit::perSecond(1);

        $start = microtime(true);

        $this->before();

        $this->each(function (stdClass $row) use ($bar, $skip, $start, $limiter) {
            try {
                if ($this->migrate($row)) {
                    $this->succeed++;
                } else {
                    $this->skipped++;
                }
            } catch (Throwable $exception) {
                $this->failed++;
                $this->logger?->error(
                    $exception->getMessage(),
                //$exception->getTrace()
                );
            }

            $this->cursor->set($row->{$this->keyName()});

            if ($bar) {
                progressbar_hint($start, $bar, $limiter, $skip);
            }

            $bar?->advance();
        }, $limit);

        $this->after();

        $bar?->finish();
    }

    public function duration(): CarbonInterval
    {
        return $this->start->diffAsCarbonInterval(now());
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function succeed(): int
    {
        return $this->succeed;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }
}