<?php

namespace App\Console\Commands\Import;

use App\Models\OrderItem;
use App\Services\Import\LegacyOrderItemNameMatcher;
use Illuminate\Console\Command;

class MatchOrderItemsByName extends Command
{
    protected $signature = 'import:match-order-item-names {--apply : Save only unambiguous matches} {--show-unmatched=30}';
    protected $description = 'Match legacy order items without SKU to the current catalogue by name, size and colour.';

    public function handle(LegacyOrderItemNameMatcher $matcher): int
    {
        $stats = ['total' => 0, 'linked_product' => 0, 'linked_variant' => 0, 'ambiguous' => 0, 'not_found' => 0, 'updated' => 0];
        $unmatched = [];

        OrderItem::query()->whereNull('product_id')->whereNull('legacy_sku')->whereNotNull('legacy_name')
            ->orderBy('id')->chunkById(500, function ($items) use ($matcher, &$stats, &$unmatched) {
                foreach ($items as $item) {
                    $stats['total']++;
                    $match = $matcher->match($item->legacy_name);
                    if ($match['product_id'] && in_array($match['reason'], ['product_only', 'product_and_variant'], true)) {
                        $stats['linked_product']++;
                        if ($match['variant_id']) $stats['linked_variant']++;
                        if ($this->option('apply')) {
                            $item->update(['product_id' => $match['product_id'], 'product_variant_id' => $match['variant_id']]);
                            $stats['updated']++;
                        }
                    } else {
                        str_starts_with($match['reason'], 'ambiguous') ? $stats['ambiguous']++ : $stats['not_found']++;
                        if (count($unmatched) < (int) $this->option('show-unmatched')) $unmatched[] = [$item->id, $item->legacy_name, $match['reason']];
                    }
                }
            });

        $this->table(['metric', 'count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($unmatched) $this->table(['order_item_id', 'legacy_name', 'reason'], $unmatched);
        return self::SUCCESS;
    }
}
