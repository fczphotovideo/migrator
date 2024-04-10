<?php

namespace Fcz\Migrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

use Psr\Log\LoggerInterface;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

abstract class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:data 
                        {--reset : Reset migration progress}
                        {--force : Work, dont ask}
                        {--stat : Show statistics}
                        {--limit= : Limited migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy data (continuously)';

    protected array $migrated = [];

    abstract public function logger(): ?LoggerInterface;
    abstract public function migrations(): array;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        DB::disableQueryLog();

        $migrations = $this->migrations();

        if ($this->option('stat')) {
            $this->cursors($migrations);
            return;
        }

        if ($this->option('force')) {
            $choice = ['Everything'];
        } else {
            $question = $this->option('reset') ? "What to reset?" : "What to migrate?";

            $choice = multiselect(
                label: $question,
                options: array_merge(['Everything'], array_keys($migrations)),
                default: ['Everything'],
                required: true,
            );
        }

        if ($this->option('reset')) {
            foreach ($migrations as $name => $closure) {
                if (in_array('Everything', $choice) || in_array($name, $choice)) {
                    $this->reset(call_user_func($closure));
                }
            }
        }

        foreach ($migrations as $name => $closure) {
            if (in_array('Everything', $choice) || in_array($name, $choice)) {
                $this->migrate(call_user_func($closure));
            }
        }

        info('Done');
    }

    public function cursors(array $migrations): void
    {
        $rows = [];

        foreach ($migrations as $name => $closure) {
            /** @var Migration $migration */
            $migration = call_user_func($closure);

            $row = $rows[$migration->table()] ?? [];

            $targetRows = $row['target'] ?? DB::table($migration->table())->count();

            if ($migration->cursor->isDisabled()) {
                $sourceRows = $migration->total();
                $progress = 0;
            } else {
                $skip = $migration->skipQuery()->count();
                $left = $migration->leftQuery()->count();
                $sourceRows = $skip + $left;
                $progress = $sourceRows ? $skip / $sourceRows : 0;
            }

            $row['source'] = ($row['source'] ?? 0) + $sourceRows;
            $row['target'] = $targetRows;
            $row['consistency'] = $row['target'] / $row['source'];
            $row['progress'] = max($row['progress'] ?? 0, $progress);

            $rows[$migration->table()] = $row;
//                [
//                $name,
//                Number::format($sourceRows), Number::format($targetRows),
//                is_numeric($progress) ? Number::percentage($progress * 100) : '—',
//                Number::percentage($consistency * 100)
//            ];
        }

        ksort($rows);

        foreach ($rows as $name => $data) {
            $rows[$name] = [
                $name,
                Number::format($data['source']),
                Number::format($data['target']),
                Number::percentage($data['progress'] * 100),
                Number::percentage($data['consistency'] * 100),
            ];
        }

        $headers = ['Migration', 'Source rows', 'Migrated rows', 'Migrated', 'Consistency'];

        table($headers, $rows);
    }

    public function shouldMigrate(Migration $migration): bool
    {
        return !in_array($migration->cursor->name, $this->migrated) && $migration->leftQuery()->count();
    }

    public function wasMigrated(Migration $migration): void
    {
        $this->migrated[] = $migration->cursor->name;
    }

    public function reset(Migration $migration): void
    {
        warning($migration->title()->replace('Migrate', 'Rewind'));
        $migration->cursor->rewind();
    }

    public function migrate(Migration $migration): void
    {
        foreach ($migration->dependsOn() as $dependency) {
            $this->migrate($dependency);
        }

        if ($this->shouldMigrate($migration)) {
            $migration
                ->setLogger($this->logger())
                ->run(
                    (int) $this->option('limit'),
                    progress(
                        label: $migration->title(),
                        steps: $migration->total()
                    )
                );

            $this->stat($migration);

            $this->wasMigrated($migration);
        }
    }

    protected function stat(Migration $migration): void
    {
        if ($n = $migration->succeed()) {
            info("{$n} migrated");
        }
        if ($n = $migration->skipped()) {
            warning("{$n} skipped");
        }
        if ($n = $migration->failed()) {
            error("{$n} failed");
        }

        note('In '.$migration->duration()->forHumans());
        $this->newLine();
    }

}
