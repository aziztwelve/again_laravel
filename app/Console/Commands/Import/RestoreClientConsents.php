<?php

namespace App\Console\Commands\Import;

use App\Services\Import\ClientConsentRestoreService;
use Illuminate\Console\Command;
use RuntimeException;

class RestoreClientConsents extends Command
{
    protected $signature = 'import:restore-client-consents {file} {--apply} {--backup= : Required path for a new backup file when applying}';

    protected $description = 'Restore only personal-data and newsletter flags for uniquely matched CSV clients (dry run by default)';

    public function handle(ClientConsentRestoreService $service): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('CSV file is not readable.');
            return self::FAILURE;
        }
        $plan = $service->plan($file);
        $this->table(['Metric', 'Count'], collect($plan['stats'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        if (! $this->option('apply')) {
            $this->info('Dry run: no records changed.');
            return self::SUCCESS;
        }
        $backup = (string) $this->option('backup');
        if ($backup === '' || ! is_dir(dirname($backup))) {
            throw new RuntimeException('Provide --backup pointing to an existing private directory.');
        }
        $handle = fopen($backup, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create a new backup; nothing changed.');
        }
        try {
            if (! chmod($backup, 0600)) {
                throw new RuntimeException('Cannot restrict backup permissions.');
            }
            $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Backup write failed; nothing changed.');
            }
        } finally {
            fclose($handle);
        }
        $count = $service->apply($plan);
        $this->info("Restored {$count} clients. Backup: {$backup}");
        return self::SUCCESS;
    }
}
