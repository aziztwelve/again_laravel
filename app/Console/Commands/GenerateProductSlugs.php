<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class GenerateProductSlugs extends Command
{
    protected $signature = 'products:generate-slugs
        {--all : Regenerate slugs for all products}
        {--dry-run : Show products that would be updated without saving}';
    protected $description = 'Generate readable slugs for products using transliteration';

    public function handle()
    {
        $query = Product::query();

        if (!$this->option('all')) {
            $query->where('slug', 'regexp', '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$');
        }

        $products = $query->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->info('No products require slug regeneration.');
            return self::SUCCESS;
        }

        foreach ($products as $product) {
            $oldSlug = $product->slug;
            $product->slug = $product->generateUniqueSlug($product->name);

            if ($this->option('dry-run')) {
                $this->line("{$product->id}: {$oldSlug} -> {$product->slug}");
                continue;
            }

            if ($product->isDirty('slug')) {
                $product->save();
                $this->line("{$product->id}: {$oldSlug} -> {$product->slug}");
            }
        }

        $mode = $this->option('dry-run') ? 'would be generated' : 'generated';
        $this->info('Slugs ' . $mode . ' for ' . $products->count() . ' products.');

        return self::SUCCESS;
    }
}
