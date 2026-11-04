<?php

namespace App\Console\Commands;

use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckDiscountsValidity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discounts:check-validity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivates expired discounts and activates currently valid ones.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $expiredQuery = Discount::where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now);
        $expiredIds = (clone $expiredQuery)->pluck('id')->all();
        $expired = $expiredQuery->update(['is_active' => false]);
        if ($expiredIds) {
            Log::info('Discounts deactivated by schedule (ends_at passed)', ['ids' => $expiredIds]);
        }

        $activated = Discount::where('is_active', false)
            ->where('is_manually_disabled', false)
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->update(['is_active' => true]);
    }
}
