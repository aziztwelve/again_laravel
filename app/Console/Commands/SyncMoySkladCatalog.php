<?php

namespace App\Console\Commands;

use App\Services\MoySklad\MoySkladHelperService;
use App\Services\MoySklad\MoySkladSettings;
use Illuminate\Console\Command;
use Throwable;

class SyncMoySkladCatalog extends Command
{
    protected $signature = 'moysklad:sync-catalog';

    protected $description = 'Synchronize products, modifications, prices and available stock from MoySklad';

    public function handle(MoySkladHelperService $moySklad): int
    {
        if (! MoySkladSettings::isConfigured()) {
            $this->warn('MoySklad is not configured; catalog sync skipped.');

            return self::SUCCESS;
        }

        try {
            $moySklad->sync_products_with_moysklad();
            $this->info('MoySklad catalog synchronized.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('MoySklad catalog synchronization failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
